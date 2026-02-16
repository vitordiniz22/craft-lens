<?php

declare(strict_types=1);

namespace vitordiniz22\craftlens\services;

use Craft;
use craft\elements\Asset;
use vitordiniz22\craftlens\enums\AiProvider;
use vitordiniz22\craftlens\enums\AnalysisStatus;
use vitordiniz22\craftlens\enums\LogCategory;
use vitordiniz22\craftlens\helpers\Logger;
use vitordiniz22\craftlens\jobs\AnalyzeAssetJob;
use vitordiniz22\craftlens\jobs\BulkAnalyzeAssetsJob;
use vitordiniz22\craftlens\Plugin;
use vitordiniz22\craftlens\records\AssetAnalysisRecord;
use yii\base\Component;
use yii\db\Query;

/**
 * Service for tracking bulk processing status and progress.
 */
class BulkProcessingStatusService extends Component
{
    /**
     * Duration in seconds to show the "complete" state after processing finishes.
     */
    private const COMPLETE_STATE_DURATION = 30;

    /**
     * Default token estimates for cost calculation when no historical data exists.
     */
    private const DEFAULT_INPUT_TOKENS = 1500;
    private const DEFAULT_OUTPUT_TOKENS = 500;

    /**
     * Get the full status response for the AJAX endpoint.
     */
    public function getStatus(): array
    {
        $stats = $this->getStats();
        $state = $this->determineState($stats);
        $session = $this->getSessionData();

        $response = [
            'success' => true,
            'state' => $state,
            'stats' => $stats,
        ];

        if ($state === 'processing') {
            $response['progress'] = $this->getProgress($session, $stats);
            $response['queueInfo'] = $this->getQueueInfo();
            $response['session'] = $this->formatSession($session);
        }

        if ($state === 'complete') {
            $response['session'] = $this->formatSession($session);
        }

        // Check for state transition
        $previousState = $this->getPreviousState();
        if ($previousState !== null && $previousState !== $state) {
            $response['transition'] = $this->getTransitionInfo($previousState, $state);
        }
        $this->setPreviousState($state);

        return $response;
    }

    /**
     * Get all stats for display.
     */
    public function getStats(?int $volumeId = null): array
    {
        return [
            'totalImages' => $this->getTotalImageCount($volumeId),
            'analyzed' => $this->getAnalyzedCount($volumeId),
            'unprocessed' => $this->getUnprocessedCount($volumeId),
            'pendingReview' => $this->getPendingReviewCount($volumeId),
            'failed' => $this->getFailedCount($volumeId),
            'processing' => $this->getProcessingCount($volumeId),
        ];
    }

    /**
     * Estimate the cost for processing unprocessed assets.
     * Always uses current provider settings for accurate estimates.
     */
    public function getEstimatedCost(int $unprocessedCount): float
    {
        if ($unprocessedCount === 0) {
            return 0.0;
        }

        // Always estimate based on current provider settings
        // This ensures the estimate reflects the user's current configuration
        return $this->estimateCostFromTokens($unprocessedCount);
    }

    /**
     * Start a new processing session.
     */
    public function startSession(?int $volumeId = null): void
    {
        $cacheKey = $this->getSessionCacheKey();
        $unprocessedCount = $this->getUnprocessedCount();

        Logger::info(LogCategory::JobStarted, 'Bulk processing session started', context: ['volumeId' => $volumeId, 'initialUnprocessed' => $unprocessedCount]);

        Craft::$app->getCache()->set($cacheKey, [
            'startedAt' => time(),
            'volumeId' => $volumeId,
            'initialUnprocessed' => $unprocessedCount,
            'completedAt' => null,
        ], 3600); // 1 hour TTL
    }

    /**
     * Determine the current processing state.
     */
    private function determineState(array $stats): string
    {
        $hasQueuedJobs = $this->hasLensJobsInQueue();
        $processingCount = $stats['processing'] ?? 0;

        if ($hasQueuedJobs || $processingCount > 0) {
            return 'processing';
        }

        if ($this->isRecentlyCompleted()) {
            return 'complete';
        }

        return 'ready';
    }

    /**
     * Check if there are Lens jobs in the queue.
     */
    private function hasLensJobsInQueue(): bool
    {
        try {
            $count = (new Query())
                ->from('{{%queue}}')
                ->where(['like', 'job', BulkAnalyzeAssetsJob::class])
                ->orWhere(['like', 'job', AnalyzeAssetJob::class])
                ->count();

            return (int) $count > 0;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Get information about the current queue state.
     */
    private function getQueueInfo(): array
    {
        try {
            $lensJobCondition = [
                'or',
                ['like', 'job', BulkAnalyzeAssetsJob::class],
                ['like', 'job', AnalyzeAssetJob::class],
            ];

            $allJobs = (new Query())
                ->select(['timePushed', 'description'])
                ->from('{{%queue}}')
                ->where($lensJobCondition)
                ->all();

            $pendingJobs = 0;
            $reservedJobs = 0;
            $currentJob = null;

            foreach ($allJobs as $job) {
                if ($job['timePushed'] === null) {
                    $pendingJobs++;
                } else {
                    $reservedJobs++;

                    if ($currentJob === null) {
                        $currentJob = $job['description'];
                    }
                }
            }

            // Translate the job description if it's a serialized Translation object
            $jobDescription = $this->translateJobDescription($currentJob);

            return [
                'pendingJobs' => $pendingJobs,
                'reservedJobs' => $reservedJobs,
                'jobDescription' => $jobDescription ?: Craft::t('lens', 'Processing assets'),
            ];
        } catch (\Throwable) {
            return [
                'pendingJobs' => 0,
                'reservedJobs' => 0,
                'jobDescription' => Craft::t('lens', 'Processing assets'),
            ];
        }
    }

    /**
     * Translate a job description that may be in serialized Translation format.
     */
    private function translateJobDescription(?string $description): ?string
    {
        if ($description === null) {
            return null;
        }

        // Check if it's a serialized Translation object (t9n:["category","message",params])
        if (str_starts_with($description, 't9n:')) {
            $data = json_decode(substr($description, 4), true);
            if (is_array($data) && count($data) >= 2) {
                $category = $data[0];
                $message = $data[1];
                $params = $data[2] ?? [];
                return Craft::t($category, $message, $params);
            }
        }

        return $description;
    }

    /**
     * Calculate progress based on session data and current stats.
     */
    private function getProgress(?array $session, array $stats): array
    {
        $initialUnprocessed = $session['initialUnprocessed'] ?? $stats['unprocessed'];
        $total = max($initialUnprocessed, 1);
        $completed = max(0, $initialUnprocessed - $stats['unprocessed']);
        $percentComplete = ($completed / $total) * 100;

        return [
            'total' => $total,
            'completed' => $completed,
            'failed' => $stats['failed'],
            'remaining' => $stats['unprocessed'],
            'percentComplete' => round($percentComplete, 1),
        ];
    }

    /**
     * Get the current session data from cache.
     */
    private function getSessionData(): ?array
    {
        $cacheKey = $this->getSessionCacheKey();
        $session = Craft::$app->getCache()->get($cacheKey);

        if (!$session || !is_array($session)) {
            return null;
        }

        return $session;
    }

    /**
     * Format session data for the API response.
     */
    private function formatSession(?array $session): ?array
    {
        if ($session === null) {
            return null;
        }

        $startedAt = $session['startedAt'] ?? 0;
        $actualCost = $this->getSessionCost($startedAt);

        return [
            'startedAt' => date('c', $startedAt),
            'actualCost' => $actualCost,
            'duration' => time() - $startedAt,
            'initialUnprocessed' => $session['initialUnprocessed'] ?? 0,
        ];
    }

    /**
     * Get the total cost of analyses since session started.
     */
    private function getSessionCost(int $startedAt): float
    {
        return (float) (AssetAnalysisRecord::find()
            ->where(['>=', 'processedAt', date('Y-m-d H:i:s', $startedAt)])
            ->sum('actualCost') ?? 0.0);
    }

    /**
     * Check if processing recently completed (within COMPLETE_STATE_DURATION).
     */
    private function isRecentlyCompleted(): bool
    {
        $session = $this->getSessionData();

        if ($session === null) {
            return false;
        }

        // If no completedAt yet, check if we should mark it
        if (!isset($session['completedAt']) || $session['completedAt'] === null) {
            // Check if processing has stopped
            if (!$this->hasLensJobsInQueue() && $this->getProcessingCount() === 0) {
                // Mark as completed
                $session['completedAt'] = time();
                Craft::$app->getCache()->set($this->getSessionCacheKey(), $session, 3600);
                return true;
            }
            return false;
        }

        // Check if within the display window
        return (time() - $session['completedAt']) < self::COMPLETE_STATE_DURATION;
    }

    /**
     * Get the previous state from cache (for transition detection).
     */
    private function getPreviousState(): ?string
    {
        $cacheKey = $this->getSessionCacheKey() . '_previous_state';
        $state = Craft::$app->getCache()->get($cacheKey);

        return $state ?: null;
    }

    /**
     * Store the current state for transition detection.
     */
    private function setPreviousState(string $state): void
    {
        $cacheKey = $this->getSessionCacheKey() . '_previous_state';
        Craft::$app->getCache()->set($cacheKey, $state, 3600);
    }

    /**
     * Get transition information for UI notifications.
     */
    private function getTransitionInfo(string $from, string $to): array
    {
        $message = match ([$from, $to]) {
            ['processing', 'complete'] => Craft::t('lens', 'Bulk processing complete!'),
            ['ready', 'processing'] => Craft::t('lens', 'Processing started'),
            default => null,
        };

        return [
            'from' => $from,
            'to' => $to,
            'message' => $message,
        ];
    }

    /**
     * Get the cache key for the current user's session.
     */
    private function getSessionCacheKey(): string
    {
        $userId = Craft::$app->getUser()->getId() ?? 0;
        return 'lens_bulk_session_' . $userId;
    }

    /**
     * Estimate cost based on default token usage and current provider.
     */
    private function estimateCostFromTokens(int $assetCount): float
    {
        $settings = Plugin::getInstance()->getSettings();
        $pricing = Plugin::getInstance()->pricing;

        try {
            $costPerAsset = match ($settings->getAiProviderEnum()) {
                AiProvider::OpenAi => $pricing->calculateOpenAiCost(
                    $settings->openaiModel,
                    self::DEFAULT_INPUT_TOKENS,
                    self::DEFAULT_OUTPUT_TOKENS
                ),
                AiProvider::Gemini => $pricing->calculateGeminiCost(
                    $settings->geminiModel,
                    self::DEFAULT_INPUT_TOKENS,
                    self::DEFAULT_OUTPUT_TOKENS
                ),
                AiProvider::Claude => $pricing->calculateClaudeCost(
                    $settings->claudeModel,
                    self::DEFAULT_INPUT_TOKENS,
                    self::DEFAULT_OUTPUT_TOKENS
                ),
            };
        } catch (\Throwable) {
            $costPerAsset = 0.001;
        }

        return $costPerAsset * $assetCount;
    }

    // =========================================================================
    // Count Methods
    // =========================================================================

    private function getTotalImageCount(?int $volumeId = null): int
    {
        $query = Asset::find()->kind(Asset::KIND_IMAGE);

        if ($volumeId !== null) {
            $query->volumeId($volumeId);
        }

        return (int) $query->count();
    }

    private function getAnalyzedCount(?int $volumeId = null): int
    {
        $query = AssetAnalysisRecord::find()
            ->where(['in', 'status', AnalysisStatus::analyzedValues()]);

        $this->applyVolumeFilter($query, $volumeId);

        return (int) $query->count();
    }

    private function getUnprocessedCount(?int $volumeId = null): int
    {
        $processedSubQuery = AssetAnalysisRecord::find()
            ->select('assetId')
            ->where(['in', 'status', [
                AnalysisStatus::Completed->value,
                AnalysisStatus::Approved->value,
                AnalysisStatus::PendingReview->value,
                AnalysisStatus::Processing->value,
            ]]);

        $query = Asset::find()->kind(Asset::KIND_IMAGE);

        if ($volumeId !== null) {
            $query->volumeId($volumeId);
        }

        $query->andWhere(['not in', 'elements.id', $processedSubQuery]);

        return (int) $query->count();
    }

    private function getFailedCount(?int $volumeId = null): int
    {
        $query = AssetAnalysisRecord::find()
            ->where(['status' => AnalysisStatus::Failed->value]);

        $this->applyVolumeFilter($query, $volumeId);

        return (int) $query->count();
    }

    private function getProcessingCount(?int $volumeId = null): int
    {
        $query = AssetAnalysisRecord::find()
            ->where(['status' => AnalysisStatus::Processing->value]);

        $this->applyVolumeFilter($query, $volumeId);

        return (int) $query->count();
    }

    private function getPendingReviewCount(?int $volumeId = null): int
    {
        $query = AssetAnalysisRecord::find()
            ->where(['status' => AnalysisStatus::PendingReview->value]);

        $this->applyVolumeFilter($query, $volumeId);

        return (int) $query->count();
    }

    /**
     * Get asset IDs belonging to a specific volume.
     *
     * @return int[]
     */
    private function getAssetIdsForVolume(int $volumeId): array
    {
        return Asset::find()
            ->kind(Asset::KIND_IMAGE)
            ->volumeId($volumeId)
            ->ids();
    }

    /**
     * Cancel all running Lens jobs.
     *
     * @return int Number of jobs cancelled
     */
    public function cancelProcessing(): int
    {
        $cancelled = 0;

        try {
            // Get job IDs to cancel
            $jobIds = (new Query())
                ->select(['id'])
                ->from('{{%queue}}')
                ->where(['like', 'job', BulkAnalyzeAssetsJob::class])
                ->orWhere(['like', 'job', AnalyzeAssetJob::class])
                ->column();

            if (!empty($jobIds)) {
                // Delete the jobs from the queue
                Craft::$app->getDb()->createCommand()
                    ->delete('{{%queue}}', ['in', 'id', $jobIds])
                    ->execute();

                $cancelled = count($jobIds);
            }

            // Reset any "processing" status records back to pending
            AssetAnalysisRecord::updateAll(
                ['status' => AnalysisStatus::Pending->value],
                ['status' => AnalysisStatus::Processing->value]
            );

            // Clear the session
            Craft::$app->getCache()->delete($this->getSessionCacheKey());
            Craft::$app->getCache()->delete($this->getSessionCacheKey() . '_previous_state');

        } catch (\Throwable $e) {
            Logger::error(LogCategory::AssetProcessing, 'Failed to cancel processing', exception: $e);
        }

        return $cancelled;
    }

    /**
     * Apply volume filter to a query by filtering asset IDs.
     *
     * @param \yii\db\ActiveQuery $query The query to filter
     * @param int|null $volumeId Volume ID to filter by (null = no filter)
     */
    private function applyVolumeFilter($query, ?int $volumeId): void
    {
        if ($volumeId === null) {
            return;
        }

        $assetIds = $this->getAssetIdsForVolume($volumeId);

        if (empty($assetIds)) {
            $query->andWhere('1 = 0');
            return;
        }

        $query->andWhere(['in', 'assetId', $assetIds]);
    }
}
