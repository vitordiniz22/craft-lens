<?php

declare(strict_types=1);

namespace vitordiniz22\craftlens\jobs;

use Craft;
use craft\elements\Asset;
use craft\helpers\Queue;
use craft\queue\BaseJob;
use vitordiniz22\craftlens\enums\LogCategory;
use vitordiniz22\craftlens\helpers\Logger;
use vitordiniz22\craftlens\Plugin;

/**
 * Dispatcher for analyzing a specific set of user-selected assets.
 *
 * Walks the selected asset IDs and enqueues one `AnalyzeAssetJob` per asset.
 * Selected runs have no session/cancel concept — once queued, each per-asset
 * job runs through the queue independently.
 */
class AnalyzeSelectedAssetsJob extends BaseJob
{
    /** @var int[] */
    public array $assetIds = [];

    public int $ttr = 600;

    private const CHUNK_SIZE = 200;

    public function execute($queue): void
    {
        if (empty($this->assetIds)) {
            return;
        }

        $assetAnalysis = Plugin::getInstance()->assetAnalysis;
        $enqueued = 0;

        $query = Asset::find()
            ->id($this->assetIds)
            ->kind(Asset::KIND_IMAGE)
            ->orderBy(['elements.id' => SORT_ASC]);

        foreach ($query->each(self::CHUNK_SIZE) as $asset) {
            /** @var Asset $asset */
            $record = $assetAnalysis->createOrUpdatePendingRecord($asset, resetTerminal: true);

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
            'Selected assets analysis dispatched',
            context: ['enqueued' => $enqueued],
        );
    }

    protected function defaultDescription(): ?string
    {
        $count = count($this->assetIds);

        return Craft::t('lens', 'Lens: Queueing {count} selected assets', ['count' => $count]);
    }
}
