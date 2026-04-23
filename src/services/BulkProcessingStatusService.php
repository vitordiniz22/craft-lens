<?php

declare(strict_types=1);

namespace vitordiniz22\craftlens\services;

use Craft;
use craft\elements\Asset;
use vitordiniz22\craftlens\enums\AnalysisStatus;
use vitordiniz22\craftlens\enums\ErrorCode;
use vitordiniz22\craftlens\enums\LogCategory;
use vitordiniz22\craftlens\helpers\Logger;
use vitordiniz22\craftlens\jobs\AnalyzeAssetJob;
use vitordiniz22\craftlens\jobs\BulkAnalyzeAssetsJob;
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

    private const SESSION_TTL = 3600;

    /**
     * Get the full status response for the AJAX endpoint.
     *
     * When no bulk session is active but records are still marked Processing,
     * only true orphans (records whose queue job has vanished) are deleted.
     * A running single-asset job stamps its queueJobId on the record via
     * queueAsset(), so its queue row stays alive while the worker runs and
     * the poll leaves it alone instead of wiping it mid-AI-call.
     */
    public function getStatus(): array
    {
        $stats = $this->getStats();
        $state = $this->determineState($stats);

        if ($state === 'ready' && ($stats['processing'] ?? 0) > 0) {
            $liveQueueIds = (new Query())->select(['id'])->from('{{%queue}}');
            AssetAnalysisRecord::deleteAll([
                'and',
                ['status' => AnalysisStatus::Processing->value],
                [
                    'or',
                    ['queueJobId' => null],
                    ['not in', 'queueJobId', $liveQueueIds],
                ],
            ]);
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
     * Start a new processing session. Two modes:
     *   - Bulk (default): scopes to $volumeId and counts all currently unprocessed
     *     image assets within that scope.
     *   - Retry: if $retriedFailedAssetIds is non-empty, scopes strictly to those
     *     asset IDs. On cancel, cancelProcessing restores these IDs to Failed
     *     so the retry can be re-initiated.
     *
     * @param int|null $volumeId Scope to a specific volume (bulk mode only)
     * @param int[] $retriedFailedAssetIds Asset IDs being retried after previous failure
     */
    public function startSession(?int $volumeId = null, array $retriedFailedAssetIds = []): void
    {
        $isRetry = !empty($retriedFailedAssetIds);
        $initialCount = $isRetry
            ? count($retriedFailedAssetIds)
            : $this->getUnprocessedCount($volumeId);

        // Snapshot how many assets in scope were already in a terminal state
        // (Completed, PendingReview, Approved, Failed, Rejected). Progress is
        // computed as (currentTerminal - initialTerminal), so the counter
        // advances only when a session asset actually finishes.
        $initialTerminalCount = $isRetry
            ? 0
            : $this->countByStatus(AnalysisStatus::terminalValues(), $volumeId);

        Logger::info(LogCategory::JobStarted, 'Bulk processing session started', context: [
            'volumeId' => $volumeId,
            'initialUnprocessed' => $initialCount,
            'initialTerminalCount' => $initialTerminalCount,
            'isRetry' => $isRetry,
        ]);

        Craft::$app->getCache()->set($this->getSessionCacheKey(), [
            'startedAt' => time(),
            'volumeId' => $volumeId,
            'initialUnprocessed' => $initialCount,
            'initialTerminalCount' => $initialTerminalCount,
            'retriedFailedAssetIds' => $retriedFailedAssetIds,
            'completedAt' => null,
        ], self::SESSION_TTL);
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
        $retriedIds = $session['retriedFailedAssetIds'] ?? [];

        if (!empty($retriedIds)) {
            // Retry session: total and remaining are strictly the retried IDs.
            // "Remaining" = retried assets still in Pending or Processing; once
            // they reach any terminal status (Completed, Failed, etc.) they
            // count as done for this session.
            $initialUnprocessed = count($retriedIds);
            $remaining = (int) AssetAnalysisRecord::find()
                ->where(['in', 'assetId', $retriedIds])
                ->andWhere(['in', 'status', [
                    AnalysisStatus::Pending->value,
                    AnalysisStatus::Processing->value,
                ]])
                ->count();
            $completed = max(0, $initialUnprocessed - $remaining);
        } else {
            // Bulk session: completed = assets that reached a terminal state
            // since this session started. Mid-flight (Processing) assets are
            // still "remaining" until they actually finish.
            $initialUnprocessed = $session['initialUnprocessed'] ?? $stats['unprocessed'];
            $initialTerminalCount = $session['initialTerminalCount'] ?? 0;
            $volumeId = $session['volumeId'] ?? null;

            $currentTerminalCount = $this->countByStatus(
                AnalysisStatus::terminalValues(),
                $volumeId,
            );

            $completed = max(0, min(
                $initialUnprocessed,
                $currentTerminalCount - $initialTerminalCount,
            ));
            $remaining = max(0, $initialUnprocessed - $completed);
        }

        $total = max($initialUnprocessed, 1);
        $percentComplete = ($completed / $total) * 100;

        $rate = $this->recordProgressSample($session, $completed);
        $etaSeconds = ($rate !== null && $rate > 0 && $remaining > 0)
            ? (int) ($remaining / $rate)
            : null;

        // Count failures from this session only, not historical ones
        $sessionFailed = 0;
        if ($session !== null && isset($session['startedAt'])) {
            $sessionFailed = (int) AssetAnalysisRecord::find()
                ->where(['status' => AnalysisStatus::Failed->value])
                ->andWhere(['>=', 'processedAt', gmdate('Y-m-d H:i:s', $session['startedAt'])])
                ->count();
        }

        return [
            'total' => $total,
            'completed' => $completed,
            'failed' => $sessionFailed,
            'remaining' => $remaining,
            'percentComplete' => round($percentComplete, 1),
            'rate' => $rate,
            'etaSeconds' => $etaSeconds,
            'etaFormatted' => $etaSeconds !== null ? self::formatDuration($etaSeconds) : null,
        ];
    }

    /**
     * Recompute the rate only when a new asset has completed since the last
     * poll. Between completions the stored rate is returned unchanged, so the
     * ETA stays constant between events and drops by one asset's worth of time
     * when a completion lands. Returns null before the first completion.
     *
     * The session is written back on every poll so its 1-hour TTL refreshes
     * even during slow stretches with no completions.
     */
    private function recordProgressSample(?array $session, int $completed): ?float
    {
        if ($session === null) {
            return null;
        }

        $lastCompleted = $session['lastRecordedCompleted'] ?? 0;
        $elapsed = time() - ($session['startedAt'] ?? time());
        $hasNewCompletion = $completed > $lastCompleted && $elapsed > 0;

        if ($hasNewCompletion) {
            $session['lastRecordedCompleted'] = $completed;
            $session['lastRecordedRate'] = $completed / $elapsed;
        }

        Craft::$app->getCache()->set($this->getSessionCacheKey(), $session, self::SESSION_TTL);

        return $session['lastRecordedRate'] ?? null;
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
            ->where(['>=', 'processedAt', gmdate('Y-m-d H:i:s', $startedAt)])
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
        // Read retriedFailedAssetIds before clearing the session. They're
        // used below to restore Failed status on assets the session flipped
        // to Pending.
        $session = $this->getSessionData();
        $retriedFailedAssetIds = $session['retriedFailedAssetIds'] ?? [];

        // Clear the session first. Any still-running job checks for session
        // existence on each iteration and will stop on its own, so session
        // removal is the authoritative "cancelled" signal.
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

            // Restore retried-but-not-finished failures back to Failed so the
            // user can retry again. Assets that already re-analyzed to a
            // terminal status (Completed, PendingReview, etc.) are skipped.
            if (!empty($retriedFailedAssetIds)) {
                AssetAnalysisRecord::updateAll(
                    ['status' => AnalysisStatus::Failed->value],
                    [
                        'and',
                        ['in', 'assetId', $retriedFailedAssetIds],
                        ['in', 'status', [AnalysisStatus::Pending->value, AnalysisStatus::Processing->value]],
                    ]
                );
            }

            // Delete any remaining Processing records (these are non-retried
            // assets that were mid-flight when cancelled) so they become truly
            // unprocessed and will be picked up by the next bulk run.
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
     * Groups by stable ErrorCode. Rows with a null errorCode bucket into ErrorCode::Unknown.
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
            $query->andWhere(['>=', 'processedAt', gmdate('Y-m-d H:i:s', $session['startedAt'])]);
        }

        $failedAnalysisIds = $query->column();

        if (empty($failedAnalysisIds)) {
            return ['groups' => [], 'totalFailed' => 0];
        }

        $failedDetails = (new Query())
            ->select(['a.assetId', 'c.errorCode'])
            ->from([Install::TABLE_ASSET_ANALYSES . ' a'])
            ->innerJoin(Install::TABLE_ANALYSIS_CONTENT . ' c', 'c.[[analysisId]] = a.[[id]]')
            ->where(['in', 'a.id', $failedAnalysisIds])
            ->limit(5000)
            ->all();

        if (empty($failedDetails)) {
            return ['groups' => [], 'totalFailed' => 0];
        }

        $assetIdsByCode = [];
        $countByCode = [];

        foreach ($failedDetails as $row) {
            $codeValue = $row['errorCode'] ?? null;
            $code = ErrorCode::fromValueOrUnknown($codeValue);
            $codeKey = $code->value;

            $assetIdsByCode[$codeKey] ??= [];
            $assetIdsByCode[$codeKey][] = (int) $row['assetId'];
            $countByCode[$codeKey] = ($countByCode[$codeKey] ?? 0) + 1;
        }

        arsort($countByCode);

        $allAssetIds = array_unique(array_column($failedDetails, 'assetId'));
        $assets = !empty($allAssetIds)
            ? Asset::find()->id($allAssetIds)->status(null)->indexBy('id')->all()
            : [];

        $totalFailed = 0;
        $groups = [];

        foreach ($countByCode as $codeKey => $count) {
            $code = ErrorCode::from($codeKey);
            $totalFailed += $count;

            $groupAssetIds = $assetIdsByCode[$codeKey] ?? [];
            $assetList = [];

            foreach (array_slice($groupAssetIds, 0, self::MAX_ASSETS_PER_GROUP) as $assetId) {
                $asset = $assets[$assetId] ?? null;

                if ($asset !== null) {
                    $volume = $asset->getVolume();
                    $thumbUrl = null;
                    if ($asset->kind === Asset::KIND_IMAGE) {
                        try {
                            $thumbUrl = Craft::$app->getAssets()->getThumbUrl($asset, 96, 96, false);
                        } catch (\Throwable) {
                            $thumbUrl = null;
                        }
                    }

                    $dimensions = null;
                    if ($asset->width && $asset->height) {
                        $dimensions = $asset->width . '×' . $asset->height;
                    }

                    $assetList[] = [
                        'id' => $asset->id,
                        'filename' => $asset->filename,
                        'editUrl' => $asset->getCpEditUrl(),
                        'size' => $asset->size !== null ? (int) $asset->size : null,
                        'volume' => $volume?->name,
                        'dimensions' => $dimensions,
                        'thumbUrl' => $thumbUrl,
                    ];
                } else {
                    $assetList[] = [
                        'id' => $assetId,
                        'filename' => null,
                        'editUrl' => null,
                        'size' => null,
                        'volume' => null,
                        'dimensions' => null,
                        'thumbUrl' => null,
                    ];
                }
            }

            $groups[] = [
                'code' => $code->value,
                'label' => $code->label(),
                'message' => $code->groupMessage(),
                'isConfigError' => $code->isConfigError(),
                'showsAssets' => $code->showsAssets(),
                'count' => $count,
                'assets' => $assetList,
                'hasMore' => max(0, $count - self::MAX_ASSETS_PER_GROUP),
            ];
        }

        return [
            'groups' => $groups,
            'totalFailed' => $totalFailed,
        ];
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
