<?php

declare(strict_types=1);

namespace vitordiniz22\craftlenstests\integration\console;

use Codeception\Test\Unit;
use Craft;
use vitordiniz22\craftlens\console\controllers\ProcessController;
use vitordiniz22\craftlens\enums\AnalysisStatus;
use vitordiniz22\craftlens\jobs\BulkAnalyzeAssetsJob;
use vitordiniz22\craftlens\Plugin;
use vitordiniz22\craftlens\records\AssetAnalysisRecord;
use vitordiniz22\craftlens\services\AssetAnalysisService;
use vitordiniz22\craftlenstests\_support\Helpers\AnalysisRecordFixtures;
use yii\console\ExitCode;
use yii\db\Query;

class ProcessControllerTest extends Unit
{
    use AnalysisRecordFixtures;

    private CapturingProcessController $controller;

    protected function _before(): void
    {
        parent::_before();
        $this->controller = new CapturingProcessController('process', Craft::$app);
        $this->controller->interactive = false;

        $this->cleanupAnalysisRecords();
        $this->clearBulkAnalyzeJobs();
    }

    protected function _after(): void
    {
        $this->cleanupAnalysisRecords();
        $this->clearBulkAnalyzeJobs();
        parent::_after();
    }

    // ---------------------------------------------------------------------
    // retry-failed
    // ---------------------------------------------------------------------

    public function testRetryFailedHappyPath(): void
    {
        $failed1 = $this->createAnalysisRecord(AnalysisStatus::Failed->value);
        $failed2 = $this->createAnalysisRecord(AnalysisStatus::Failed->value);
        $failed3 = $this->createAnalysisRecord(AnalysisStatus::Failed->value);
        $completed = $this->createAnalysisRecord(AnalysisStatus::Completed->value);
        $pending = $this->createAnalysisRecord(AnalysisStatus::Pending->value);

        [$exitCode, $stdout, $stderr] = $this->capture(fn () => $this->controller->actionRetryFailed());

        $this->assertSame(ExitCode::OK, $exitCode);
        $this->assertSame('', $stderr);
        $this->assertStringContainsString('Queued 3 failed analyses for retry.', $stdout);
        $this->assertStringContainsString('php craft queue/run', $stdout);

        $this->assertSame(AnalysisStatus::Pending->value, $this->reload($failed1)->status);
        $this->assertSame(AnalysisStatus::Pending->value, $this->reload($failed2)->status);
        $this->assertSame(AnalysisStatus::Pending->value, $this->reload($failed3)->status);
        $this->assertSame(AnalysisStatus::Completed->value, $this->reload($completed)->status);
        $this->assertSame(AnalysisStatus::Pending->value, $this->reload($pending)->status);
    }

    public function testRetryFailedWithNoFailedRecords(): void
    {
        $completed = $this->createAnalysisRecord(AnalysisStatus::Completed->value);
        $pending = $this->createAnalysisRecord(AnalysisStatus::Pending->value);

        [$exitCode, $stdout, $stderr] = $this->capture(fn () => $this->controller->actionRetryFailed());

        $this->assertSame(ExitCode::OK, $exitCode);
        $this->assertSame('', $stderr);
        $this->assertStringContainsString('No failed analyses found.', $stdout);
        $this->assertStringNotContainsString('Queued', $stdout);

        $this->assertSame(AnalysisStatus::Completed->value, $this->reload($completed)->status);
        $this->assertSame(AnalysisStatus::Pending->value, $this->reload($pending)->status);
        $this->assertSame(0, $this->countBulkAnalyzeJobs());
    }

    public function testRetryFailedWithEmptyTable(): void
    {
        [$exitCode, $stdout, $stderr] = $this->capture(fn () => $this->controller->actionRetryFailed());

        $this->assertSame(ExitCode::OK, $exitCode);
        $this->assertSame('', $stderr);
        $this->assertStringContainsString('No failed analyses found.', $stdout);
        $this->assertSame(0, $this->countBulkAnalyzeJobs());
    }

    public function testRetryFailedDoesNotTouchNonFailedStatuses(): void
    {
        $records = [
            AnalysisStatus::Pending->value => $this->createAnalysisRecord(AnalysisStatus::Pending->value),
            AnalysisStatus::Processing->value => $this->createAnalysisRecord(AnalysisStatus::Processing->value),
            AnalysisStatus::Completed->value => $this->createAnalysisRecord(AnalysisStatus::Completed->value),
            AnalysisStatus::PendingReview->value => $this->createAnalysisRecord(AnalysisStatus::PendingReview->value),
            AnalysisStatus::Approved->value => $this->createAnalysisRecord(AnalysisStatus::Approved->value),
            AnalysisStatus::Rejected->value => $this->createAnalysisRecord(AnalysisStatus::Rejected->value),
        ];
        $failed = $this->createAnalysisRecord(AnalysisStatus::Failed->value);

        $exitCode = $this->controller->actionRetryFailed();

        $this->assertSame(ExitCode::OK, $exitCode);
        $this->assertSame(AnalysisStatus::Pending->value, $this->reload($failed)->status);

        foreach ($records as $expectedStatus => $record) {
            $this->assertSame(
                $expectedStatus,
                $this->reload($record)->status,
                "Record with status '{$expectedStatus}' should not be changed by retry-failed",
            );
        }
    }

    public function testRetryFailedQueuesBulkAnalyzeJob(): void
    {
        $this->createAnalysisRecord(AnalysisStatus::Failed->value);
        $this->createAnalysisRecord(AnalysisStatus::Failed->value);

        $this->controller->actionRetryFailed();

        $this->assertSame(1, $this->countBulkAnalyzeJobs(), 'Exactly one BulkAnalyzeAssetsJob should be queued');
    }

    public function testRetryFailedDoesNotQueueJobWhenNothingToRetry(): void
    {
        $this->controller->actionRetryFailed();

        $this->assertSame(0, $this->countBulkAnalyzeJobs());
    }

    public function testRetryFailedHandlesServiceException(): void
    {
        $throwingService = new class extends AssetAnalysisService {
            public function retryAllFailed(): int
            {
                throw new \RuntimeException('simulated failure');
            }
        };

        $originalService = Plugin::getInstance()->assetAnalysis;
        Plugin::getInstance()->set('assetAnalysis', $throwingService);

        try {
            [$exitCode, $stdout, $stderr] = $this->capture(fn () => $this->controller->actionRetryFailed());
        } finally {
            Plugin::getInstance()->set('assetAnalysis', $originalService);
        }

        $this->assertSame(ExitCode::UNSPECIFIED_ERROR, $exitCode);
        $this->assertStringContainsString('Failed to retry analyses: simulated failure', $stderr);
    }

    public function testDefaultActionIsAll(): void
    {
        $this->assertSame('all', $this->controller->defaultAction);
    }

    public function testRetryFailedIsIdempotent(): void
    {
        $failed = $this->createAnalysisRecord(AnalysisStatus::Failed->value);

        $firstExit = $this->controller->actionRetryFailed();
        $this->assertSame(ExitCode::OK, $firstExit);
        $this->assertSame(AnalysisStatus::Pending->value, $this->reload($failed)->status);
        $this->assertSame(1, $this->countBulkAnalyzeJobs());

        $this->clearBulkAnalyzeJobs();

        [$secondExit, $stdout, $stderr] = $this->capture(fn () => $this->controller->actionRetryFailed());
        $this->assertSame(ExitCode::OK, $secondExit);
        $this->assertSame('', $stderr);
        $this->assertStringContainsString('No failed analyses found.', $stdout);
        $this->assertSame(0, $this->countBulkAnalyzeJobs());
    }

    public function testRetryFailedViaConsoleRoute(): void
    {
        $failed = $this->createAnalysisRecord(AnalysisStatus::Failed->value);

        Craft::$app->runAction('lens/process/retry-failed');

        $this->assertSame(AnalysisStatus::Pending->value, $this->reload($failed)->status);
    }

    public function testRetryFailedRollsBackOnQueuePushFailure(): void
    {
        $failed1 = $this->createAnalysisRecord(AnalysisStatus::Failed->value);
        $failed2 = $this->createAnalysisRecord(AnalysisStatus::Failed->value);

        $throwingService = new class extends AssetAnalysisService {
            public function retryAllFailed(): int
            {
                $db = \Craft::$app->getDb();
                $transaction = $db->beginTransaction();
                try {
                    AssetAnalysisRecord::updateAll(
                        ['status' => AnalysisStatus::Pending->value],
                        ['status' => AnalysisStatus::Failed->value],
                    );
                    throw new \RuntimeException('queue push exploded');
                } catch (\Throwable $e) {
                    $transaction->rollBack();
                    throw $e;
                }
            }
        };

        $originalService = Plugin::getInstance()->assetAnalysis;
        Plugin::getInstance()->set('assetAnalysis', $throwingService);

        try {
            $exitCode = $this->controller->actionRetryFailed();
        } finally {
            Plugin::getInstance()->set('assetAnalysis', $originalService);
        }

        $this->assertSame(ExitCode::UNSPECIFIED_ERROR, $exitCode);
        $this->assertSame(AnalysisStatus::Failed->value, $this->reload($failed1)->status);
        $this->assertSame(AnalysisStatus::Failed->value, $this->reload($failed2)->status);
        $this->assertSame(0, $this->countBulkAnalyzeJobs());
    }

    // ---------------------------------------------------------------------
    // Service return-shape contract
    // ---------------------------------------------------------------------

    public function testRetryAllFailedReturnsExactCount(): void
    {
        $this->createAnalysisRecord(AnalysisStatus::Failed->value);
        $this->createAnalysisRecord(AnalysisStatus::Failed->value);
        $this->createAnalysisRecord(AnalysisStatus::Failed->value);
        $this->createAnalysisRecord(AnalysisStatus::Completed->value);

        $count = Plugin::getInstance()->assetAnalysis->retryAllFailed();

        $this->assertSame(3, $count);
    }

    public function testRetryAllFailedReturnsZeroWhenNoFailed(): void
    {
        $this->createAnalysisRecord(AnalysisStatus::Completed->value);

        $count = Plugin::getInstance()->assetAnalysis->retryAllFailed();

        $this->assertSame(0, $count);
    }

    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------

    /**
     * @return array{0:int,1:string,2:string}
     */
    private function capture(callable $fn): array
    {
        $this->controller->capturedStdout = '';
        $this->controller->capturedStderr = '';
        $exitCode = $fn();
        return [$exitCode, $this->controller->capturedStdout, $this->controller->capturedStderr];
    }

    private function reload(AssetAnalysisRecord $record): AssetAnalysisRecord
    {
        $fresh = AssetAnalysisRecord::findOne(['id' => $record->id]);
        $this->assertNotNull($fresh, "Record {$record->id} disappeared");
        return $fresh;
    }

    private function countBulkAnalyzeJobs(): int
    {
        return (int) (new Query())
            ->from('{{%queue}}')
            ->where(['like', 'job', BulkAnalyzeAssetsJob::class])
            ->count('*', Craft::$app->getDb());
    }

    private function clearBulkAnalyzeJobs(): void
    {
        Craft::$app->getDb()
            ->createCommand()
            ->delete('{{%queue}}', ['like', 'job', BulkAnalyzeAssetsJob::class])
            ->execute();
    }
}

/**
 * Captures stdout/stderr so tests can assert on console output without writing to real streams.
 */
class CapturingProcessController extends ProcessController
{
    public string $capturedStdout = '';
    public string $capturedStderr = '';

    public function stdout($string, ...$args)
    {
        $this->capturedStdout .= (string) $string;
        return strlen((string) $string);
    }

    public function stderr($string, ...$args)
    {
        $this->capturedStderr .= (string) $string;
        return strlen((string) $string);
    }
}
