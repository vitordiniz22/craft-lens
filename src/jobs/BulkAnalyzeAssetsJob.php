<?php

declare(strict_types=1);

namespace vitordiniz22\craftlens\jobs;

use Craft;
use craft\base\Batchable;
use craft\db\QueryBatcher;
use craft\elements\Asset;
use craft\queue\BaseBatchedJob;
use vitordiniz22\craftlens\enums\AnalysisStatus;
use vitordiniz22\craftlens\helpers\Logger;
use vitordiniz22\craftlens\models\Settings;
use vitordiniz22\craftlens\Plugin;
use vitordiniz22\craftlens\records\AssetAnalysisRecord;

/**
 * Queue job for analyzing multiple assets in batches.
 */
class BulkAnalyzeAssetsJob extends BaseBatchedJob
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

    public function init(): void
    {
        parent::init();
        $this->batchSize = Settings::BATCH_SIZE;
    }

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
        // or a record in Pending, Failed, or Rejected status.
        if (!$this->reprocess) {
            $handledAssetIds = AssetAnalysisRecord::find()
                ->select('assetId')
                ->where(['not in', 'status', AnalysisStatus::unprocessedStatuses()]);

            $query->andWhere(['not in', 'elements.id', $handledAssetIds]);
        }

        return new QueryBatcher($query);
    }

    protected function processItem(mixed $item): void
    {
        /** @var Asset $item */

        // Stop processing if the session was cleared (user cancelled)
        if (Plugin::getInstance()->bulkProcessingStatus->getSessionData() === null) {
            return;
        }

        // Skip already-processed assets unless reprocessing
        if (!$this->reprocess && AssetAnalysisRecord::find()
            ->where(['assetId' => $item->id])
            ->andWhere(['not in', 'status', AnalysisStatus::unprocessedStatuses()])
            ->exists()
        ) {
            return;
        }

        try {
            Plugin::getInstance()->assetAnalysis->processAsset($item);
        } catch (\Throwable $e) {
            Logger::jobFailure(
                jobType: 'BulkAnalyzeAssetsJob',
                message: sprintf('Failed to process asset %d in bulk job: %s', $item->id, $e->getMessage()),
                assetId: $item->id,
                retryJobData: [
                    'class' => AnalyzeAssetJob::class,
                    'params' => ['assetId' => $item->id],
                ],
                exception: $e,
            );
        }
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
