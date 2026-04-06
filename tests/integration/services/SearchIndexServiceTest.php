<?php

declare(strict_types=1);

namespace vitordiniz22\craftlenstests\integration\services;

use Craft;
use Codeception\Test\Unit;
use vitordiniz22\craftlens\migrations\Install;
use vitordiniz22\craftlens\Plugin;
use vitordiniz22\craftlens\records\AssetAnalysisRecord;
use vitordiniz22\craftlens\services\SearchIndexService;

/**
 * Integration tests for SearchIndexService.
 *
 * Demonstrates how to:
 * - Access plugin services via Plugin::getInstance()
 * - Create test data in the database (with FK handling)
 * - Test service methods that read/write to Craft's DB
 *
 * The Craft test module wraps each test in a DB transaction that rolls back,
 * so test data never persists between tests.
 */
class SearchIndexServiceTest extends Unit
{
    private SearchIndexService $service;

    protected function _before(): void
    {
        parent::_before();
        $this->service = Plugin::getInstance()->searchIndex;
    }

    public function testPluginIsInstalled(): void
    {
        $this->assertInstanceOf(Plugin::class, Plugin::getInstance());
        $this->assertInstanceOf(SearchIndexService::class, $this->service);
    }

    public function testIndexAssetCreatesTokens(): void
    {
        $record = $this->createTestAnalysisRecord([
            'altText' => 'A golden retriever playing fetch in the park',
            'suggestedTitle' => 'Golden Retriever Park',
            'longDescription' => 'A happy golden retriever dog catches a red ball in a sunny park.',
        ]);

        $this->service->indexAsset($record);

        // Verify tokens were inserted into the search index
        $tokenCount = (int) (new \yii\db\Query())
            ->from(Install::TABLE_SEARCH_INDEX)
            ->where(['assetId' => $record->assetId])
            ->count();

        $this->assertGreaterThan(0, $tokenCount, 'indexAsset() should create tokens in the search index');
    }

    public function testSearchFindsIndexedAsset(): void
    {
        $record = $this->createTestAnalysisRecord([
            'altText' => 'A majestic lighthouse on a rocky cliff at sunset',
            'suggestedTitle' => 'Lighthouse Sunset',
        ]);

        $this->service->indexAsset($record);

        $results = $this->service->search(['lighthouse']);

        $this->assertNotEmpty($results, 'search() should find the indexed asset');
        $this->assertArrayHasKey($record->assetId, $results, 'Results should contain the indexed asset ID');
        $this->assertGreaterThan(0, $results[$record->assetId], 'BM25 score should be positive');
    }

    public function testSearchReturnsEmptyForUnmatchedTerms(): void
    {
        $record = $this->createTestAnalysisRecord([
            'altText' => 'A cat sleeping on a windowsill',
            'suggestedTitle' => 'Sleeping Cat',
        ]);

        $this->service->indexAsset($record);

        $results = $this->service->search(['spaceship', 'astronaut']);

        $this->assertArrayNotHasKey(
            $record->assetId,
            $results,
            'search() should not match unrelated terms'
        );
    }

    /**
     * Creates a minimal element record and an AssetAnalysisRecord for testing.
     *
     * The lens_asset_analyses table has a FK to elements.id, so we must insert
     * a valid element first. We temporarily disable FK checks to keep the test
     * setup minimal (no full Volume/Filesystem/Asset setup required).
     */
    private function createTestAnalysisRecord(array $fields): AssetAnalysisRecord
    {
        $db = Craft::$app->getDb();

        // Disable FK checks so we can insert a minimal element row
        $db->createCommand('SET FOREIGN_KEY_CHECKS=0')->execute();

        try {
            // Insert a minimal element record
            $db->createCommand()->insert('{{%elements}}', [
                'type' => 'craft\\elements\\Asset',
                'enabled' => true,
                'dateCreated' => date('Y-m-d H:i:s'),
                'dateUpdated' => date('Y-m-d H:i:s'),
                'uid' => \craft\helpers\StringHelper::UUID(),
            ])->execute();

            $elementId = (int) $db->getLastInsertID();

            // Insert elements_sites row (required for element queries)
            $primarySite = Craft::$app->getSites()->getPrimarySite();
            $db->createCommand()->insert('{{%elements_sites}}', [
                'elementId' => $elementId,
                'siteId' => $primarySite->id,
                'slug' => 'test-asset-' . $elementId,
                'uri' => null,
                'enabled' => true,
                'dateCreated' => date('Y-m-d H:i:s'),
                'dateUpdated' => date('Y-m-d H:i:s'),
                'uid' => \craft\helpers\StringHelper::UUID(),
            ])->execute();

            // Create the analysis record
            $record = new AssetAnalysisRecord();
            $record->assetId = $elementId;
            $record->status = 'completed';
            $record->provider = 'test';
            $record->providerModel = 'test-model';
            $record->altText = $fields['altText'] ?? '';
            $record->altTextAi = $fields['altText'] ?? '';
            $record->suggestedTitle = $fields['suggestedTitle'] ?? '';
            $record->suggestedTitleAi = $fields['suggestedTitle'] ?? '';
            $record->longDescription = $fields['longDescription'] ?? '';
            $record->longDescriptionAi = $fields['longDescription'] ?? '';
            $record->extractedText = $fields['extractedText'] ?? null;
            $record->extractedTextAi = $fields['extractedText'] ?? null;
            $record->altTextConfidence = 0.95;
            $record->titleConfidence = 0.90;
            $record->longDescriptionConfidence = 0.85;

            $saved = $record->save(false);
            $this->assertTrue($saved, 'AssetAnalysisRecord should save successfully');

            return $record;
        } finally {
            $db->createCommand('SET FOREIGN_KEY_CHECKS=1')->execute();
        }
    }
}
