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
    public ?int $volumeId = null;
    public bool $reprocess = false;

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

        if ($this->volumeId !== null) {
            $query->volumeId($this->volumeId);
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
        if ($this->volumeId !== null) {
            $volume = Craft::$app->getVolumes()->getVolumeById($this->volumeId);
            $volumeName = $volume?->name ?? "ID {$this->volumeId}";

            return Craft::t('lens', 'Lens: Analyzing assets in {volume}', ['volume' => $volumeName]);
        }

        return Craft::t('lens', 'Lens: Analyzing all assets');
    }

}
