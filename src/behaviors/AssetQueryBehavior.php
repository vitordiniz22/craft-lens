<?php

declare(strict_types=1);

namespace vitordiniz22\craftlens\behaviors;

use Craft;
use craft\elements\db\AssetQuery;
use craft\helpers\Db;
use vitordiniz22\craftlens\enums\AnalysisStatus;
use vitordiniz22\craftlens\enums\LogCategory;
use vitordiniz22\craftlens\helpers\Logger;
use vitordiniz22\craftlens\helpers\ImageQualityChecker;
use vitordiniz22\craftlens\helpers\ImageMetricsAnalyzer;
use vitordiniz22\craftlens\migrations\Install;
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
    public ?bool $lensHasTags = null;
    public ?string $lensTag = null;
    public ?string $lensColor = null;
    public ?bool $lensNsfwFlagged = null;
    public ?bool $lensHasWatermark = null;
    public ?string $lensWatermarkType = null;
    public ?array $lensWatermarkTypes = null;
    public ?bool $lensContainsBrandLogo = null;
    public ?string $lensDetectedBrand = null;
    public ?bool $lensHasDuplicates = null;
    public ?string $lensTextSearch = null;
    public string|array|null $lensStockProvider = null;

    // Quality filters
    public ?float $lensQualityBelow = null;
    public ?float $lensSharpnessBelow = null;
    public ?bool $lensExposureIssues = null;
    public ?bool $lensHasFocalPoint = null;
    public ?bool $lensLowQuality = null;
    public ?bool $lensBlurry = null;
    public ?bool $lensTooDark = null;
    public ?bool $lensTooBright = null;
    public ?bool $lensLowContrast = null;
    public ?bool $lensTooLarge = null;
    public ?array $lensWebReadinessIssues = null;
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
     * Sets the has tags filter.
     */
    public function lensHasTags(?bool $value): AssetQuery
    {
        $this->lensHasTags = $value;
        return $this->owner;
    }

    /**
     * Filters assets by a specific AI tag.
     */
    public function lensTag(?string $value): AssetQuery
    {
        $this->lensTag = $value;
        return $this->owner;
    }

    /**
     * Filters assets by dominant color hex.
     */
    public function lensColor(?string $value): AssetQuery
    {
        $this->lensColor = $value;
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
        $this->lensStockProvider = $value;
        return $this->owner;
    }

    /**
     * Sets the has duplicates filter.
     */
    public function lensHasDuplicates(?bool $value): AssetQuery
    {
        $this->lensHasDuplicates = $value;
        return $this->owner;
    }

    /**
     * Full-text search across extracted text in images.
     */
    public function lensTextSearch(?string $value): AssetQuery
    {
        $this->lensTextSearch = $value;
        return $this->owner;
    }

    /**
     * Filters assets by overall quality score below threshold.
     */
    public function lensQualityBelow(?float $value): AssetQuery
    {
        $this->lensQualityBelow = $value;
        return $this->owner;
    }

    /**
     * Filters assets by sharpness score below threshold.
     */
    public function lensSharpnessBelow(?float $value): AssetQuery
    {
        $this->lensSharpnessBelow = $value;
        return $this->owner;
    }

    /**
     * Filters assets with exposure issues (too dark or too bright).
     */
    public function lensExposureIssues(?bool $value): AssetQuery
    {
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
     * Filters assets with low overall quality score.
     */
    public function lensLowQuality(?bool $value): AssetQuery
    {
        $this->lensLowQuality = $value;
        return $this->owner;
    }

    /**
     * Filters assets that are blurry (low sharpness score).
     */
    public function lensBlurry(?bool $value): AssetQuery
    {
        $this->lensBlurry = $value;
        return $this->owner;
    }

    /**
     * Filters assets that are too dark (low exposure score).
     */
    public function lensTooDark(?bool $value): AssetQuery
    {
        $this->lensTooDark = $value;
        return $this->owner;
    }

    /**
     * Filters assets that are too bright (high exposure score).
     */
    public function lensTooBright(?bool $value): AssetQuery
    {
        $this->lensTooBright = $value;
        return $this->owner;
    }

    /**
     * Filters assets with low contrast.
     */
    public function lensLowContrast(?bool $value): AssetQuery
    {
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
     * Filters assets by web readiness issues.
     *
     * @param string[]|null $value
     */
    public function lensWebReadinessIssues(?array $value): AssetQuery
    {
        $this->lensWebReadinessIssues = $value;
        return $this->owner;
    }

    /**
     * Filters assets that contain embedded text (OCR).
     */
    public function lensHasTextInImage(?bool $value): AssetQuery
    {
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

    public function lensApplyHasTagsFilter(): void
    {
        if ($this->lensHasTags !== null && $this->owner->subQuery !== null) {
            $this->safeApplyFilter(fn() => $this->applyHasTagsFilter(), 'HasTagsFilter', 'lensHasTags');
        }
    }

    public function lensApplyNsfwFlaggedFilter(): void
    {
        if ($this->lensNsfwFlagged !== null && $this->owner->subQuery !== null) {
            $this->safeApplyFilter(
                fn() => $this->applySimpleFilter('lens.isFlaggedNsfw', $this->lensNsfwFlagged),
                'NsfwFlaggedFilter',
                'lensNsfwFlagged',
            );
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

    public function lensApplyLowQualityFilter(): void
    {
        if ($this->lensLowQuality !== null && $this->owner->subQuery !== null) {
            $this->safeApplyFilter(fn() => $this->applyLowQualityFilter(), 'LowQualityFilter', 'lensLowQuality');
        }
    }

    public function lensApplyWebReadinessFilter(): void
    {
        if ($this->lensWebReadinessIssues !== null && !empty($this->lensWebReadinessIssues) && $this->owner->subQuery !== null) {
            $this->safeApplyFilter(fn() => $this->applyWebReadinessFilter(), 'WebReadinessFilter', 'lensWebReadinessIssues');
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

        if ($this->lensHasTags !== null) {
            $this->applyHasTagsFilter();
        }

        if ($this->lensTag !== null) {
            $this->applyTagFilter();
        }

        if ($this->lensColor !== null) {
            $this->applyColorFilter();
        }

        if ($this->lensNsfwFlagged !== null) {
            $this->applySimpleFilter('lens.isFlaggedNsfw', $this->lensNsfwFlagged);
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

        if ($this->lensTextSearch !== null) {
            $this->applyTextSearchFilter();
        }

        if ($this->lensQualityBelow !== null) {
            $this->applyQualityBelowFilter();
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

        if ($this->lensLowQuality !== null) {
            $this->applyLowQualityFilter();
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

        if ($this->lensWebReadinessIssues !== null && !empty($this->lensWebReadinessIssues)) {
            $this->applyWebReadinessFilter();
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
            $this->owner->subQuery->andWhere(['lens.status' => AnalysisStatus::analyzedValues()]);
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

    private function applyHasTagsFilter(): void
    {
        $this->ensureJoined();
        $tagTable = Install::TABLE_ASSET_TAGS;

        $tagSubQuery = (new Query())
            ->select(['analysisId'])
            ->from(['t' => $tagTable])
            ->where('[[t.analysisId]] = [[lens.id]]');

        if ($this->lensHasTags) {
            $this->owner->subQuery->andWhere(['exists', $tagSubQuery]);
        } else {
            $this->owner->subQuery->andWhere(['not exists', $tagSubQuery]);
        }
    }

    private function applyTagFilter(): void
    {
        $this->ensureJoined();
        $tagTable = Install::TABLE_ASSET_TAGS;

        $tagSubQuery = (new Query())
            ->select(['analysisId'])
            ->from(['t' => $tagTable])
            ->where('[[t.analysisId]] = [[lens.id]]')
            ->andWhere(['t.tag' => $this->lensTag]);

        $this->owner->subQuery->andWhere(['exists', $tagSubQuery]);
    }

    private function applyColorFilter(): void
    {
        $this->ensureJoined();
        $colorTable = Install::TABLE_ASSET_COLORS;

        $colorSubQuery = (new Query())
            ->select(['analysisId'])
            ->from(['c' => $colorTable])
            ->where('[[c.analysisId]] = [[lens.id]]')
            ->andWhere(['c.hex' => $this->lensColor]);

        $this->owner->subQuery->andWhere(['exists', $colorSubQuery]);
    }

    private function applyDetectedBrandFilter(): void
    {
        $this->ensureJoined();
        $brand = $this->lensDetectedBrand ?? '';
        $escapedBrand = Db::escapeForLike($brand);
        $escapedBrand = str_replace('"', '\\"', $escapedBrand);

        $this->owner->subQuery->andWhere([
            'like',
            'lens.detectedBrands',
            '%"brand":"' . $escapedBrand . '"%',
            false,
        ]);
    }

    private function applyStockProviderFilter(): void
    {
        $this->ensureJoined();

        $providers = is_array($this->lensStockProvider)
            ? $this->lensStockProvider
            : [$this->lensStockProvider];

        $conditions = ['or'];

        foreach ($providers as $provider) {
            $provider = strtolower($provider);
            $escapedProvider = Db::escapeForLike($provider);
            $escapedProvider = str_replace('"', '\\"', $escapedProvider);

            $conditions[] = [
                'like',
                'LOWER(lens.watermarkDetails)',
                '%"stockprovider":"' . $escapedProvider . '"%',
                false,
            ];
        }

        $this->owner->subQuery->andWhere($conditions);
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
            || $this->lensHasTags !== null
            || $this->lensTag !== null
            || $this->lensColor !== null
            || $this->lensNsfwFlagged !== null
            || $this->lensHasWatermark !== null
            || $this->lensWatermarkType !== null
            || $this->lensWatermarkTypes !== null
            || $this->lensContainsBrandLogo !== null
            || $this->lensDetectedBrand !== null
            || $this->lensHasDuplicates !== null
            || $this->lensTextSearch !== null
            || $this->lensStockProvider !== null
            || $this->lensQualityBelow !== null
            || $this->lensSharpnessBelow !== null
            || $this->lensExposureIssues !== null
            || $this->lensHasFocalPoint !== null
            || $this->lensLowQuality !== null
            || $this->lensBlurry !== null
            || $this->lensTooDark !== null
            || $this->lensTooBright !== null
            || $this->lensLowContrast !== null
            || $this->lensTooLarge !== null
            || $this->lensWebReadinessIssues !== null
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
        $this->lensHasTags = null;
        $this->lensTag = null;
        $this->lensColor = null;
        $this->lensNsfwFlagged = null;
        $this->lensHasWatermark = null;
        $this->lensWatermarkType = null;
        $this->lensWatermarkTypes = null;
        $this->lensContainsBrandLogo = null;
        $this->lensDetectedBrand = null;
        $this->lensHasDuplicates = null;
        $this->lensTextSearch = null;
        $this->lensStockProvider = null;
        $this->lensQualityBelow = null;
        $this->lensSharpnessBelow = null;
        $this->lensExposureIssues = null;
        $this->lensHasFocalPoint = null;
        $this->lensLowQuality = null;
        $this->lensBlurry = null;
        $this->lensTooDark = null;
        $this->lensTooBright = null;
        $this->lensLowContrast = null;
        $this->lensTooLarge = null;
        $this->lensWebReadinessIssues = null;
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

    private function applyTextSearchFilter(): void
    {
        if (empty($this->lensTextSearch)) {
            return;
        }

        $this->ensureJoined();
        $this->owner->subQuery->andWhere(['like', 'lens.extractedText', $this->lensTextSearch]);
    }

    private function applyQualityBelowFilter(): void
    {
        $this->ensureJoined();
        $this->owner->subQuery->andWhere(['and',
            ['not', ['lens.overallQualityScore' => null]],
            ['<', 'lens.overallQualityScore', $this->lensQualityBelow],
        ]);
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

    private function applyLowQualityFilter(): void
    {
        if ($this->lensLowQuality) {
            $this->ensureJoined();
            $this->owner->subQuery->andWhere(['<', 'lens.overallQualityScore', ImageMetricsAnalyzer::LOW_QUALITY_THRESHOLD]);
            $this->owner->subQuery->andWhere(['not', ['lens.overallQualityScore' => null]]);
        } else {
            $this->ensureLeftJoined();
            $this->owner->subQuery->andWhere([
                'or',
                ['lens.overallQualityScore' => null],
                ['>=', 'lens.overallQualityScore', ImageMetricsAnalyzer::LOW_QUALITY_THRESHOLD],
            ]);
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
            $this->owner->subQuery->andWhere(['<', 'lens.exposureScore', ImageMetricsAnalyzer::BRIGHTNESS_DARK]);
            $this->owner->subQuery->andWhere(['not', ['lens.exposureScore' => null]]);
        }
    }

    private function applyTooBrightFilter(): void
    {
        if ($this->lensTooBright) {
            $this->ensureJoined();
            $this->owner->subQuery->andWhere(['>', 'lens.exposureScore', ImageMetricsAnalyzer::BRIGHTNESS_BRIGHT]);
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
            $this->owner->subQuery->andWhere(['>=', 'assets.size', ImageQualityChecker::FILE_SIZE_WARNING]);
        }
    }

    private function applyWebReadinessFilter(): void
    {
        $this->ensureJoined();
        $conditions = ['or'];

        foreach ($this->lensWebReadinessIssues as $issue) {
            match ($issue) {
                'fileTooLarge' => $conditions[] = ['>=', 'assets.size', ImageQualityChecker::FILE_SIZE_WARNING],
                'resolutionTooSmall' => $conditions[] = [
                    'and',
                    ['>', 'assets.width', 0],
                    ['<', 'assets.width', ImageQualityChecker::MIN_WIDTH_RECOMMENDED],
                    ['not like', 'assets.filename', '%.svg', false],
                ],
                'resolutionOversized' => $conditions[] = [
                    'and',
                    ['>', 'assets.width', 0],
                    ['>', 'assets.width', ImageQualityChecker::MAX_WIDTH_RECOMMENDED],
                    ['not like', 'assets.filename', '%.svg', false],
                ],
                'unsupportedFormat' => $conditions[] = [
                    'or',
                    ['like', 'assets.filename', '%.tif', false],
                    ['like', 'assets.filename', '%.tiff', false],
                ],
                default => null,
            };
        }

        if (count($conditions) > 1) {
            $this->owner->subQuery->andWhere($conditions);
        }
    }

    private function applyHasTextInImageFilter(): void
    {
        $this->ensureJoined();

        if ($this->lensHasTextInImage) {
            $this->owner->subQuery->andWhere(['not', ['lens.extractedText' => null]]);
            $this->owner->subQuery->andWhere(['!=', 'lens.extractedText', '']);
        } else {
            $this->owner->subQuery->andWhere([
                'or',
                ['lens.extractedText' => null],
                ['lens.extractedText' => ''],
            ]);
        }
    }
}
