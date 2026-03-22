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
 * Queue job for analyzing a specific set of assets selected by the user.
 */
class AnalyzeSelectedAssetsJob extends BaseJob
{
    /** @var int[] */
    public array $assetIds = [];
    public int $ttr = 600;

    public function execute($queue): void
    {
        $total = count($this->assetIds);

        foreach ($this->assetIds as $i => $assetId) {
            $this->setProgress($queue, $i / $total, Craft::t('lens', 'Analyzing asset {current} of {total}', [
                'current' => $i + 1,
                'total' => $total,
            ]));

            $asset = Asset::find()->id($assetId)->one();

            if ($asset === null) {
                Logger::warning(
                    LogCategory::JobFailed,
                    "Asset {$assetId} not found, skipping",
                    $assetId,
                );
                continue;
            }

            try {
                Plugin::getInstance()->assetAnalysis->processAsset($asset);
            } catch (\Throwable $e) {
                Logger::jobFailure(
                    jobType: 'AnalyzeSelectedAssetsJob',
                    message: "Failed to analyze asset {$assetId}: {$e->getMessage()}",
                    assetId: $assetId,
                    retryJobData: [
                        'class' => AnalyzeAssetJob::class,
                        'params' => ['assetId' => $assetId],
                    ],
                    exception: $e,
                );
            }
        }

        $this->setProgress($queue, 1);
    }

    protected function defaultDescription(): ?string
    {
        $count = count($this->assetIds);
        return Craft::t('lens', 'Lens: Analyzing {count} selected assets', ['count' => $count]);
    }
}
