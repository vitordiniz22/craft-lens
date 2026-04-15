<?php

declare(strict_types=1);

namespace vitordiniz22\craftlenstests\integration\services;

use Codeception\Test\Unit;
use vitordiniz22\craftlens\enums\AnalysisStatus;
use vitordiniz22\craftlens\Plugin;
use vitordiniz22\craftlens\records\AssetAnalysisRecord;
use vitordiniz22\craftlens\services\AnalysisCancellationService;
use vitordiniz22\craftlenstests\_support\Helpers\AnalysisRecordFixtures;

class CancelAnalysisFlowTest extends Unit
{
    use AnalysisRecordFixtures;

    private AnalysisCancellationService $service;

    protected function _before(): void
    {
        parent::_before();
        $this->service = Plugin::getInstance()->analysisCancellation;
    }

    public function testCancelPendingFirstTimeAnalysis(): void
    {
        $record = $this->createAnalysisRecord(AnalysisStatus::Pending->value);
        $record->queueJobId = '99999';
        $record->save(false);

        $result = $this->service->cancel($record->assetId);

        $this->assertTrue($result['success']);
        $this->assertFalse($result['restored']);
        $this->assertNull($result['status']);
        $this->assertNull(AssetAnalysisRecord::findOne(['assetId' => $record->assetId]));
    }

    public function testCancelDoesNotAffectOtherAssets(): void
    {
        $record1 = $this->createAnalysisRecord(AnalysisStatus::Pending->value);
        $record1->queueJobId = '88881';
        $record1->save(false);

        $record2 = $this->createAnalysisRecord(AnalysisStatus::Pending->value);
        $record2->queueJobId = '88882';
        $record2->save(false);

        $this->service->cancel($record1->assetId);

        $this->assertNull(AssetAnalysisRecord::findOne(['assetId' => $record1->assetId]));
        $this->assertNotNull(AssetAnalysisRecord::findOne(['assetId' => $record2->assetId]));
    }

    public function testCancelReAnalysisRestoresPreviousStatus(): void
    {
        $record = $this->createAnalysisRecord(AnalysisStatus::Processing->value);
        $record->previousStatus = AnalysisStatus::Completed->value;
        $record->altText = 'My manual edit';
        $record->altTextAi = 'AI original';
        $record->suggestedTitle = 'Manual title';
        $record->save(false);

        $result = $this->service->cancel($record->assetId);

        $this->assertTrue($result['success']);
        $this->assertTrue($result['restored']);
        $this->assertSame(AnalysisStatus::Completed->value, $result['status']);

        $restored = AssetAnalysisRecord::findOne(['assetId' => $record->assetId]);
        $this->assertNotNull($restored);
        $this->assertSame(AnalysisStatus::Completed->value, $restored->status);
        $this->assertNull($restored->previousStatus);
        $this->assertSame('My manual edit', $restored->altText);
        $this->assertSame('AI original', $restored->altTextAi);
        $this->assertSame('Manual title', $restored->suggestedTitle);
    }

    public function testPreviousStatusClearedOnSuccessfulCompletion(): void
    {
        $record = $this->createAnalysisRecord(AnalysisStatus::Processing->value);
        $record->previousStatus = AnalysisStatus::Completed->value;
        $record->save(false);

        $record->status = AnalysisStatus::Completed->value;
        $record->previousStatus = null;
        $record->queueJobId = null;
        $record->save(false);

        $check = AssetAnalysisRecord::findOne(['assetId' => $record->assetId]);
        $this->assertSame(AnalysisStatus::Completed->value, $check->status);
        $this->assertNull($check->previousStatus);
        $this->assertNull($check->queueJobId);
    }

    public function testOrphanedRecordCleanedUpOnCancel(): void
    {
        $record = $this->createAnalysisRecord(AnalysisStatus::Pending->value);
        $record->queueJobId = '00000';
        $record->save(false);

        $this->assertFalse($this->service->isQueueJobAlive('00000'));

        $result = $this->service->cancel($record->assetId);

        $this->assertTrue($result['success']);
        $this->assertNull(AssetAnalysisRecord::findOne(['assetId' => $record->assetId]));
    }

    public function testCancelRejectsCompletedAnalysis(): void
    {
        $record = $this->createAnalysisRecord(AnalysisStatus::Completed->value);

        $result = $this->service->cancel($record->assetId);

        $this->assertFalse($result['success']);
        $this->assertTrue($result['alreadyCompleted']);
        $this->assertNotNull(AssetAnalysisRecord::findOne(['assetId' => $record->assetId]));
    }

}
