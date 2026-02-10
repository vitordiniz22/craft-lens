<?php

declare(strict_types=1);

namespace vitordiniz22\craftlens\console\controllers;

use craft\console\Controller;
use vitordiniz22\craftlens\enums\AnalysisStatus;
use vitordiniz22\craftlens\enums\LogCategory;
use vitordiniz22\craftlens\helpers\Logger;
use vitordiniz22\craftlens\records\AssetAnalysisRecord;
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
        $cutoffTime = time() - ($minutes * 60);
        $cutoffDate = date('Y-m-d H:i:s', $cutoffTime);

        $stuckRecords = AssetAnalysisRecord::find()
            ->where(['status' => AnalysisStatus::Pending->value])
            ->andWhere(['<', 'dateUpdated', $cutoffDate])
            ->all();

        if (empty($stuckRecords)) {
            $this->stdout("No stuck pending records found.\n");
            return ExitCode::OK;
        }

        $count = count($stuckRecords);
        $this->stdout("Found {$count} record(s) stuck in pending status for more than {$minutes} minutes.\n");

        foreach ($stuckRecords as $record) {
            $minutesStuck = (time() - $record->dateUpdated->getTimestamp()) / 60;

            Logger::warning(
                LogCategory::AssetProcessing,
                "Resetting stuck pending record for asset {$record->assetId} (stuck for " . round($minutesStuck) . " minutes)",
                $record->assetId
            );

            $record->status = AnalysisStatus::Failed->value;
            $record->save();

            $this->stdout("  - Reset asset {$record->assetId}\n");
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
        $cutoffTime = time() - ($minutes * 60);
        $cutoffDate = date('Y-m-d H:i:s', $cutoffTime);

        $stuckRecords = AssetAnalysisRecord::find()
            ->where(['status' => AnalysisStatus::Processing->value])
            ->andWhere(['<', 'dateUpdated', $cutoffDate])
            ->all();

        if (empty($stuckRecords)) {
            $this->stdout("No stuck processing records found.\n");
            return ExitCode::OK;
        }

        $count = count($stuckRecords);
        $this->stdout("Found {$count} record(s) stuck in processing status for more than {$minutes} minutes.\n");

        foreach ($stuckRecords as $record) {
            $minutesStuck = (time() - $record->dateUpdated->getTimestamp()) / 60;

            Logger::warning(
                LogCategory::AssetProcessing,
                "Resetting stuck processing record for asset {$record->assetId} (stuck for " . round($minutesStuck) . " minutes)",
                $record->assetId
            );

            $record->status = AnalysisStatus::Failed->value;
            $record->save();

            $this->stdout("  - Reset asset {$record->assetId}\n");
        }

        $this->stdout("Done! Reset {$count} stuck record(s).\n");
        return ExitCode::OK;
    }
}
