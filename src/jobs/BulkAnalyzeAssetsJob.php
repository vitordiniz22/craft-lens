<?php

declare(strict_types=1);

namespace vitordiniz22\craftlens\jobs;

use Craft;
use craft\base\Batchable;
use craft\db\QueryBatcher;
use craft\elements\Asset;
use craft\queue\BaseBatchedJob;
use vitordiniz22\craftlens\enums\AnalysisStatus;
use vitordiniz22\craftlens\enums\LogCategory;
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

    /** @var int[] Asset IDs that failed during this batch execution */
    private array $failedAssetIds = [];

    public function __construct($config = [])
    {
        parent::__construct($config);

        $this->batchSize = Settings::BATCH_SIZE;
    }

    protected function loadData(): Batchable
    {
        $query = Asset::find()
            ->kind(Asset::KIND_IMAGE);

        if ($this->volumeId !== null) {
            $query->volumeId($this->volumeId);
        }

        if (!$this->reprocess) {
            $processedAssetIds = $this->getProcessedAssetIds();
            if (!empty($processedAssetIds)) {
                $query->andWhere(['not in', 'elements.id', $processedAssetIds]);
            }
        }

        return new QueryBatcher($query);
    }

    protected function processItem(mixed $item): void
    {
        /** @var Asset $item */
        try {
            Plugin::getInstance()->assetAnalysis->processAsset($item);
        } catch (\Throwable $e) {
            $this->failedAssetIds[] = $item->id;
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

    public function execute($queue): void
    {
        $this->failedAssetIds = [];

        parent::execute($queue);

        if ($this->failedAssetIds !== []) {
            Logger::warning(
                LogCategory::JobCompleted,
                sprintf(
                    'Bulk analysis batch completed with %d failures. Failed asset IDs: %s',
                    count($this->failedAssetIds),
                    implode(', ', $this->failedAssetIds)
                ),
            );
        }
    }

    protected function defaultDescription(): ?string
    {
        if ($this->volumeId !== null) {
            $volume = Craft::$app->getVolumes()->getVolumeById($this->volumeId);
            $volumeName = $volume?->name ?? "ID {$this->volumeId}";

            return Craft::t('lens', 'Analyzing assets in {volume}', ['volume' => $volumeName]);
        }

        return Craft::t('lens', 'Analyzing all assets');
    }

    /**
     * @return int[]
     */
    private function getProcessedAssetIds(): array
    {
        return AssetAnalysisRecord::find()
            ->select('assetId')
            ->where(['in', 'status', AnalysisStatus::shouldNotReprocessValues()])
            ->column();
    }
}
