<?php

declare(strict_types=1);

namespace vitordiniz22\craftlens\services;

use Craft;
use craft\elements\Asset;
use vitordiniz22\craftlens\enums\AnalysisStatus;
use vitordiniz22\craftlens\enums\LogCategory;
use vitordiniz22\craftlens\helpers\Logger;
use vitordiniz22\craftlens\migrations\Install;
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
    private const DEFAULT_LIMIT = 20;

    /**
     * Quick filter presets for one-click filtering.
     */
    public const QUICK_FILTERS = [
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
            'filters' => ['status' => ['pending_review']],
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

        $baseQuery = $this->buildMatchingQuery($filters);

        $total = (int) (clone $baseQuery)->count();

        if ($total === 0) {
            return [
                'assets' => [],
                'total' => 0,
                'offset' => $offset,
                'limit' => $limit,
            ];
        }

        $paginatedIds = (clone $baseQuery)
            ->orderBy(['MAX([[lens.processedAt]])' => SORT_DESC])
            ->offset($offset)
            ->limit($limit)
            ->column();

        $paginatedIds = array_map('intval', $paginatedIds);

        $assets = Asset::find()
            ->id($paginatedIds)
            ->fixedOrder()
            ->all();

        Logger::info(LogCategory::AssetProcessing, 'Asset search executed', context: ['resultsCount' => $total]);

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
    private function buildMatchingQuery(array $filters): Query
    {
        $query = (new Query())
            ->select(['assets.id'])
            ->from('{{%assets}} assets')
            ->innerJoin('{{%elements}} elements', '[[assets.id]] = [[elements.id]]')
            ->innerJoin(Install::TABLE_ASSET_ANALYSES . ' lens', '[[assets.id]] = [[lens.assetId]]')
            ->where(['elements.dateDeleted' => null])
            ->groupBy(['assets.id']);

        if ($this->needsTextSearchJoins($filters)) {
            $query->leftJoin('{{%elements_sites}} elements_sites', '[[assets.id]] = [[elements_sites.elementId]]');
            $query->leftJoin(Install::TABLE_ASSET_TAGS . ' tags', '[[lens.id]] = [[tags.analysisId]]');
        }

        $this->applyTextSearch($query, $filters['query'] ?? null);
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
     * Check if we need to join tables for text search (elements_sites + tags).
     */
    private function needsTextSearchJoins(array $filters): bool
    {
        return !empty($filters['query']);
    }

    /**
     * Apply full-text search across Craft fields and Lens data.
     *
     * Searches: asset title, Lens alt text, long description, tags, extracted text
     */
    private function applyTextSearch(Query $query, ?string $searchQuery): void
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
     * Color families with their representative hex values and HSL ranges.
     */
    private const COLOR_FAMILIES = [
        'red'     => ['hex' => '#E53935', 'hue' => [350, 10]],
        'orange'  => ['hex' => '#FB8C00', 'hue' => [11, 40]],
        'yellow'  => ['hex' => '#FDD835', 'hue' => [41, 65]],
        'lime'    => ['hex' => '#C0CA33', 'hue' => [66, 80]],
        'green'   => ['hex' => '#43A047', 'hue' => [81, 150]],
        'teal'    => ['hex' => '#00897B', 'hue' => [151, 180]],
        'cyan'    => ['hex' => '#00ACC1', 'hue' => [181, 200]],
        'blue'    => ['hex' => '#1E88E5', 'hue' => [201, 250]],
        'purple'  => ['hex' => '#8E24AA', 'hue' => [251, 290]],
        'magenta' => ['hex' => '#D81B60', 'hue' => [291, 330]],
        'pink'    => ['hex' => '#EC407A', 'hue' => [331, 349]],
        'brown'   => ['hex' => '#6D4C41', 'special' => 'brown'],
        'gray'    => ['hex' => '#757575', 'special' => 'gray'],
        'black'   => ['hex' => '#212121', 'special' => 'black'],
        'white'   => ['hex' => '#FAFAFA', 'special' => 'white'],
    ];

    /**
     * Apply color family filter.
     */
    private function applyColorFilter(Query $query, array $filters): void
    {
        if (!isset($filters['colorFamily'])) {
            return;
        }

        $colorFamily = $filters['colorFamily'];
        $tolerance = $filters['colorTolerance'] ?? 30;

        if (!isset(self::COLOR_FAMILIES[$colorFamily])) {
            return;
        }

        // Find assets with matching dominant colors
        $matchingAssetIds = $this->findAssetsWithColorFamily($colorFamily, $tolerance);

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
                ['lens.focalPointY' => null]
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
     * Find assets that have dominant colors matching the given color family.
     * Uses batch processing to avoid loading all records into memory.
     *
     * @return int[]
     */
    private function findAssetsWithColorFamily(string $colorFamily, int $tolerance): array
    {
        $query = (new Query())
            ->select(['c.hex', 'a.assetId'])
            ->from(Install::TABLE_ASSET_COLORS . ' c')
            ->innerJoin(Install::TABLE_ASSET_ANALYSES . ' a', '[[c.analysisId]] = [[a.id]]')
            ->where(['in', 'a.status', AnalysisStatus::analyzedValues()]);

        $matchingIds = [];

        foreach ($query->batch(500) as $rows) {
            foreach ($rows as $row) {
                if ($this->colorMatchesFamily($row['hex'] ?? '', $colorFamily, $tolerance)) {
                    $matchingIds[] = (int) $row['assetId'];
                }
            }
        }

        return array_unique($matchingIds);
    }

    /**
     * Check if a hex color matches a color family within tolerance.
     */
    private function colorMatchesFamily(string $hex, string $family, int $tolerance): bool
    {
        if (empty($hex)) {
            return false;
        }

        $hsl = $this->hexToHsl($hex);
        $familyDef = self::COLOR_FAMILIES[$family] ?? null;

        if ($familyDef === null) {
            return false;
        }

        // Handle special cases (brown, gray, black, white)
        if (isset($familyDef['special'])) {
            return $this->matchesSpecialColor($hsl, $familyDef['special'], $tolerance);
        }

        // Handle hue-based colors
        $hueRange = $familyDef['hue'];
        $toleranceHue = $tolerance * 0.5; // Scale tolerance for hue

        $minHue = $hueRange[0] - $toleranceHue;
        $maxHue = $hueRange[1] + $toleranceHue;

        // Handle wraparound for red (hue crosses 0/360)
        if ($minHue < 0 || $maxHue > 360 || $hueRange[0] > $hueRange[1]) {
            // Wraparound case (like red: 350-10)
            $minHue = ($minHue + 360) % 360;
            $maxHue = $maxHue % 360;
            return $hsl['h'] >= $minHue || $hsl['h'] <= $maxHue;
        }

        return $hsl['h'] >= $minHue && $hsl['h'] <= $maxHue;
    }

    /**
     * Match special colors (brown, gray, black, white) based on saturation and lightness.
     */
    private function matchesSpecialColor(array $hsl, string $special, int $tolerance): bool
    {
        $toleranceSat = $tolerance * 0.3;
        $toleranceLight = $tolerance * 0.5;

        switch ($special) {
            case 'black':
                return $hsl['l'] <= (14 + $toleranceLight);
            case 'white':
                return $hsl['l'] >= (86 - $toleranceLight);
            case 'gray':
                return $hsl['s'] <= (10 + $toleranceSat) && $hsl['l'] > 14 && $hsl['l'] < 86;
            case 'brown':
                // Brown: low-medium saturation, low-medium lightness, warm hues
                $warmHue = ($hsl['h'] >= 0 && $hsl['h'] <= 50) || $hsl['h'] >= 350;
                $brownSat = $hsl['s'] >= (10 - $toleranceSat) && $hsl['s'] <= (50 + $toleranceSat);
                $brownLight = $hsl['l'] >= (15 - $toleranceLight) && $hsl['l'] <= (40 + $toleranceLight);
                return $warmHue && $brownSat && $brownLight;
        }

        return false;
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
     * Get available color families for the filter UI.
     *
     * @return array<array{id: string, hex: string, name: string}>
     */
    public function getColorFamilies(): array
    {
        $families = [];
        foreach (self::COLOR_FAMILIES as $id => $def) {
            $families[] = [
                'id' => $id,
                'hex' => $def['hex'],
                'name' => ucfirst($id),
            ];
        }
        return $families;
    }

    /**
     * Get all unique tags for autocomplete.
     *
     * @return string[]
     */
    public function getAllTags(): array
    {
        return (new Query())
            ->select(['tag'])
            ->distinct()
            ->from(Install::TABLE_ASSET_TAGS . ' tags')
            ->innerJoin(Install::TABLE_ASSET_ANALYSES . ' lens', '[[tags.analysisId]] = [[lens.id]]')
            ->where(['in', 'lens.status', AnalysisStatus::analyzedValues()])
            ->orderBy(['tag' => SORT_ASC])
            ->column();
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

        foreach (self::QUICK_FILTERS as $key => $def) {
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
        $preset = self::QUICK_FILTERS[$key] ?? null;

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
