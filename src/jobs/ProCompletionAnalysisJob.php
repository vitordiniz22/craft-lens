<?php

declare(strict_types=1);

namespace vitordiniz22\craftlens\jobs;

use Craft;
use craft\base\Batchable;
use craft\db\QueryBatcher;
use craft\elements\Asset;
use vitordiniz22\craftlens\enums\AnalysisStatus;
use vitordiniz22\craftlens\Plugin;
use vitordiniz22\craftlens\records\AssetAnalysisRecord;

/**
 * Queue job for backfilling Pro-only metadata onto Lite-analyzed assets.
 *
 * Loaded after a Lite → Pro upgrade: extracts only the missing
 * `longDescription`, AI tags, and `extractedText` fields without touching
 * the rest of the analysis. Uses the same buffer-and-flush concurrent
 * machinery as `BulkAnalyzeAssetsJob`, but routes each batch through
 * `processAssetsForProCompletion()` instead.
 */
class ProCompletionAnalysisJob extends BatchedAnalysisJob
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
        // Stop processing if the session was cleared (user cancelled).
        if (Plugin::getInstance()->bulkProcessingStatus->getSessionData() === null) {
            return false;
        }

        // Skip if the record was filled since we queued (parallel run, manual edit).
        $record = AssetAnalysisRecord::findOne(['assetId' => $item->id]);
        if ($record === null || $record->status !== AnalysisStatus::Completed->value) {
            return false;
        }
        if (!empty($record->longDescriptionAi)) {
            return false;
        }

        return true;
    }

    protected function getCancelSignal(): ?callable
    {
        return static fn(): bool => Plugin::getInstance()->bulkProcessingStatus->getSessionData() === null;
    }

    protected function dispatch(array $batch, int $concurrency, ?callable $cancelSignal): void
    {
        Plugin::getInstance()->assetAnalysis->processAssetsForProCompletion($batch, $concurrency, $cancelSignal);
    }

    protected function defaultDescription(): ?string
    {
        $count = count($this->assetIds);

        return Craft::t('lens', 'Lens: Syncing Pro metadata for {count} assets', ['count' => $count]);
    }
}
