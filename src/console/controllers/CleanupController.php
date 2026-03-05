<?php

declare(strict_types=1);

namespace vitordiniz22\craftlens\console\controllers;

use craft\console\Controller;
use vitordiniz22\craftlens\Plugin;
use yii\console\ExitCode;

/**
 * Cleanup utility commands for Lens plugin.
 */
class CleanupController extends Controller
{
    public $defaultAction = 'stuck-pending';

    /**
     * Find and reset records stuck in pending status.
     *
     * @param int $minutes Number of minutes before considering a record stuck (default: 10)
     * @return int Exit code
     */
    public function actionStuckPending(int $minutes = 10): int
    {
        if ($minutes <= 0) {
            $this->stderr("Minutes must be a positive integer.\n");
            return ExitCode::USAGE;
        }

        $resetInfo = Plugin::getInstance()->assetAnalysis->resetStuckPending($minutes);

        if (empty($resetInfo)) {
            $this->stdout("No stuck pending records found.\n");
            return ExitCode::OK;
        }

        $count = count($resetInfo);
        $this->stdout("Found {$count} record(s) stuck in pending status for more than {$minutes} minutes.\n");

        foreach ($resetInfo as $info) {
            $this->stdout("  - Reset asset {$info['assetId']} (stuck for {$info['minutesStuck']} minutes)\n");
        }

        $this->stdout("Done! Reset {$count} stuck record(s).\n");
        return ExitCode::OK;
    }

    /**
     * Find records stuck in processing status.
     *
     * @param int $minutes Number of minutes before considering a record stuck (default: 30)
     * @return int Exit code
     */
    public function actionStuckProcessing(int $minutes = 30): int
    {
        if ($minutes <= 0) {
            $this->stderr("Minutes must be a positive integer.\n");
            return ExitCode::USAGE;
        }

        $resetInfo = Plugin::getInstance()->assetAnalysis->resetStuckProcessing($minutes);

        if (empty($resetInfo)) {
            $this->stdout("No stuck processing records found.\n");
            return ExitCode::OK;
        }

        $count = count($resetInfo);
        $this->stdout("Found {$count} record(s) stuck in processing status for more than {$minutes} minutes.\n");

        foreach ($resetInfo as $info) {
            $this->stdout("  - Reset asset {$info['assetId']} (stuck for {$info['minutesStuck']} minutes)\n");
        }

        $this->stdout("Done! Reset {$count} stuck record(s).\n");
        return ExitCode::OK;
    }
}
