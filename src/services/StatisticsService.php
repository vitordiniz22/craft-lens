<?php

declare(strict_types=1);

namespace vitordiniz22\craftlens\services;

use Craft;
use craft\elements\Asset;
use DateTime;
use vitordiniz22\craftlens\enums\AiProvider;
use vitordiniz22\craftlens\enums\AnalysisStatus;
use vitordiniz22\craftlens\helpers\ColorSupport;
use vitordiniz22\craftlens\helpers\ImageMetricsAnalyzer;
use vitordiniz22\craftlens\helpers\QualitySupport;
use vitordiniz22\craftlens\migrations\Install;
use vitordiniz22\craftlens\Plugin;
use vitordiniz22\craftlens\records\AssetAnalysisRecord;
use yii\base\Component;
use yii\db\Expression;
use yii\db\Query;

/**
 * Service for aggregating and retrieving processing statistics.
 */
class StatisticsService extends Component
{
    private const NSFW_SCORE_THRESHOLD = 0.5;

    /**
     * Get overview statistics for the dashboard.
     */
    public function getOverviewStats(): array
    {
        $analyzedStatuses = AnalysisStatus::analyzedValues();

        $result = AssetAnalysisRecord::find()
            ->select([
                new Expression(
                    'SUM(CASE WHEN status IN (:analyzed1, :analyzed2, :analyzed3) THEN 1 ELSE 0 END) as analyzed',
                    [':analyzed1' => $analyzedStatuses[0], ':analyzed2' => $analyzedStatuses[1], ':analyzed3' => $analyzedStatuses[2]]
                ),
                new Expression(
                    'SUM(CASE WHEN status = :approved THEN 1 ELSE 0 END) as approved',
                    [':approved' => AnalysisStatus::Approved->value]
                ),
                new Expression(
                    'SUM(CASE WHEN status = :rejected THEN 1 ELSE 0 END) as rejected',
                    [':rejected' => AnalysisStatus::Rejected->value]
                ),
                new Expression(
                    'SUM(CASE WHEN status = :pendingReview THEN 1 ELSE 0 END) as pendingReview',
                    [':pendingReview' => AnalysisStatus::PendingReview->value]
                ),
                new Expression(
                    'SUM(CASE WHEN status = :failed THEN 1 ELSE 0 END) as failed',
                    [':failed' => AnalysisStatus::Failed->value]
                ),
                'SUM(actualCost) as totalCost',
            ])
            ->asArray()
            ->one();

        $analyzed = (int) ($result['analyzed'] ?? 0);
        $totalCost = (float) ($result['totalCost'] ?? 0.0);

        return [
            'totalImages' => $this->getTotalImageCount(),
            'analyzed' => $analyzed,
            'unprocessed' => $this->getUnprocessedCount(),
            'pendingReview' => (int) ($result['pendingReview'] ?? 0),
            'approved' => (int) ($result['approved'] ?? 0),
            'rejected' => (int) ($result['rejected'] ?? 0),
            'failed' => (int) ($result['failed'] ?? 0),
            'totalCost' => $totalCost,
        ];
    }

    /**
     * Get top tags by frequency, scoped to enabled volumes.
     */
    public function getTopTags(int $limit = 10): array
    {
        return Plugin::getInstance()->tagAggregation->getTagCounts($limit, 'count', $this->getEnabledVolumeIds());
    }

    /**
     * Get dominant colors by frequency, scoped to enabled volumes.
     */
    public function getDominantColors(int $limit = 5): array
    {
        if (!ColorSupport::isAvailable()) {
            return [];
        }

        return Plugin::getInstance()->colorAggregation->getColorCounts($limit, $this->getEnabledVolumeIds());
    }

    /**
     * @param int[]|null $volumeIds Pre-computed enabled volume IDs, or null for all volumes.
     */
    private function getTotalImageCount(?array $volumeIds = null): int
    {
        $query = Asset::find()->kind(Asset::KIND_IMAGE);

        if ($volumeIds !== null) {
            if (empty($volumeIds)) {
                return 0;
            }

            $query->volumeId($volumeIds);
        }

        return (int) $query->count();
    }

    /**
     * Get IDs of volumes explicitly enabled for Lens processing.
     *
     * @return int[] empty array means no volume is enabled
     */
    private function getEnabledVolumeIds(): array
    {
        return Plugin::getInstance()->getSettings()->getEnabledVolumeIds();
    }

    /**
     * Build a subquery that returns asset IDs belonging to the given volumes.
     * Used to scope analysis record queries to enabled volumes only.
     *
     * @param int[] $volumeIds
     */
    private function buildVolumeSubquery(array $volumeIds): Query
    {
        return (new Query())
            ->select('id')
            ->from('{{%assets}}')
            ->where(['in', 'volumeId', $volumeIds]);
    }

    private function getUnprocessedCount(): int
    {
        return Plugin::getInstance()->assetAnalysis->getUnprocessedCount();
    }

    public function getPendingReviewCount(): int
    {
        return Plugin::getInstance()->review->getPendingReviewCount();
    }

    public function getFailedCount(): int
    {
        return (int) AssetAnalysisRecord::find()
            ->where(['status' => AnalysisStatus::Failed->value])
            ->count();
    }

    /**
     * Get total cost across all analyses.
     */
    public function getTotalCost(): float
    {
        return (float) (AssetAnalysisRecord::find()->sum('actualCost') ?? 0.0);
    }

    /**
     * Get count of assets flagged as NSFW (nsfwScore >= threshold).
     */
    public function getNsfwFlaggedCount(): int
    {
        return (int) AssetAnalysisRecord::find()
            ->where(['in', 'status', AnalysisStatus::processedValues()])
            ->andWhere(['>=', 'nsfwScore', self::NSFW_SCORE_THRESHOLD])
            ->count();
    }

    /**
     * Get count of assets with watermarks detected.
     */
    public function getWatermarkedCount(): int
    {
        return (int) AssetAnalysisRecord::find()
            ->where(['in', 'status', AnalysisStatus::processedValues()])
            ->andWhere(['hasWatermark' => true])
            ->count();
    }

    /**
     * Get alt text coverage using Craft's native alt field.
     *
     * Counts all images in enabled volumes with a non-empty native alt
     * attribute, regardless of Lens analysis status. This reflects the
     * real state of the library, not just AI-drafted content.
     *
     * @return array{percentage: float, withAltText: int, total: int}
     */
    public function getAltTextCoverage(): array
    {
        $volumeIds = $this->getEnabledVolumeIds();
        $total = $this->getTotalImageCount($volumeIds);

        if ($total === 0) {
            return $this->buildCoverageResult(0, 0, 'withAltText');
        }

        $query = Asset::find()
            ->kind(Asset::KIND_IMAGE)
            ->andWhere(['not', ['assets.alt' => null]])
            ->andWhere(['!=', 'assets.alt', '']);

        if ($volumeIds !== null) {
            if (empty($volumeIds)) {
                return $this->buildCoverageResult(0, $total, 'withAltText');
            }

            $query->volumeId($volumeIds);
        }

        $withAltText = (int) $query->count();

        return $this->buildCoverageResult($withAltText, $total, 'withAltText');
    }

    /**
     * Get focal point coverage using Craft's native focalPoint field.
     *
     * Counts all images in enabled volumes with an explicitly set focal point,
     * regardless of Lens analysis status. Lens auto-applies its detected focal
     * point to the native Craft field at analysis time, so this reflects the
     * real state of the library.
     *
     * @return array{percentage: float, withFocalPoint: int, total: int}
     */
    public function getFocalPointCoverage(): array
    {
        $volumeIds = $this->getEnabledVolumeIds();
        $total = $this->getTotalImageCount($volumeIds);

        if ($total === 0) {
            return $this->buildCoverageResult(0, 0, 'withFocalPoint');
        }

        $query = Asset::find()
            ->kind(Asset::KIND_IMAGE)
            ->andWhere(['not', ['assets.focalPoint' => null]]);

        if ($volumeIds !== null) {
            if (empty($volumeIds)) {
                return $this->buildCoverageResult(0, $total, 'withFocalPoint');
            }
            $query->volumeId($volumeIds);
        }

        $withFocalPoint = (int) $query->count();

        return $this->buildCoverageResult($withFocalPoint, $total, 'withFocalPoint');
    }

    /**
     * Get recent activity feed items for dashboard.
     *
     * @return array<array{type: string, statusLabel: string, assetId: int|null, assetTitle: string, assetUrl: string|null, thumbnailUrl: string|null, timestamp: string, timeAgo: string}>
     */
    public function getRecentActivity(int $limit = 10): array
    {
        $plugin = Plugin::getInstance();
        $isReviewActive = $plugin->getIsPro() && $plugin->getSettings()->requireReviewBeforeApply;

        $statusTypeMap = [
            AnalysisStatus::Completed->value => 'analyzed',
            AnalysisStatus::Failed->value => 'failed',
        ];

        if ($isReviewActive) {
            $statusTypeMap[AnalysisStatus::PendingReview->value] = 'pending_review';
            $statusTypeMap[AnalysisStatus::Approved->value] = 'approved';
            $statusTypeMap[AnalysisStatus::Rejected->value] = 'rejected';
        } else {
            $statusTypeMap[AnalysisStatus::PendingReview->value] = 'analyzed';
            $statusTypeMap[AnalysisStatus::Approved->value] = 'analyzed';
            $statusTypeMap[AnalysisStatus::Rejected->value] = 'analyzed';
        }

        $records = AssetAnalysisRecord::find()
            ->where(['in', 'status', array_keys($statusTypeMap)])
            ->orderBy(['dateUpdated' => SORT_DESC])
            ->limit($limit)
            ->all();

        $assetIds = array_filter(array_map(fn($r) => $r->assetId, $records));
        $assets = !empty($assetIds)
            ? Asset::find()->id($assetIds)->indexBy('id')->all()
            : [];

        $activities = [];

        foreach ($records as $record) {
            $asset = $assets[$record->assetId] ?? null;

            $dateUpdated = $record->dateUpdated;
            if ($dateUpdated instanceof DateTime) {
                $timestamp = $dateUpdated;
            } else {
                $timestamp = new DateTime($dateUpdated, new \DateTimeZone('UTC'));
            }

            $thumbnailUrl = $asset !== null
                ? Craft::$app->getAssets()->getThumbUrl($asset, 34, 34)
                : null;

            $activities[] = [
                'type' => $statusTypeMap[$record->status] ?? 'analyzed',
                'statusLabel' => ($statusTypeMap[$record->status] ?? 'analyzed') === 'analyzed'
                    ? AnalysisStatus::Completed->label()
                    : AnalysisStatus::from($record->status)->label(),
                'assetId' => $record->assetId,
                'assetTitle' => $asset?->title ?? Craft::t('lens', 'Deleted asset'),
                'assetUrl' => $asset?->getCpEditUrl(),
                'thumbnailUrl' => $thumbnailUrl,
                'timestamp' => $timestamp->format('c'),
                'timeAgo' => $this->formatTimeAgo($timestamp),
            ];
        }

        return $activities;
    }

    /**
     * Format a timestamp as a human-readable "time ago" string.
     */
    private function formatTimeAgo(DateTime $timestamp): string
    {
        $now = new DateTime('now', $timestamp->getTimezone());
        $diff = $now->diff($timestamp);

        if ($diff->days > 0) {
            return $diff->days === 1
                ? Craft::t('lens', '1 day ago')
                : Craft::t('lens', '{count} days ago', ['count' => $diff->days]);
        }
        if ($diff->h > 0) {
            return $diff->h === 1
                ? Craft::t('lens', '1 hour ago')
                : Craft::t('lens', '{count} hours ago', ['count' => $diff->h]);
        }
        if ($diff->i > 0) {
            return $diff->i === 1
                ? Craft::t('lens', '1 min ago')
                : Craft::t('lens', '{count} mins ago', ['count' => $diff->i]);
        }

        return Craft::t('lens', 'just now');
    }

    /**
     * Get this month's usage summary for the collapsed usage section.
     *
     * @return array{assetsProcessed: int, totalCost: float, avgCostPerAsset: float}
     */
    public function getMonthlyUsageSummary(): array
    {
        return $this->getUsageSummaryForPeriod(
            (new DateTime('first day of this month'))->format('Y-m-d 00:00:00'),
        );
    }

    /**
     * Get last month's usage summary for comparison.
     *
     * @return array{assetsProcessed: int, totalCost: float, avgCostPerAsset: float}
     */
    public function getLastMonthUsageSummary(): array
    {
        return $this->getUsageSummaryForPeriod(
            (new DateTime('first day of last month'))->format('Y-m-d 00:00:00'),
            (new DateTime('last day of last month'))->format('Y-m-d 23:59:59'),
        );
    }

    /**
     * Get monthly usage history for the last N months (including current).
     * Returns months in chronological order, skipping months with no activity.
     *
     * @return array<array{label: string, assetsProcessed: int, totalCost: float}>
     */
    public function getMonthlyUsageHistory(int $months = 6): array
    {
        $results = [];
        $now = new DateTime();

        for ($i = $months - 1; $i >= 0; $i--) {
            $date = (clone $now)->modify("-{$i} months");
            $from = (new DateTime($date->format('Y-m-01')))->format('Y-m-d 00:00:00');

            if ($i === 0) {
                $to = null;
            } else {
                $lastDay = (new DateTime($date->format('Y-m-t')));
                $to = $lastDay->format('Y-m-d 23:59:59');
            }

            $summary = $this->getUsageSummaryForPeriod($from, $to);

            if ($summary['assetsProcessed'] > 0) {
                $results[] = [
                    'label' => $date->format('M Y'),
                    'assetsProcessed' => $summary['assetsProcessed'],
                    'totalCost' => $summary['totalCost'],
                ];
            }
        }

        return $results;
    }

    /**
     * Get usage summary for a date range.
     *
     * @return array{assetsProcessed: int, totalCost: float, avgCostPerAsset: float}
     */
    private function getUsageSummaryForPeriod(string $from, ?string $to = null): array
    {
        $query = AssetAnalysisRecord::find()
            ->select([
                'COUNT(*) as count',
                'SUM(actualCost) as totalCost',
            ])
            ->where(['in', 'status', AnalysisStatus::processedValues()])
            ->andWhere(['>=', 'processedAt', $from]);

        if ($to !== null) {
            $query->andWhere(['<=', 'processedAt', $to]);
        }

        $result = $query->asArray()->one();

        $count = (int) ($result['count'] ?? 0);
        $totalCost = (float) ($result['totalCost'] ?? 0.0);

        return [
            'assetsProcessed' => $count,
            'totalCost' => $totalCost,
            'avgCostPerAsset' => $count > 0 ? $totalCost / $count : 0.0,
        ];
    }

    /**
     * Get all-time usage totals.
     *
     * @return array{totalAssets: int, totalCost: float}
     */
    public function getAllTimeUsage(): array
    {
        $result = AssetAnalysisRecord::find()
            ->select([
                'COUNT(*) as count',
                'SUM(actualCost) as totalCost',
            ])
            ->where(['in', 'status', AnalysisStatus::processedValues()])
            ->asArray()
            ->one();

        return [
            'totalAssets' => (int) ($result['count'] ?? 0),
            'totalCost' => (float) ($result['totalCost'] ?? 0.0),
        ];
    }

    /**
     * Cost projection for remaining unprocessed assets. Returns the per-asset
     * estimate from the PricingService model table and the total for the
     * given count.
     *
     * @return array{remainingAssets: int, estimatedCost: float, avgCostPerAsset: float}
     */
    public function getCostProjection(?int $remainingAssets = null): array
    {
        $remainingAssets ??= $this->getUnprocessedCount();
        $avgCost = Plugin::getInstance()->pricing->estimateCostPerAssetForCurrentProvider();

        return [
            'remainingAssets' => $remainingAssets,
            'estimatedCost' => $remainingAssets * $avgCost,
            'avgCostPerAsset' => $avgCost,
        ];
    }

    /**
     * Get all items requiring user attention with counts and links.
     * Returns only items with count > 0.
     * Order: Pending Review, Failed, NSFW, Watermarked, quality issues.
     */
    public function getAttentionItems(?array $overviewStats = null): array
    {
        $plugin = Plugin::getInstance();
        $isPro = $plugin->getIsPro();
        $overview = $overviewStats ?? $this->getOverviewStats();
        $nsfwCount = $this->getNsfwFlaggedCount();
        $watermarkedCount = $this->getWatermarkedCount();
        $qualityIssues = $this->getQualityIssueCounts();

        $items = [];

        if ($overview['pendingReview'] > 0 && $isPro && $plugin->getSettings()->requireReviewBeforeApply) {
            $items[] = [
                'type' => 'pending_review',
                'label' => Craft::t('lens', 'Pending Review'),
                'count' => $overview['pendingReview'],
                'url' => 'lens/review',
                'color' => 'blue',
                'icon' => 'eye',
            ];
        }

        if ($overview['failed'] > 0) {
            $items[] = [
                'type' => 'failed',
                'label' => Craft::t('lens', 'Failed Analyses'),
                'count' => $overview['failed'],
                'url' => 'assets?source=lens:failed',
                'color' => 'red',
                'icon' => 'triangle-exclamation',
            ];
        }

        if ($nsfwCount > 0) {
            $items[] = [
                'type' => 'nsfw_flagged',
                'label' => Craft::t('lens', 'NSFW Flagged'),
                'count' => $nsfwCount,
                'url' => 'assets?source=lens:nsfw-flagged',
                'color' => 'red',
                'icon' => 'triangle-exclamation',
            ];
        }

        if ($watermarkedCount > 0) {
            $items[] = [
                'type' => 'watermarked',
                'label' => Craft::t('lens', 'Watermarked'),
                'count' => $watermarkedCount,
                'url' => 'assets?source=lens:has-watermark',
                'color' => 'amber',
                'icon' => 'stamp',
            ];
        }

        if ($qualityIssues['blurry'] > 0) {
            $items[] = [
                'type' => 'blurry',
                'label' => Craft::t('lens', 'Blurry'),
                'count' => $qualityIssues['blurry'],
                'url' => 'assets?source=lens:all&lensFilter=blurry',
                'color' => 'amber',
                'icon' => 'eye',
            ];
        }

        if ($qualityIssues['tooDark'] > 0) {
            $items[] = [
                'type' => 'too_dark',
                'label' => Craft::t('lens', 'Too Dark'),
                'count' => $qualityIssues['tooDark'],
                'url' => 'assets?source=lens:all&lensFilter=too-dark',
                'color' => 'amber',
                'icon' => 'sun',
            ];
        }

        if ($qualityIssues['tooBright'] > 0) {
            $items[] = [
                'type' => 'too_bright',
                'label' => Craft::t('lens', 'Too Bright'),
                'count' => $qualityIssues['tooBright'],
                'url' => 'assets?source=lens:all&lensFilter=too-bright',
                'color' => 'amber',
                'icon' => 'sun',
            ];
        }

        if ($qualityIssues['lowContrast'] > 0) {
            $items[] = [
                'type' => 'low_contrast',
                'label' => Craft::t('lens', 'Low Contrast'),
                'count' => $qualityIssues['lowContrast'],
                'url' => 'assets?source=lens:all&lensFilter=low-contrast',
                'color' => 'amber',
                'icon' => 'circle-half-stroke',
            ];
        }

        if ($isPro) {
            $duplicateCount = $plugin->duplicateDetection->getUnresolvedDuplicateCount();

            if ($duplicateCount > 0) {
                $items[] = [
                    'type' => 'has_duplicates',
                    'label' => Craft::t('lens', 'Has Duplicates'),
                    'count' => $duplicateCount,
                    'url' => 'assets?source=lens:has-duplicates',
                    'color' => 'amber',
                    'icon' => 'clone',
                ];
            }
        }

        return $items;
    }

    /**
     * Get counts of specific quality issues across analyzed assets.
     *
     * @return array{blurry: int, tooDark: int, tooBright: int, lowContrast: int}
     */
    public function getQualityIssueCounts(): array
    {
        if (!QualitySupport::isAvailable()) {
            return ['blurry' => 0, 'tooDark' => 0, 'tooBright' => 0, 'lowContrast' => 0];
        }

        $volumeIds = $this->getEnabledVolumeIds();

        $query = (new Query())
            ->select([
                new Expression(
                    'SUM(CASE WHEN [[sharpnessScore]] IS NOT NULL AND [[sharpnessScore]] < :blur THEN 1 ELSE 0 END) as blurry',
                    [':blur' => ImageMetricsAnalyzer::SHARPNESS_BLURRY]
                ),
                new Expression(
                    'SUM(CASE WHEN [[exposureScore]] IS NOT NULL AND [[exposureScore]] < :dark AND [[shadowClipRatio]] > :shadowClip THEN 1 ELSE 0 END) as tooDark',
                    [
                        ':dark' => ImageMetricsAnalyzer::BRIGHTNESS_DARK_MEDIAN,
                        ':shadowClip' => ImageMetricsAnalyzer::SHADOW_CLIP_RATIO,
                    ]
                ),
                new Expression(
                    'SUM(CASE WHEN [[exposureScore]] IS NOT NULL AND [[exposureScore]] > :bright AND [[highlightClipRatio]] > :highlightClip THEN 1 ELSE 0 END) as tooBright',
                    [
                        ':bright' => ImageMetricsAnalyzer::BRIGHTNESS_BRIGHT_MEDIAN,
                        ':highlightClip' => ImageMetricsAnalyzer::HIGHLIGHT_CLIP_RATIO,
                    ]
                ),
                new Expression(
                    'SUM(CASE WHEN [[noiseScore]] IS NOT NULL AND [[noiseScore]] < :contrast THEN 1 ELSE 0 END) as lowContrast',
                    [':contrast' => ImageMetricsAnalyzer::CONTRAST_LOW]
                ),
            ])
            ->from(Install::TABLE_ASSET_ANALYSES)
            ->where(['in', 'status', AnalysisStatus::processedValues()]);

        if ($volumeIds !== null) {
            if (empty($volumeIds)) {
                return ['blurry' => 0, 'tooDark' => 0, 'tooBright' => 0, 'lowContrast' => 0];
            }
            $query->andWhere(['in', 'assetId', $this->buildVolumeSubquery($volumeIds)]);
        }

        $result = $query->one();

        return [
            'blurry' => (int) ($result['blurry'] ?? 0),
            'tooDark' => (int) ($result['tooDark'] ?? 0),
            'tooBright' => (int) ($result['tooBright'] ?? 0),
            'lowContrast' => (int) ($result['lowContrast'] ?? 0),
        ];
    }

    /**
     * Get total token usage across all analyses.
     *
     * @return array{totalTokens: int, inputTokens: int, outputTokens: int}
     */
    public function getTokenUsage(): array
    {
        $result = AssetAnalysisRecord::find()
            ->select([
                'SUM(inputTokens) as inputTokens',
                'SUM(outputTokens) as outputTokens',
            ])
            ->where(['in', 'status', AnalysisStatus::processedValues()])
            ->asArray()
            ->one();

        $input = (int) ($result['inputTokens'] ?? 0);
        $output = (int) ($result['outputTokens'] ?? 0);

        return [
            'totalTokens' => $input + $output,
            'inputTokens' => $input,
            'outputTokens' => $output,
        ];
    }

    /**
     * Get cost/usage breakdown grouped by provider and model.
     *
     * @return array<array{provider: string, model: string, label: string, assets: int, cost: float}>
     */
    public function getProviderBreakdown(): array
    {
        $rows = AssetAnalysisRecord::find()
            ->select([
                'provider',
                'providerModel',
                'COUNT(*) as cnt',
                'SUM(actualCost) as totalCost',
            ])
            ->where(['in', 'status', AnalysisStatus::processedValues()])
            ->andWhere(['not', ['provider' => null]])
            ->groupBy(['provider', 'providerModel'])
            ->orderBy(['totalCost' => SORT_DESC])
            ->asArray()
            ->all();

        return array_map(fn(array $r) => [
            'provider' => $r['provider'],
            'model' => $r['providerModel'],
            'label' => AiProvider::from($r['provider'])->label()
                . ' / ' . ($r['providerModel'] ?? '?'),
            'assets' => (int) $r['cnt'],
            'cost' => (float) $r['totalCost'],
        ], $rows);
    }

    /**
     * Build a standardized coverage result array.
     *
     * @return array{percentage: float, total: int}
     */
    private function buildCoverageResult(int $matchingCount, int $total, string $key): array
    {
        return [
            'percentage' => $total > 0 ? round(($matchingCount / $total) * 100, 1) : 0.0,
            $key => $matchingCount,
            'total' => $total,
        ];
    }
}
