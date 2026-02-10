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
        $result = AssetAnalysisRecord::find()
            ->select([
                'COUNT(*) as total',
                'SUM(CASE WHEN status IN (\'' . implode("','", AnalysisStatus::analyzedValues()) . '\') THEN 1 ELSE 0 END) as analyzed',
                'SUM(CASE WHEN status = \'' . AnalysisStatus::Approved->value . '\' THEN 1 ELSE 0 END) as approved',
                'SUM(CASE WHEN status = \'' . AnalysisStatus::Rejected->value . '\' THEN 1 ELSE 0 END) as rejected',
                'SUM(CASE WHEN status = \'' . AnalysisStatus::PendingReview->value . '\' THEN 1 ELSE 0 END) as pendingReview',
                'SUM(CASE WHEN status = \'' . AnalysisStatus::Failed->value . '\' THEN 1 ELSE 0 END) as failed',
                'SUM(CASE WHEN status IN (\'' . implode("','", AnalysisStatus::analyzedValues()) . '\') AND (altText IS NULL OR altText = \'\') THEN 1 ELSE 0 END) as missingAltText',
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
            'missingAltText' => (int) ($result['missingAltText'] ?? 0),
            'totalCost' => $totalCost,
            'avgCostPerAsset' => $analyzed > 0 ? round($totalCost / $analyzed, 4) : 0.0,
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
     * @return array{percentage: float, withAltText: int, total: int}
     */
    public function getAltTextCoverage(?int $precomputedAnalyzedCount = null): array
    {
        $analyzedStatuses = AnalysisStatus::analyzedValues();

        $total = $precomputedAnalyzedCount ?? (int) AssetAnalysisRecord::find()
            ->where(['in', 'status', $analyzedStatuses])
            ->count();

        if ($total === 0) {
            return ['percentage' => 0.0, 'withAltText' => 0, 'total' => 0];
        }

        $withAltText = (int) AssetAnalysisRecord::find()
            ->where(['in', 'status', $analyzedStatuses])
            ->andWhere(['not', ['altText' => null]])
            ->andWhere(['!=', 'altText', ''])
            ->count();

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
        $analyzedStatuses = AnalysisStatus::analyzedValues();

        $total = $precomputedAnalyzedCount ?? (int) AssetAnalysisRecord::find()
            ->where(['in', 'status', $analyzedStatuses])
            ->count();

        if ($total === 0) {
            return ['percentage' => 0.0, 'withTags' => 0, 'total' => 0];
        }

        // Count distinct analysisIds that have at least one tag
        $withTags = (int) (new Query())
            ->select(['COUNT(DISTINCT [[tags.analysisId]])'])
            ->from(Install::TABLE_ASSET_TAGS . ' tags')
            ->innerJoin(Install::TABLE_ASSET_ANALYSES . ' lens', '[[tags.analysisId]] = [[lens.id]]')
            ->where(['in', 'lens.status', $analyzedStatuses])
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
        $analyzedStatuses = AnalysisStatus::analyzedValues();

        // Only count assets that have a quality score (not null)
        $total = $precomputedScoredCount ?? (int) AssetAnalysisRecord::find()
            ->where(['in', 'status', $analyzedStatuses])
            ->andWhere(['not', ['overallQualityScore' => null]])
            ->count();

        if ($total === 0) {
            return ['percentage' => 0.0, 'highQuality' => 0, 'total' => 0];
        }

        $highQuality = (int) AssetAnalysisRecord::find()
            ->where(['in', 'status', $analyzedStatuses])
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
     * @return array<array{type: string, message: string, assetId: int|null, assetFilename: string|null, assetUrl: string|null, timestamp: string, timeAgo: string}>
     */
    public function getRecentActivity(int $limit = 10): array
    {
        $records = AssetAnalysisRecord::find()
            ->where(['in', 'status', [
                AnalysisStatus::Completed->value,
                AnalysisStatus::Approved->value,
                AnalysisStatus::Failed->value,
            ]])
            ->andWhere(['not', ['processedAt' => null]])
            ->orderBy(['processedAt' => SORT_DESC])
            ->limit($limit)
            ->all();

        $assetIds = array_filter(array_map(fn($r) => $r->assetId, $records));
        $assets = !empty($assetIds)
            ? Asset::find()->id($assetIds)->indexBy('id')->all()
            : [];

        $activities = [];

        foreach ($records as $record) {
            $asset = $assets[$record->assetId] ?? null;
            $processedAt = $record->processedAt;

            if (!$processedAt) {
                continue;
            }

            $timestamp = $processedAt instanceof DateTime
                ? $processedAt
                : new DateTime($processedAt);

            $activities[] = [
                'type' => $record->status === AnalysisStatus::Failed->value ? 'failed' : 'analyzed',
                'message' => $record->status === AnalysisStatus::Failed->value
                    ? Craft::t('lens', '{filename} failed to analyze', ['filename' => $asset?->filename ?? 'Unknown'])
                    : Craft::t('lens', '{filename} analyzed', ['filename' => $asset?->filename ?? 'Unknown']),
                'assetId' => $record->assetId,
                'assetFilename' => $asset?->filename,
                'assetUrl' => $asset?->getCpEditUrl(),
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
        $startOfMonth = (new DateTime('first day of this month'))->format('Y-m-d 00:00:00');

        $result = AssetAnalysisRecord::find()
            ->select([
                'COUNT(*) as count',
                'SUM(actualCost) as totalCost',
            ])
            ->where(['in', 'status', AnalysisStatus::analyzedValues()])
            ->andWhere(['>=', 'processedAt', $startOfMonth])
            ->asArray()
            ->one();

        $count = (int) ($result['count'] ?? 0);
        $totalCost = (float) ($result['totalCost'] ?? 0.0);

        return [
            'assetsProcessed' => $count,
            'totalCost' => $totalCost,
            'avgCostPerAsset' => $count > 0 ? $totalCost / $count : 0.0,
        ];
    }

    /**
     * Get last month's usage summary for comparison.
     *
     * @return array{assetsProcessed: int, totalCost: float, avgCostPerAsset: float}
     */
    public function getLastMonthUsageSummary(): array
    {
        $startOfLastMonth = (new DateTime('first day of last month'))->format('Y-m-d 00:00:00');
        $endOfLastMonth = (new DateTime('last day of last month'))->format('Y-m-d 23:59:59');

        $result = AssetAnalysisRecord::find()
            ->select([
                'COUNT(*) as count',
                'SUM(actualCost) as totalCost',
            ])
            ->where(['in', 'status', AnalysisStatus::analyzedValues()])
            ->andWhere(['>=', 'processedAt', $startOfLastMonth])
            ->andWhere(['<=', 'processedAt', $endOfLastMonth])
            ->asArray()
            ->one();

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
            ->where(['in', 'status', AnalysisStatus::analyzedValues()])
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
     * Order: Pending Review, Failed, NSFW, Watermarked, Duplicates, Missing Alt Text
     */
    public function getAttentionItems(?array $overviewStats = null): array
    {
        $plugin = Plugin::getInstance();
        $overview = $overviewStats ?? $this->getOverviewStats();

        $items = [];

        // 1. Pending Review
        if ($overview['pendingReview'] > 0) {
            $items[] = [
                'type' => 'pending_review',
                'label' => Craft::t('lens', 'Pending Review'),
                'count' => $overview['pendingReview'],
                'url' => 'lens/review',
                'color' => 'blue',
                'icon' => 'eye',
            ];
        }

        // 2. Failed Analyses
        if ($overview['failed'] > 0) {
            $items[] = [
                'type' => 'failed',
                'label' => Craft::t('lens', 'Failed Analyses'),
                'count' => $overview['failed'],
                'url' => 'assets?lensStatus=failed',
                'color' => 'red',
                'icon' => 'triangle-exclamation',
            ];
        }

        // 3. NSFW Flagged
        $nsfwCount = $this->getNsfwFlaggedCount();
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

        // 4. Watermarked Images
        $watermarkedCount = $this->getWatermarkedCount();
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

        // 5. Duplicates
        $duplicateCount = $plugin->duplicateDetection->getUnresolvedDuplicateCount();
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

        // 6. Missing Alt Text
        if ($overview['missingAltText'] > 0) {
            $items[] = [
                'type' => 'missing_alt',
                'label' => Craft::t('lens', 'Missing Alt Text'),
                'count' => $overview['missingAltText'],
                'url' => 'lens/search?missingAltText=1',
                'color' => 'amber',
                'icon' => 'text',
            ];
        }

        return $items;
    }
}
