<?php

declare(strict_types=1);

namespace vitordiniz22\craftlens\services;

use Craft;
use craft\db\Table;
use vitordiniz22\craftlens\enums\AnalysisStatus;
use vitordiniz22\craftlens\enums\LogCategory;
use vitordiniz22\craftlens\exceptions\AnalysisCancelledException;
use vitordiniz22\craftlens\helpers\Logger;
use vitordiniz22\craftlens\Plugin;
use vitordiniz22\craftlens\records\AssetAnalysisRecord;
use yii\base\Component;
use yii\db\Query;

/**
 * Service for cancelling in-progress analysis and restoring previous state.
 */
class AnalysisCancellationService extends Component
{
    /**
     * Cancel an analysis for the given asset.
     *
     * - First-time analysis (previousStatus is null): deletes the record.
     * - Re-analysis (previousStatus is set): restores the previous status.
     * - Terminal status (completed, failed): rejects as already completed.
     *
     * @return array{success: bool, restored: bool, status: ?string, alreadyCompleted?: bool, message?: string}
     */
    public function cancel(int $assetId): array
    {
        $record = AssetAnalysisRecord::findOne(['assetId' => $assetId]);

        if ($record === null) {
            return ['success' => true, 'restored' => false, 'status' => null];
        }

        $status = AnalysisStatus::tryFrom($record->status);

        if ($status !== null && $status->isTerminal()) {
            return [
                'success' => false,
                'restored' => false,
                'status' => $record->status,
                'alreadyCompleted' => true,
                'message' => Craft::t('lens', 'Analysis already completed.'),
            ];
        }

        $this->releaseQueueJob($record->queueJobId);

        if ($record->previousStatus !== null) {
            $restoredStatus = $record->previousStatus;
            $record->status = $restoredStatus;
            $record->previousStatus = null;
            $record->queueJobId = null;
            $record->save();

            Logger::info(
                LogCategory::Cancellation,
                "Re-analysis cancelled, status restored to {$restoredStatus}",
                assetId: $assetId,
            );

            return ['success' => true, 'restored' => true, 'status' => $restoredStatus];
        }

        Plugin::getInstance()->assetAnalysis->deleteAnalysis($assetId);

        Logger::info(
            LogCategory::Cancellation,
            'First-time analysis cancelled, record deleted',
            assetId: $assetId,
        );

        return ['success' => true, 'restored' => false, 'status' => null];
    }

    /**
     * Assert that the analysis has not been cancelled.
     *
     * Called at checkpoints in processImageAsset() to detect cancellation
     * before expensive operations (AI call) or data commits (transaction).
     *
     * @throws AnalysisCancelledException if the record is gone or status changed
     */
    public function assertNotCancelled(int $assetId): void
    {
        $record = AssetAnalysisRecord::findOne(['assetId' => $assetId]);

        if ($record === null) {
            throw new AnalysisCancelledException("Analysis record deleted for asset {$assetId}");
        }

        if ($record->status !== AnalysisStatus::Processing->value) {
            throw new AnalysisCancelledException(
                "Analysis status changed to '{$record->status}' for asset {$assetId}"
            );
        }
    }

    /**
     * Check whether a queue job still exists in the queue table.
     */
    public function isQueueJobAlive(?string $jobId): bool
    {
        if ($jobId === null) {
            return false;
        }

        return (new Query())
            ->from(Table::QUEUE)
            ->where(['id' => $jobId])
            ->exists();
    }

    /**
     * Release a queue job, silently ignoring failures.
     */
    public function releaseQueueJob(?string $jobId): void
    {
        if ($jobId === null) {
            return;
        }

        try {
            $queue = Craft::$app->getQueue();
            $queue->release($jobId);
        } catch (\Throwable) {
        }
    }
}
