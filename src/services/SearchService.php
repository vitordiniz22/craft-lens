<?php

declare(strict_types=1);

namespace vitordiniz22\craftlens\services;

use Craft;
use craft\elements\Asset;
use vitordiniz22\craftlens\enums\AnalysisStatus;
use vitordiniz22\craftlens\enums\LogCategory;
use vitordiniz22\craftlens\enums\QuickFilter;
use vitordiniz22\craftlens\enums\WatermarkType;
use vitordiniz22\craftlens\helpers\DuplicateSupport;
use vitordiniz22\craftlens\helpers\ImageMetricsAnalyzer;
use vitordiniz22\craftlens\helpers\Logger;
use vitordiniz22\craftlens\helpers\QualitySupport;
use vitordiniz22\craftlens\migrations\Install;
use vitordiniz22\craftlens\Plugin;
use yii\base\Component;
use yii\db\Expression;
use yii\db\Query;

/**
 * Service for searching assets using Craft fields and Lens metadata.
 *
 * Search priority:
 * - Text search: BM25 over Lens's indexed token table + Craft's native search index
 *   (the latter surfaces non-image assets the Lens index doesn't cover).
 * - Tag filtering: Lens's indexed tags table (`lens_asset_tags`).
 * - AI metadata (status, people, NSFW, watermark, brand, quality, etc.):
 *   always from the Lens analyses table.
 */
class SearchService extends Component
{
    private const DEFAULT_LIMIT = 50;
    private const NATIVE_SEARCH_LIMIT = 500;


    /**
     * Search assets using combined filters.
     *
     * @param array{
     *     query?: string,
     *     tags?: string[],
     *     tagOperator?: 'and'|'or',
     *     status?: string[],
     *     containsPeople?: bool,
     *     faceCountPreset?: string,
     *     nsfwScoreMin?: float,
     *     nsfwScoreMax?: float,
     *     nsfwFlagged?: bool,
     *     provider?: string,
     *     providerModel?: string,
     *     qualityIssue?: string,
     *     fileSizePreset?: string,
     *     siteId?: int,
     *     offset?: int,
     *     limit?: int,
     * } $filters
     * @return array{assets: Asset[], total: int, offset: int, limit: int}
     */
    public function search(array $filters): array
    {
        $limit = $filters['limit'] ?? self::DEFAULT_LIMIT;
        $offset = $filters['offset'] ?? 0;
        $siteId = $this->resolveSiteId($filters['siteId'] ?? null);

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
            $craftNativeIds = $this->getCraftNativeSearchIds($rawQuery, $siteId);

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

            usort($allIds, function(int $a, int $b) use ($clusterKeys): int {
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

        $assets = Asset::find()
            ->id($paginatedIds)
            ->siteId($siteId)
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
     * @return array{assets: list<never>, total: 0, offset: int, limit: int}
     */
    private function emptyResult(int $offset, int $limit): array
    {
        return ['assets' => [], 'total' => 0, 'offset' => $offset, 'limit' => $limit];
    }

    /**
     * Build the base query for matching assets. Returns a grouped query
     * selecting asset IDs that can be used for both counting and pagination.
     */
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
        $this->applyNsfwFilter($query, $filters);
        $this->applyDateFilter($query, $filters);
        $this->applyDuplicatesFilter($query, $filters);
        $this->applyWatermarkFilter($query, $filters);
        $this->applyBrandLogoFilter($query, $filters);
        $this->applyFocalPointFilter($query, $filters);
        $this->applyMissingAltTextFilter($query, $filters);
        $this->applyUnprocessedFilter($query, $filters);
        $this->applyQualityIssueFilter($query, $filters);
        $this->applyFileSizeFilter($query, $filters);
        $this->applyHasTextInImageFilter($query, $filters);
        $this->applyProviderFilter($query, $filters);
        $this->applyVolumeFilter($query);

        return $query;
    }

    /**
     * Resolve the site ID to use for searches. Falls back to the primary site
     * when no override is provided or when the requested site does not exist.
     */
    private function resolveSiteId(mixed $requested): int
    {
        $sitesService = Craft::$app->getSites();

        if ($requested !== null && $requested !== '') {
            $siteId = (int) $requested;
            if ($siteId > 0 && $sitesService->getSiteById($siteId) !== null) {
                return $siteId;
            }
        }

        return $sitesService->getPrimarySite()->id;
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
            'nsfwScoreMin', 'nsfwScoreMax', 'nsfwFlagged',
            'processedFrom', 'processedTo',
            'hasDuplicates',
            'hasWatermark', 'watermarkType', 'containsBrandLogo',
            'hasFocalPoint',
            'missingAltText', 'unprocessed',
            'qualityIssue', 'fileSizePreset', 'hasTextInImage',
            'provider', 'providerModel',
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
    private function getCraftNativeSearchIds(string $query, int $siteId): array
    {
        $assetQuery = Asset::find()
            ->search($query)
            ->siteId($siteId)
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
     * Apply tag filters using the Lens indexed tags table. Operator 'and'
     * requires every tag to be present on the asset; 'or' matches any.
     *
     * @param string[] $tags
     */
    private function applyTagFilters(Query $query, array $tags, string $operator): void
    {
        if (empty($tags)) {
            return;
        }

        $normalizedTags = array_map('mb_strtolower', $tags);

        if ($operator === 'and') {
            foreach ($normalizedTags as $tag) {
                $subQuery = (new Query())
                    ->select(['assetId'])
                    ->from(Install::TABLE_ASSET_TAGS)
                    ->where(['tagNormalized' => $tag]);

                $query->andWhere(['assets.id' => $subQuery]);
            }
            return;
        }

        $subQuery = (new Query())
            ->select(['assetId'])
            ->distinct()
            ->from(Install::TABLE_ASSET_TAGS)
            ->where(['tagNormalized' => $normalizedTags]);

        $query->andWhere(['assets.id' => $subQuery]);
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
     * Filter assets by whether they have unresolved duplicates.
     */
    private function applyDuplicatesFilter(Query $query, array $filters): void
    {
        if (!isset($filters['hasDuplicates'])) {
            return;
        }

        if (!DuplicateSupport::isAvailable()) {
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
     * Filter to assets the bulk analysis job will pick up: no analysis row, or
     * a status in AnalysisStatus::unprocessedStatuses(). LEFT JOIN is active so
     * NULL lens.assetId (no record) is included. Failed is a subset: it's
     * counted here but keeps its own badge and dashboard tile.
     */
    private function applyUnprocessedFilter(Query $query, array $filters): void
    {
        if (empty($filters['unprocessed'])) {
            return;
        }

        $query->andWhere(['or',
            ['lens.assetId' => null],
            ['in', 'lens.status', AnalysisStatus::unprocessedStatuses()],
        ]);
    }

    /**
     * Filter assets by a single quality issue. Accepts one of:
     * blurry, tooDark, tooBright, lowContrast.
     */
    private function applyQualityIssueFilter(Query $query, array $filters): void
    {
        $issue = $filters['qualityIssue'] ?? null;

        if ($issue === null || $issue === '') {
            return;
        }

        if (!QualitySupport::isAvailable()) {
            return;
        }

        match ($issue) {
            'blurry' => $query->andWhere([
                'and',
                ['<', 'lens.sharpnessScore', ImageMetricsAnalyzer::SHARPNESS_BLURRY],
                ['not', ['lens.sharpnessScore' => null]],
            ]),
            'tooDark' => $query->andWhere([
                'and',
                ['<', 'lens.exposureScore', ImageMetricsAnalyzer::BRIGHTNESS_DARK_MEDIAN],
                ['>', 'lens.shadowClipRatio', ImageMetricsAnalyzer::SHADOW_CLIP_RATIO],
                ['not', ['lens.exposureScore' => null]],
            ]),
            'tooBright' => $query->andWhere([
                'and',
                ['>', 'lens.exposureScore', ImageMetricsAnalyzer::BRIGHTNESS_BRIGHT_MEDIAN],
                ['>', 'lens.highlightClipRatio', ImageMetricsAnalyzer::HIGHLIGHT_CLIP_RATIO],
                ['not', ['lens.exposureScore' => null]],
            ]),
            'lowContrast' => $query->andWhere([
                'and',
                ['<', 'lens.noiseScore', ImageMetricsAnalyzer::CONTRAST_LOW],
                ['not', ['lens.noiseScore' => null]],
            ]),
            default => null,
        };
    }

    /**
     * Filter assets by file size threshold in megabytes. The preset value is
     * an integer N, matched as assets.size >= N * 1 MiB.
     */
    private function applyFileSizeFilter(Query $query, array $filters): void
    {
        $preset = $filters['fileSizePreset'] ?? null;

        if ($preset === null || $preset === '') {
            return;
        }

        $megabytes = (int) $preset;

        if ($megabytes <= 0) {
            return;
        }

        $query->andWhere(['>=', 'assets.size', $megabytes * 1_048_576]);
    }

    /**
     * Filter assets by AI provider and model (exact match).
     */
    private function applyProviderFilter(Query $query, array $filters): void
    {
        if (!empty($filters['provider'])) {
            $query->andWhere(['lens.provider' => $filters['provider']]);
        }

        if (!empty($filters['providerModel'])) {
            $query->andWhere(['lens.providerModel' => $filters['providerModel']]);
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
     * Get status options for filter dropdown.
     *
     * @return array<array{value: string, label: string}>
     */
    public function getStatusOptions(): array
    {
        return [
            ['value' => AnalysisStatus::Completed->value, 'label' => AnalysisStatus::Completed->label()],
            ['value' => AnalysisStatus::Pending->value, 'label' => AnalysisStatus::Pending->label()],
            ['value' => AnalysisStatus::Processing->value, 'label' => AnalysisStatus::Processing->label()],
            ['value' => AnalysisStatus::Failed->value, 'label' => AnalysisStatus::Failed->label()],
        ];
    }

    /**
     * Distinct provider values present in the analyses table, sorted alphabetically.
     *
     * @return array<array{value: string, label: string}>
     */
    public function getProviderOptions(): array
    {
        $rows = (new Query())
            ->select(['provider'])
            ->distinct()
            ->from(Install::TABLE_ASSET_ANALYSES)
            ->where(['not', ['provider' => null]])
            ->andWhere(['!=', 'provider', ''])
            ->orderBy(['provider' => SORT_ASC])
            ->column();

        $providers = Plugin::getInstance()->aiProvider->getAllProviders();

        return array_map(
            function(string $name) use ($providers) {
                $label = isset($providers[$name]) ? $providers[$name]->getDisplayName() : ucfirst($name);
                return ['value' => $name, 'label' => $label];
            },
            $rows,
        );
    }

    /**
     * Distinct provider models, optionally narrowed to one provider. Used to
     * populate the Model dropdown dependent on the Provider selection.
     *
     * @return array<array{value: string, label: string}>
     */
    public function getProviderModelOptions(?string $provider = null): array
    {
        $query = (new Query())
            ->select(['providerModel'])
            ->distinct()
            ->from(Install::TABLE_ASSET_ANALYSES)
            ->where(['not', ['providerModel' => null]])
            ->andWhere(['!=', 'providerModel', '']);

        if ($provider !== null && $provider !== '') {
            $query->andWhere(['provider' => $provider]);
        }

        $rows = $query->orderBy(['providerModel' => SORT_ASC])->column();

        return array_map(
            fn(string $model) => ['value' => $model, 'label' => $model],
            $rows,
        );
    }

    /**
     * Options for the watermark-type multi-select.
     *
     * @return array<array{value: string, label: string}>
     */
    public function getWatermarkTypeOptions(): array
    {
        $out = [];
        foreach (WatermarkType::options() as $value => $label) {
            $out[] = ['value' => $value, 'label' => $label];
        }
        return $out;
    }

    /**
     * Options for the single-select quality issue filter.
     *
     * @return array<array{value: string, label: string}>
     */
    public function getQualityIssueOptions(): array
    {
        return [
            ['value' => '', 'label' => Craft::t('lens', 'Any')],
            ['value' => 'blurry', 'label' => Craft::t('lens', 'Blurry')],
            ['value' => 'tooDark', 'label' => Craft::t('lens', 'Too dark')],
            ['value' => 'tooBright', 'label' => Craft::t('lens', 'Too bright')],
            ['value' => 'lowContrast', 'label' => Craft::t('lens', 'Low contrast')],
        ];
    }

    /**
     * Options for the file-size preset button group.
     *
     * @return array<array{value: string, label: string}>
     */
    public function getFileSizePresets(): array
    {
        return [
            ['value' => '', 'label' => Craft::t('lens', 'Any')],
            ['value' => '5', 'label' => Craft::t('lens', '> 5 MB')],
            ['value' => '10', 'label' => Craft::t('lens', '> 10 MB')],
            ['value' => '25', 'label' => Craft::t('lens', '> 25 MB')],
            ['value' => '50', 'label' => Craft::t('lens', '> 50 MB')],
        ];
    }

    /**
     * Compose the filter registry consumed by the filter-picker JS.
     *
     * Groups every user-facing filter into one payload: section, label, popover
     * type, and options (where applicable). The JS module renders the picker
     * list and each value popover from this single shape — keeping the filter
     * catalog in PHP so labels and sections stay in sync with `FilterField`
     * and `QuickFilter`.
     *
     * @param array<string, mixed> $currentFilters The already-parsed filters
     *        from `FilterParser::fromRequest`, used to pre-seed `providerModel`
     *        options with the models of the currently selected provider.
     * @return array<string, array{label: string, section: string, type: string, options?: array}>
     */
    public function getFilterRegistry(array $currentFilters = []): array
    {
        $triState = function(string $yesLabel, string $noLabel): array {
            return [
                'type' => 'tri-state',
                'triStateLabels' => [
                    'any' => Craft::t('lens', 'Any'),
                    'yes' => $yesLabel,
                    'no' => $noLabel,
                ],
            ];
        };

        $registry = [
            'containsPeople' => ['label' => Craft::t('lens', 'People'), 'section' => 'content']
                + $triState(Craft::t('lens', 'With people'), Craft::t('lens', 'Without people')),
            'faceCountPreset' => [
                'label' => Craft::t('lens', 'Face count'),
                'section' => 'content',
                'type' => 'preset-buttons',
                'options' => [
                    ['value' => '0', 'label' => '0'],
                    ['value' => '1', 'label' => '1'],
                    ['value' => '2-5', 'label' => '2-5'],
                    ['value' => '6+', 'label' => '6+'],
                ],
            ],
            'hasTextInImage' => ['label' => Craft::t('lens', 'Text in image'), 'section' => 'content']
                + $triState(Craft::t('lens', 'Contains text'), Craft::t('lens', 'No text')),
            'hasWatermark' => ['label' => Craft::t('lens', 'Watermark'), 'section' => 'content']
                + $triState(Craft::t('lens', 'Watermarked'), Craft::t('lens', 'Clean')),
            'watermarkType' => [
                'label' => Craft::t('lens', 'Watermark type'),
                'section' => 'content',
                'type' => 'multi-select',
                'options' => $this->getWatermarkTypeOptions(),
            ],
            'containsBrandLogo' => ['label' => Craft::t('lens', 'Brand logo'), 'section' => 'content']
                + $triState(Craft::t('lens', 'Has brand logo'), Craft::t('lens', 'No brand logo')),
            'nsfwScore' => [
                'label' => Craft::t('lens', 'NSFW score'),
                'section' => 'content',
                'type' => 'range',
                'min' => 0,
                'max' => 1,
                'step' => 0.05,
                'params' => ['min' => 'nsfwScoreMin', 'max' => 'nsfwScoreMax'],
            ],
            'qualityIssue' => [
                'label' => Craft::t('lens', 'Quality issue'),
                'section' => 'technical',
                'type' => 'single-select',
                'options' => $this->getQualityIssueOptions(),
            ],
            'fileSizePreset' => [
                'label' => Craft::t('lens', 'File size'),
                'section' => 'technical',
                'type' => 'preset-buttons',
                'options' => $this->getFileSizePresets(),
            ],
            'hasFocalPoint' => ['label' => Craft::t('lens', 'Focal point'), 'section' => 'technical']
                + $triState(Craft::t('lens', 'Focal point set'), Craft::t('lens', 'Not set')),
            'status' => [
                'label' => Craft::t('lens', 'Status'),
                'section' => 'workflow',
                'type' => 'multi-select',
                'options' => $this->getStatusOptions(),
            ],
            'provider' => [
                'label' => Craft::t('lens', 'Provider'),
                'section' => 'workflow',
                'type' => 'provider',
                'options' => $this->getProviderOptions(),
                'modelOptions' => $this->getProviderModelOptions($currentFilters['provider'] ?? null),
                'modelsEndpoint' => 'lens/search/provider-models',
                'params' => ['provider' => 'provider', 'model' => 'providerModel'],
            ],
            'processedDate' => [
                'label' => Craft::t('lens', 'Date analyzed'),
                'section' => 'workflow',
                'type' => 'date-range',
                'params' => ['from' => 'processedFrom', 'to' => 'processedTo'],
            ],
            'hasDuplicates' => ['label' => Craft::t('lens', 'Duplicates'), 'section' => 'workflow']
                + $triState(Craft::t('lens', 'Has duplicates'), Craft::t('lens', 'Unique')),
            'tags' => [
                'label' => Craft::t('lens', 'Tags'),
                'section' => 'tags',
                'type' => 'tags',
                'params' => ['tags' => 'tags', 'operator' => 'tagOperator'],
            ],
        ];

        if (!QualitySupport::isAvailable()) {
            unset($registry['qualityIssue']);
        }

        if (!DuplicateSupport::isAvailable()) {
            unset($registry['hasDuplicates']);
        }

        return $registry;
    }

    /**
     * Flatten parsed filters to a JS-safe snapshot. `DateTime` instances
     * become `Y-m-d` strings so the filter-picker can JSON-encode active
     * values and rehydrate its popovers in edit mode.
     *
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    public function getActiveFilterSnapshot(array $filters): array
    {
        $snapshot = [];

        foreach ($filters as $key => $value) {
            if ($value instanceof \DateTimeInterface) {
                $snapshot[$key] = $value->format('Y-m-d');
                continue;
            }

            if (is_scalar($value) || is_array($value) || $value === null) {
                $snapshot[$key] = $value;
            }
        }

        return $snapshot;
    }

    /**
     * Labels for filter-picker sections, in display order.
     *
     * @return array<string, string>
     */
    public function getFilterSectionLabels(): array
    {
        return [
            'content' => Craft::t('lens', 'Content'),
            'technical' => Craft::t('lens', 'Technical'),
            'workflow' => Craft::t('lens', 'Workflow'),
            'tags' => Craft::t('lens', 'Tags'),
        ];
    }

    /**
     * Get quick filter definitions for the UI.
     *
     * Each entry includes the raw URL params the preset represents and whether
     * it is currently active against the provided filters.
     *
     * Each entry's `hrefParams` is the **merged** URL param set to navigate
     * to on click — additive with any other filters currently applied.
     * Clicking an active preset toggles its params off without touching the
     * rest; clicking an inactive preset overlays its params on top of the
     * current URL so presets compose with chips.
     *
     * @param array<string, mixed> $filters Parsed filter state (from `FilterParser`).
     * @param array<string, mixed> $rawQueryParams Raw current-request query params,
     *        used as the base for `hrefParams`.
     * @return array<string, array{key: string, label: string, icon: string, params: array<string, mixed>, active: bool, hrefParams: array<string, mixed>}>
     */
    public function getQuickFilters(array $filters = [], array $rawQueryParams = []): array
    {
        // Drop pagination cursor and the similar-to anchor when navigating
        // to a different curated view — neither survives a preset toggle.
        unset($rawQueryParams['offset'], $rawQueryParams['p']);

        $quickFilters = [];

        foreach (QuickFilter::cases() as $case) {
            $presetParams = $case->params();
            $active = $case->matches($filters);

            if ($active) {
                $hrefParams = array_diff_key($rawQueryParams, $presetParams);
            } else {
                $hrefParams = array_merge($rawQueryParams, $presetParams);
            }

            $quickFilters[$case->value] = [
                'key' => $case->value,
                'label' => Craft::t('lens', $case->label()),
                'icon' => $case->icon(),
                'params' => $presetParams,
                'active' => $active,
                'hrefParams' => $hrefParams,
            ];
        }

        return $quickFilters;
    }

    /**
     * Count the distinct filter "chips" currently active. Grouped keys
     * (provider/providerModel, nsfwScoreMin/Max, processedFrom/To) count
     * as one chip each. Used to decide whether the "Clear all" affordance
     * is worth showing — with a single chip, its own × already clears
     * everything.
     *
     * @param array<string, mixed> $filters
     */
    public function countActiveFilterChips(array $filters): int
    {
        $groups = [
            ['query'],
            ['tags'],
            ['containsPeople'],
            ['faceCountPreset'],
            ['qualityIssue'],
            ['fileSizePreset'],
            ['hasTextInImage'],
            ['hasWatermark'],
            ['watermarkType'],
            ['containsBrandLogo'],
            ['status'],
            ['provider', 'providerModel'],
            ['nsfwScoreMin', 'nsfwScoreMax'],
            ['processedFrom', 'processedTo'],
            ['hasDuplicates'],
            ['similarTo'],
            ['hasFocalPoint'],
            ['missingAltText'],
            ['unprocessed'],
        ];

        $count = 0;
        foreach ($groups as $group) {
            foreach ($group as $key) {
                $value = $filters[$key] ?? null;
                if ($value === null || $value === '' || $value === []) {
                    continue;
                }
                $count++;
                break;
            }
        }

        return $count;
    }
}
