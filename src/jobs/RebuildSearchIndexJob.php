<?php

declare(strict_types=1);

namespace vitordiniz22\craftlens\jobs;

use Craft;
use craft\queue\BaseJob;
use vitordiniz22\craftlens\enums\LogCategory;
use vitordiniz22\craftlens\helpers\Logger;
use vitordiniz22\craftlens\Plugin;

/**
 * Queue job for rebuilding the entire search index in the background.
 * Push this job from migrations that change indexed fields or weights.
 *
 * Usage in a migration:
 *   Queue::push(new RebuildSearchIndexJob());
 */
class RebuildSearchIndexJob extends BaseJob
{
    public function execute($queue): void
    {
        Logger::info(LogCategory::SearchIndex, 'Background search index rebuild started');

        $indexed = Plugin::getInstance()->searchIndex->rebuildAll(
            function(int $current, int $total) use ($queue) {
                $this->setProgress($queue, $current / max($total, 1), Craft::t('lens', 'Indexing asset {current} of {total}', [
                    'current' => $current,
                    'total' => $total,
                ]));
            }
        );

        Logger::info(LogCategory::SearchIndex, 'Background search index rebuild complete', context: ['indexed' => $indexed]);
    }

    protected function defaultDescription(): ?string
    {
        return Craft::t('lens', 'Lens: Rebuilding search index');
    }
}
