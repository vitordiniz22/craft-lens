<?php

declare(strict_types=1);

namespace vitordiniz22\craftlens\services;

use Craft;
use craft\elements\Asset;
use DateTime;
use vitordiniz22\craftlens\enums\AnalysisStatus;
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
    /**
     * Get overview statistics for the dashboard.
     */
    public function getOverviewStats(): array
    {
        $analyzedStatuses = AnalysisStatus::analyzedValues();
        $processedStatuses = AnalysisStatus::processedValues();

        $result = AssetAnalysisRecord::find()
            ->select([
                'COUNT(*) as total',
                new Expression(
                    'SUM(CASE WHEN status IN (:analyzed1, :analyzed2) THEN 1 ELSE 0 END) as analyzed',
                    [':analyzed1' => $analyzedStatuses[0], ':analyzed2' => $analyzedStatuses[1]]
                ),
                new Expression(
                    'SUM(CASE WHEN status IN (:proc1, :proc2, :proc3, :proc4) THEN 1 ELSE 0 END) as processed',
                    [
                        ':proc1' => $processedStatuses[0],
                        ':proc2' => $processedStatuses[1],
                        ':proc3' => $processedStatuses[2],
                        ':proc4' => $processedStatuses[3],
                    ]
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
                new Expression(
                    "SUM(CASE WHEN status IN (:analyzed3, :analyzed4) AND (altText IS NULL OR altText = '') THEN 1 ELSE 0 END) as missingAltText",
                    [':analyzed3' => $analyzedStatuses[0], ':analyzed4' => $analyzedStatuses[1]]
                ),
                'SUM(actualCost) as totalCost',
            ])
            ->asArray()
            ->one();

        $analyzed = (int) ($result['analyzed'] ?? 0);
        $processed = (int) ($result['processed'] ?? 0);
        $totalCost = (float) ($result['totalCost'] ?? 0.0);

        return [
            'totalImages' => $this->getTotalImageCount(),
            'analyzed' => $analyzed,
            'unprocessed' => $this->getUnprocessedCount(),
            'pendingReview' => (int) ($result['pendingReview'] ?? 0),
            'approved' => (int) ($result['approved'] ?? 0),
            'rejected' => (int) ($result['rejected'] ?? 0),
            'failed' => (int) ($result['failed'] ?? 0),
            'missingAltText' => (int) ($result['missingAltText'] ?? 0),
            'totalCost' => $totalCost,
            'avgCostPerAsset' => $processed > 0 ? round($totalCost / $processed, 4) : 0.0,
        ];
    }

    /**
     * Get top tags by frequency.
     */
    public function getTopTags(int $limit = 10): array
    {
        return Plugin::getInstance()->tagAggregation->getTagCounts($limit, 'count');
    }

    private function getTotalImageCount(): int
    {
        return (int) Asset::find()
            ->kind(Asset::KIND_IMAGE)
            ->count();
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
     * Get average cost per asset.
     */
    public function getAverageCostPerAsset(): float
    {
        return (float) (AssetAnalysisRecord::find()
            ->where(['not', ['actualCost' => null]])
            ->average('actualCost') ?? 0.0);
    }

    /**
     * Get count of assets flagged as NSFW (nsfwScore >= 0.5 or isFlaggedNsfw = true).
     */
    public function getNsfwFlaggedCount(): int
    {
        return (int) AssetAnalysisRecord::find()
            ->where(['in', 'status', AnalysisStatus::analyzedValues()])
            ->andWhere([
                'or',
                ['>=', 'nsfwScore', 0.5],
                ['isFlaggedNsfw' => true],
            ])
            ->count();
    }

    /**
     * Get count of assets with watermarks detected.
     */
    public function getWatermarkedCount(): int
    {
        return (int) AssetAnalysisRecord::find()
            ->where(['in', 'status', AnalysisStatus::analyzedValues()])
            ->andWhere(['hasWatermark' => true])
            ->count();
    }

    /**
     * Get alt text coverage statistics.
     *
     * When $siteId is provided, counts coverage from the per-site content table
     * for that specific site. Otherwise counts from the main analysis record
     * (primary site).
     *
     * @return array{percentage: float, withAltText: int, total: int}
     */
    public function getAltTextCoverage(?int $precomputedAnalyzedCount = null, ?int $siteId = null): array
    {
        $metadataStatuses = AnalysisStatus::withMetadataValues();

        $total = $precomputedAnalyzedCount ?? (int) AssetAnalysisRecord::find()
            ->where(['in', 'status', $metadataStatuses])
            ->count();

        if ($total === 0) {
            return ['percentage' => 0.0, 'withAltText' => 0, 'total' => 0];
        }

        if ($siteId !== null) {
            $withAltText = (int) (new Query())
                ->select(['COUNT(*)'])
                ->from(Install::TABLE_ANALYSIS_SITE_CONTENT . ' sc')
                ->innerJoin(Install::TABLE_ASSET_ANALYSES . ' a', '[[sc.analysisId]] = [[a.id]]')
                ->where(['in', 'a.status', $metadataStatuses])
                ->andWhere(['sc.siteId' => $siteId])
                ->andWhere(['not', ['sc.altText' => null]])
                ->andWhere(['!=', 'sc.altText', ''])
                ->scalar();
        } else {
            $withAltText = (int) AssetAnalysisRecord::find()
                ->where(['in', 'status', $metadataStatuses])
                ->andWhere(['not', ['altText' => null]])
                ->andWhere(['!=', 'altText', ''])
                ->count();
        }

        return [
            'percentage' => round(($withAltText / $total) * 100, 1),
            'withAltText' => $withAltText,
            'total' => $total,
        ];
    }

    /**
     * Get percentage of analyzed assets that have at least one tag.
     *
     * @return array{percentage: float, withTags: int, total: int}
     */
    public function getTaggedPercentage(?int $precomputedAnalyzedCount = null): array
    {
        $metadataStatuses = AnalysisStatus::withMetadataValues();

        $total = $precomputedAnalyzedCount ?? (int) AssetAnalysisRecord::find()
            ->where(['in', 'status', $metadataStatuses])
            ->count();

        if ($total === 0) {
            return ['percentage' => 0.0, 'withTags' => 0, 'total' => 0];
        }

        // Count distinct analysisIds that have at least one tag
        $withTags = (int) (new Query())
            ->select(['COUNT(DISTINCT [[tags.analysisId]])'])
            ->from(Install::TABLE_ASSET_TAGS . ' tags')
            ->innerJoin(Install::TABLE_ASSET_ANALYSES . ' lens', '[[tags.analysisId]] = [[lens.id]]')
            ->where(['in', 'lens.status', $metadataStatuses])
            ->scalar();

        return [
            'percentage' => round(($withTags / $total) * 100, 1),
            'withTags' => (int) $withTags,
            'total' => $total,
        ];
    }

    /**
     * Get percentage of analyzed assets with high quality (overallQualityScore >= 0.7).
     *
     * @return array{percentage: float, highQuality: int, total: int}
     */
    public function getHighQualityPercentage(?int $precomputedScoredCount = null): array
    {
        $metadataStatuses = AnalysisStatus::withMetadataValues();

        // Only count assets that have a quality score (not null)
        $total = $precomputedScoredCount ?? (int) AssetAnalysisRecord::find()
            ->where(['in', 'status', $metadataStatuses])
            ->andWhere(['not', ['overallQualityScore' => null]])
            ->count();

        if ($total === 0) {
            return ['percentage' => 0.0, 'highQuality' => 0, 'total' => 0];
        }

        $highQuality = (int) AssetAnalysisRecord::find()
            ->where(['in', 'status', $metadataStatuses])
            ->andWhere(['>=', 'overallQualityScore', 0.7])
            ->count();

        return [
            'percentage' => round(($highQuality / $total) * 100, 1),
            'highQuality' => $highQuality,
            'total' => $total,
        ];
    }

    /**
     * Get recent activity feed items for dashboard.
     *
     * @return array<array{type: string, statusLabel: string, assetId: int|null, assetFilename: string|null, assetUrl: string|null, thumbnailUrl: string|null, timestamp: string, timeAgo: string}>
     */
    public function getRecentActivity(int $limit = 10): array
    {
        $statusTypeMap = [
            AnalysisStatus::Completed->value => 'analyzed',
            AnalysisStatus::Approved->value => 'approved',
            AnalysisStatus::Rejected->value => 'rejected',
            AnalysisStatus::Failed->value => 'failed',
            AnalysisStatus::PendingReview->value => 'pending_review',
        ];

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
            $timestamp = $dateUpdated instanceof DateTime
                ? $dateUpdated
                : new DateTime($dateUpdated);

            $thumbnailUrl = $asset !== null
                ? Craft::$app->getAssets()->getThumbUrl($asset, 34, 34)
                : null;

            $activities[] = [
                'type' => $statusTypeMap[$record->status] ?? 'analyzed',
                'statusLabel' => AnalysisStatus::from($record->status)->label(),
                'assetId' => $record->assetId,
                'assetFilename' => $asset?->filename ?? Craft::t('lens', 'Deleted asset'),
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
        $now = new DateTime();
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
     * Get cost projection for remaining unprocessed assets.
     *
     * @return array{remainingAssets: int, estimatedCost: float}
     */
    public function getCostProjection(?int $remainingAssets = null, ?float $avgCost = null): array
    {
        $remainingAssets ??= $this->getUnprocessedCount();
        $avgCost ??= $this->getAverageCostPerAsset();

        return [
            'remainingAssets' => $remainingAssets,
            'estimatedCost' => $remainingAssets * $avgCost,
        ];
    }

    /**
     * Get all items requiring user attention with counts and links.
     * Returns only items with count > 0.
     * Order: Pending Review, Failed, NSFW, Watermarked, Duplicates
     */
    public function getAttentionItems(?array $overviewStats = null): array
    {
        $plugin = Plugin::getInstance();
        $overview = $overviewStats ?? $this->getOverviewStats();
        $nsfwCount = $this->getNsfwFlaggedCount();
        $watermarkedCount = $this->getWatermarkedCount();
        $duplicateCount = $plugin->duplicateDetection->getUnresolvedDuplicateCount();

        $items = [];

        if ($overview['pendingReview'] > 0) {
            $items[] = [
                'type' => 'pending_review',
                'label' => Craft::t('lens', 'Pending Review'),
                'count' => $overview['pendingReview'],
                'url' => 'lens/search?status=' . AnalysisStatus::PendingReview->value,
                'color' => 'blue',
                'icon' => 'eye',
            ];
        }

        if ($overview['failed'] > 0) {
            $items[] = [
                'type' => 'failed',
                'label' => Craft::t('lens', 'Failed Analyses'),
                'count' => $overview['failed'],
                'url' => 'lens/search?status=' . AnalysisStatus::Failed->value,
                'color' => 'red',
                'icon' => 'triangle-exclamation',
            ];
        }

        if ($nsfwCount > 0) {
            $items[] = [
                'type' => 'nsfw_flagged',
                'label' => Craft::t('lens', 'NSFW Flagged'),
                'count' => $nsfwCount,
                'url' => 'lens/search?nsfwFlagged=1',
                'color' => 'red',
                'icon' => 'triangle-exclamation',
            ];
        }

        if ($watermarkedCount > 0) {
            $items[] = [
                'type' => 'watermarked',
                'label' => Craft::t('lens', 'Watermarked'),
                'count' => $watermarkedCount,
                'url' => 'lens/search?hasWatermark=1',
                'color' => 'amber',
                'icon' => 'stamp',
            ];
        }

        if ($duplicateCount > 0) {
            $items[] = [
                'type' => 'duplicates',
                'label' => Craft::t('lens', 'Duplicates'),
                'count' => $duplicateCount,
                'url' => 'lens/duplicates',
                'color' => 'amber',
                'icon' => 'copy',
            ];
        }

        return $items;
    }
}
