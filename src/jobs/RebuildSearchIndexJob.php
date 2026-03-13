<?php

declare(strict_types=1);

namespace vitordiniz22\craftlens\jobs;

use Craft;
use craft\queue\BaseJob;
use vitordiniz22\craftlens\enums\LogCategory;
use vitordiniz22\craftlens\helpers\Logger;
use vitordiniz22\craftlens\Plugin;

/**
 * Queue job that rebuilds the full-text search index in the background.
 *
 * Auto-queued on plugin load when the search index table exists but is empty
 * and there are analyzed assets (e.g. after deploying to an existing install).
 */
class RebuildSearchIndexJob extends BaseJob
{
    public int $ttr = 3600;

    public function execute($queue): void
    {
        Logger::info(LogCategory::SearchIndex, 'Background search index rebuild started');

        try {
            $indexed = Plugin::getInstance()->searchIndex->rebuildAll();
        } catch (\Throwable $e) {
            Logger::error(LogCategory::SearchIndex, 'Search index rebuild failed: ' . $e->getMessage(), exception: $e);
            throw $e;
        }

        Logger::info(
            LogCategory::SearchIndex,
            'Background search index rebuild complete',
            context: ['indexedAssets' => $indexed]
        );
    }

    protected function defaultDescription(): ?string
    {
        return Craft::t('lens', 'Rebuilding Lens search index');
    }
}
