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
 * Queue job for analyzing a single asset.
 */
class AnalyzeAssetJob extends BaseJob
{
    public int $assetId;
    public int $ttr = 600;

    public function execute($queue): void
    {
        $asset = Asset::find()->id($this->assetId)->one();

        if ($asset === null) {
            Logger::warning(
                LogCategory::JobFailed,
                "Asset {$this->assetId} not found, skipping analysis",
                $this->assetId,
            );
            Plugin::getInstance()->assetAnalysis->deleteAnalysis($this->assetId);
            return;
        }

        try {
            Plugin::getInstance()->assetAnalysis->processAsset($asset);
        } catch (\Throwable $e) {
            Logger::error(
                LogCategory::JobFailed,
                "Failed to analyze asset {$this->assetId}: {$e->getMessage()}",
                $this->assetId,
                $e,
            );
            throw $e;
        }
    }

    protected function defaultDescription(): ?string
    {
        return Craft::t('lens', 'Lens: Analyzing asset {id}', ['id' => $this->assetId]);
    }
}
