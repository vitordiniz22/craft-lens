<?php

declare(strict_types=1);

namespace vitordiniz22\craftlenstests\unit\services;

use Craft;
use Codeception\Test\Unit;
use craft\helpers\StringHelper;
use vitordiniz22\craftlens\enums\AnalysisStatus;
use vitordiniz22\craftlens\exceptions\AnalysisCancelledException;
use vitordiniz22\craftlens\Plugin;
use vitordiniz22\craftlens\records\AssetAnalysisRecord;
use vitordiniz22\craftlens\services\AnalysisCancellationService;

/**
 * Unit tests for AnalysisCancellationService.
 *
 * Tests cancel logic, checkpoint detection, and status restoration.
 * Craft test module wraps each test in a rolled-back transaction.
 */
class AnalysisCancellationServiceTest extends Unit
{
    private AnalysisCancellationService $service;

    protected function _before(): void
    {
        parent::_before();
        $this->service = Plugin::getInstance()->analysisCancellation;
    }

    // -- cancel(): first-time analysis --

    public function testCancelFirstTimeDeletesRecord(): void
    {
        $record = $this->createAnalysisRecord(AnalysisStatus::Pending->value);

        $result = $this->service->cancel($record->assetId);

        $this->assertTrue($result['success']);
        $this->assertFalse($result['restored']);
        $this->assertNull($result['status']);
        $this->assertNull(AssetAnalysisRecord::findOne(['assetId' => $record->assetId]));
    }

    public function testCancelIdempotentWhenNoRecord(): void
    {
        $result = $this->service->cancel(999999);

        $this->assertTrue($result['success']);
        $this->assertFalse($result['restored']);
        $this->assertNull($result['status']);
    }

    public function testCancelReleasesQueueJob(): void
    {
        $record = $this->createAnalysisRecord(AnalysisStatus::Pending->value);
        $record->queueJobId = '12345';
        $record->save(false);

        $result = $this->service->cancel($record->assetId);

        $this->assertTrue($result['success']);
        $this->assertNull(AssetAnalysisRecord::findOne(['assetId' => $record->assetId]));
    }

    // -- cancel(): re-analysis --

    public function testCancelReAnalysisRestoresStatus(): void
    {
        $record = $this->createAnalysisRecord(AnalysisStatus::Processing->value);
        $record->previousStatus = AnalysisStatus::Completed->value;
        $record->save(false);

        $result = $this->service->cancel($record->assetId);

        $this->assertTrue($result['success']);
        $this->assertTrue($result['restored']);
        $this->assertSame(AnalysisStatus::Completed->value, $result['status']);

        $restored = AssetAnalysisRecord::findOne(['assetId' => $record->assetId]);
        $this->assertNotNull($restored);
        $this->assertSame(AnalysisStatus::Completed->value, $restored->status);
        $this->assertNull($restored->previousStatus);
        $this->assertNull($restored->queueJobId);
    }

    public function testCancelReAnalysisPreservesData(): void
    {
        $record = $this->createAnalysisRecord(AnalysisStatus::Processing->value);
        $record->previousStatus = AnalysisStatus::Approved->value;
        $record->altText = 'My manual edit';
        $record->altTextAi = 'AI suggested text';
        $record->suggestedTitle = 'My title';
        $record->save(false);

        $this->service->cancel($record->assetId);

        $restored = AssetAnalysisRecord::findOne(['assetId' => $record->assetId]);
        $this->assertSame('My manual edit', $restored->altText);
        $this->assertSame('AI suggested text', $restored->altTextAi);
        $this->assertSame('My title', $restored->suggestedTitle);
        $this->assertSame(AnalysisStatus::Approved->value, $restored->status);
    }

    // -- cancel(): terminal status (race condition) --

    public function testCancelRejectsTerminalStatus(): void
    {
        $record = $this->createAnalysisRecord(AnalysisStatus::Completed->value);

        $result = $this->service->cancel($record->assetId);

        $this->assertFalse($result['success']);
        $this->assertTrue($result['alreadyCompleted']);
        $this->assertNotNull(AssetAnalysisRecord::findOne(['assetId' => $record->assetId]));
    }

    // -- assertNotCancelled() --

    public function testAssertNotCancelledPassesWhenProcessing(): void
    {
        $record = $this->createAnalysisRecord(AnalysisStatus::Processing->value);

        $this->service->assertNotCancelled($record->assetId);
        $this->assertTrue(true);
    }

    public function testAssertNotCancelledThrowsWhenRecordDeleted(): void
    {
        $this->expectException(AnalysisCancelledException::class);

        $this->service->assertNotCancelled(999999);
    }

    public function testAssertNotCancelledThrowsWhenStatusChanged(): void
    {
        $record = $this->createAnalysisRecord(AnalysisStatus::Completed->value);

        $this->expectException(AnalysisCancelledException::class);

        $this->service->assertNotCancelled($record->assetId);
    }

    // -- Helpers --

    private function createAnalysisRecord(string $status): AssetAnalysisRecord
    {
        $db = Craft::$app->getDb();
        $db->createCommand('SET FOREIGN_KEY_CHECKS=0')->execute();

        try {
            $db->createCommand()->insert('{{%elements}}', [
                'type' => 'craft\\elements\\Asset',
                'enabled' => true,
                'dateCreated' => date('Y-m-d H:i:s'),
                'dateUpdated' => date('Y-m-d H:i:s'),
                'uid' => StringHelper::UUID(),
            ])->execute();

            $elementId = (int) $db->getLastInsertID();

            $primarySite = Craft::$app->getSites()->getPrimarySite();
            $db->createCommand()->insert('{{%elements_sites}}', [
                'elementId' => $elementId,
                'siteId' => $primarySite->id,
                'slug' => 'test-asset-' . $elementId,
                'uri' => null,
                'enabled' => true,
                'dateCreated' => date('Y-m-d H:i:s'),
                'dateUpdated' => date('Y-m-d H:i:s'),
                'uid' => StringHelper::UUID(),
            ])->execute();

            $record = new AssetAnalysisRecord();
            $record->assetId = $elementId;
            $record->status = $status;
            $record->save(false);

            return $record;
        } finally {
            $db->createCommand('SET FOREIGN_KEY_CHECKS=1')->execute();
        }
    }
}
