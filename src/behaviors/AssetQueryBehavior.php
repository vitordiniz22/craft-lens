<?php

declare(strict_types=1);

namespace vitordiniz22\craftlens\behaviors;

use craft\elements\db\AssetQuery;
use craft\helpers\Db;
use vitordiniz22\craftlens\enums\AnalysisStatus;
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
    public ?string $lensStatus = null;
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
    // EXIF/GPS filters
    public ?bool $lensHasGpsCoordinates = null;
    public ?bool $lensHasExifData = null;

    private bool $innerJoined = false;
    private bool $leftJoined = false;
    private bool $exifMetadataJoined = false;

    /**
     * Sets the lens status filter.
     */
    public function lensStatus(?string $value): AssetQuery
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
     * Filters assets by whether they have GPS coordinates.
     */
    public function lensHasGpsCoordinates(?bool $value): AssetQuery
    {
        $this->lensHasGpsCoordinates = $value;
        return $this->owner;
    }

    /**
     * Filters assets by whether they have EXIF metadata.
     */
    public function lensHasExifData(?bool $value): AssetQuery
    {
        $this->lensHasExifData = $value;
        return $this->owner;
    }

    /**
     * Ensures the lens analysis table is joined to the query.
     * Use this when you need to add custom conditions on the lens table
     * without using the built-in filter methods.
     */
    public function lensEnsureJoined(): AssetQuery
    {
        $this->ensureJoined();
        return $this->owner;
    }

    public function events(): array
    {
        return [
            AssetQuery::EVENT_BEFORE_PREPARE => 'beforePrepare',
        ];
    }

    public function beforePrepare(): void
    {
        $this->applyLensFilters();
    }

    private function applyLensFilters(): void
    {
        if ($this->lensStatus !== null) {
            $this->applyStatusFilter();
        }

        if ($this->lensContainsPeople !== null) {
            $this->ensureJoined();
            $this->owner->subQuery->andWhere(['lens.containsPeople' => $this->lensContainsPeople]);
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
            $this->ensureJoined();
            $this->owner->subQuery->andWhere(['lens.isFlaggedNsfw' => $this->lensNsfwFlagged]);
        }

        if ($this->lensHasWatermark !== null) {
            $this->ensureJoined();
            $this->owner->subQuery->andWhere(['lens.hasWatermark' => $this->lensHasWatermark]);
        }

        if ($this->lensWatermarkType !== null) {
            $this->ensureJoined();
            $this->owner->subQuery->andWhere(['lens.watermarkType' => $this->lensWatermarkType]);
        }

        if ($this->lensWatermarkTypes !== null && !empty($this->lensWatermarkTypes)) {
            $this->ensureJoined();
            $this->owner->subQuery->andWhere(['lens.watermarkType' => $this->lensWatermarkTypes]);
        }

        if ($this->lensContainsBrandLogo !== null) {
            $this->ensureJoined();
            $this->owner->subQuery->andWhere(['lens.containsBrandLogo' => $this->lensContainsBrandLogo]);
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

        if ($this->lensHasGpsCoordinates !== null) {
            $this->applyHasGpsCoordinatesFilter();
        }

        if ($this->lensHasExifData !== null) {
            $this->applyHasExifDataFilter();
        }
    }

    private function applyStatusFilter(): void
    {
        $status = $this->lensStatus;

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

    private function applyHasTagsFilter(): void
    {
        $this->ensureJoined();
        $tagTable = Install::TABLE_ASSET_TAGS;

        $tagSubQuery = (new Query())
            ->select(['analysisId'])
            ->from($tagTable)
            ->where('[[' . $tagTable . '.analysisId]] = [[lens.id]]');

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
            ->from($tagTable)
            ->where('[[' . $tagTable . '.analysisId]] = [[lens.id]]')
            ->andWhere(['tag' => $this->lensTag]);

        $this->owner->subQuery->andWhere(['exists', $tagSubQuery]);
    }

    private function applyColorFilter(): void
    {
        $this->ensureJoined();
        $colorTable = Install::TABLE_ASSET_COLORS;

        $colorSubQuery = (new Query())
            ->select(['analysisId'])
            ->from($colorTable)
            ->where('[[' . $colorTable . '.analysisId]] = [[lens.id]]')
            ->andWhere(['hex' => $this->lensColor]);

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

    private function ensureJoined(): void
    {
        if ($this->innerJoined || $this->leftJoined) {
            return;
        }

        $this->owner->subQuery->innerJoin(
            Install::TABLE_ASSET_ANALYSES . ' lens',
            '[[lens.assetId]] = [[elements.id]]'
        );

        $this->innerJoined = true;
    }

    private function ensureLeftJoined(): void
    {
        if ($this->leftJoined) {
            return;
        }

        if ($this->innerJoined) {
            return;
        }

        $this->owner->subQuery->leftJoin(
            Install::TABLE_ASSET_ANALYSES . ' lens',
            '[[lens.assetId]] = [[elements.id]]'
        );

        $this->leftJoined = true;
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
        $this->owner->subQuery->andWhere(['<', 'lens.overallQualityScore', $this->lensQualityBelow]);
        $this->owner->subQuery->andWhere(['not', ['lens.overallQualityScore' => null]]);
    }

    private function applySharpnessBelowFilter(): void
    {
        $this->ensureJoined();
        $this->owner->subQuery->andWhere(['<', 'lens.sharpnessScore', $this->lensSharpnessBelow]);
        $this->owner->subQuery->andWhere(['not', ['lens.sharpnessScore' => null]]);
    }

    private function applyExposureIssuesFilter(): void
    {
        $this->ensureJoined();

        if ($this->lensExposureIssues) {
            // Assets with exposure issues (too dark OR too bright)
            $this->owner->subQuery->andWhere([
                'or',
                ['<', 'lens.exposureScore', 0.3],
                ['>', 'lens.exposureScore', 0.7],
            ]);
            $this->owner->subQuery->andWhere(['not', ['lens.exposureScore' => null]]);
        } else {
            // Assets with good exposure
            $this->owner->subQuery->andWhere([
                'and',
                ['>=', 'lens.exposureScore', 0.3],
                ['<=', 'lens.exposureScore', 0.7],
            ]);
        }
    }

    private function ensureExifMetadataJoined(): void
    {
        if ($this->exifMetadataJoined) {
            return;
        }

        $this->owner->subQuery->leftJoin(
            Install::TABLE_EXIF_METADATA . ' lens_exif',
            '[[lens_exif.assetId]] = [[elements.id]]'
        );

        $this->exifMetadataJoined = true;
    }

    private function applyHasGpsCoordinatesFilter(): void
    {
        $this->ensureExifMetadataJoined();

        if ($this->lensHasGpsCoordinates) {
            $this->owner->subQuery->andWhere(['not', ['lens_exif.latitude' => null]]);
            $this->owner->subQuery->andWhere(['not', ['lens_exif.longitude' => null]]);
        } else {
            $this->owner->subQuery->andWhere([
                'or',
                ['lens_exif.latitude' => null],
                ['lens_exif.longitude' => null],
            ]);
        }
    }

    private function applyHasExifDataFilter(): void
    {
        $this->ensureExifMetadataJoined();

        if ($this->lensHasExifData) {
            $this->owner->subQuery->andWhere(['not', ['lens_exif.id' => null]]);
        } else {
            $this->owner->subQuery->andWhere(['lens_exif.id' => null]);
        }
    }
}
