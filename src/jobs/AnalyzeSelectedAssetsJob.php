<?php

declare(strict_types=1);

namespace vitordiniz22\craftlens\jobs;

use Craft;
use craft\base\Batchable;
use craft\db\QueryBatcher;
use craft\elements\Asset;

/**
 * Queue job for analyzing a specific set of assets selected by the user.
 *
 * Assets are loaded from the DB in batches (no in-memory list of all IDs),
 * then dispatched concurrently via the shared `BatchedAnalysisJob` machinery.
 * Selected runs have no session/cancel concept — once queued, they run to
 * completion (or until the queue worker is cancelled).
 */
class AnalyzeSelectedAssetsJob extends BatchedAnalysisJob
{
    /** @var int[] */
    public array $assetIds = [];

    protected function loadData(): Batchable
    {
        $query = Asset::find()
            ->id($this->assetIds)
            ->orderBy(['elements.id' => SORT_ASC]);

        return new QueryBatcher($query);
    }

    protected function shouldQueueItem(Asset $item): bool
    {
        return true;
    }

    protected function getCancelSignal(): ?callable
    {
        return null;
    }

    protected function defaultDescription(): ?string
    {
        $count = count($this->assetIds);

        return Craft::t('lens', 'Lens: Analyzing {count} selected assets', ['count' => $count]);
    }
}
