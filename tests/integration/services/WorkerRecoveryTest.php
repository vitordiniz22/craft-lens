<?php

declare(strict_types=1);

namespace vitordiniz22\craftlenstests\integration\services;

use Codeception\Test\Unit;
use vitordiniz22\craftlens\enums\AnalysisStatus;
use vitordiniz22\craftlens\enums\ErrorCode;
use vitordiniz22\craftlens\jobs\AnalyzeAssetJob;
use vitordiniz22\craftlens\Plugin;
use vitordiniz22\craftlens\records\AssetAnalysisRecord;
use vitordiniz22\craftlenstests\_support\Helpers\AnalysisRecordFixtures;

class WorkerRecoveryTest extends Unit
{
    use AnalysisRecordFixtures;

    protected function _after(): void
    {
        $this->cleanupAnalysisRecords();
        parent::_after();
    }

    public function testInterruptedFirstAnalysisBecomesFailed(): void
    {
        $record = $this->createAnalysisRecord(AnalysisStatus::Processing->value, [
            'queueJobId' => '123',
        ]);

        Plugin::getInstance()->assetAnalysis->recoverInterruptedAnalysis($record);

        $recovered = AssetAnalysisRecord::findOne($record->id);
        $this->assertSame(AnalysisStatus::Failed->value, $recovered->status);
        $this->assertNull($recovered->queueJobId);
        $this->assertNull($recovered->previousStatus);
        $this->assertNotNull($recovered->processedAt);
        $this->assertSame(
            ErrorCode::WorkerInterrupted->value,
            $recovered->analysisContent?->errorCode,
        );
    }

    public function testInterruptedReanalysisRestoresCompletedData(): void
    {
        $record = $this->createAnalysisRecord(AnalysisStatus::Processing->value, [
            'previousStatus' => AnalysisStatus::Completed->value,
            'queueJobId' => '456',
            'altText' => 'Existing result',
            'altTextAi' => 'Existing result',
        ]);

        Plugin::getInstance()->assetAnalysis->recoverInterruptedAnalysis($record);

        $recovered = AssetAnalysisRecord::findOne($record->id);
        $this->assertSame(AnalysisStatus::Completed->value, $recovered->status);
        $this->assertSame('Existing result', $recovered->altText);
        $this->assertNull($recovered->queueJobId);
        $this->assertNull($recovered->previousStatus);
    }

    public function testAnalyzeJobRetriesOnlyOnce(): void
    {
        $job = new AnalyzeAssetJob(['assetId' => 1]);

        $this->assertTrue($job->canRetry(1, new \RuntimeException('worker disappeared')));
        $this->assertFalse($job->canRetry(2, new \RuntimeException('worker disappeared')));
        $this->assertSame(600, $job->getTtr());
    }
}
