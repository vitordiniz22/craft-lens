<?php

declare(strict_types=1);

namespace vitordiniz22\craftlens\services;

use Craft;
use craft\elements\Asset;
use vitordiniz22\craftlens\enums\AnalysisStatus;
use vitordiniz22\craftlens\enums\LogCategory;
use vitordiniz22\craftlens\helpers\Logger;
use vitordiniz22\craftlens\migrations\Install;
use vitordiniz22\craftlens\Plugin;
use yii\base\Component;
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

    /**
     * Quick filter presets for one-click filtering.
     */
    public static function quickFilters(): array
    {
        return [
            'untagged' => [
                'label' => 'Untagged',
                'icon' => 'tag',
                'specialCondition' => 'noTags',
            ],
            'low-confidence' => [
                'label' => 'Low Confidence',
                'icon' => 'alert',
                'filters' => ['confidenceMax' => 0.7],
            ],
            'needs-review' => [
                'label' => 'Needs Review',
                'icon' => 'eye',
                'filters' => ['status' => [AnalysisStatus::PendingReview->value]],
            ],
            'with-people' => [
                'label' => 'With People',
                'icon' => 'users',
                'filters' => ['containsPeople' => true],
            ],
            'nsfw-flagged' => [
                'label' => 'NSFW Flagged',
                'icon' => 'warning',
                'filters' => ['nsfwScoreMin' => 0.5],
            ],
            'nsfw-caution' => [
                'label' => 'NSFW Caution',
                'icon' => 'warning',
                'filters' => ['nsfwScoreMin' => 0.2, 'nsfwScoreMax' => 0.499],
            ],
            'recent-7d' => [
                'label' => 'Last 7 Days',
                'icon' => 'clock',
                'relativeDays' => 7,
            ],
            'has-duplicates' => [
                'label' => 'Has Duplicates',
                'icon' => 'copy',
                'filters' => ['hasDuplicates' => true],
            ],
        ];
    }

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
        $usedBm25 = false;
        $rawQuery = $filters['query'] ?? null;

        if ($rawQuery !== null && trim($rawQuery) !== '') {
            $terms = $this->parseSearchTerms($rawQuery);

            if (!empty($terms)) {
                $scores = Plugin::getInstance()->searchIndex->search($terms);

                if (!empty($scores)) {
                    // Index returned matches — use BM25 relevance ordering.
                    $rankedAssetIds = array_keys($scores);
                    $usedBm25 = true;
                } elseif (Plugin::getInstance()->searchIndex->isIndexPopulated()) {
                    // Index is populated but no tokens matched — genuine zero results.
                    Logger::info(LogCategory::AssetProcessing, 'Asset search executed (BM25, zero results)', context: [
                        'query' => $rawQuery,
                        'resultsCount' => 0,
                    ]);

                    return [
                        'assets' => [],
                        'total' => 0,
                        'offset' => $offset,
                        'limit' => $limit,
                    ];
                }
                // else: index is empty — fall through to legacy LIKE search below.
            }
        }

        $baseQuery = $this->buildMatchingQuery($filters, $rankedAssetIds);

        $total = (int) (clone $baseQuery)->count();

        if ($total === 0) {
            return [
                'assets' => [],
                'total' => 0,
                'offset' => $offset,
                'limit' => $limit,
            ];
        }

        if ($usedBm25 && $rankedAssetIds !== null) {
            // Preserve BM25 relevance order using MySQL FIELD().
            $fieldList = implode(',', array_map('intval', $rankedAssetIds));
            $paginatedIds = (clone $baseQuery)
                ->orderBy(new \yii\db\Expression('FIELD(assets.id, ' . $fieldList . ')'))
                ->offset($offset)
                ->limit($limit)
                ->column();
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
            'usedBm25' => $usedBm25,
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
    private function buildMatchingQuery(array $filters, ?array $rankedAssetIds = null): Query
    {
        $query = (new Query())
            ->select(['assets.id'])
            ->from('{{%assets}} assets')
            ->innerJoin('{{%elements}} elements', '[[assets.id]] = [[elements.id]]')
            ->innerJoin(Install::TABLE_ASSET_ANALYSES . ' lens', '[[assets.id]] = [[lens.assetId]]')
            ->where(['elements.dateDeleted' => null])
            ->groupBy(['assets.id']);

        if ($rankedAssetIds !== null) {
            // BM25 path: filter to pre-ranked IDs — no text-search table joins needed.
            $query->andWhere(['assets.id' => $rankedAssetIds]);
        } else {
            // Legacy LIKE path: join tables needed for text search.
            if (!empty($filters['query'])) {
                $query->leftJoin('{{%elements_sites}} elements_sites', '[[assets.id]] = [[elements_sites.elementId]]');
                $query->leftJoin(Install::TABLE_ASSET_TAGS . ' tags', '[[lens.id]] = [[tags.analysisId]]');
                $query->leftJoin(Install::TABLE_ANALYSIS_SITE_CONTENT . ' site_content', '[[lens.id]] = [[site_content.analysisId]]');
            }

            $this->applyTextSearchLegacy($query, $filters['query'] ?? null);
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
        $this->applyQualityPresetFilter($query, $filters);
        $this->applyGpsFilter($query, $filters);
        $this->applyFocalPointFilter($query, $filters);
        $this->applyDefaultStatusExclusion($query, $filters);

        return $query;
    }

    /**
     * Legacy LIKE-based full-text search. Used as fallback when the BM25 index is empty.
     *
     * Searches: asset title, Lens alt text, long description, tags, extracted text,
     * and per-site translated alt text / suggested title.
     */
    private function applyTextSearchLegacy(Query $query, ?string $searchQuery): void
    {
        if ($searchQuery === null || trim($searchQuery) === '') {
            return;
        }

        $searchTerms = $this->parseSearchTerms($searchQuery);

        if (empty($searchTerms)) {
            return;
        }

        $conditions = ['or'];

        foreach ($searchTerms as $term) {
            $escapedTerm = '%' . $term . '%';

            $conditions[] = ['like', 'elements_sites.title', $escapedTerm, false];

            $conditions[] = ['like', 'lens.altText', $escapedTerm, false];
            $conditions[] = ['like', 'lens.suggestedTitle', $escapedTerm, false];
            $conditions[] = ['like', 'lens.longDescription', $escapedTerm, false];

            $conditions[] = ['like', 'tags.tag', $escapedTerm, false];
            $conditions[] = ['like', 'lens.extractedText', $escapedTerm, false];

            $conditions[] = ['like', 'site_content.altText', $escapedTerm, false];
            $conditions[] = ['like', 'site_content.suggestedTitle', $escapedTerm, false];
        }

        $query->andWhere($conditions);
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

        $tolerance = $filters['colorTolerance'] ?? 30;
        $matchingAssetIds = $this->findAssetsWithColorHex($filters['color'], $tolerance);

        if (empty($matchingAssetIds)) {
            $query->andWhere('1 = 0');
            return;
        }

        $query->andWhere(['assets.id' => $matchingAssetIds]);
    }

    /**
     * Apply "no tags" filter for untagged assets.
     * Shows assets that have been analyzed but have no AI tags.
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
        $query->andWhere(['in', 'lens.status', AnalysisStatus::analyzedValues()]);
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
     * Filter assets by quality preset (high/medium/low).
     */
    private function applyQualityPresetFilter(Query $query, array $filters): void
    {
        if (!isset($filters['qualityPreset'])) {
            return;
        }

        switch ($filters['qualityPreset']) {
            case 'high':
                $query->andWhere(['>=', 'lens.overallQualityScore', 0.7]);
                break;
            case 'medium':
                $query->andWhere(['>=', 'lens.overallQualityScore', 0.4]);
                $query->andWhere(['<', 'lens.overallQualityScore', 0.7]);
                break;
            case 'low':
                $query->andWhere(['<', 'lens.overallQualityScore', 0.4]);
                break;
        }

        $query->andWhere(['not', ['lens.overallQualityScore' => null]]);
    }

    /**
     * Filter assets by GPS data availability (via EXIF join).
     */
    private function applyGpsFilter(Query $query, array $filters): void
    {
        if (!isset($filters['hasGps'])) {
            return;
        }

        $subQuery = (new Query())
            ->select(['assetId'])
            ->from(Install::TABLE_EXIF_METADATA)
            ->where(['not', ['latitude' => null]])
            ->andWhere(['not', ['longitude' => null]]);

        if ($filters['hasGps']) {
            $query->andWhere(['in', 'assets.id', $subQuery]);
        } else {
            $query->andWhere(['not in', 'assets.id', $subQuery]);
        }
    }

    /**
     * Filter assets by focal point availability.
     */
    private function applyFocalPointFilter(Query $query, array $filters): void
    {
        if (!isset($filters['hasFocalPoint'])) {
            return;
        }

        if ($filters['hasFocalPoint']) {
            $query->andWhere(['not', ['lens.focalPointX' => null]]);
            $query->andWhere(['not', ['lens.focalPointY' => null]]);
        } else {
            $query->andWhere(['or',
                ['lens.focalPointX' => null],
                ['lens.focalPointY' => null],
            ]);
        }
    }

    /**
     * Exclude non-analyzed statuses by default when no explicit status filter is set.
     * Failed, processing, and pending assets are hidden unless explicitly requested.
     */
    private function applyDefaultStatusExclusion(Query $query, array $filters): void
    {
        if (!empty($filters['status'])) {
            return;
        }

        $query->andWhere(['not', ['lens.status' => [
            AnalysisStatus::Failed->value,
            AnalysisStatus::Processing->value,
            AnalysisStatus::Pending->value,
        ]]]);
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
        return [
            ['value' => AnalysisStatus::Completed->value, 'label' => AnalysisStatus::Completed->label()],
            ['value' => AnalysisStatus::Approved->value, 'label' => AnalysisStatus::Approved->label()],
            ['value' => AnalysisStatus::Pending->value, 'label' => AnalysisStatus::Pending->label()],
            ['value' => AnalysisStatus::Processing->value, 'label' => AnalysisStatus::Processing->label()],
            ['value' => AnalysisStatus::PendingReview->value, 'label' => AnalysisStatus::PendingReview->label()],
            ['value' => AnalysisStatus::Failed->value, 'label' => AnalysisStatus::Failed->label()],
        ];
    }

    /**
     * Get quick filter definitions for the UI.
     *
     * @return array<string, array{key: string, label: string, icon: string}>
     */
    public function getQuickFilters(): array
    {
        $filters = [];

        foreach (self::quickFilters() as $key => $def) {
            $filters[$key] = [
                'key' => $key,
                'label' => Craft::t('lens', $def['label']),
                'icon' => $def['icon'],
            ];
        }

        return $filters;
    }

    /**
     * Apply a quick filter preset to the filters array.
     */
    public function applyQuickFilter(string $key, array $filters): array
    {
        $preset = self::quickFilters()[$key] ?? null;

        if ($preset === null) {
            return $filters;
        }

        if (isset($preset['specialCondition']) && $preset['specialCondition'] === 'noTags') {
            $filters['noTags'] = true;
        }

        if (isset($preset['filters'])) {
            $filters = array_merge($filters, $preset['filters']);
        }

        if (isset($preset['relativeDays'])) {
            $filters['processedFrom'] = (new \DateTime())->modify("-{$preset['relativeDays']} days");
        }

        return $filters;
    }
}
