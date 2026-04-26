<?php

declare(strict_types=1);

namespace vitordiniz22\craftlens\behaviors;

use Craft;
use craft\elements\db\AssetQuery;
use craft\helpers\Db;
use vitordiniz22\craftlens\conditions\FileTooLargeConditionRule;
use vitordiniz22\craftlens\enums\AnalysisStatus;
use vitordiniz22\craftlens\enums\LogCategory;
use vitordiniz22\craftlens\helpers\DuplicateSupport;
use vitordiniz22\craftlens\helpers\ImageMetricsAnalyzer;
use vitordiniz22\craftlens\helpers\Logger;
use vitordiniz22\craftlens\helpers\QualitySupport;
use vitordiniz22\craftlens\migrations\Install;
use vitordiniz22\craftlens\Plugin;
use yii\base\Behavior;
use yii\db\Query;

/**
 * Behavior that adds Lens-specific query parameters to AssetQuery.
 *
 * @property AssetQuery $owner
 */
class AssetQueryBehavior extends Behavior
{
    // Exposure score thresholds for "exposure issues" filter
    private const EXPOSURE_LOW_THRESHOLD = 0.3;
    private const EXPOSURE_HIGH_THRESHOLD = 0.7;

    public string|array|null $lensStatus = null;
    public ?bool $lensContainsPeople = null;
    public ?float $lensConfidenceBelow = null;
    public ?float $lensConfidenceAbove = null;
    public string|array|null $lensTag = null;
    /** @var string[]|null AI tags that must ALL be present on the asset */
    public ?array $lensTagsAll = null;
    public ?bool $lensNsfwFlagged = null;
    public ?float $lensNsfwScoreMin = null;
    public ?float $lensNsfwScoreMax = null;
    public ?bool $lensHasWatermark = null;
    public ?string $lensWatermarkType = null;
    public ?array $lensWatermarkTypes = null;
    public ?bool $lensContainsBrandLogo = null;
    public ?string $lensDetectedBrand = null;
    public ?bool $lensHasDuplicates = null;
    public ?int $lensSimilarTo = null;
    public ?string $lensTextSearch = null;
    public string|array|null $lensStockProvider = null;
    /** @var string|string[]|null */
    public string|array|null $lensProvider = null;
    /** @var string|string[]|null */
    public string|array|null $lensProviderModel = null;
    public ?string $lensFaceCountPreset = null;
    public ?float $lensFileSizeMinMb = null;
    public ?float $lensFileSizeMaxMb = null;
    public ?\DateTimeInterface $lensProcessedFrom = null;
    public ?\DateTimeInterface $lensProcessedTo = null;
    public ?bool $lensUnprocessed = null;

    // Quality filters
    public ?float $lensSharpnessBelow = null;
    public ?bool $lensExposureIssues = null;
    public ?bool $lensHasFocalPoint = null;
    public ?bool $lensBlurry = null;
    public ?bool $lensTooDark = null;
    public ?bool $lensTooBright = null;
    public ?bool $lensLowContrast = null;
    public ?bool $lensTooLarge = null;
    public ?bool $lensHasTextInImage = null;
    /** @var array[] Raw WHERE conditions requiring the lens join, used by complex condition rules */
    public array $lensRawWhereConditions = [];

    private static bool $flashShown = false;
    private static ?bool $schemaValid = null;
    /**
     * Sets the lens status filter.
     */
    public function lensStatus(string|array|null $value): AssetQuery
    {
        if (!Plugin::getInstance()->getIsPro()) {
            return $this->owner;
        }
        $this->lensStatus = $value;
        return $this->owner;
    }

    /**
     * Sets the contains people filter.
     */
    public function lensContainsPeople(?bool $value): AssetQuery
    {
        $this->lensContainsPeople = $value;
        return $this->owner;
    }

    /**
     * Sets the confidence below filter.
     */
    public function lensConfidenceBelow(?float $value): AssetQuery
    {
        $this->lensConfidenceBelow = $value;
        return $this->owner;
    }

    /**
     * Sets the confidence above filter.
     */
    public function lensConfidenceAbove(?float $value): AssetQuery
    {
        $this->lensConfidenceAbove = $value;
        return $this->owner;
    }

    /**
     * Filters assets by AI tag(s). Passing an array matches assets tagged with
     * ANY of the given tags; use `lensTagsAll()` for strict AND semantics.
     *
     * @param string|string[]|null $value
     */
    public function lensTag(string|array|null $value): AssetQuery
    {
        if (!Plugin::getInstance()->getIsPro()) {
            return $this->owner;
        }
        $this->lensTag = $value;
        return $this->owner;
    }

    /**
     * Filters assets that have ALL of the given AI tags.
     *
     * @param string[]|null $value
     */
    public function lensTagsAll(?array $value): AssetQuery
    {
        if (!Plugin::getInstance()->getIsPro()) {
            return $this->owner;
        }
        $this->lensTagsAll = $value;
        return $this->owner;
    }

    /**
     * Sets the NSFW flagged filter.
     */
    public function lensNsfwFlagged(?bool $value): AssetQuery
    {
        $this->lensNsfwFlagged = $value;
        return $this->owner;
    }

    /**
     * Sets the NSFW score lower bound (inclusive).
     */
    public function lensNsfwScoreMin(?float $value): AssetQuery
    {
        $this->lensNsfwScoreMin = $value;
        return $this->owner;
    }

    /**
     * Sets the NSFW score upper bound (inclusive).
     */
    public function lensNsfwScoreMax(?float $value): AssetQuery
    {
        $this->lensNsfwScoreMax = $value;
        return $this->owner;
    }

    /**
     * Face count bucket: '0', '1', '2-5', or '6+'.
     */
    public function lensFaceCountPreset(?string $value): AssetQuery
    {
        $this->lensFaceCountPreset = $value;
        return $this->owner;
    }

    /**
     * Minimum file size in megabytes (inclusive).
     */
    public function lensFileSizeMinMb(?float $value): AssetQuery
    {
        $this->lensFileSizeMinMb = $value;
        return $this->owner;
    }

    /**
     * Maximum file size in megabytes (inclusive).
     */
    public function lensFileSizeMaxMb(?float $value): AssetQuery
    {
        $this->lensFileSizeMaxMb = $value;
        return $this->owner;
    }

    /**
     * Start of the processedAt range (inclusive).
     */
    public function lensProcessedFrom(?\DateTimeInterface $value): AssetQuery
    {
        $this->lensProcessedFrom = $value;
        return $this->owner;
    }

    /**
     * End of the processedAt range (inclusive, exclusive internally via +1 day).
     */
    public function lensProcessedTo(?\DateTimeInterface $value): AssetQuery
    {
        $this->lensProcessedTo = $value;
        return $this->owner;
    }

    /**
     * Filter by AI provider name(s).
     *
     * @param string|string[]|null $value
     */
    public function lensProvider(string|array|null $value): AssetQuery
    {
        $this->lensProvider = $value;
        return $this->owner;
    }

    /**
     * Filter by AI provider model identifier(s).
     *
     * @param string|string[]|null $value
     */
    public function lensProviderModel(string|array|null $value): AssetQuery
    {
        $this->lensProviderModel = $value;
        return $this->owner;
    }

    /**
     * Filter to assets the bulk job treats as unprocessed: either no analysis
     * row, or a status in `AnalysisStatus::unprocessedStatuses()`.
     */
    public function lensUnprocessed(?bool $value): AssetQuery
    {
        $this->lensUnprocessed = $value;
        return $this->owner;
    }

    /**
     * Filter to assets in the same duplicate cluster as the given asset.
     * Requires the Pro tier and `DuplicateSupport`.
     */
    public function lensSimilarTo(?int $assetId): AssetQuery
    {
        if (!Plugin::getInstance()->getIsPro()) {
            return $this->owner;
        }
        if (!DuplicateSupport::isAvailable()) {
            return $this->owner;
        }
        $this->lensSimilarTo = $assetId;
        return $this->owner;
    }

    /**
     * Filters assets by watermark presence.
     */
    public function lensHasWatermark(?bool $value): AssetQuery
    {
        $this->lensHasWatermark = $value;
        return $this->owner;
    }

    /**
     * Filters assets by watermark type (single type).
     */
    public function lensWatermarkType(?string $value): AssetQuery
    {
        $this->lensWatermarkType = $value;
        return $this->owner;
    }

    /**
     * Filters assets by watermark types (multiple types).
     *
     * @param string[]|null $value
     */
    public function lensWatermarkTypes(?array $value): AssetQuery
    {
        $this->lensWatermarkTypes = $value;
        return $this->owner;
    }

    /**
     * Filters assets by brand logo presence.
     */
    public function lensContainsBrandLogo(?bool $value): AssetQuery
    {
        $this->lensContainsBrandLogo = $value;
        return $this->owner;
    }

    /**
     * Filters assets by a specific detected brand.
     */
    public function lensDetectedBrand(?string $value): AssetQuery
    {
        $this->lensDetectedBrand = $value;
        return $this->owner;
    }

    /**
     * Filters assets by stock photo provider.
     *
     * @param string|string[]|null $value
     */
    public function lensStockProvider(string|array|null $value): AssetQuery
    {
        if (!Plugin::getInstance()->getIsPro()) {
            return $this->owner;
        }
        $this->lensStockProvider = $value;
        return $this->owner;
    }

    /**
     * Sets the has duplicates filter.
     */
    public function lensHasDuplicates(?bool $value): AssetQuery
    {
        if (!Plugin::getInstance()->getIsPro()) {
            return $this->owner;
        }
        if (!DuplicateSupport::isAvailable()) {
            return $this->owner;
        }
        $this->lensHasDuplicates = $value;
        return $this->owner;
    }

    /**
     * Full-text search across extracted text in images.
     */
    public function lensTextSearch(?string $value): AssetQuery
    {
        if (!Plugin::getInstance()->getIsPro()) {
            return $this->owner;
        }
        $this->lensTextSearch = $value;
        return $this->owner;
    }

    /**
     * Filters assets by sharpness score below threshold.
     */
    public function lensSharpnessBelow(?float $value): AssetQuery
    {
        if (!QualitySupport::isAvailable()) {
            return $this->owner;
        }
        $this->lensSharpnessBelow = $value;
        return $this->owner;
    }

    /**
     * Filters assets with exposure issues (too dark or too bright).
     */
    public function lensExposureIssues(?bool $value): AssetQuery
    {
        if (!QualitySupport::isAvailable()) {
            return $this->owner;
        }
        $this->lensExposureIssues = $value;
        return $this->owner;
    }

    /**
     * Filters assets by whether they have a focal point set.
     */
    public function lensHasFocalPoint(?bool $value): AssetQuery
    {
        $this->lensHasFocalPoint = $value;
        return $this->owner;
    }

    /**
     * Filters assets that are blurry (low sharpness score).
     */
    public function lensBlurry(?bool $value): AssetQuery
    {
        if (!QualitySupport::isAvailable()) {
            return $this->owner;
        }
        $this->lensBlurry = $value;
        return $this->owner;
    }

    /**
     * Filters assets that are too dark (low exposure score).
     */
    public function lensTooDark(?bool $value): AssetQuery
    {
        if (!QualitySupport::isAvailable()) {
            return $this->owner;
        }
        $this->lensTooDark = $value;
        return $this->owner;
    }

    /**
     * Filters assets that are too bright (high exposure score).
     */
    public function lensTooBright(?bool $value): AssetQuery
    {
        if (!QualitySupport::isAvailable()) {
            return $this->owner;
        }
        $this->lensTooBright = $value;
        return $this->owner;
    }

    /**
     * Filters assets with low contrast.
     */
    public function lensLowContrast(?bool $value): AssetQuery
    {
        if (!QualitySupport::isAvailable()) {
            return $this->owner;
        }
        $this->lensLowContrast = $value;
        return $this->owner;
    }

    /**
     * Filters assets with file size >= 1MB.
     */
    public function lensTooLarge(?bool $value): AssetQuery
    {
        $this->lensTooLarge = $value;
        return $this->owner;
    }

    /**
     * Filters assets that contain embedded text (OCR).
     */
    public function lensHasTextInImage(?bool $value): AssetQuery
    {
        if (!Plugin::getInstance()->getIsPro()) {
            return $this->owner;
        }
        $this->lensHasTextInImage = $value;
        return $this->owner;
    }

    // ------------------------------------------------------------------
    // Public apply methods for condition rules (Flow B: subQuery exists)
    // Each checks property + subQuery, then delegates to the private method.
    // ------------------------------------------------------------------

    public function lensApplyStatusFilter(): void
    {
        if ($this->lensStatus !== null && $this->owner->subQuery !== null) {
            $this->safeApplyFilter(fn() => $this->applyStatusFilter(), 'StatusFilter', 'lensStatus');
        }
    }

    public function lensApplyContainsPeopleFilter(): void
    {
        if ($this->lensContainsPeople !== null && $this->owner->subQuery !== null) {
            $this->safeApplyFilter(
                fn() => $this->applySimpleFilter('lens.containsPeople', $this->lensContainsPeople),
                'ContainsPeopleFilter',
                'lensContainsPeople',
            );
        }
    }

    public function lensApplyNsfwFlaggedFilter(): void
    {
        if ($this->lensNsfwFlagged !== null && $this->owner->subQuery !== null) {
            $this->safeApplyFilter(
                fn() => $this->filterByNsfwFlagged((bool) $this->lensNsfwFlagged),
                'NsfwFlaggedFilter',
                'lensNsfwFlagged',
            );
        }
    }

    /**
     * NULL nsfwScore (unanalyzed) matches "not flagged", never "flagged".
     */
    private function filterByNsfwFlagged(bool $flagged): void
    {
        $this->ensureJoined();

        if ($flagged) {
            $this->owner->subQuery->andWhere(['>=', 'lens.nsfwScore', 0.5]);
        } else {
            $this->owner->subQuery->andWhere([
                'or',
                ['<', 'lens.nsfwScore', 0.5],
                ['lens.nsfwScore' => null],
            ]);
        }
    }

    public function lensApplyHasWatermarkFilter(): void
    {
        if ($this->lensHasWatermark !== null && $this->owner->subQuery !== null) {
            $this->safeApplyFilter(
                fn() => $this->applySimpleFilter('lens.hasWatermark', $this->lensHasWatermark),
                'HasWatermarkFilter',
                'lensHasWatermark',
            );
        }
    }

    public function lensApplyWatermarkTypesFilter(): void
    {
        if ($this->lensWatermarkTypes !== null && !empty($this->lensWatermarkTypes) && $this->owner->subQuery !== null) {
            $this->safeApplyFilter(
                fn() => $this->applySimpleFilter('lens.watermarkType', $this->lensWatermarkTypes),
                'WatermarkTypesFilter',
                'lensWatermarkTypes',
            );
        }
    }

    public function lensApplyContainsBrandLogoFilter(): void
    {
        if ($this->lensContainsBrandLogo !== null && $this->owner->subQuery !== null) {
            $this->safeApplyFilter(
                fn() => $this->applySimpleFilter('lens.containsBrandLogo', $this->lensContainsBrandLogo),
                'ContainsBrandLogoFilter',
                'lensContainsBrandLogo',
            );
        }
    }

    public function lensApplyStockProviderFilter(): void
    {
        if ($this->lensStockProvider !== null && $this->owner->subQuery !== null) {
            $this->safeApplyFilter(fn() => $this->applyStockProviderFilter(), 'StockProviderFilter', 'lensStockProvider');
        }
    }

    public function lensApplyHasFocalPointFilter(): void
    {
        if ($this->lensHasFocalPoint !== null && $this->owner->subQuery !== null) {
            $this->safeApplyFilter(fn() => $this->applyHasFocalPointFilter(), 'HasFocalPointFilter', 'lensHasFocalPoint');
        }
    }

    public function lensApplyTooLargeFilter(): void
    {
        if ($this->lensTooLarge !== null && $this->owner->subQuery !== null) {
            $this->safeApplyFilter(fn() => $this->applyTooLargeFilter(), 'TooLargeFilter', 'lensTooLarge');
        }
    }

    public function lensApplyHasTextInImageFilter(): void
    {
        if ($this->lensHasTextInImage !== null && $this->owner->subQuery !== null) {
            $this->safeApplyFilter(fn() => $this->applyHasTextInImageFilter(), 'HasTextInImageFilter', 'lensHasTextInImage');
        }
    }

    public function lensApplyRawWhereConditions(): void
    {
        if (!empty($this->lensRawWhereConditions) && $this->owner->subQuery !== null) {
            $this->safeApplyFilter(fn() => $this->applyRawWhereConditions(), 'RawWhereConditions', 'lensRawWhereConditions');
        }
    }

    /**
     * Append a WHERE fragment that requires the lens analyses join.
     * Used by composite condition rules (e.g. quality issues) that need
     * OR-across-subconditions and don't map to a single scope setter.
     *
     * @param array<mixed> $condition
     */
    public function lensAddRawWhere(array $condition): AssetQuery
    {
        $this->lensRawWhereConditions[] = $condition;
        return $this->owner;
    }

    public function lensApplyHasDuplicatesFilter(): void
    {
        if ($this->lensHasDuplicates !== null && $this->owner->subQuery !== null) {
            $this->safeApplyFilter(fn() => $this->applyHasDuplicatesFilter(), 'HasDuplicatesFilter', 'lensHasDuplicates');
        }
    }

    public function lensApplySimilarToFilter(): void
    {
        if ($this->lensSimilarTo !== null && $this->owner->subQuery !== null) {
            $this->safeApplyFilter(fn() => $this->applySimilarToFilter(), 'SimilarToFilter', 'lensSimilarTo');
        }
    }

    public function lensApplyTagFilter(): void
    {
        if ($this->lensTag !== null && $this->owner->subQuery !== null) {
            $this->safeApplyFilter(fn() => $this->applyTagFilter(), 'TagFilter', 'lensTag');
        }
    }

    public function lensApplyTagsAllFilter(): void
    {
        if ($this->lensTagsAll !== null && !empty($this->lensTagsAll) && $this->owner->subQuery !== null) {
            $this->safeApplyFilter(fn() => $this->applyTagsAllFilter(), 'TagsAllFilter', 'lensTagsAll');
        }
    }

    public function lensApplyNsfwScoreRangeFilter(): void
    {
        if (($this->lensNsfwScoreMin !== null || $this->lensNsfwScoreMax !== null) && $this->owner->subQuery !== null) {
            $this->safeApplyFilter(fn() => $this->applyNsfwScoreRangeFilter(), 'NsfwScoreRangeFilter');
        }
    }

    public function lensApplyFaceCountPresetFilter(): void
    {
        if ($this->lensFaceCountPreset !== null && $this->lensFaceCountPreset !== '' && $this->owner->subQuery !== null) {
            $this->safeApplyFilter(fn() => $this->applyFaceCountPresetFilter(), 'FaceCountPresetFilter', 'lensFaceCountPreset');
        }
    }

    public function lensApplyFileSizeRangeFilter(): void
    {
        if (($this->lensFileSizeMinMb !== null || $this->lensFileSizeMaxMb !== null) && $this->owner->subQuery !== null) {
            $this->safeApplyFilter(fn() => $this->applyFileSizeRangeFilter(), 'FileSizeRangeFilter');
        }
    }

    public function lensApplyProcessedDateFilter(): void
    {
        if (($this->lensProcessedFrom !== null || $this->lensProcessedTo !== null) && $this->owner->subQuery !== null) {
            $this->safeApplyFilter(fn() => $this->applyProcessedDateFilter(), 'ProcessedDateFilter');
        }
    }

    public function lensApplyProviderFilter(): void
    {
        if ($this->lensProvider !== null && $this->owner->subQuery !== null) {
            $this->safeApplyFilter(fn() => $this->applyProviderFilter(), 'ProviderFilter', 'lensProvider');
        }
    }

    public function lensApplyProviderModelFilter(): void
    {
        if ($this->lensProviderModel !== null && $this->owner->subQuery !== null) {
            $this->safeApplyFilter(fn() => $this->applyProviderModelFilter(), 'ProviderModelFilter', 'lensProviderModel');
        }
    }

    public function lensApplyUnprocessedFilter(): void
    {
        if ($this->lensUnprocessed !== null && $this->owner->subQuery !== null) {
            $this->safeApplyFilter(fn() => $this->applyUnprocessedFilter(), 'UnprocessedFilter', 'lensUnprocessed');
        }
    }

    public function events(): array
    {
        return [
            AssetQuery::EVENT_BEFORE_PREPARE => 'beforePrepare',
        ];
    }

    public function beforePrepare(): void
    {
        if (!$this->hasAnyLensFilter()) {
            return;
        }

        // Schema gate: verify the lens table exists before touching the query.
        // Catches missing tables from failed migrations, partial installs, etc.
        // Cached per-request (uses Yii2's schema cache, no extra DB query).
        if (!$this->isLensSchemaValid()) {
            $this->resetAllFilterProperties();
            $this->logFilterFailure('schemaValidation', new \RuntimeException(
                'Lens table ' . Install::TABLE_ASSET_ANALYSES . ' not found in database schema'
            ));
            return;
        }

        // Snapshot the clean subQuery BEFORE we touch it.
        // If anything goes wrong, we restore this and the query runs
        // as if Lens was never installed.
        $cleanSubQuery = clone $this->owner->subQuery;

        try {
            $this->applyLensFilters();
        } catch (\Throwable $e) {
            $this->owner->subQuery = $cleanSubQuery;
            $this->resetAllFilterProperties();
            $this->logFilterFailure('applyLensFilters', $e);
        }
    }

    private function applyLensFilters(): void
    {
        if ($this->lensStatus !== null) {
            $this->applyStatusFilter();
        }

        if ($this->lensContainsPeople !== null) {
            $this->applySimpleFilter('lens.containsPeople', $this->lensContainsPeople);
        }

        if ($this->lensConfidenceBelow !== null) {
            $this->ensureJoined();
            $this->owner->subQuery->andWhere(['<', 'lens.altTextConfidence', $this->lensConfidenceBelow]);
        }

        if ($this->lensConfidenceAbove !== null) {
            $this->ensureJoined();
            $this->owner->subQuery->andWhere(['>=', 'lens.altTextConfidence', $this->lensConfidenceAbove]);
        }

        if ($this->lensTag !== null) {
            $this->applyTagFilter();
        }

        if ($this->lensNsfwFlagged !== null) {
            $this->filterByNsfwFlagged((bool) $this->lensNsfwFlagged);
        }

        if ($this->lensHasWatermark !== null) {
            $this->applySimpleFilter('lens.hasWatermark', $this->lensHasWatermark);
        }

        if ($this->lensWatermarkType !== null) {
            $this->applySimpleFilter('lens.watermarkType', $this->lensWatermarkType);
        }

        if ($this->lensWatermarkTypes !== null && !empty($this->lensWatermarkTypes)) {
            $this->applySimpleFilter('lens.watermarkType', $this->lensWatermarkTypes);
        }

        if ($this->lensContainsBrandLogo !== null) {
            $this->applySimpleFilter('lens.containsBrandLogo', $this->lensContainsBrandLogo);
        }

        if ($this->lensDetectedBrand !== null) {
            $this->applyDetectedBrandFilter();
        }

        if ($this->lensStockProvider !== null) {
            $this->applyStockProviderFilter();
        }

        if ($this->lensHasDuplicates !== null) {
            $this->applyHasDuplicatesFilter();
        }

        if ($this->lensSimilarTo !== null) {
            $this->applySimilarToFilter();
        }

        if ($this->lensTagsAll !== null && !empty($this->lensTagsAll)) {
            $this->applyTagsAllFilter();
        }

        if ($this->lensNsfwScoreMin !== null || $this->lensNsfwScoreMax !== null) {
            $this->applyNsfwScoreRangeFilter();
        }

        if ($this->lensFaceCountPreset !== null && $this->lensFaceCountPreset !== '') {
            $this->applyFaceCountPresetFilter();
        }

        if ($this->lensFileSizeMinMb !== null || $this->lensFileSizeMaxMb !== null) {
            $this->applyFileSizeRangeFilter();
        }

        if ($this->lensProcessedFrom !== null || $this->lensProcessedTo !== null) {
            $this->applyProcessedDateFilter();
        }

        if ($this->lensProvider !== null) {
            $this->applyProviderFilter();
        }

        if ($this->lensProviderModel !== null) {
            $this->applyProviderModelFilter();
        }

        if ($this->lensUnprocessed !== null) {
            $this->applyUnprocessedFilter();
        }

        if ($this->lensTextSearch !== null) {
            $this->applyTextSearchFilter();
        }

        if ($this->lensSharpnessBelow !== null) {
            $this->applySharpnessBelowFilter();
        }

        if ($this->lensExposureIssues !== null) {
            $this->applyExposureIssuesFilter();
        }

        if ($this->lensHasFocalPoint !== null) {
            $this->applyHasFocalPointFilter();
        }

        if ($this->lensBlurry !== null) {
            $this->applyBlurryFilter();
        }

        if ($this->lensTooDark !== null) {
            $this->applyTooDarkFilter();
        }

        if ($this->lensTooBright !== null) {
            $this->applyTooBrightFilter();
        }

        if ($this->lensLowContrast !== null) {
            $this->applyLowContrastFilter();
        }

        if ($this->lensTooLarge !== null) {
            $this->applyTooLargeFilter();
        }

        if ($this->lensHasTextInImage !== null) {
            $this->applyHasTextInImageFilter();
        }

        if (!empty($this->lensRawWhereConditions)) {
            $this->applyRawWhereConditions();
        }
    }

    private function applyStatusFilter(): void
    {
        $status = $this->lensStatus;

        // Array of statuses from condition rule multi-select
        if (is_array($status)) {
            $this->ensureJoined();
            $this->owner->subQuery->andWhere(['lens.status' => $status]);
            return;
        }

        if ($status === 'untagged') {
            $this->ensureLeftJoined();
            $this->owner->subQuery->andWhere([
                'or',
                ['lens.id' => null],
                ['lens.status' => [
                    AnalysisStatus::Pending->value,
                    AnalysisStatus::Failed->value,
                ]],
            ]);
        } elseif ($status === 'analyzed') {
            $this->ensureJoined();
            $this->owner->subQuery->andWhere(['lens.status' => AnalysisStatus::Completed->value]);
        } else {
            $this->ensureJoined();
            $this->owner->subQuery->andWhere(['lens.status' => $status]);
        }
    }

    /**
     * Apply a simple equality filter on a Lens column.
     */
    private function applySimpleFilter(string $column, mixed $value): void
    {
        $this->ensureJoined();
        $this->owner->subQuery->andWhere([$column => $value]);
    }

    private function applyRawWhereConditions(): void
    {
        $this->ensureJoined();
        foreach ($this->lensRawWhereConditions as $condition) {
            $this->owner->subQuery->andWhere($condition);
        }
    }

    private function applyTagFilter(): void
    {
        $this->ensureJoined();
        $tagTable = Install::TABLE_ASSET_TAGS;

        $values = is_array($this->lensTag) ? $this->lensTag : [$this->lensTag];
        $values = array_map('mb_strtolower', array_filter($values, fn($v) => $v !== null && $v !== ''));

        if (empty($values)) {
            return;
        }

        $tagSubQuery = (new Query())
            ->select(['analysisId'])
            ->from(['t' => $tagTable])
            ->where('[[t.analysisId]] = [[lens.id]]')
            ->andWhere(['t.tagNormalized' => $values]);

        $this->owner->subQuery->andWhere(['exists', $tagSubQuery]);
    }

    private function applyTagsAllFilter(): void
    {
        $this->ensureJoined();
        $tagTable = Install::TABLE_ASSET_TAGS;

        $values = array_map('mb_strtolower', array_filter(
            $this->lensTagsAll,
            fn($v) => $v !== null && $v !== '',
        ));

        foreach ($values as $tag) {
            $existsSubQuery = (new Query())
                ->select(['analysisId'])
                ->from(['t' => $tagTable])
                ->where('[[t.analysisId]] = [[lens.id]]')
                ->andWhere(['t.tagNormalized' => $tag]);

            $this->owner->subQuery->andWhere(['exists', $existsSubQuery]);
        }
    }

    private function applyDetectedBrandFilter(): void
    {
        $this->ensureJoined();

        // Structural JSON match on the $[*].brand path. Works regardless of
        // how the database canonicalizes JSON whitespace on storage (MySQL 8
        // adds spaces after colons; MariaDB preserves verbatim).
        $this->owner->subQuery->andWhere(
            "JSON_SEARCH([[lens.detectedBrands]], 'one', :lensBrand, NULL, '$[*].brand') IS NOT NULL",
            [':lensBrand' => $this->lensDetectedBrand],
        );
    }

    private function applyStockProviderFilter(): void
    {
        $this->ensureJoined();

        $providers = is_array($this->lensStockProvider)
            ? $this->lensStockProvider
            : [$this->lensStockProvider];

        $placeholders = [];
        $params = [];

        foreach ($providers as $i => $provider) {
            $placeholder = ':lensStockProvider' . $i;
            $placeholders[] = $placeholder;
            $params[$placeholder] = strtolower($provider);
        }

        $in = implode(', ', $placeholders);
        $this->owner->subQuery->andWhere(
            "LOWER(JSON_UNQUOTE(JSON_EXTRACT([[lens.watermarkDetails]], '$.stockProvider'))) IN ({$in})",
            $params,
        );
    }

    private function applyHasDuplicatesFilter(): void
    {
        $dupTable = Install::TABLE_DUPLICATE_GROUPS;

        $subQuery = (new Query())
            ->from($dupTable . ' dup')
            ->where(['dup.resolution' => null])
            ->andWhere([
                'or',
                '[[dup.canonicalAssetId]] = [[elements.id]]',
                '[[dup.duplicateAssetId]] = [[elements.id]]',
            ]);

        if ($this->lensHasDuplicates) {
            $this->owner->subQuery->andWhere(['exists', $subQuery]);
        } else {
            $this->owner->subQuery->andWhere(['not exists', $subQuery]);
        }
    }

    /**
     * Narrow the result set to assets sharing a duplicate cluster with the
     * anchor asset. Delegates cluster resolution to DuplicateDetectionService,
     * which walks the union-find graph and returns every sibling ID.
     */
    private function applySimilarToFilter(): void
    {
        if (!DuplicateSupport::isAvailable()) {
            return;
        }

        $anchorId = (int) $this->lensSimilarTo;

        if ($anchorId <= 0) {
            return;
        }

        // Seed with the anchor + any direct pairs so the cluster walk has a basis.
        $directPairs = (new Query())
            ->select(['canonicalAssetId', 'duplicateAssetId'])
            ->from(Install::TABLE_DUPLICATE_GROUPS)
            ->where(['resolution' => null])
            ->andWhere([
                'or',
                ['canonicalAssetId' => $anchorId],
                ['duplicateAssetId' => $anchorId],
            ])
            ->all();

        $seedIds = [$anchorId];

        foreach ($directPairs as $pair) {
            $seedIds[] = (int) $pair['canonicalAssetId'];
            $seedIds[] = (int) $pair['duplicateAssetId'];
        }

        $seedIds = array_values(array_unique($seedIds));
        $clusterMap = Plugin::getInstance()->duplicateDetection->getClusterKeysForAssets($seedIds);

        if (!isset($clusterMap[$anchorId])) {
            $this->owner->subQuery->andWhere(['elements.id' => $anchorId]);
            return;
        }

        $anchorCluster = $clusterMap[$anchorId];
        $siblingIds = array_keys(array_filter(
            $clusterMap,
            fn(int $cluster) => $cluster === $anchorCluster,
        ));

        // Always include the anchor even if it isn't clustered (graceful fallback).
        if (!in_array($anchorId, $siblingIds, true)) {
            $siblingIds[] = $anchorId;
        }

        $this->owner->subQuery->andWhere(['elements.id' => $siblingIds]);
    }

    private function applyNsfwScoreRangeFilter(): void
    {
        $this->ensureJoined();

        if ($this->lensNsfwScoreMin !== null) {
            $this->owner->subQuery->andWhere(['>=', 'lens.nsfwScore', $this->lensNsfwScoreMin]);
        }

        if ($this->lensNsfwScoreMax !== null) {
            $this->owner->subQuery->andWhere(['<=', 'lens.nsfwScore', $this->lensNsfwScoreMax]);
        }
    }

    private function applyFaceCountPresetFilter(): void
    {
        $this->ensureJoined();

        match ($this->lensFaceCountPreset) {
            '0' => $this->owner->subQuery->andWhere(['lens.containsPeople' => false]),
            '1' => $this->owner->subQuery->andWhere(['lens.faceCount' => 1]),
            '2-5' => $this->owner->subQuery
                ->andWhere(['>=', 'lens.faceCount', 2])
                ->andWhere(['<=', 'lens.faceCount', 5]),
            '6+' => $this->owner->subQuery->andWhere(['>=', 'lens.faceCount', 6]),
            default => null,
        };
    }

    private function applyFileSizeRangeFilter(): void
    {
        $oneMib = 1_048_576;

        if ($this->lensFileSizeMinMb !== null) {
            $this->owner->subQuery->andWhere([
                '>=',
                'assets.size',
                (int) round($this->lensFileSizeMinMb * $oneMib),
            ]);
        }

        if ($this->lensFileSizeMaxMb !== null) {
            $this->owner->subQuery->andWhere([
                '<=',
                'assets.size',
                (int) round($this->lensFileSizeMaxMb * $oneMib),
            ]);
        }
    }

    private function applyProcessedDateFilter(): void
    {
        $this->ensureJoined();

        if ($this->lensProcessedFrom !== null) {
            $this->owner->subQuery->andWhere([
                '>=',
                'lens.processedAt',
                $this->lensProcessedFrom->format('Y-m-d H:i:s'),
            ]);
        }

        if ($this->lensProcessedTo !== null) {
            // Match the custom browser: inclusive end-of-day, implemented as `< (to + 1 day)`.
            $to = \DateTimeImmutable::createFromInterface($this->lensProcessedTo)->modify('+1 day');
            $this->owner->subQuery->andWhere([
                '<',
                'lens.processedAt',
                $to->format('Y-m-d H:i:s'),
            ]);
        }
    }

    private function applyProviderFilter(): void
    {
        $this->ensureJoined();
        $value = is_array($this->lensProvider) ? $this->lensProvider : [$this->lensProvider];
        $value = array_values(array_filter($value, fn($v) => $v !== null && $v !== ''));

        if (empty($value)) {
            return;
        }

        $this->owner->subQuery->andWhere(['lens.provider' => $value]);
    }

    private function applyProviderModelFilter(): void
    {
        $this->ensureJoined();
        $value = is_array($this->lensProviderModel) ? $this->lensProviderModel : [$this->lensProviderModel];
        $value = array_values(array_filter($value, fn($v) => $v !== null && $v !== ''));

        if (empty($value)) {
            return;
        }

        $this->owner->subQuery->andWhere(['lens.providerModel' => $value]);
    }

    private function applyUnprocessedFilter(): void
    {
        if ($this->lensUnprocessed) {
            $this->ensureLeftJoined();
            $this->owner->subQuery->andWhere([
                'or',
                ['lens.assetId' => null],
                ['in', 'lens.status', AnalysisStatus::unprocessedStatuses()],
            ]);
            return;
        }

        $this->ensureJoined();
        $this->owner->subQuery->andWhere([
            'not in',
            'lens.status',
            AnalysisStatus::unprocessedStatuses(),
        ]);
    }

    /**
     * Verify the Lens analysis table exists in the database.
     * Result cached per-request via static property.
     */
    private function isLensSchemaValid(): bool
    {
        if (self::$schemaValid !== null) {
            return self::$schemaValid;
        }

        try {
            $schema = Craft::$app->getDb()->getTableSchema(Install::TABLE_ASSET_ANALYSES);
            self::$schemaValid = $schema !== null;
        } catch (\Throwable) {
            self::$schemaValid = false;
        }

        return self::$schemaValid;
    }

    /**
     * Check whether any Lens filter property is set.
     */
    private function hasAnyLensFilter(): bool
    {
        return $this->lensStatus !== null
            || $this->lensContainsPeople !== null
            || $this->lensConfidenceBelow !== null
            || $this->lensConfidenceAbove !== null
            || $this->lensTag !== null
            || $this->lensTagsAll !== null
            || $this->lensNsfwFlagged !== null
            || $this->lensNsfwScoreMin !== null
            || $this->lensNsfwScoreMax !== null
            || $this->lensHasWatermark !== null
            || $this->lensWatermarkType !== null
            || $this->lensWatermarkTypes !== null
            || $this->lensContainsBrandLogo !== null
            || $this->lensDetectedBrand !== null
            || $this->lensHasDuplicates !== null
            || $this->lensSimilarTo !== null
            || $this->lensTextSearch !== null
            || $this->lensStockProvider !== null
            || $this->lensProvider !== null
            || $this->lensProviderModel !== null
            || $this->lensFaceCountPreset !== null
            || $this->lensFileSizeMinMb !== null
            || $this->lensFileSizeMaxMb !== null
            || $this->lensProcessedFrom !== null
            || $this->lensProcessedTo !== null
            || $this->lensUnprocessed !== null
            || $this->lensSharpnessBelow !== null
            || $this->lensExposureIssues !== null
            || $this->lensHasFocalPoint !== null
            || $this->lensBlurry !== null
            || $this->lensTooDark !== null
            || $this->lensTooBright !== null
            || $this->lensLowContrast !== null
            || $this->lensTooLarge !== null
            || $this->lensHasTextInImage !== null
            || !empty($this->lensRawWhereConditions);
    }

    /**
     * Safely execute a filter callback. If it throws, null the property
     * so Flow A skips it, log the error, and flash a one-time CP notice.
     * The query continues without the broken filter.
     */
    private function safeApplyFilter(callable $fn, string $filterName, ?string $propertyToNull = null): void
    {
        try {
            $fn();
        } catch (\Throwable $e) {
            if ($propertyToNull !== null && property_exists($this, $propertyToNull)) {
                // Use [] for array-typed properties to avoid TypeError with strict_types
                $this->{$propertyToNull} = is_array($this->{$propertyToNull}) ? [] : null;
            }

            $this->logFilterFailure($filterName, $e);
        }
    }

    /**
     * Null every Lens filter property to prevent partial re-application.
     */
    private function resetAllFilterProperties(): void
    {
        $this->lensStatus = null;
        $this->lensContainsPeople = null;
        $this->lensConfidenceBelow = null;
        $this->lensConfidenceAbove = null;
        $this->lensTag = null;
        $this->lensTagsAll = null;
        $this->lensNsfwFlagged = null;
        $this->lensNsfwScoreMin = null;
        $this->lensNsfwScoreMax = null;
        $this->lensHasWatermark = null;
        $this->lensWatermarkType = null;
        $this->lensWatermarkTypes = null;
        $this->lensContainsBrandLogo = null;
        $this->lensDetectedBrand = null;
        $this->lensHasDuplicates = null;
        $this->lensSimilarTo = null;
        $this->lensTextSearch = null;
        $this->lensStockProvider = null;
        $this->lensProvider = null;
        $this->lensProviderModel = null;
        $this->lensFaceCountPreset = null;
        $this->lensFileSizeMinMb = null;
        $this->lensFileSizeMaxMb = null;
        $this->lensProcessedFrom = null;
        $this->lensProcessedTo = null;
        $this->lensUnprocessed = null;
        $this->lensSharpnessBelow = null;
        $this->lensExposureIssues = null;
        $this->lensHasFocalPoint = null;
        $this->lensBlurry = null;
        $this->lensTooDark = null;
        $this->lensTooBright = null;
        $this->lensLowContrast = null;
        $this->lensTooLarge = null;
        $this->lensHasTextInImage = null;
        $this->lensRawWhereConditions = [];
    }

    /**
     * Log a filter failure and show a one-time CP notice (web only).
     */
    private function logFilterFailure(string $filterName, \Throwable $e): void
    {
        try {
            Logger::warning(
                LogCategory::QueryFilter,
                "Lens filter '{$filterName}' failed: {$e->getMessage()}",
                exception: $e,
                context: ['filter' => $filterName],
            );
        } catch (\Throwable) {
            Craft::warning(
                "[lens] Lens filter '{$filterName}' failed: {$e->getMessage()}",
                'lens',
            );
        }

        if (!self::$flashShown) {
            try {
                if (!Craft::$app->getRequest()->getIsConsoleRequest()) {
                    Craft::$app->getSession()->setNotice(
                        Craft::t('lens', 'One or more Lens filters could not be applied. Results may include unfiltered assets.')
                    );
                    self::$flashShown = true;
                }
            } catch (\Throwable) {
                // Session unavailable (queue worker, console)
            }
        }
    }

    /**
     * Check whether the lens table is already JOINed on the subQuery
     * by inspecting the actual query state, not a boolean flag.
     * This eliminates stale-flag bugs entirely.
     */
    private function isLensTableJoined(): bool
    {
        foreach ($this->owner->subQuery->join ?? [] as $join) {
            if (is_string($join[1] ?? null) && str_contains($join[1], 'lens_asset_analyses')) {
                return true;
            }
        }
        return false;
    }

    private function ensureJoined(): void
    {
        if ($this->isLensTableJoined()) {
            return;
        }

        $this->owner->subQuery->innerJoin(
            Install::TABLE_ASSET_ANALYSES . ' lens',
            '[[lens.assetId]] = [[elements.id]]'
        );
    }

    private function ensureLeftJoined(): void
    {
        if ($this->isLensTableJoined()) {
            return;
        }

        $this->owner->subQuery->leftJoin(
            Install::TABLE_ASSET_ANALYSES . ' lens',
            '[[lens.assetId]] = [[elements.id]]'
        );
    }

    /**
     * Substring-matches `lensTextSearch` against both the edited and AI-original
     * OCR columns. Both are JSON-encoded arrays of strings; LIKE on the
     * serialized value still finds matches inside any region.
     */
    private function applyTextSearchFilter(): void
    {
        if (empty($this->lensTextSearch)) {
            return;
        }

        $this->ensureJoined();
        $this->owner->subQuery->andWhere(['like', 'lens.extractedTextAi', $this->lensTextSearch]);
    }

    private function applySharpnessBelowFilter(): void
    {
        $this->ensureJoined();
        $this->owner->subQuery->andWhere(['and',
            ['not', ['lens.sharpnessScore' => null]],
            ['<', 'lens.sharpnessScore', $this->lensSharpnessBelow],
        ]);
    }

    private function applyExposureIssuesFilter(): void
    {
        $this->ensureJoined();

        if ($this->lensExposureIssues) {
            // Assets with exposure issues (too dark OR too bright)
            $this->owner->subQuery->andWhere([
                'or',
                ['<', 'lens.exposureScore', self::EXPOSURE_LOW_THRESHOLD],
                ['>', 'lens.exposureScore', self::EXPOSURE_HIGH_THRESHOLD],
            ]);
            $this->owner->subQuery->andWhere(['not', ['lens.exposureScore' => null]]);
        } else {
            // Assets with good exposure
            $this->owner->subQuery->andWhere([
                'and',
                ['>=', 'lens.exposureScore', self::EXPOSURE_LOW_THRESHOLD],
                ['<=', 'lens.exposureScore', self::EXPOSURE_HIGH_THRESHOLD],
            ]);
        }
    }

    private function applyHasFocalPointFilter(): void
    {
        if ($this->lensHasFocalPoint) {
            $this->owner->subQuery->andWhere(['not', ['assets.focalPoint' => null]]);
        } else {
            $this->owner->subQuery->andWhere(['assets.focalPoint' => null]);
        }
    }

    private function applyBlurryFilter(): void
    {
        if ($this->lensBlurry) {
            $this->ensureJoined();
            $this->owner->subQuery->andWhere(['<', 'lens.sharpnessScore', ImageMetricsAnalyzer::SHARPNESS_BLURRY]);
            $this->owner->subQuery->andWhere(['not', ['lens.sharpnessScore' => null]]);
        }
    }

    private function applyTooDarkFilter(): void
    {
        if ($this->lensTooDark) {
            $this->ensureJoined();
            $this->owner->subQuery->andWhere(['<', 'lens.exposureScore', ImageMetricsAnalyzer::BRIGHTNESS_DARK_MEDIAN]);
            $this->owner->subQuery->andWhere(['>', 'lens.shadowClipRatio', ImageMetricsAnalyzer::SHADOW_CLIP_RATIO]);
            $this->owner->subQuery->andWhere(['not', ['lens.exposureScore' => null]]);
        }
    }

    private function applyTooBrightFilter(): void
    {
        if ($this->lensTooBright) {
            $this->ensureJoined();
            $this->owner->subQuery->andWhere(['>', 'lens.exposureScore', ImageMetricsAnalyzer::BRIGHTNESS_BRIGHT_MEDIAN]);
            $this->owner->subQuery->andWhere(['>', 'lens.highlightClipRatio', ImageMetricsAnalyzer::HIGHLIGHT_CLIP_RATIO]);
            $this->owner->subQuery->andWhere(['not', ['lens.exposureScore' => null]]);
        }
    }

    private function applyLowContrastFilter(): void
    {
        if ($this->lensLowContrast) {
            $this->ensureJoined();
            $this->owner->subQuery->andWhere(['<', 'lens.noiseScore', ImageMetricsAnalyzer::CONTRAST_LOW]);
            $this->owner->subQuery->andWhere(['not', ['lens.noiseScore' => null]]);
        }
    }

    private function applyTooLargeFilter(): void
    {
        if ($this->lensTooLarge) {
            $this->owner->subQuery->andWhere(['>=', 'assets.size', FileTooLargeConditionRule::FILE_SIZE_WARNING]);
        }
    }

    /**
     * Presence filter for OCR text. extractedTextAi is a JSON array column.
     * "Has text" means the array is non-empty; "no text" means NULL or empty.
     * JSON_LENGTH returns NULL on NULL input and 0 on '[]', expressing the
     * intent without depending on the column's text-form representation.
     */
    private function applyHasTextInImageFilter(): void
    {
        $this->ensureJoined();

        if ($this->lensHasTextInImage) {
            $this->owner->subQuery->andWhere('JSON_LENGTH([[lens.extractedTextAi]]) > 0');
        } else {
            $this->owner->subQuery->andWhere(
                '[[lens.extractedTextAi]] IS NULL OR JSON_LENGTH([[lens.extractedTextAi]]) = 0',
            );
        }
    }
}
