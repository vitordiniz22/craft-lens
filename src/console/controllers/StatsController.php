<?php

declare(strict_types=1);

namespace vitordiniz22\craftlens\console\controllers;

use Craft;
use craft\console\Controller;
use craft\helpers\Console;
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

        $this->stdout("Unprocessed assets: ", Console::FG_YELLOW);
        $this->stdout("{$unprocessed}\n");

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
