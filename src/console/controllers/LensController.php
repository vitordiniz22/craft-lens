<?php

declare(strict_types=1);

namespace vitordiniz22\craftlens\console\controllers;

use Craft;
use craft\console\Controller;
use craft\helpers\Console;
use vitordiniz22\craftlens\enums\LogCategory;
use vitordiniz22\craftlens\exceptions\AnalysisException;
use vitordiniz22\craftlens\exceptions\ConfigurationException;
use vitordiniz22\craftlens\helpers\Logger;
use vitordiniz22\craftlens\jobs\BulkAnalyzeAssetsJob;
use vitordiniz22\craftlens\Plugin;
use yii\console\ExitCode;

/**
 * Console commands for Lens plugin.
 */
class LensController extends Controller
{
    public $defaultAction = 'stats';

    /**
     * @var bool Whether to reprocess already analyzed assets.
     */
    public bool $reprocess = false;

    public function options($actionID): array
    {
        $options = parent::options($actionID);

        if (in_array($actionID, ['process-all', 'process-volume'], true)) {
            $options[] = 'reprocess';
        }

        return $options;
    }

    /**
     * Displays Lens analysis statistics.
     */
    public function actionStats(): int
    {
        $this->stdout("Lens Analysis Statistics\n", Console::FG_CYAN);
        $this->stdout("========================\n\n");

        $unprocessed = Plugin::getInstance()->assetAnalysis->getUnprocessedCount();
        $pendingReview = Plugin::getInstance()->review->getPendingReviewCount();

        $this->stdout("Unprocessed assets: ", Console::FG_YELLOW);
        $this->stdout("{$unprocessed}\n");

        $this->stdout("Pending review: ", Console::FG_YELLOW);
        $this->stdout("{$pendingReview}\n");

        return ExitCode::OK;
    }

    /**
     * Queues all unprocessed assets for AI analysis.
     *
     * @return int
     */
    public function actionProcessAll(): int
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
     * Queues assets in a specific volume for AI analysis.
     *
     * @param string $handle The volume handle
     * @return int
     */
    public function actionProcessVolume(string $handle): int
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
     * Lists all available volumes.
     *
     * @return int
     */
    public function actionListVolumes(): int
    {
        $volumes = Craft::$app->getVolumes()->getAllVolumes();

        if (empty($volumes)) {
            $this->stdout("No volumes found.\n", Console::FG_YELLOW);
            return ExitCode::OK;
        }

        $this->stdout("Available Volumes:\n", Console::FG_CYAN);
        $this->stdout("==================\n\n");

        foreach ($volumes as $volume) {
            $this->stdout("  - ", Console::FG_GREEN);
            $this->stdout("{$volume->handle}");
            $this->stdout(" ({$volume->name})\n");
        }

        return ExitCode::OK;
    }

    /**
     * Validates the AI provider configuration.
     *
     * @return int
     */
    public function actionValidate(): int
    {
        $this->stdout("Validating Lens configuration...\n\n");

        try {
            Plugin::getInstance()->aiProvider->validateCredentials();
            $this->stdout("API credentials are valid.\n", Console::FG_GREEN);
            return ExitCode::OK;
        } catch (AnalysisException $e) {
            $this->stderr("API Error: {$e->getMessage()}\n", Console::FG_RED);
            Logger::error(LogCategory::AssetProcessing, "Validation API error: {$e->getMessage()}", exception: $e);
            return ExitCode::CONFIG;
        } catch (ConfigurationException $e) {
            $this->stderr("Configuration Error: {$e->getMessage()}\n", Console::FG_RED);
            Logger::error(LogCategory::Configuration, "Validation config error: {$e->getMessage()}", exception: $e);
            return ExitCode::CONFIG;
        } catch (\Throwable $e) {
            $this->stderr("Unexpected error: {$e->getMessage()}\n", Console::FG_RED);
            Logger::error(LogCategory::Configuration, "Unexpected validation error: {$e->getMessage()}", exception: $e);
            return ExitCode::UNSPECIFIED_ERROR;
        }
    }

    /**
     * Displays tag frequency statistics.
     *
     * @param int $limit Number of results to show (default: 20)
     * @return int
     */
    public function actionTagStats(int $limit = 20): int
    {
        $this->stdout("AI Tag Statistics (Top {$limit})\n", Console::FG_CYAN);
        $this->stdout("==================\n\n");

        $tags = Plugin::getInstance()->tagAggregation->getTagCounts($limit, 'count');

        if (empty($tags)) {
            $this->stdout("No tags found.\n", Console::FG_YELLOW);
            return ExitCode::OK;
        }

        foreach ($tags as $item) {
            $this->stdout(sprintf("  %-30s %d\n", $item['tag'], $item['count']));
        }

        return ExitCode::OK;
    }

    /**
     * Displays color palette statistics.
     *
     * @param int $limit Number of results to show (default: 15)
     * @return int
     */
    public function actionColorStats(int $limit = 15): int
    {
        $this->stdout("AI Color Statistics (Top {$limit})\n", Console::FG_CYAN);
        $this->stdout("====================\n\n");

        $colors = Plugin::getInstance()->colorAggregation->getColorCounts($limit);

        if (empty($colors)) {
            $this->stdout("No colors found.\n", Console::FG_YELLOW);
            return ExitCode::OK;
        }

        foreach ($colors as $item) {
            $this->stdout(sprintf("  %s  %d assets\n", $item['hex'], $item['count']));
        }

        return ExitCode::OK;
    }

    /**
     * Runs a full duplicate scan across all analyzed assets.
     *
     * @return int
     */
    public function actionScanDuplicates(): int
    {
        $this->stdout("Running full duplicate scan...\n");

        $service = Plugin::getInstance()->duplicateDetection;
        $newPairs = $service->runFullScan();

        $this->stdout("Done! ", Console::FG_GREEN);
        $this->stdout("Found {$newPairs} new duplicate pair(s).\n");

        $totalUnresolved = $service->getUnresolvedDuplicateCount();

        $this->stdout("Total unresolved duplicates: ", Console::FG_YELLOW);
        $this->stdout("{$totalUnresolved}\n");

        return ExitCode::OK;
    }

    /**
     * Retries all failed analyses.
     *
     * @return int
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
