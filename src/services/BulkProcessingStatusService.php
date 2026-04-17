<?php

declare(strict_types=1);

namespace vitordiniz22\craftlens\services;

use Craft;
use craft\elements\Asset;
use vitordiniz22\craftlens\enums\AnalysisStatus;
use vitordiniz22\craftlens\enums\LogCategory;
use vitordiniz22\craftlens\helpers\Logger;
use vitordiniz22\craftlens\jobs\AnalyzeAssetJob;
use vitordiniz22\craftlens\jobs\BulkAnalyzeAssetsJob;
use vitordiniz22\craftlens\Plugin;
use vitordiniz22\craftlens\migrations\Install;
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
    private const COMPLETE_STATE_DURATION = 300;

    /**
     * Default token estimates for cost calculation when no historical data exists.
     */
    private const DEFAULT_INPUT_TOKENS = 1500;
    private const DEFAULT_OUTPUT_TOKENS = 500;
    private const SESSION_TTL = 3600;

    /**
     * Get the full status response for the AJAX endpoint.
     */
    public function getStatus(): array
    {
        $stats = $this->getStats();
        $state = $this->determineState($stats);

        // If state reset to ready but assets are still in Processing status,
        // jobs were cancelled externally. Clean up orphaned records and re-fetch stats.
        if ($state === 'ready' && ($stats['processing'] ?? 0) > 0) {
            AssetAnalysisRecord::deleteAll(
                ['status' => AnalysisStatus::Processing->value]
            );
            $stats = $this->getStats();
        }

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
    public function getStats(null|int|array $volumeId = null): array
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
     *
     * @param int|null $volumeId Scope to a specific volume
     * @param int $additionalCount Extra assets being re-queued (e.g. retried failures reset to Pending)
     */
    public function startSession(?int $volumeId = null, int $additionalCount = 0): void
    {
        $cacheKey = $this->getSessionCacheKey();
        $unprocessedCount = $this->getUnprocessedCount($volumeId);
        $initialCount = $unprocessedCount + $additionalCount;

        Logger::info(LogCategory::JobStarted, 'Bulk processing session started', context: [
            'volumeId' => $volumeId,
            'initialUnprocessed' => $initialCount,
            'additionalCount' => $additionalCount,
        ]);

        Craft::$app->getCache()->set($cacheKey, [
            'startedAt' => time(),
            'volumeId' => $volumeId,
            'initialUnprocessed' => $initialCount,
            'includesPending' => $additionalCount > 0,
            'completedAt' => null,
        ], self::SESSION_TTL); // 1 hour TTL
    }

    /**
     * Determine the current processing state.
     */
    public function determineState(array $stats): string
    {
        $session = $this->getSessionData();

        // Only show processing state if a bulk session was explicitly started.
        // Individual AnalyzeAssetJob jobs (from uploads/replacements) should not
        // trigger the bulk processing UI.
        if ($session !== null && !isset($session['completedAt'])) {
            $hasQueuedJobs = $this->hasLensJobsInQueue();
            $processingCount = $stats['processing'] ?? 0;

            if ($hasQueuedJobs || $processingCount > 0) {
                // If assets are stuck in Processing but no jobs remain in the queue,
                // it means jobs were cancelled externally (e.g. from Craft's Jobs page).
                // Clear the session so the UI resets — record cleanup happens in getStatus()
                // to avoid racing with a still-finishing job.
                if (!$hasQueuedJobs && $processingCount > 0) {
                    $this->clearSession();

                    return 'ready';
                }

                return 'processing';
            }
        }

        if ($this->isRecentlyCompleted($stats)) {
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
        } catch (\Throwable $e) {
            Logger::warning(LogCategory::JobStatus, 'Queue monitoring query failed', exception: $e);
            return false;
        }
    }

    /**
     * Get information about the current queue state.
     */
    public function getQueueInfo(): array
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
                ->limit(1000)
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
        } catch (\Throwable $e) {
            Logger::warning(LogCategory::JobStatus, 'Queue info query failed', exception: $e);
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
    public function getProgress(?array $session, array $stats): array
    {
        $initialUnprocessed = $session['initialUnprocessed'] ?? $stats['unprocessed'];
        $includesPending = $session['includesPending'] ?? false;

        // For retry sessions, "remaining" includes Pending + Processing assets
        // (they have records but haven't reached a final status yet).
        // For normal sessions, "remaining" is truly unprocessed assets (no record).
        if ($includesPending) {
            $pendingCount = $this->countByStatus(
                [AnalysisStatus::Pending->value],
                $session['volumeId'] ?? null
            );
            $remaining = $stats['unprocessed'] + $pendingCount + ($stats['processing'] ?? 0);
        } else {
            $remaining = $stats['unprocessed'];
        }

        $total = max($initialUnprocessed, 1);
        $completed = max(0, $initialUnprocessed - $remaining);
        $percentComplete = ($completed / $total) * 100;

        // Calculate rate and ETA
        $elapsed = time() - ($session['startedAt'] ?? time());
        $rate = ($elapsed > 10 && $completed > 0) ? $completed / $elapsed : null;
        $etaSeconds = ($rate !== null && $rate > 0 && $remaining > 0)
            ? (int) ($remaining / $rate)
            : null;

        // Count failures from this session only, not historical ones
        $sessionFailed = 0;
        if ($session !== null && isset($session['startedAt'])) {
            $sessionFailed = (int) AssetAnalysisRecord::find()
                ->where(['status' => AnalysisStatus::Failed->value])
                ->andWhere(['>=', 'processedAt', date('Y-m-d H:i:s', $session['startedAt'])])
                ->count();
        }

        return [
            'total' => $total,
            'completed' => $completed,
            'failed' => $sessionFailed,
            'remaining' => $remaining,
            'percentComplete' => round($percentComplete, 1),
            'rate' => $rate !== null ? round($rate, 1) : null,
            'etaSeconds' => $etaSeconds,
            'etaFormatted' => $etaSeconds !== null ? self::formatDuration($etaSeconds) : null,
        ];
    }

    /**
     * Get the current session data from cache.
     */
    public function getSessionData(): ?array
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
    public function formatSession(?array $session): ?array
    {
        if ($session === null) {
            return null;
        }

        $startedAt = $session['startedAt'] ?? 0;
        $actualCost = $this->getSessionCost($startedAt);

        $endedAt = $session['completedAt'] ?? time();
        $duration = $endedAt - $startedAt;

        return [
            'startedAt' => date('c', $startedAt),
            'actualCost' => $actualCost,
            'duration' => $duration,
            'durationFormatted' => self::formatDuration($duration),
            'initialUnprocessed' => $session['initialUnprocessed'] ?? 0,
        ];
    }

    /**
     * Format a duration in seconds to a human-readable string.
     */
    public static function formatDuration(int $seconds): string
    {
        if ($seconds < 60) {
            return Craft::t('lens', '{count}s', ['count' => $seconds]);
        }

        $minutes = (int) floor($seconds / 60);
        $remainingSeconds = $seconds % 60;

        if ($minutes < 60) {
            return $remainingSeconds > 0
                ? Craft::t('lens', '{min}m {sec}s', ['min' => $minutes, 'sec' => $remainingSeconds])
                : Craft::t('lens', '{min}m', ['min' => $minutes]);
        }

        $hours = (int) floor($minutes / 60);
        $remainingMinutes = $minutes % 60;

        return $remainingMinutes > 0
            ? Craft::t('lens', '{hr}h {min}m', ['hr' => $hours, 'min' => $remainingMinutes])
            : Craft::t('lens', '{hr}h', ['hr' => $hours]);
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
    private function isRecentlyCompleted(array $stats): bool
    {
        $session = $this->getSessionData();

        if ($session === null) {
            return false;
        }

        if (!isset($session['completedAt'])) {
            if (!$this->hasLensJobsInQueue() && $this->getProcessingCount() === 0) {
                $session['completedAt'] = time();
                Craft::$app->getCache()->set($this->getSessionCacheKey(), $session, 3600);
                return true;
            }
            return false;
        }

        if (($stats['failed'] ?? 0) > 0) {
            return true;
        }

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
        try {
            $costPerAsset = Plugin::getInstance()->pricing->calculateCostForCurrentProvider(
                self::DEFAULT_INPUT_TOKENS,
                self::DEFAULT_OUTPUT_TOKENS
            );
        } catch (\Throwable $e) {
            Logger::warning(LogCategory::JobStatus, 'Cost estimation failed, using fallback', exception: $e);
            $costPerAsset = 0.001;
        }

        return $costPerAsset * $assetCount;
    }

    // =========================================================================
    // Count Methods
    // =========================================================================

    private function getTotalImageCount(null|int|array $volumeId = null): int
    {
        $query = Asset::find()->kind(Asset::KIND_IMAGE);

        if ($volumeId !== null) {
            $query->volumeId($volumeId);
        }

        return (int) $query->count();
    }

    private function getAnalyzedCount(null|int|array $volumeId = null): int
    {
        return $this->countByStatus(AnalysisStatus::processedValues(), $volumeId);
    }

    private function getUnprocessedCount(null|int|array $volumeId = null): int
    {
        $handledAssetIds = AssetAnalysisRecord::find()
            ->select('assetId')
            ->where(['not in', 'status', AnalysisStatus::unprocessedStatuses()]);

        $query = Asset::find()->kind(Asset::KIND_IMAGE);

        if ($volumeId !== null) {
            $query->volumeId($volumeId);
        }

        $query->andWhere(['not in', 'elements.id', $handledAssetIds]);

        return (int) $query->count();
    }

    private function getFailedCount(null|int|array $volumeId = null): int
    {
        return $this->countByStatus([AnalysisStatus::Failed->value], $volumeId);
    }

    private function getProcessingCount(null|int|array $volumeId = null): int
    {
        return $this->countByStatus([AnalysisStatus::Processing->value], $volumeId);
    }

    private function getPendingReviewCount(null|int|array $volumeId = null): int
    {
        return $this->countByStatus([AnalysisStatus::PendingReview->value], $volumeId);
    }

    /**
     * Get asset IDs belonging to one or more volumes.
     *
     * @return int[]
     */
    private function getAssetIdsForVolume(int|array $volumeId): array
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
        // Always clear the session first — even if queue cleanup fails,
        // the running job checks for session existence and will stop.
        Craft::$app->getCache()->delete($this->getSessionCacheKey());
        Craft::$app->getCache()->delete($this->getSessionCacheKey() . '_previous_state');

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

            // Delete incomplete analysis records so assets become truly unprocessed
            // and will be picked up by the next bulk run.
            AssetAnalysisRecord::deleteAll(
                ['status' => AnalysisStatus::Processing->value]
            );
        } catch (\Throwable $e) {
            Logger::error(LogCategory::AssetProcessing, 'Failed to cancel processing', exception: $e);
        }

        return $cancelled;
    }

    /**
     * Clear the current session so the state reverts to "ready".
     */
    public function clearSession(): void
    {
        Craft::$app->getCache()->delete($this->getSessionCacheKey());
        Craft::$app->getCache()->delete($this->getSessionCacheKey() . '_previous_state');
    }

    /**
     * Maximum number of asset details to include per error group.
     */
    private const MAX_ASSETS_PER_GROUP = 10;

    /**
     * Get all error groups from failed analyses with asset details.
     *
     * Returns error messages grouped by distinct message, each with a count
     * and a list of affected assets (capped at MAX_ASSETS_PER_GROUP).
     *
     * @return array{groups: array, totalFailed: int}
     */
    public function getFailureReasons(): array
    {
        $session = $this->getSessionData();
        $query = AssetAnalysisRecord::find()
            ->select(['id'])
            ->where(['status' => AnalysisStatus::Failed->value]);

        if ($session !== null && isset($session['startedAt'])) {
            $query->andWhere(['>=', 'processedAt', date('Y-m-d H:i:s', $session['startedAt'])]);
        }

        $failedAnalysisIds = $query->column();

        if (empty($failedAnalysisIds)) {
            return ['groups' => [], 'totalFailed' => 0, 'hasConfigError' => false];
        }

        $errorGroups = (new Query())
            ->select(['errorMessage', 'COUNT(*) as cnt'])
            ->from(Install::TABLE_ANALYSIS_CONTENT)
            ->where(['in', 'analysisId', $failedAnalysisIds])
            ->andWhere(['not', ['errorMessage' => null]])
            ->groupBy(['errorMessage'])
            ->orderBy(['cnt' => SORT_DESC])
            ->all();

        if (empty($errorGroups)) {
            return ['groups' => [], 'totalFailed' => 0, 'hasConfigError' => false];
        }

        $failedDetails = (new Query())
            ->select(['a.assetId', 'c.errorMessage'])
            ->from([Install::TABLE_ASSET_ANALYSES . ' a'])
            ->innerJoin(Install::TABLE_ANALYSIS_CONTENT . ' c', 'c.[[analysisId]] = a.[[id]]')
            ->where(['in', 'a.id', $failedAnalysisIds])
            ->andWhere(['not', ['c.errorMessage' => null]])
            ->limit(5000)
            ->all();

        $assetIdsByMessage = [];

        foreach ($failedDetails as $row) {
            $assetIdsByMessage[$row['errorMessage']][] = (int) $row['assetId'];
        }

        $allAssetIds = array_unique(array_column($failedDetails, 'assetId'));
        $assets = !empty($allAssetIds)
            ? Asset::find()->id($allAssetIds)->status(null)->indexBy('id')->all()
            : [];
        $totalFailed = 0;
        $groups = [];

        foreach ($errorGroups as $eg) {
            $message = $eg['errorMessage'];
            $count = (int) $eg['cnt'];
            $totalFailed += $count;

            $groupAssetIds = $assetIdsByMessage[$message] ?? [];
            $assetList = [];

            foreach (array_slice($groupAssetIds, 0, self::MAX_ASSETS_PER_GROUP) as $assetId) {
                $asset = $assets[$assetId] ?? null;

                if ($asset !== null) {
                    $assetList[] = [
                        'id' => $asset->id,
                        'filename' => $asset->filename,
                        'editUrl' => $asset->getCpEditUrl(),
                    ];
                } else {
                    $assetList[] = [
                        'id' => $assetId,
                        'filename' => null,
                        'editUrl' => null,
                    ];
                }
            }

            $groups[] = [
                'message' => $message,
                'count' => $count,
                'assets' => $assetList,
                'hasMore' => max(0, $count - self::MAX_ASSETS_PER_GROUP),
            ];
        }

        $hasConfigError = false;
        $configErrorMessage = null;

        foreach ($groups as $group) {
            if ($this->isConfigurationError($group['message'])) {
                $hasConfigError = true;
                $configErrorMessage = $group['message'];
                break;
            }
        }

        return [
            'groups' => $groups,
            'totalFailed' => $totalFailed,
            'hasConfigError' => $hasConfigError,
            'configErrorMessage' => $configErrorMessage,
        ];
    }

    /**
     * Check whether an error message indicates a configuration problem.
     */
    private function isConfigurationError(string $message): bool
    {
        $lower = strtolower($message);

        return str_contains($lower, 'not configured')
            || str_contains($lower, 'is invalid')
            || str_contains($lower, 'api key')
            || str_contains($lower, 'unauthorized');
    }

    /**
     * Apply volume filter to a query by filtering asset IDs.
     *
     * @param \yii\db\ActiveQuery $query The query to filter
     * @param int|array|null $volumeId Volume ID(s) to filter by (null = no filter)
     */
    /**
     * Count analysis records matching one or more statuses, optionally filtered by volume.
     *
     * @param string[] $statusValues
     */
    private function countByStatus(array $statusValues, null|int|array $volumeId = null): int
    {
        $query = AssetAnalysisRecord::find()
            ->where(['in', 'status', $statusValues]);

        $this->applyVolumeFilter($query, $volumeId);

        return (int) $query->count();
    }

    private function applyVolumeFilter($query, null|int|array $volumeId): void
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
