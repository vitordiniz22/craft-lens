<?php

declare(strict_types=1);

namespace vitordiniz22\craftlens\console\controllers;

use craft\console\Controller;
use craft\helpers\Console;
use vitordiniz22\craftlens\Plugin;
use yii\console\ExitCode;

/**
 * Manages the Lens full-text search index.
 */
class SearchIndexController extends Controller
{
    public $defaultAction = 'rebuild';

    /**
     * Rebuilds the full-text search index from scratch.
     *
     * Truncates the existing index and re-indexes every analyzed asset.
     * Safe to run multiple times. Required after initial install or when
     * changing the primary site language.
     *
     * Example: php craft lens/search-index/rebuild
     */
    public function actionRebuild(): int
    {
        $this->stdout("Rebuilding Lens search index...\n");

        $indexed = Plugin::getInstance()->searchIndex->rebuildAll(
            function(int $current, int $total): void {
                Console::updateProgress($current, $total);
            }
        );

        Console::clearLine();
        $this->stdout("Done! ", Console::FG_GREEN);
        $this->stdout("Indexed {$indexed} assets.\n");

        return ExitCode::OK;
    }
}
