<?php

declare(strict_types=1);

namespace vitordiniz22\craftlens\services;

use Craft;
use craft\elements\Asset;
use vitordiniz22\craftlens\conditions\FileTooLargeConditionRule;
use vitordiniz22\craftlens\enums\AnalysisStatus;
use vitordiniz22\craftlens\enums\LogCategory;
use vitordiniz22\craftlens\enums\QuickFilter;
use vitordiniz22\craftlens\helpers\ImageMetricsAnalyzer;
use vitordiniz22\craftlens\helpers\Logger;
use vitordiniz22\craftlens\migrations\Install;
use vitordiniz22\craftlens\Plugin;
use yii\base\Component;
use yii\db\Expression;
use yii\db\Query;

/**
 * Service for searching assets using Craft fields and Lens metadata.
 *
 * Search priority:
 * - Text search: Craft fields (title, native alt, mapped alt field) + extracted text from Lens
 * - Tag filtering: Craft Tag relations (if mapped) or Lens table JSON
 * - AI metadata (status, confidence, faces, NSFW): Always from Lens table
 */
class SearchService extends Component
{
    private const DEFAULT_LIMIT = 50;
    private const NATIVE_SEARCH_LIMIT = 500;
    private const DEFAULT_COLOR_TOLERANCE = 30;


    /**
     * Search assets using combined filters.
     *
     * @param array{
     *     query?: string,
     *     tags?: string[],
     *     tagOperator?: 'and'|'or',
     *     status?: string[],
     *     containsPeople?: bool,
     *     faceCountMin?: int,
     *     faceCountMax?: int,
     *     confidenceMin?: float,
     *     confidenceMax?: float,
     *     nsfwFlagged?: bool,
     *     offset?: int,
     *     limit?: int,
     * } $filters
     * @return array{assets: Asset[], total: int, offset: int, limit: int}
     */
    public function search(array $filters): array
    {
        $limit = $filters['limit'] ?? self::DEFAULT_LIMIT;
        $offset = $filters['offset'] ?? 0;

        // BM25 index path: resolve ranked IDs first when a text query is present.
        $rankedAssetIds = null;
        $hasRankedOrder = false;
        $rawQuery = $filters['query'] ?? null;
        $hasTextQuery = $rawQuery !== null && trim($rawQuery) !== '';

        if ($hasTextQuery) {
            $terms = $this->parseSearchTerms($rawQuery);

            if (!empty($terms)) {
                $scores = Plugin::getInstance()->searchIndex->search($terms);

                if (!empty($scores)) {
                    // Index returned matches — use BM25 relevance ordering.
                    $rankedAssetIds = array_keys($scores);
                    $hasRankedOrder = true;
                }
            }

            // Also query Craft's native search index to surface assets that
            // don't have Lens analysis (videos, documents, etc.) by their
            // title, filename, extension, or native alt text.
            $craftNativeIds = $this->getCraftNativeSearchIds($rawQuery);

            if ($rankedAssetIds !== null) {
                $rankedAssetIds = array_values(array_unique(
                    array_merge($rankedAssetIds, $craftNativeIds)
                ));
            } elseif (!empty($craftNativeIds)) {
                $rankedAssetIds = $craftNativeIds;
                $hasRankedOrder = true;
            } else {
                // Both BM25 and Craft native returned zero — genuine zero results.
                Logger::info(LogCategory::AssetProcessing, 'Asset search executed (zero results)', context: [
                    'query' => $rawQuery,
                    'resultsCount' => 0,
                ]);

                return $this->emptyResult($offset, $limit);
            }
        }

        // Similarity path: resolve ranked IDs when similarTo is set.
        $similarAssetIds = null;
        $similarToAssetId = $filters['similarTo'] ?? null;

        if ($similarToAssetId !== null) {
            $similarAssetIds = Plugin::getInstance()
                ->duplicateDetection
                ->getSimilarAssetIds($similarToAssetId);

            if (empty($similarAssetIds)) {
                return $this->emptyResult($offset, $limit);
            }
        }

        $baseQuery = $this->buildMatchingQuery($filters, $rankedAssetIds, $similarAssetIds, $hasTextQuery);

        $total = (int) (clone $baseQuery)->count();

        if ($total === 0) {
            return $this->emptyResult($offset, $limit);
        }

        if ($hasRankedOrder && $rankedAssetIds !== null) {
            // Preserve relevance order (BM25 + Craft native) using MySQL FIELD().
            // When combined with similarTo, use only the intersected IDs.
            $orderIds = ($similarAssetIds !== null)
                ? array_values(array_intersect($rankedAssetIds, $similarAssetIds))
                : $rankedAssetIds;
            $fieldList = implode(',', array_map('intval', $orderIds));
            $paginatedIds = (clone $baseQuery)
                ->orderBy(new Expression('FIELD(assets.id, ' . $fieldList . ')'))
                ->offset($offset)
                ->limit($limit)
                ->column();
        } elseif ($similarAssetIds !== null) {
            // Similarity ordering: most similar first.
            $fieldList = implode(',', array_map('intval', $similarAssetIds));
            $paginatedIds = (clone $baseQuery)
                ->orderBy(new Expression('FIELD(assets.id, ' . $fieldList . ')'))
                ->offset($offset)
                ->limit($limit)
                ->column();
        } elseif (!empty($filters['hasDuplicates'])) {
            // Cluster-grouped ordering: group duplicate pairs together.
            // Fetch all matching IDs, compute transitive clusters via Union-Find,
            // then sort by cluster key (smallest ID in component) and asset ID.
            $allIds = array_map('intval', (clone $baseQuery)->column());
            $clusterKeys = Plugin::getInstance()
                ->duplicateDetection
                ->getClusterKeysForAssets($allIds);

            usort($allIds, function (int $a, int $b) use ($clusterKeys): int {
                $groupA = $clusterKeys[$a] ?? PHP_INT_MAX;
                $groupB = $clusterKeys[$b] ?? PHP_INT_MAX;
                return $groupA <=> $groupB ?: $a <=> $b;
            });

            $paginatedIds = array_slice($allIds, $offset, $limit);
        } else {
            $paginatedIds = (clone $baseQuery)
                ->orderBy(['MAX([[lens.processedAt]])' => SORT_DESC])
                ->offset($offset)
                ->limit($limit)
                ->column();
        }

        $paginatedIds = array_map('intval', $paginatedIds);

        $primarySiteId = Craft::$app->getSites()->getPrimarySite()->id;

        $assets = Asset::find()
            ->id($paginatedIds)
            ->siteId($primarySiteId)
            ->fixedOrder()
            ->all();

        Logger::info(LogCategory::AssetProcessing, 'Asset search executed', context: [
            'query' => $rawQuery,
            'hasRankedOrder' => $hasRankedOrder,
            'resultsCount' => $total,
        ]);

        return [
            'assets' => $assets,
            'total' => $total,
            'offset' => $offset,
            'limit' => $limit,
        ];
    }

    /**
     * Build the base query for matching assets. Returns a grouped query
     * selecting asset IDs that can be used for both counting and pagination.
     */
    /**
     * @return array{assets: list<never>, total: 0, offset: int, limit: int}
     */
    private function emptyResult(int $offset, int $limit): array
    {
        return ['assets' => [], 'total' => 0, 'offset' => $offset, 'limit' => $limit];
    }

    private function buildMatchingQuery(array $filters, ?array $rankedAssetIds = null, ?array $similarAssetIds = null, bool $hasTextQuery = false): Query
    {
        $query = (new Query())
            ->select(['assets.id'])
            ->from('{{%assets}} assets')
            ->innerJoin('{{%elements}} elements', '[[assets.id]] = [[elements.id]]')
            ->where(['elements.dateDeleted' => null])
            ->groupBy(['assets.id']);

        // Text-only searches may surface non-image assets (videos, documents)
        // matched by Craft's native search index. However, when Lens-specific
        // metadata filters are active (tags, quality, status, etc.), those
        // filters use NULL/absence checks that non-image assets would
        // incorrectly match (e.g., "no tags" would match every video).
        // Restrict to images unless the query is text-only.
        if (!$hasTextQuery || self::hasLensMetadataFilters($filters)) {
            $query->andWhere(['assets.kind' => Asset::KIND_IMAGE]);
        }

        $query->leftJoin(Install::TABLE_ASSET_ANALYSES . ' lens', '[[assets.id]] = [[lens.assetId]]');

        if ($similarAssetIds !== null) {
            // Constrain to only assets similar to the target asset.
            if ($rankedAssetIds !== null) {
                // Intersect with ranked results when both are active.
                $intersected = array_values(array_intersect($rankedAssetIds, $similarAssetIds));
                $query->andWhere(empty($intersected) ? '1 = 0' : ['assets.id' => $intersected]);
            } else {
                $query->andWhere(['assets.id' => $similarAssetIds]);
            }
        }

        if ($rankedAssetIds !== null && $similarAssetIds === null) {
            // Ranked path (BM25 + Craft native): filter to pre-ranked IDs.
            $query->andWhere(['assets.id' => $rankedAssetIds]);
        }

        $this->applyTagFilters($query, $filters['tags'] ?? [], $filters['tagOperator'] ?? 'or');
        $this->applyStatusFilter($query, $filters['status'] ?? []);
        $this->applyPeopleFilter($query, $filters);
        $this->applyConfidenceFilter($query, $filters);
        $this->applyNsfwFilter($query, $filters);
        $this->applyDateFilter($query, $filters);
        $this->applyColorFilter($query, $filters);
        $this->applyNoTagsFilter($query, $filters);
        $this->applyDuplicatesFilter($query, $filters);
        $this->applyWatermarkFilter($query, $filters);
        $this->applyBrandLogoFilter($query, $filters);
        $this->applyFocalPointFilter($query, $filters);
        $this->applyMissingAltTextFilter($query, $filters);
        $this->applyUnprocessedFilter($query, $filters);
        $this->applyQualityIssuesFilter($query, $filters);
        $this->applyStandaloneQualityFilters($query, $filters);
        $this->applyHasTextInImageFilter($query, $filters);
        $this->applyVolumeFilter($query);

        return $query;
    }

    /**
     * Parse search query into individual terms.
     *
     * @return string[]
     */
    private function parseSearchTerms(string $query): array
    {
        $terms = preg_split('/\s+/', trim($query));

        return array_filter($terms, fn($term) => strlen($term) >= 2);
    }

    /**
     * Check whether any Lens-specific metadata filters are active.
     *
     * These filters query analysis tables (tags, status, quality, etc.) and
     * use NULL/absence checks that non-image assets would incorrectly match.
     * When active alongside a text query, the KIND_IMAGE restriction must
     * remain to prevent false positives.
     */
    private static function hasLensMetadataFilters(array $filters): bool
    {
        $lensFilterKeys = [
            'tags', 'status', 'containsPeople', 'faceCountPreset',
            'confidenceMin', 'confidenceMax',
            'nsfwScoreMin', 'nsfwScoreMax', 'nsfwFlagged',
            'processedFrom', 'processedTo',
            'color', 'noTags', 'hasDuplicates',
            'hasWatermark', 'watermarkType', 'containsBrandLogo',
            'hasFocalPoint',
            'missingAltText', 'unprocessed',
            'qualityIssues', 'hasTextInImage',
        ];

        foreach ($lensFilterKeys as $key) {
            if (isset($filters[$key]) && $filters[$key] !== [] && $filters[$key] !== '') {
                return true;
            }
        }

        return false;
    }

    /**
     * Search Craft's native search index to find assets by title, filename,
     * extension, or native alt text. This surfaces non-image assets (videos,
     * documents) that don't have Lens analysis but match the query.
     *
     * @return int[]
     */
    private function getCraftNativeSearchIds(string $query): array
    {
        $primarySiteId = Craft::$app->getSites()->getPrimarySite()->id;

        $assetQuery = Asset::find()
            ->search($query)
            ->siteId($primarySiteId)
            ->status(null)
            ->limit(self::NATIVE_SEARCH_LIMIT);

        // Respect plugin volume settings: no enabled volumes means nothing to return.
        $volumeIds = Plugin::getInstance()->getSettings()->getEnabledVolumeIds();

        if (empty($volumeIds)) {
            return [];
        }

        $assetQuery->volumeId($volumeIds);

        return array_map('intval', $assetQuery->ids());
    }

    /**
     * Apply tag filters using the Lens indexed tags table.
     *
     * @param string[] $tags
     */
    private function applyTagFilters(Query $query, array $tags, string $operator): void
    {
        if (empty($tags)) {
            return;
        }

        $this->applyTagFiltersFromLensTable($query, $tags, $operator);
    }

    /**
     * Apply tag filters using the indexed tags table.
     */
    private function applyTagFiltersFromLensTable(Query $query, array $tags, string $operator): void
    {
        $normalizedTags = array_map('mb_strtolower', $tags);

        if ($operator === 'and') {
            // All tags must be present
            foreach ($normalizedTags as $tag) {
                $subQuery = (new Query())
                    ->select(['assetId'])
                    ->from(Install::TABLE_ASSET_TAGS)
                    ->where(['tagNormalized' => $tag]);

                $query->andWhere(['assets.id' => $subQuery]);
            }
        } else {
            // Any tag (OR)
            $subQuery = (new Query())
                ->select(['assetId'])
                ->distinct()
                ->from(Install::TABLE_ASSET_TAGS)
                ->where(['tagNormalized' => $normalizedTags]);

            $query->andWhere(['assets.id' => $subQuery]);
        }
    }

    /**
     * Apply status filter.
     *
     * @param string[] $statuses
     */
    private function applyStatusFilter(Query $query, array $statuses): void
    {
        if (empty($statuses)) {
            return;
        }

        $query->andWhere(['lens.status' => $statuses]);
    }

    /**
     * Apply people/face detection filters.
     */
    private function applyPeopleFilter(Query $query, array $filters): void
    {
        if (isset($filters['containsPeople'])) {
            $query->andWhere(['lens.containsPeople' => $filters['containsPeople']]);
        }

        // Face count preset buttons (0, 1, 2-5, 6+)
        if (isset($filters['faceCountPreset'])) {
            switch ($filters['faceCountPreset']) {
                case '0':
                    $query->andWhere(['lens.containsPeople' => false]);
                    break;
                case '1':
                    $query->andWhere(['lens.faceCount' => 1]);
                    break;
                case '2-5':
                    $query->andWhere(['>=', 'lens.faceCount', 2]);
                    $query->andWhere(['<=', 'lens.faceCount', 5]);
                    break;
                case '6+':
                    $query->andWhere(['>=', 'lens.faceCount', 6]);
                    break;
            }
        }
    }

    /**
     * Apply confidence range filter.
     */
    private function applyConfidenceFilter(Query $query, array $filters): void
    {
        if (isset($filters['confidenceMin'])) {
            $query->andWhere(['>=', 'lens.altTextConfidence', $filters['confidenceMin']]);
        }

        if (isset($filters['confidenceMax'])) {
            $query->andWhere(['<=', 'lens.altTextConfidence', $filters['confidenceMax']]);
        }
    }

    /**
     * Apply NSFW score range filter.
     */
    private function applyNsfwFilter(Query $query, array $filters): void
    {
        if (isset($filters['nsfwScoreMin'])) {
            $query->andWhere(['>=', 'lens.nsfwScore', $filters['nsfwScoreMin']]);
        }

        if (isset($filters['nsfwScoreMax'])) {
            $query->andWhere(['<=', 'lens.nsfwScore', $filters['nsfwScoreMax']]);
        }
    }

    /**
     * Apply date range filter on processedAt.
     */
    private function applyDateFilter(Query $query, array $filters): void
    {
        if (isset($filters['processedFrom'])) {
            /** @var \DateTime $from */
            $from = $filters['processedFrom'];
            $query->andWhere(['>=', 'lens.processedAt', $from->format('Y-m-d H:i:s')]);
        }

        if (isset($filters['processedTo'])) {
            /** @var \DateTime $to */
            $to = $filters['processedTo'];
            // Add 1 day to include the end date fully
            $to = (clone $to)->modify('+1 day');
            $query->andWhere(['<', 'lens.processedAt', $to->format('Y-m-d H:i:s')]);
        }
    }

    /**
     * Apply color filter using direct hex matching with tolerance.
     */
    private function applyColorFilter(Query $query, array $filters): void
    {
        if (!isset($filters['color'])) {
            return;
        }

        $tolerance = $filters['colorTolerance'] ?? self::DEFAULT_COLOR_TOLERANCE;
        $matchingAssetIds = $this->findAssetsWithColorHex($filters['color'], $tolerance);

        if (empty($matchingAssetIds)) {
            $query->andWhere('1 = 0');
            return;
        }

        $query->andWhere(['assets.id' => $matchingAssetIds]);
    }

    /**
     * Apply "no tags" filter for untagged assets.
     * Shows analyzed assets (any non-failed status) that have no AI tags.
     */
    private function applyNoTagsFilter(Query $query, array $filters): void
    {
        if (empty($filters['noTags'])) {
            return;
        }

        $subQuery = (new Query())
            ->select(['assetId'])
            ->from(Install::TABLE_ASSET_TAGS);

        $query->andWhere(['not in', 'assets.id', $subQuery]);
    }

    /**
     * Filter assets by whether they have unresolved duplicates.
     */
    private function applyDuplicatesFilter(Query $query, array $filters): void
    {
        if (!isset($filters['hasDuplicates'])) {
            return;
        }

        $subQuery = (new Query())
            ->select(['dup_asset_id'])
            ->from([
                'dup_union' => (new Query())
                    ->select(['canonicalAssetId AS dup_asset_id'])
                    ->from(Install::TABLE_DUPLICATE_GROUPS)
                    ->where(['resolution' => null])
                    ->union(
                        (new Query())
                            ->select(['duplicateAssetId AS dup_asset_id'])
                            ->from(Install::TABLE_DUPLICATE_GROUPS)
                            ->where(['resolution' => null]),
                    ),
            ]);

        if ($filters['hasDuplicates']) {
            $query->andWhere(['in', 'assets.id', $subQuery]);
        } else {
            $query->andWhere(['not in', 'assets.id', $subQuery]);
        }
    }

    /**
     * Filter assets by watermark detection.
     */
    private function applyWatermarkFilter(Query $query, array $filters): void
    {
        if (isset($filters['hasWatermark'])) {
            $query->andWhere(['lens.hasWatermark' => $filters['hasWatermark']]);
        }

        if (!empty($filters['watermarkType'])) {
            $query->andWhere(['lens.watermarkType' => $filters['watermarkType']]);
        }
    }

    /**
     * Filter assets by brand logo detection.
     */
    private function applyBrandLogoFilter(Query $query, array $filters): void
    {
        if (isset($filters['containsBrandLogo'])) {
            $query->andWhere(['lens.containsBrandLogo' => $filters['containsBrandLogo']]);
        }
    }

    /**
     * Filter assets by focal point availability.
     *
     * Uses Craft's native assets.focalPoint field to match the dashboard coverage
     * metric. When filtering for missing focal point, the base query uses LEFT JOIN
     * so unanalyzed assets (no lens record) are included.
     */
    private function applyFocalPointFilter(Query $query, array $filters): void
    {
        if (!isset($filters['hasFocalPoint'])) {
            return;
        }

        if ($filters['hasFocalPoint']) {
            $query->andWhere(['not', ['assets.focalPoint' => null]]);
        } else {
            $query->andWhere(['assets.focalPoint' => null]);
        }
    }

    /**
     * Filter assets by whether Craft's native alt field is empty.
     */
    private function applyMissingAltTextFilter(Query $query, array $filters): void
    {
        if (!isset($filters['missingAltText'])) {
            return;
        }

        if ($filters['missingAltText']) {
            $query->andWhere(['or', ['assets.alt' => null], ['assets.alt' => '']]);
        } else {
            $query->andWhere(['not', ['assets.alt' => null]]);
            $query->andWhere(['!=', 'assets.alt', '']);
        }
    }

    /**
     * Filter to assets that have not been successfully processed.
     * Mirrors BulkProcessingStatusService::getUnprocessedCount(): assets that are
     * NOT in (completed, approved, pending_review, processing).
     * LEFT JOIN is active so NULL lens.assetId (no record) is included.
     */
    private function applyUnprocessedFilter(Query $query, array $filters): void
    {
        if (empty($filters['unprocessed'])) {
            return;
        }

        $query->andWhere(['or',
            ['lens.assetId' => null],
            ['in', 'lens.status', [
                AnalysisStatus::Pending->value,
                AnalysisStatus::Failed->value,
                AnalysisStatus::Rejected->value,
            ]],
        ]);
    }

    /**
     * Filter assets by specific quality issues (blurry, tooDark, tooBright, lowContrast).
     */
    private function applyQualityIssuesFilter(Query $query, array $filters): void
    {
        if (empty($filters['qualityIssues'])) {
            return;
        }

        $conditions = ['or'];

        foreach ($filters['qualityIssues'] as $issue) {
            match ($issue) {
                'blurry' => $conditions[] = [
                    'and',
                    ['<', 'lens.sharpnessScore', ImageMetricsAnalyzer::SHARPNESS_BLURRY],
                    ['not', ['lens.sharpnessScore' => null]],
                ],
                'tooDark' => $conditions[] = [
                    'and',
                    ['<', 'lens.exposureScore', ImageMetricsAnalyzer::BRIGHTNESS_DARK_MEDIAN],
                    ['>', 'lens.shadowClipRatio', ImageMetricsAnalyzer::SHADOW_CLIP_RATIO],
                    ['<', 'lens.noiseScore', ImageMetricsAnalyzer::CONTRAST_LOW],
                    ['not', ['lens.exposureScore' => null]],
                    ['not', ['lens.noiseScore' => null]],
                ],
                'tooBright' => $conditions[] = [
                    'and',
                    ['>', 'lens.exposureScore', ImageMetricsAnalyzer::BRIGHTNESS_BRIGHT_MEDIAN],
                    ['>', 'lens.highlightClipRatio', ImageMetricsAnalyzer::HIGHLIGHT_CLIP_RATIO],
                    ['<', 'lens.noiseScore', ImageMetricsAnalyzer::CONTRAST_LOW],
                    ['not', ['lens.exposureScore' => null]],
                    ['not', ['lens.noiseScore' => null]],
                ],
                'lowContrast' => $conditions[] = [
                    'and',
                    ['<', 'lens.noiseScore', ImageMetricsAnalyzer::CONTRAST_LOW],
                    ['not', ['lens.noiseScore' => null]],
                ],
                default => null,
            };
        }

        if (count($conditions) > 1) {
            $query->andWhere($conditions);
        }
    }

    /**
     * Filter assets by standalone quality flags.
     */
    private function applyStandaloneQualityFilters(Query $query, array $filters): void
    {
        if (!empty($filters['isBlurry'])) {
            $query->andWhere([
                'and',
                ['<', 'lens.sharpnessScore', ImageMetricsAnalyzer::SHARPNESS_BLURRY],
                ['not', ['lens.sharpnessScore' => null]],
            ]);
        }

        if (!empty($filters['isTooDark'])) {
            $query->andWhere([
                'and',
                ['<', 'lens.exposureScore', ImageMetricsAnalyzer::BRIGHTNESS_DARK_MEDIAN],
                ['>', 'lens.shadowClipRatio', ImageMetricsAnalyzer::SHADOW_CLIP_RATIO],
                ['<', 'lens.noiseScore', ImageMetricsAnalyzer::CONTRAST_LOW],
                ['not', ['lens.exposureScore' => null]],
                ['not', ['lens.noiseScore' => null]],
            ]);
        }

        if (!empty($filters['isTooBright'])) {
            $query->andWhere([
                'and',
                ['>', 'lens.exposureScore', ImageMetricsAnalyzer::BRIGHTNESS_BRIGHT_MEDIAN],
                ['>', 'lens.highlightClipRatio', ImageMetricsAnalyzer::HIGHLIGHT_CLIP_RATIO],
                ['<', 'lens.noiseScore', ImageMetricsAnalyzer::CONTRAST_LOW],
                ['not', ['lens.exposureScore' => null]],
                ['not', ['lens.noiseScore' => null]],
            ]);
        }

        if (!empty($filters['isLowContrast'])) {
            $query->andWhere([
                'and',
                ['<', 'lens.noiseScore', ImageMetricsAnalyzer::CONTRAST_LOW],
                ['not', ['lens.noiseScore' => null]],
            ]);
        }

        if (!empty($filters['isTooLarge'])) {
            $query->andWhere(['>=', 'assets.size', FileTooLargeConditionRule::FILE_SIZE_WARNING]);
        }
    }

    /**
     * Filter assets by whether they contain embedded text (OCR).
     *
     * extractedTextAi is a JSON array column: MySQL stores an empty array as
     * the literal string '[]', so "has text" means "not NULL and not '[]'".
     */
    private function applyHasTextInImageFilter(Query $query, array $filters): void
    {
        if (!isset($filters['hasTextInImage'])) {
            return;
        }

        if ($filters['hasTextInImage']) {
            $query->andWhere(['not', ['lens.extractedTextAi' => null]]);
            $query->andWhere(['!=', 'lens.extractedTextAi', '[]']);
        } else {
            $query->andWhere([
                'or',
                ['lens.extractedTextAi' => null],
                ['lens.extractedTextAi' => '[]'],
            ]);
        }
    }

    /**
     * Restrict results to assets belonging to volumes enabled in plugin settings.
     * No enabled volumes means no results.
     */
    private function applyVolumeFilter(Query $query): void
    {
        $volumeIds = Plugin::getInstance()->getSettings()->getEnabledVolumeIds();

        if (empty($volumeIds)) {
            $query->andWhere('1 = 0');
            return;
        }

        $query->andWhere(['assets.volumeId' => $volumeIds]);
    }

    /**
     * Find assets that have dominant colors matching a given hex color within tolerance.
     *
     * @return int[]
     */
    private function findAssetsWithColorHex(string $hex, int $tolerance): array
    {
        $targetHsl = $this->hexToHsl($hex);

        $query = (new Query())
            ->select(['c.hex', 'a.assetId'])
            ->from(Install::TABLE_ASSET_COLORS . ' c')
            ->innerJoin(Install::TABLE_ASSET_ANALYSES . ' a', '[[c.analysisId]] = [[a.id]]')
            ->where(['in', 'a.status', AnalysisStatus::analyzedValues()]);

        $matchingIds = [];

        foreach ($query->batch(500) as $rows) {
            foreach ($rows as $row) {
                if ($this->colorMatchesHsl($row['hex'] ?? '', $targetHsl, $tolerance)) {
                    $matchingIds[] = (int) $row['assetId'];
                }
            }
        }

        return array_unique($matchingIds);
    }

    /**
     * Check if a hex color matches a target HSL within tolerance.
     */
    private function colorMatchesHsl(string $hex, array $targetHsl, int $tolerance): bool
    {
        if (empty($hex)) {
            return false;
        }

        $hsl = $this->hexToHsl($hex);

        // Circular hue distance (0-180 max)
        $hueDiff = abs($hsl['h'] - $targetHsl['h']);
        $hueDist = min($hueDiff, 360 - $hueDiff);

        $satDist = abs($hsl['s'] - $targetHsl['s']);
        $lightDist = abs($hsl['l'] - $targetHsl['l']);

        // Scale tolerance into per-channel thresholds
        $hueThreshold = $tolerance * 1.5;   // 0-150 degrees
        $satThreshold = $tolerance;          // 0-100%
        $lightThreshold = $tolerance;        // 0-100%

        return $hueDist <= $hueThreshold
            && $satDist <= $satThreshold
            && $lightDist <= $lightThreshold;
    }

    /**
     * Convert hex color to HSL.
     *
     * @return array{h: int, s: int, l: int}
     */
    private function hexToHsl(string $hex): array
    {
        $hex = ltrim($hex, '#');
        if (strlen($hex) === 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }

        $r = hexdec(substr($hex, 0, 2)) / 255;
        $g = hexdec(substr($hex, 2, 2)) / 255;
        $b = hexdec(substr($hex, 4, 2)) / 255;

        $max = max($r, $g, $b);
        $min = min($r, $g, $b);
        $l = ($max + $min) / 2;

        if ($max === $min) {
            $h = $s = 0;
        } else {
            $d = $max - $min;
            $s = $l > 0.5 ? $d / (2 - $max - $min) : $d / ($max + $min);

            switch ($max) {
                case $r:
                    $h = (($g - $b) / $d + ($g < $b ? 6 : 0)) / 6;
                    break;
                case $g:
                    $h = (($b - $r) / $d + 2) / 6;
                    break;
                case $b:
                default:
                    $h = (($r - $g) / $d + 4) / 6;
                    break;
            }
        }

        return [
            'h' => (int) round($h * 360),
            's' => (int) round($s * 100),
            'l' => (int) round($l * 100),
        ];
    }

    /**
     * Get status options for filter dropdown.
     *
     * @return array<array{value: string, label: string}>
     */
    public function getStatusOptions(): array
    {
        $isReviewActive = Plugin::getInstance()->getIsPro()
            && Plugin::getInstance()->getSettings()->requireReviewBeforeApply;

        $options = [
            ['value' => AnalysisStatus::Completed->value, 'label' => AnalysisStatus::Completed->label()],
            ['value' => AnalysisStatus::Pending->value, 'label' => AnalysisStatus::Pending->label()],
            ['value' => AnalysisStatus::Processing->value, 'label' => AnalysisStatus::Processing->label()],
            ['value' => AnalysisStatus::Failed->value, 'label' => AnalysisStatus::Failed->label()],
        ];

        if ($isReviewActive) {
            $options[] = ['value' => AnalysisStatus::Approved->value, 'label' => AnalysisStatus::Approved->label()];
            $options[] = ['value' => AnalysisStatus::PendingReview->value, 'label' => AnalysisStatus::PendingReview->label()];
        }

        return $options;
    }

    /**
     * Get quick filter definitions for the UI.
     *
     * @return array<string, array{key: string, label: string, icon: string}>
     */
    public function getQuickFilters(): array
    {
        $isReviewActive = Plugin::getInstance()->getIsPro()
            && Plugin::getInstance()->getSettings()->requireReviewBeforeApply;

        $filters = [];

        foreach (QuickFilter::cases() as $case) {
            if ($case === QuickFilter::NeedsReview && !$isReviewActive) {
                continue;
            }
            $filters[$case->value] = [
                'key' => $case->value,
                'label' => Craft::t('lens', $case->label()),
                'icon' => $case->icon(),
            ];
        }

        return $filters;
    }

    /**
     * Apply a quick filter preset to the filters array.
     */
    public function applyQuickFilter(string $key, array $filters): array
    {
        $case = QuickFilter::tryFrom($key);

        if ($case === null) {
            return $filters;
        }

        return $case->applyToFilters($filters);
    }
}
