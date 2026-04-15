<?php

declare(strict_types=1);

namespace vitordiniz22\craftlenstests\integration\console;

use Codeception\Test\Unit;
use Craft;
use vitordiniz22\craftlens\console\controllers\CleanupController;
use vitordiniz22\craftlens\enums\AnalysisStatus;
use vitordiniz22\craftlens\Plugin;
use vitordiniz22\craftlens\records\AssetAnalysisRecord;
use vitordiniz22\craftlenstests\_support\Helpers\AnalysisRecordFixtures;
use yii\console\ExitCode;

class CleanupControllerTest extends Unit
{
    use AnalysisRecordFixtures;

    private CapturingCleanupController $controller;

    protected function _before(): void
    {
        parent::_before();
        $this->controller = new CapturingCleanupController('cleanup', Craft::$app);
        $this->controller->interactive = false;

        $this->cleanupAnalysisRecords();
    }

    protected function _after(): void
    {
        $this->cleanupAnalysisRecords();
        parent::_after();
    }

    // ---------------------------------------------------------------------
    // stuck-pending
    // ---------------------------------------------------------------------

    public function testStuckPendingHappyPath(): void
    {
        $stuck1 = $this->createAnalysisRecord(AnalysisStatus::Pending->value);
        $stuck2 = $this->createAnalysisRecord(AnalysisStatus::Pending->value);
        $fresh = $this->createAnalysisRecord(AnalysisStatus::Pending->value);
        $completed = $this->createAnalysisRecord(AnalysisStatus::Completed->value);

        $this->backdateAnalysisRecord($stuck1, 15);
        $this->backdateAnalysisRecord($stuck2, 15);
        $this->backdateAnalysisRecord($fresh, 2);
        $this->backdateAnalysisRecord($completed, 60);

        [$exitCode, $stdout, $stderr] = $this->capture(fn () => $this->controller->actionStuckPending(10));

        $this->assertSame(ExitCode::OK, $exitCode);
        $this->assertSame('', $stderr);
        $this->assertStringContainsString('Found 2 record(s) stuck in pending status for more than 10 minutes.', $stdout);
        $this->assertStringContainsString("Reset asset {$stuck1->assetId}", $stdout);
        $this->assertStringContainsString("Reset asset {$stuck2->assetId}", $stdout);
        $this->assertStringContainsString('Done! Reset 2 stuck record(s).', $stdout);

        $this->assertSame(AnalysisStatus::Failed->value, $this->reload($stuck1)->status);
        $this->assertSame(AnalysisStatus::Failed->value, $this->reload($stuck2)->status);
        $this->assertSame(AnalysisStatus::Pending->value, $this->reload($fresh)->status);
        $this->assertSame(AnalysisStatus::Completed->value, $this->reload($completed)->status);
    }

    public function testStuckPendingNoRecords(): void
    {
        $fresh = $this->createAnalysisRecord(AnalysisStatus::Pending->value);
        $this->backdateAnalysisRecord($fresh, 2);

        [$exitCode, $stdout, $stderr] = $this->capture(fn () => $this->controller->actionStuckPending(10));

        $this->assertSame(ExitCode::OK, $exitCode);
        $this->assertSame('', $stderr);
        $this->assertStringContainsString('No stuck pending records found.', $stdout);
        $this->assertSame(AnalysisStatus::Pending->value, $this->reload($fresh)->status);
    }

    public function testStuckPendingRejectsZero(): void
    {
        $stuck = $this->createAnalysisRecord(AnalysisStatus::Pending->value);
        $this->backdateAnalysisRecord($stuck, 60);

        [$exitCode, $stdout, $stderr] = $this->capture(fn () => $this->controller->actionStuckPending(0));

        $this->assertSame(ExitCode::USAGE, $exitCode);
        $this->assertSame('', $stdout);
        $this->assertStringContainsString('Minutes must be a positive integer.', $stderr);
        $this->assertSame(AnalysisStatus::Pending->value, $this->reload($stuck)->status);
    }

    public function testStuckPendingRejectsNegative(): void
    {
        $stuck = $this->createAnalysisRecord(AnalysisStatus::Pending->value);
        $this->backdateAnalysisRecord($stuck, 60);

        [$exitCode, $stdout, $stderr] = $this->capture(fn () => $this->controller->actionStuckPending(-5));

        $this->assertSame(ExitCode::USAGE, $exitCode);
        $this->assertSame('', $stdout);
        $this->assertStringContainsString('Minutes must be a positive integer.', $stderr);
        $this->assertSame(AnalysisStatus::Pending->value, $this->reload($stuck)->status);
    }

    public function testStuckPendingThresholdBoundary(): void
    {
        $belowThreshold = $this->createAnalysisRecord(AnalysisStatus::Pending->value);
        $overThreshold = $this->createAnalysisRecord(AnalysisStatus::Pending->value);

        $this->backdateAnalysisRecord($belowThreshold, 9);
        $this->backdateAnalysisRecord($overThreshold, 11);

        $exitCode = $this->controller->actionStuckPending(10);

        $this->assertSame(ExitCode::OK, $exitCode);
        $this->assertSame(AnalysisStatus::Pending->value, $this->reload($belowThreshold)->status);
        $this->assertSame(AnalysisStatus::Failed->value, $this->reload($overThreshold)->status);
    }

    public function testStuckPendingDoesNotTouchProcessingRecords(): void
    {
        $pending = $this->createAnalysisRecord(AnalysisStatus::Pending->value);
        $processing = $this->createAnalysisRecord(AnalysisStatus::Processing->value);

        $this->backdateAnalysisRecord($pending, 60);
        $this->backdateAnalysisRecord($processing, 60);

        $this->controller->actionStuckPending(10);

        $this->assertSame(AnalysisStatus::Failed->value, $this->reload($pending)->status);
        $this->assertSame(AnalysisStatus::Processing->value, $this->reload($processing)->status);
    }

    // ---------------------------------------------------------------------
    // stuck-processing
    // ---------------------------------------------------------------------

    public function testStuckProcessingHappyPath(): void
    {
        $stuck = $this->createAnalysisRecord(AnalysisStatus::Processing->value);
        $fresh = $this->createAnalysisRecord(AnalysisStatus::Processing->value);
        $completed = $this->createAnalysisRecord(AnalysisStatus::Completed->value);

        $this->backdateAnalysisRecord($stuck, 45);
        $this->backdateAnalysisRecord($fresh, 5);
        $this->backdateAnalysisRecord($completed, 120);

        [$exitCode, $stdout, $stderr] = $this->capture(fn () => $this->controller->actionStuckProcessing(30));

        $this->assertSame(ExitCode::OK, $exitCode);
        $this->assertSame('', $stderr);
        $this->assertStringContainsString('Found 1 record(s) stuck in processing status for more than 30 minutes.', $stdout);
        $this->assertStringContainsString("Reset asset {$stuck->assetId}", $stdout);
        $this->assertStringContainsString('Done! Reset 1 stuck record(s).', $stdout);

        $this->assertSame(AnalysisStatus::Failed->value, $this->reload($stuck)->status);
        $this->assertSame(AnalysisStatus::Processing->value, $this->reload($fresh)->status);
        $this->assertSame(AnalysisStatus::Completed->value, $this->reload($completed)->status);
    }

    public function testStuckProcessingNoRecords(): void
    {
        $fresh = $this->createAnalysisRecord(AnalysisStatus::Processing->value);
        $this->backdateAnalysisRecord($fresh, 5);

        [$exitCode, $stdout, $stderr] = $this->capture(fn () => $this->controller->actionStuckProcessing(30));

        $this->assertSame('', $stderr);

        $this->assertSame(ExitCode::OK, $exitCode);
        $this->assertStringContainsString('No stuck processing records found.', $stdout);
        $this->assertSame(AnalysisStatus::Processing->value, $this->reload($fresh)->status);
    }

    public function testStuckProcessingRejectsZero(): void
    {
        [$exitCode, $stdout, $stderr] = $this->capture(fn () => $this->controller->actionStuckProcessing(0));

        $this->assertSame(ExitCode::USAGE, $exitCode);
        $this->assertSame('', $stdout);
        $this->assertStringContainsString('Minutes must be a positive integer.', $stderr);
    }

    public function testStuckProcessingThresholdBoundary(): void
    {
        $belowThreshold = $this->createAnalysisRecord(AnalysisStatus::Processing->value);
        $overThreshold = $this->createAnalysisRecord(AnalysisStatus::Processing->value);

        $this->backdateAnalysisRecord($belowThreshold, 29);
        $this->backdateAnalysisRecord($overThreshold, 31);

        $this->controller->actionStuckProcessing(30);

        $this->assertSame(AnalysisStatus::Processing->value, $this->reload($belowThreshold)->status);
        $this->assertSame(AnalysisStatus::Failed->value, $this->reload($overThreshold)->status);
    }

    public function testStuckProcessingDoesNotTouchPendingRecords(): void
    {
        $pending = $this->createAnalysisRecord(AnalysisStatus::Pending->value);
        $processing = $this->createAnalysisRecord(AnalysisStatus::Processing->value);

        $this->backdateAnalysisRecord($pending, 120);
        $this->backdateAnalysisRecord($processing, 120);

        $this->controller->actionStuckProcessing(30);

        $this->assertSame(AnalysisStatus::Pending->value, $this->reload($pending)->status);
        $this->assertSame(AnalysisStatus::Failed->value, $this->reload($processing)->status);
    }

    // ---------------------------------------------------------------------
    // Default arguments and default action
    // ---------------------------------------------------------------------

    public function testStuckPendingUsesDefaultTenMinutes(): void
    {
        $under = $this->createAnalysisRecord(AnalysisStatus::Pending->value);
        $over = $this->createAnalysisRecord(AnalysisStatus::Pending->value);

        $this->backdateAnalysisRecord($under, 9);
        $this->backdateAnalysisRecord($over, 11);

        $exitCode = $this->controller->actionStuckPending();

        $this->assertSame(ExitCode::OK, $exitCode);
        $this->assertSame(AnalysisStatus::Pending->value, $this->reload($under)->status);
        $this->assertSame(AnalysisStatus::Failed->value, $this->reload($over)->status);
    }

    public function testStuckProcessingUsesDefaultThirtyMinutes(): void
    {
        $under = $this->createAnalysisRecord(AnalysisStatus::Processing->value);
        $over = $this->createAnalysisRecord(AnalysisStatus::Processing->value);

        $this->backdateAnalysisRecord($under, 29);
        $this->backdateAnalysisRecord($over, 31);

        $exitCode = $this->controller->actionStuckProcessing();

        $this->assertSame(ExitCode::OK, $exitCode);
        $this->assertSame(AnalysisStatus::Processing->value, $this->reload($under)->status);
        $this->assertSame(AnalysisStatus::Failed->value, $this->reload($over)->status);
    }

    public function testDefaultActionResolvesToStuckPending(): void
    {
        $stuck = $this->createAnalysisRecord(AnalysisStatus::Pending->value);
        $this->backdateAnalysisRecord($stuck, 15);

        Craft::$app->runAction('lens/cleanup');

        $this->assertSame(AnalysisStatus::Failed->value, $this->reload($stuck)->status);
    }

    public function testPerAssetLineIncludesMinutesStuck(): void
    {
        $stuck = $this->createAnalysisRecord(AnalysisStatus::Pending->value);
        $this->backdateAnalysisRecord($stuck, 17);

        [$exitCode, $stdout] = $this->capture(fn () => $this->controller->actionStuckPending(10));

        $this->assertSame(ExitCode::OK, $exitCode);
        $this->assertMatchesRegularExpression(
            "/- Reset asset {$stuck->assetId} \(stuck for \d+ minutes\)/",
            $stdout,
        );
    }

    // ---------------------------------------------------------------------
    // service return-shape contract
    // ---------------------------------------------------------------------

    public function testResetStuckPendingReturnsAssetIdAndMinutes(): void
    {
        $stuck = $this->createAnalysisRecord(AnalysisStatus::Pending->value);
        $this->backdateAnalysisRecord($stuck, 20);

        $result = Plugin::getInstance()->assetAnalysis->resetStuckPending(10);

        $this->assertCount(1, $result);
        $this->assertArrayHasKey('assetId', $result[0]);
        $this->assertArrayHasKey('minutesStuck', $result[0]);
        $this->assertSame($stuck->assetId, $result[0]['assetId']);
        $this->assertGreaterThanOrEqual(10, $result[0]['minutesStuck']);
    }

    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------

    /**
     * Run a controller action and capture its stdout/stderr.
     *
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
}

/**
 * Test-only subclass that captures stdout/stderr instead of writing to real streams.
 * Yii2's Console::stdout() uses fwrite(STDOUT, ...) which bypasses ob_start(), so
 * we override the methods at the controller level.
 */
class CapturingCleanupController extends CleanupController
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
