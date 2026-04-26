<?php

declare(strict_types=1);

namespace vitordiniz22\craftlenstests\unit\services;

use Codeception\Test\Unit;
use vitordiniz22\craftlens\enums\AnalysisStatus;
use vitordiniz22\craftlens\exceptions\AnalysisCancelledException;
use vitordiniz22\craftlens\Plugin;
use vitordiniz22\craftlens\records\AssetAnalysisRecord;
use vitordiniz22\craftlens\services\AnalysisCancellationService;
use vitordiniz22\craftlenstests\_support\Helpers\AnalysisRecordFixtures;

/**
 * Unit tests for AnalysisCancellationService.
 *
 * Tests cancel logic, checkpoint detection, and status restoration.
 * Craft test module wraps each test in a rolled-back transaction.
 */
class AnalysisCancellationServiceTest extends Unit
{
    use AnalysisRecordFixtures;

    private AnalysisCancellationService $service;

    protected function _before(): void
    {
        parent::_before();
        $this->service = Plugin::getInstance()->analysisCancellation;
    }

    protected function _after(): void
    {
        $this->cleanupAnalysisRecords();
        parent::_after();
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
        $record->previousStatus = AnalysisStatus::Completed->value;
        $record->altText = 'My manual edit';
        $record->altTextAi = 'AI suggested text';
        $record->suggestedTitle = 'My title';
        $record->save(false);

        $this->service->cancel($record->assetId);

        $restored = AssetAnalysisRecord::findOne(['assetId' => $record->assetId]);
        $this->assertSame('My manual edit', $restored->altText);
        $this->assertSame('AI suggested text', $restored->altTextAi);
        $this->assertSame('My title', $restored->suggestedTitle);
        $this->assertSame(AnalysisStatus::Completed->value, $restored->status);
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

}
