<?php

declare(strict_types=1);

namespace vitordiniz22\craftlens\jobs;

use Craft;
use craft\elements\Asset;
use craft\queue\BaseJob;
use vitordiniz22\craftlens\enums\AnalysisStatus;
use vitordiniz22\craftlens\enums\LogCategory;
use vitordiniz22\craftlens\exceptions\ConfigurationException;
use vitordiniz22\craftlens\helpers\Logger;
use vitordiniz22\craftlens\Plugin;
use yii\queue\RetryableJobInterface;

/**
 * Queue job for analyzing a single asset.
 */
class AnalyzeAssetJob extends BaseJob implements RetryableJobInterface
{
    private const LOCK_TIMEOUT_SECONDS = 1;

    private const INTERRUPTED_AFTER_SECONDS = 570;

    private const MAX_ATTEMPTS = 2;

    public int $assetId;
    public int $ttr = 600;

    public function execute($queue): void
    {
        $lockName = "lens:analysis:{$this->assetId}";
        $mutex = Craft::$app->getMutex();

        if (!$mutex->acquire($lockName, self::LOCK_TIMEOUT_SECONDS)) {
            Logger::info(LogCategory::AssetProcessing, 'Analysis already running, duplicate job skipped', $this->assetId);

            return;
        }

        try {
            $this->executeWithLock();
        } finally {
            $mutex->release($lockName);
        }
    }

    private function executeWithLock(): void
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

        $analysisService = Plugin::getInstance()->assetAnalysis;
        $record = $analysisService->getAnalysis($this->assetId);

        if ($record?->status === AnalysisStatus::Completed->value) {
            return;
        }

        if ($record?->status === AnalysisStatus::Processing->value) {
            if ($this->processingAge($record->dateUpdated) >= self::INTERRUPTED_AFTER_SECONDS) {
                $analysisService->recoverInterruptedAnalysis($record);
            } else {
                Logger::info(LogCategory::AssetProcessing, 'Analysis already processing, duplicate job skipped', $this->assetId);
            }

            return;
        }

        try {
            $analysisService->processAsset($asset);
        } catch (\Throwable $e) {
            Logger::error(
                LogCategory::JobFailed,
                "Failed to analyze asset {$this->assetId}: {$e->getMessage()}",
                $this->assetId,
                $e,
                [
                    'jobType' => self::class,
                    'exceptionClass' => get_class($e),
                    'assetFilename' => $asset->filename,
                    'assetSize' => $asset->size,
                    'assetMimeType' => $asset->mimeType,
                ],
            );
            throw $e;
        }
    }

    public function getTtr(): int
    {
        return $this->ttr;
    }

    public function canRetry($attempt, $error): bool
    {
        return $attempt < self::MAX_ATTEMPTS && !$error instanceof ConfigurationException;
    }

    private function processingAge(mixed $dateUpdated): int
    {
        if ($dateUpdated === null) {
            return PHP_INT_MAX;
        }

        $updated = $dateUpdated instanceof \DateTimeInterface
            ? $dateUpdated
            : new \DateTimeImmutable((string) $dateUpdated);

        return max(0, time() - $updated->getTimestamp());
    }

    protected function defaultDescription(): ?string
    {
        return Craft::t('lens', 'Lens: Analyzing asset {id}', ['id' => $this->assetId]);
    }
}
