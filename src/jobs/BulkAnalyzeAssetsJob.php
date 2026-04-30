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
 * Queue job for analyzing all unprocessed assets in a volume (or every volume).
 *
 * Drives a `BulkProcessingStatusService` session — clearing the session is the
 * authoritative cancel signal, consulted both per-item (skip) and inside the
 * concurrent flush (halt prep, fail in-flight at the cancellation checkpoint).
 */
class BulkAnalyzeAssetsJob extends BatchedAnalysisJob
{
    public int|array|null $volumeId = null;
    public bool $reprocess = false;

    /**
     * When set, restricts processing to exactly these asset IDs. Used by the
     * "Retry Failed" flow to scope the run to previously-failed assets.
     *
     * @var int[]
     */
    public array $assetIds = [];

    protected function loadData(): Batchable
    {
        $query = Asset::find()
            ->kind(Asset::KIND_IMAGE)
            ->orderBy(['elements.id' => SORT_ASC]);

        if (!empty($this->assetIds)) {
            // Scoped run: exactly these assets, nothing else.
            $query->andWhere(['in', 'elements.id', $this->assetIds]);

            return new QueryBatcher($query);
        }

        if ($this->volumeId !== null) {
            $query->volumeId($this->volumeId);
        }

        // Restrict to assets that still need analysis: those with no record,
        // or a record in Pending or Failed status.
        if (!$this->reprocess) {
            $handledAssetIds = AssetAnalysisRecord::find()
                ->select('assetId')
                ->where(['not in', 'status', AnalysisStatus::unprocessedStatuses()]);

            $query->andWhere(['not in', 'elements.id', $handledAssetIds]);
        }

        return new QueryBatcher($query);
    }

    protected function shouldQueueItem(Asset $item): bool
    {
        // Stop processing if the session was cleared (user cancelled)
        if (Plugin::getInstance()->bulkProcessingStatus->getSessionData() === null) {
            return false;
        }

        // Skip already-processed assets unless reprocessing
        if (!$this->reprocess && AssetAnalysisRecord::find()
            ->where(['assetId' => $item->id])
            ->andWhere(['not in', 'status', AnalysisStatus::unprocessedStatuses()])
            ->exists()
        ) {
            return false;
        }

        return true;
    }

    protected function getCancelSignal(): ?callable
    {
        return static fn(): bool => Plugin::getInstance()->bulkProcessingStatus->getSessionData() === null;
    }

    protected function defaultDescription(): ?string
    {
        if (is_int($this->volumeId)) {
            $volume = Craft::$app->getVolumes()->getVolumeById($this->volumeId);
            $volumeName = $volume?->name ?? "ID {$this->volumeId}";

            return Craft::t('lens', 'Lens: Analyzing assets in {volume}', ['volume' => $volumeName]);
        }

        return Craft::t('lens', 'Lens: Analyzing all assets');
    }
}
