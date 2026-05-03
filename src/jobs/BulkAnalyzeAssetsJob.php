<?php

declare(strict_types=1);

namespace vitordiniz22\craftlens\jobs;

use Craft;
use craft\elements\Asset;
use craft\helpers\Queue;
use craft\queue\BaseJob;
use vitordiniz22\craftlens\enums\AnalysisStatus;
use vitordiniz22\craftlens\enums\LogCategory;
use vitordiniz22\craftlens\helpers\Logger;
use vitordiniz22\craftlens\Plugin;
use vitordiniz22\craftlens\records\AssetAnalysisRecord;

/**
 * Dispatcher for bulk asset analysis: walks the matching asset query in
 * chunks and enqueues one `AnalyzeAssetJob` per asset, then exits.
 *
 * The actual analysis work happens in those per-asset jobs — same code path
 * as a single-asset run from the CP. Concurrency is whatever the host's
 * queue runner provides (number of workers); this job no longer pretends
 * to do parallelism inside one PHP process.
 *
 * Cancellation: clearing the bulk processing session is the authoritative
 * signal. This dispatcher checks before each enqueue, and each per-asset
 * job re-checks at the cancellation checkpoints.
 */
class BulkAnalyzeAssetsJob extends BaseJob
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

    /**
     * Chunk size for walking the asset query.
     */
    private const CHUNK_SIZE = 200;

    public int $ttr = 600;

    public function execute($queue): void
    {
        $query = Asset::find()
            ->kind(Asset::KIND_IMAGE)
            ->orderBy(['elements.id' => SORT_ASC]);

        if (!empty($this->assetIds)) {
            $query->andWhere(['in', 'elements.id', $this->assetIds]);
        } else {
            if ($this->volumeId !== null) {
                $query->volumeId($this->volumeId);
            }

            if (!$this->reprocess) {
                $handledAssetIds = AssetAnalysisRecord::find()
                    ->select('assetId')
                    ->where(['not in', 'status', AnalysisStatus::unprocessedStatuses()]);

                $query->andWhere(['not in', 'elements.id', $handledAssetIds]);
            }
        }

        $bulkStatus = Plugin::getInstance()->bulkProcessingStatus;
        $assetAnalysis = Plugin::getInstance()->assetAnalysis;
        $enqueued = 0;

        foreach ($query->each(self::CHUNK_SIZE) as $asset) {
            /** @var Asset $asset */
            if ($bulkStatus->getSessionData() === null) {
                return;
            }

            if (!$this->reprocess && AssetAnalysisRecord::find()
                ->where(['assetId' => $asset->id])
                ->andWhere(['not in', 'status', AnalysisStatus::unprocessedStatuses()])
                ->exists()
            ) {
                continue;
            }

            $record = $assetAnalysis->createOrUpdatePendingRecord($asset, resetTerminal: $this->reprocess);

            $jobId = Queue::push(new AnalyzeAssetJob([
                'assetId' => $asset->id,
            ]));

            if ($jobId !== null) {
                $record->queueJobId = (string) $jobId;
                $record->save();
            }

            $enqueued++;
        }

        Logger::info(
            LogCategory::JobStarted,
            'Bulk analysis dispatched',
            context: [
                'enqueued' => $enqueued,
                'volumeId' => $this->volumeId,
                'reprocess' => $this->reprocess,
                'assetIdScope' => !empty($this->assetIds) ? count($this->assetIds) : null,
            ],
        );
    }

    protected function defaultDescription(): ?string
    {
        if (is_int($this->volumeId)) {
            $volume = Craft::$app->getVolumes()->getVolumeById($this->volumeId);
            $volumeName = $volume?->name ?? "ID {$this->volumeId}";

            return Craft::t('lens', 'Lens: Queueing assets in {volume}', ['volume' => $volumeName]);
        }

        return Craft::t('lens', 'Lens: Queueing assets for analysis');
    }
}
