<?php

declare(strict_types=1);

namespace vitordiniz22\craftlens\console\controllers;

use Craft;
use craft\console\Controller;
use craft\helpers\Console;
use vitordiniz22\craftlens\enums\LogCategory;
use vitordiniz22\craftlens\helpers\Logger;
use vitordiniz22\craftlens\jobs\BulkAnalyzeAssetsJob;
use vitordiniz22\craftlens\Plugin;
use yii\console\ExitCode;

/**
 * Queues assets for AI analysis.
 */
class ProcessController extends Controller
{
    public $defaultAction = 'all';

    /**
     * @var bool Whether to reprocess already analyzed assets.
     */
    public bool $reprocess = false;

    public function options($actionID): array
    {
        $options = parent::options($actionID);
        $options[] = 'reprocess';

        return $options;
    }

    /**
     * Queues all unprocessed assets for analysis.
     */
    public function actionAll(): int
    {
        $this->stdout("Queuing all unprocessed assets for analysis...\n");

        Craft::$app->getQueue()->push(new BulkAnalyzeAssetsJob([
            'reprocess' => $this->reprocess,
        ]));

        Logger::info(LogCategory::JobStarted, 'Bulk analysis job queued from console', context: ['reprocess' => $this->reprocess]);

        $this->stdout("Done! ", Console::FG_GREEN);
        $this->stdout("Run ");
        $this->stdout("php craft queue/run", Console::FG_CYAN);
        $this->stdout(" to process the queue.\n");

        return ExitCode::OK;
    }

    /**
     * Queues assets in a specific volume for analysis.
     *
     * @param string $handle The volume handle
     */
    public function actionVolume(string $handle): int
    {
        $volume = Craft::$app->getVolumes()->getVolumeByHandle($handle);

        if ($volume === null) {
            $this->stderr("Volume not found: {$handle}\n", Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $this->stdout("Queuing assets in volume: ", Console::FG_YELLOW);
        $this->stdout("{$volume->name}\n");

        Craft::$app->getQueue()->push(new BulkAnalyzeAssetsJob([
            'volumeId' => $volume->id,
            'reprocess' => $this->reprocess,
        ]));

        Logger::info(LogCategory::JobStarted, "Volume analysis job queued from console", context: ['volume' => $handle, 'reprocess' => $this->reprocess]);

        $this->stdout("Done! ", Console::FG_GREEN);
        $this->stdout("Run ");
        $this->stdout("php craft queue/run", Console::FG_CYAN);
        $this->stdout(" to process the queue.\n");

        return ExitCode::OK;
    }

    /**
     * Retries all failed analyses.
     */
    public function actionRetryFailed(): int
    {
        $this->stdout("Retrying failed analyses...\n");

        try {
            $failedCount = Plugin::getInstance()->assetAnalysis->retryAllFailed();

            if ($failedCount === 0) {
                $this->stdout("No failed analyses found.\n", Console::FG_GREEN);
                return ExitCode::OK;
            }

            $this->stdout("Queued {$failedCount} failed analyses for retry.\n", Console::FG_GREEN);
            $this->stdout("Run ");
            $this->stdout("php craft queue/run", Console::FG_CYAN);
            $this->stdout(" to process the queue.\n");

            return ExitCode::OK;
        } catch (\Throwable $e) {
            $this->stderr("Failed to retry analyses: {$e->getMessage()}\n", Console::FG_RED);
            Logger::error(LogCategory::AssetProcessing, "Failed to retry analyses: {$e->getMessage()}", exception: $e);
            return ExitCode::UNSPECIFIED_ERROR;
        }
    }
}
