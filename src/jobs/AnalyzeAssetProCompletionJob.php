<?php

declare(strict_types=1);

namespace vitordiniz22\craftlens\jobs;

use Craft;
use craft\elements\Asset;
use craft\queue\BaseJob;
use vitordiniz22\craftlens\enums\LogCategory;
use vitordiniz22\craftlens\helpers\Logger;
use vitordiniz22\craftlens\Plugin;

/**
 * Backfills Pro-only metadata (`longDescription`, AI tags, `extractedText`)
 * onto a single asset whose record was originally analyzed under Lite.
 */
class AnalyzeAssetProCompletionJob extends BaseJob
{
    public int $assetId;
    public int $ttr = 600;

    public function execute($queue): void
    {
        if (Plugin::getInstance()->bulkProcessingStatus->getSessionData() === null) {
            // Session was cleared; treat as cancelled.
            return;
        }

        $asset = Asset::find()->id($this->assetId)->one();

        if ($asset === null) {
            Logger::warning(
                LogCategory::JobFailed,
                "Asset {$this->assetId} not found, skipping Pro completion",
                $this->assetId,
            );
            return;
        }

        Plugin::getInstance()->assetAnalysis->processAssetForProCompletion($asset);
    }

    protected function defaultDescription(): ?string
    {
        return Craft::t('lens', 'Lens: Syncing Pro metadata for asset {id}', ['id' => $this->assetId]);
    }
}
