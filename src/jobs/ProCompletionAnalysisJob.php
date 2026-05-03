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
 * Dispatcher for backfilling Pro-only metadata onto Lite-analyzed assets.
 *
 * Loaded after a Lite → Pro upgrade: enqueues one
 * `AnalyzeAssetProCompletionJob` per asset whose record is still missing
 * `longDescriptionAi`. Records keep their `Completed` status throughout —
 * this is a backfill, not a re-analysis.
 */
class ProCompletionAnalysisJob extends BaseJob
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

        $bulkStatus = Plugin::getInstance()->bulkProcessingStatus;
        $enqueued = 0;

        $query = Asset::find()
            ->id($this->assetIds)
            ->kind(Asset::KIND_IMAGE)
            ->orderBy(['elements.id' => SORT_ASC]);

        foreach ($query->each(self::CHUNK_SIZE) as $asset) {
            /** @var Asset $asset */
            if ($bulkStatus->getSessionData() === null) {
                return;
            }

            $record = AssetAnalysisRecord::findOne(['assetId' => $asset->id]);

            if ($record === null || $record->status !== AnalysisStatus::Completed->value) {
                continue;
            }

            if (!empty($record->longDescriptionAi)) {
                continue;
            }

            Queue::push(new AnalyzeAssetProCompletionJob([
                'assetId' => $asset->id,
            ]));

            $enqueued++;
        }

        Logger::info(
            LogCategory::JobStarted,
            'Pro completion dispatched',
            context: ['enqueued' => $enqueued],
        );
    }

    protected function defaultDescription(): ?string
    {
        $count = count($this->assetIds);

        return Craft::t('lens', 'Lens: Queueing {count} assets for Pro metadata sync', ['count' => $count]);
    }
}
