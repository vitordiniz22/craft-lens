<?php

declare(strict_types=1);

namespace vitordiniz22\craftlenstests\integration\services;

use Codeception\Test\Unit;
use vitordiniz22\craftlens\migrations\Install;
use vitordiniz22\craftlens\Plugin;
use vitordiniz22\craftlens\records\AssetAnalysisRecord;
use vitordiniz22\craftlens\services\SearchIndexService;
use vitordiniz22\craftlenstests\_support\Helpers\AnalysisRecordFixtures;

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
    use AnalysisRecordFixtures;

    private SearchIndexService $service;

    protected function _before(): void
    {
        parent::_before();
        $this->service = Plugin::getInstance()->searchIndex;
    }

    protected function _after(): void
    {
        $this->cleanupAnalysisRecords();
        parent::_after();
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
     * Creates an AssetAnalysisRecord with search-relevant fields populated.
     * Wraps the shared fixture and adds content defaults specific to search tests.
     */
    private function createTestAnalysisRecord(array $fields): AssetAnalysisRecord
    {
        $overrides = [
            'provider' => 'test',
            'providerModel' => 'test-model',
            'altText' => $fields['altText'] ?? '',
            'altTextAi' => $fields['altText'] ?? '',
            'suggestedTitle' => $fields['suggestedTitle'] ?? '',
            'suggestedTitleAi' => $fields['suggestedTitle'] ?? '',
            'longDescription' => $fields['longDescription'] ?? '',
            'longDescriptionAi' => $fields['longDescription'] ?? '',
            'altTextConfidence' => 0.95,
            'titleConfidence' => 0.90,
            'longDescriptionConfidence' => 0.85,
        ];

        if (array_key_exists('extractedTextAi', $fields)) {
            $overrides['extractedTextAi'] = $fields['extractedTextAi'];
        }

        return $this->createAnalysisRecord('completed', $overrides);
    }
}
