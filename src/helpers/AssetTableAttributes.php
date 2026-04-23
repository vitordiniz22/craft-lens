<?php

declare(strict_types=1);

namespace vitordiniz22\craftlens\helpers;

use Craft;
use craft\elements\Asset;
use craft\helpers\Html;
use vitordiniz22\craftlens\enums\AiProvider;
use vitordiniz22\craftlens\enums\AnalysisStatus;
use vitordiniz22\craftlens\enums\WatermarkType;
use vitordiniz22\craftlens\migrations\Install;
use vitordiniz22\craftlens\Plugin;
use vitordiniz22\craftlens\records\AssetAnalysisRecord;

/**
 * Sort options, table columns, and cell rendering for the native asset index.
 * Keeps Plugin.php thin: the event handlers there just delegate here.
 */
class AssetTableAttributes
{
    public const ATTR_STATUS = 'lensStatus';
    public const ATTR_PROVIDER = 'lensProvider';
    public const ATTR_TAGS = 'lensTags';
    public const ATTR_NSFW = 'lensNsfwScore';
    public const ATTR_DUPLICATES = 'lensDuplicates';
    public const ATTR_PEOPLE = 'lensPeople';
    public const ATTR_BRANDS = 'lensBrands';
    public const ATTR_WATERMARK = 'lensWatermark';

    private const TAG_DISPLAY_LIMIT = 5;

    /** @var array<int, AssetAnalysisRecord|false> request-scoped cache */
    private static array $analysisCache = [];

    /**
     * Sort options appended to the asset view dropdown. Each uses a correlated
     * subquery so we don't need to add an outer-query JOIN on lens_asset_analyses.
     * The table name is resolved up front so Yii doesn't have to expand the
     * `{{%...}}` placeholder inside a raw SQL fragment at execution time.
     *
     * @return array<int, array{label: string, orderBy: string, attribute?: string, defaultDir?: string}>
     */
    public static function sortOptions(): array
    {
        $table = Craft::$app->getDb()->getSchema()->getRawTableName(Install::TABLE_ASSET_ANALYSES);

        return [
            [
                'label' => Craft::t('lens', 'Lens — NSFW Score'),
                'orderBy' => "(SELECT nsfwScore FROM $table WHERE assetId = elements.id)",
                'attribute' => self::ATTR_NSFW,
                'defaultDir' => 'desc',
            ],
            [
                'label' => Craft::t('lens', 'Lens — Sharpness'),
                'orderBy' => "(SELECT sharpnessScore FROM $table WHERE assetId = elements.id)",
                'attribute' => 'lensSharpness',
                'defaultDir' => 'asc',
            ],
            [
                'label' => Craft::t('lens', 'Lens — Face Count'),
                'orderBy' => "(SELECT faceCount FROM $table WHERE assetId = elements.id)",
                'attribute' => 'lensFaceCount',
                'defaultDir' => 'desc',
            ],
            [
                'label' => Craft::t('lens', 'Lens — Processed Date'),
                'orderBy' => "(SELECT processedAt FROM $table WHERE assetId = elements.id)",
                'attribute' => 'lensProcessedAt',
                'defaultDir' => 'desc',
            ],
            [
                'label' => Craft::t('lens', 'Lens — Alt Text Confidence'),
                'orderBy' => "(SELECT altTextConfidence FROM $table WHERE assetId = elements.id)",
                'attribute' => 'lensAltTextConfidence',
                'defaultDir' => 'asc',
            ],
        ];
    }

    /**
     * Columns added to the asset table-view "Customize" panel.
     *
     * @return array<string, array{label: string}>
     */
    public static function tableAttributes(): array
    {
        $attrs = [
            self::ATTR_STATUS => ['label' => Craft::t('lens', 'Lens Status')],
            self::ATTR_PROVIDER => ['label' => Craft::t('lens', 'Lens Provider')],
            self::ATTR_NSFW => ['label' => Craft::t('lens', 'Lens NSFW Score')],
            self::ATTR_PEOPLE => ['label' => Craft::t('lens', 'Lens People')],
            self::ATTR_BRANDS => ['label' => Craft::t('lens', 'Lens Brands')],
            self::ATTR_WATERMARK => ['label' => Craft::t('lens', 'Lens Watermark')],
        ];

        if (Plugin::getInstance()->getIsPro()) {
            $attrs[self::ATTR_TAGS] = ['label' => Craft::t('lens', 'Lens Tags')];
            $attrs[self::ATTR_DUPLICATES] = ['label' => Craft::t('lens', 'Lens Duplicates')];
        }

        return $attrs;
    }

    /**
     * Default columns per source. Only Lens-managed sources get preselected;
     * native volume sources keep Craft's defaults untouched.
     *
     * @return string[]
     */
    public static function defaultAttributes(string $source): array
    {
        return match ($source) {
            'lens:all' => [self::ATTR_PROVIDER, self::ATTR_STATUS],
            'lens:not-analysed',
            'lens:failed' => [],
            'lens:nsfw-flagged' => [self::ATTR_NSFW, self::ATTR_PROVIDER, self::ATTR_STATUS],
            'lens:missing-alt-text' => [self::ATTR_STATUS],
            'lens:has-watermark' => [self::ATTR_WATERMARK, self::ATTR_PROVIDER, self::ATTR_STATUS],
            'lens:has-brand-logo' => [self::ATTR_BRANDS, self::ATTR_PROVIDER, self::ATTR_STATUS],
            'lens:contains-people' => [self::ATTR_PEOPLE, self::ATTR_PROVIDER, self::ATTR_STATUS],
            'lens:missing-focal-point' => [self::ATTR_STATUS],
            'lens:needs-review' => [self::ATTR_PROVIDER, self::ATTR_TAGS],
            default => [],
        };
    }

    /**
     * Render the HTML for a Lens-managed attribute. Returns null when the
     * attribute isn't one of ours so the caller can skip it.
     */
    public static function attributeHtml(Asset $asset, string $attribute): ?string
    {
        return match ($attribute) {
            self::ATTR_STATUS => self::statusHtml($asset),
            self::ATTR_PROVIDER => self::providerHtml($asset),
            self::ATTR_TAGS => self::tagsHtml($asset),
            self::ATTR_NSFW => self::nsfwHtml($asset),
            self::ATTR_DUPLICATES => self::duplicatesHtml($asset),
            self::ATTR_PEOPLE => self::peopleHtml($asset),
            self::ATTR_BRANDS => self::brandsHtml($asset),
            self::ATTR_WATERMARK => self::watermarkHtml($asset),
            default => null,
        };
    }

    private static function statusHtml(Asset $asset): string
    {
        $analysis = self::getAnalysis($asset->id);

        if ($analysis === null) {
            return Html::tag('span', Craft::t('lens', 'Not analysed'), [
                'class' => 'status-label-with-icon gray',
            ]);
        }

        $status = AnalysisStatus::tryFrom($analysis->status);
        $label = $status?->label() ?? $analysis->status;
        $color = match ($status) {
            AnalysisStatus::Completed, AnalysisStatus::Approved => 'green',
            AnalysisStatus::PendingReview => 'orange',
            AnalysisStatus::Pending, AnalysisStatus::Processing => 'blue',
            AnalysisStatus::Failed, AnalysisStatus::Rejected => 'red',
            default => 'gray',
        };

        return Html::tag('span', Html::encode($label), [
            'class' => "status-label-with-icon $color",
        ]);
    }

    private static function providerHtml(Asset $asset): string
    {
        $analysis = self::getAnalysis($asset->id);

        if ($analysis === null || $analysis->provider === null) {
            return '';
        }

        $providerLabel = AiProvider::tryFrom((string) $analysis->provider)?->label()
            ?? (string) $analysis->provider;

        $display = $analysis->providerModel
            ? "$providerLabel / $analysis->providerModel"
            : $providerLabel;

        return Html::encode($display);
    }

    /**
     * Renders the top tags as a comma-separated list. Plain text so the cell
     * renders correctly without needing Lens-specific CSS on the native asset
     * index (LensAsset only loads on `lens/*` routes).
     */
    private static function tagsHtml(Asset $asset): string
    {
        $analysis = self::getAnalysis($asset->id);

        if ($analysis === null) {
            return '';
        }

        $records = Plugin::getInstance()->tagAggregation->getTagsForAnalysis($analysis->id);
        $tags = array_slice(array_map(fn($r) => (string) $r->tag, $records), 0, self::TAG_DISPLAY_LIMIT);

        if (empty($tags)) {
            return '';
        }

        return Html::encode(implode(', ', $tags));
    }

    private static function nsfwHtml(Asset $asset): string
    {
        $analysis = self::getAnalysis($asset->id);

        if ($analysis === null || $analysis->nsfwScore === null) {
            return '';
        }

        $score = (float) $analysis->nsfwScore;
        $pct = (int) round($score * 100);
        $color = match (true) {
            $score >= 0.8 => 'red',
            $score >= 0.5 => 'orange',
            default => 'gray',
        };

        return Html::tag('span', "{$pct}%", [
            'class' => "status-label-with-icon $color",
        ]);
    }

    private static function watermarkHtml(Asset $asset): string
    {
        $analysis = self::getAnalysis($asset->id);

        if ($analysis === null || !$analysis->hasWatermark) {
            return '';
        }

        $type = $analysis->watermarkType
            ? WatermarkType::tryFrom((string) $analysis->watermarkType)
            : null;

        $label = $type?->label() ?? Craft::t('lens', 'Detected');

        $stockProvider = is_array($analysis->watermarkDetails)
            ? ($analysis->watermarkDetails['stockProvider'] ?? null)
            : null;

        if ($type === WatermarkType::Stock && $stockProvider) {
            $label .= ' (' . $stockProvider . ')';
        }

        return Html::encode($label);
    }

    private static function brandsHtml(Asset $asset): string
    {
        $analysis = self::getAnalysis($asset->id);

        if ($analysis === null || empty($analysis->detectedBrands)) {
            return '';
        }

        $names = [];
        foreach ($analysis->detectedBrands as $entry) {
            $brand = is_array($entry) ? ($entry['brand'] ?? null) : null;
            if ($brand !== null && $brand !== '') {
                $names[] = (string) $brand;
            }
        }

        if (empty($names)) {
            return '';
        }

        $names = array_slice($names, 0, self::TAG_DISPLAY_LIMIT);

        return Html::encode(implode(', ', $names));
    }

    private static function peopleHtml(Asset $asset): string
    {
        $analysis = self::getAnalysis($asset->id);

        if ($analysis === null) {
            return '';
        }

        if (!$analysis->containsPeople) {
            return Html::encode(Craft::t('lens', 'No people'));
        }

        $faceCount = (int) $analysis->faceCount;
        $label = match (true) {
            $faceCount === 0 => Craft::t('lens', 'People, no visible faces'),
            $faceCount === 1 => Craft::t('lens', '1 person'),
            $faceCount === 2 => Craft::t('lens', '2 people'),
            $faceCount <= 5 => Craft::t('lens', '3-5 people'),
            default => Craft::t('lens', '6+ people'),
        };

        return Html::encode($label);
    }

    private static function duplicatesHtml(Asset $asset): string
    {
        if (!DuplicateSupport::isAvailable()) {
            return '';
        }

        $counts = Plugin::getInstance()->duplicateDetection->getUnresolvedDuplicateCountsForAssets([$asset->id]);
        $count = $counts[$asset->id] ?? 0;

        if ($count === 0) {
            return '';
        }

        $label = Craft::t('lens', '{count, plural, =1{1 duplicate} other{# duplicates}}', ['count' => $count]);

        return Html::tag('span', Html::encode($label), [
            'class' => 'status-label-with-icon orange',
        ]);
    }

    private static function getAnalysis(int $assetId): ?AssetAnalysisRecord
    {
        if (!array_key_exists($assetId, self::$analysisCache)) {
            self::$analysisCache[$assetId] = Plugin::getInstance()->assetAnalysis->getAnalysis($assetId) ?? false;
        }

        $cached = self::$analysisCache[$assetId];

        return $cached === false ? null : $cached;
    }
}
