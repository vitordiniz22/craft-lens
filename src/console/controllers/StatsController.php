<?php

declare(strict_types=1);

namespace vitordiniz22\craftlens\console\controllers;

use Craft;
use craft\console\Controller;
use craft\helpers\Console;
use vitordiniz22\craftlens\helpers\ColorSupport;
use vitordiniz22\craftlens\Plugin;
use yii\console\ExitCode;

/**
 * Displays analysis statistics.
 */
class StatsController extends Controller
{
    public $defaultAction = 'index';

    /**
     * Displays Lens analysis statistics.
     */
    public function actionIndex(): int
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
     * Displays tag frequency statistics.
     *
     * @param int $limit Number of results to show (default: 20)
     */
    public function actionTags(int $limit = 20): int
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
     */
    public function actionColors(int $limit = 15): int
    {
        $this->stdout("AI Color Statistics (Top {$limit})\n", Console::FG_CYAN);
        $this->stdout("====================\n\n");

        if (!ColorSupport::isAvailable()) {
            $this->stdout("Color support unavailable: install the Imagick or GD PHP extension.\n", Console::FG_YELLOW);
            return ExitCode::OK;
        }

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
     * Lists all available volumes.
     */
    public function actionVolumes(): int
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
     * Runs a full duplicate scan across all analyzed assets.
     */
    public function actionScanDuplicates(): int
    {
        if (!Plugin::getInstance()->getIsPro()) {
            $this->stderr("Duplicate detection requires the Pro edition.\n", Console::FG_RED);
            return ExitCode::CONFIG;
        }

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
}
