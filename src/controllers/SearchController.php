<?php

declare(strict_types=1);

namespace vitordiniz22\craftlens\controllers;

use Craft;
use craft\elements\Asset;
use craft\web\Controller;
use vitordiniz22\craftlens\controllers\traits\RequiresAiProviderTrait;
use vitordiniz22\craftlens\enums\AnalysisStatus;
use vitordiniz22\craftlens\enums\QuickFilter;
use vitordiniz22\craftlens\enums\LogCategory;
use vitordiniz22\craftlens\helpers\FilterParser;
use vitordiniz22\craftlens\helpers\Logger;
use vitordiniz22\craftlens\Plugin;
use vitordiniz22\craftlens\records\AssetAnalysisRecord;
use vitordiniz22\craftlens\records\AssetColorRecord;
use vitordiniz22\craftlens\records\AssetTagRecord;
use yii\web\BadRequestHttpException;
use yii\web\Response;

/**
 * Controller for the Lens Asset Search.
 */
class SearchController extends Controller
{
    use RequiresAiProviderTrait;

    private const EXPORT_ROW_LIMIT = 10000;

    protected array|int|bool $allowAnonymous = false;

    public function actionIndex(): Response
    {
        $this->requireCpRequest();
        $this->requirePermission('accessPlugin-lens');

        Plugin::getInstance()->requireProEdition();

        $plugin = Plugin::getInstance();
        $request = Craft::$app->getRequest();

        $filters = FilterParser::fromRequest($request);

        $similarToAsset = null;

        if (isset($filters['similarTo'])) {
            $similarToAsset = Asset::find()
                ->id($filters['similarTo'])
                ->kind(Asset::KIND_IMAGE)
                ->one();

            if ($similarToAsset === null) {
                unset($filters['similarTo']);
            }
        }

        $results = $plugin->search->search($filters);

        $assetIds = array_map(fn($a) => $a->id, $results['assets']);
        $analysisMap = $plugin->assetAnalysis->getAnalysesByAssetIds($assetIds);
        $analysisIds = array_values(array_filter(array_map(
            fn($a) => $a->id ?? null,
            $analysisMap
        )));

        $tagsMap = $plugin->tagAggregation->getTagsForAnalyses($analysisIds);
        $colorsMap = $plugin->colorAggregation->getColorsForAnalyses($analysisIds);
        $dupCountsMap = $plugin->duplicateDetection->getUnresolvedDuplicateCountsForAssets($assetIds);

        $similarityMap = [];

        if ($similarToAsset !== null) {
            $similarityMap = $plugin->duplicateDetection->getSimilarityMapForAsset($similarToAsset->id);
        }

        return $this->renderTemplate('lens/_search/index', [
            'assets' => $results['assets'],
            'total' => $results['total'],
            'offset' => $results['offset'],
            'limit' => $results['limit'],
            'filters' => $filters,
            'statusOptions' => $plugin->search->getStatusOptions(),
            'quickFilters' => $plugin->search->getQuickFilters(),
            'quickFilterDerivedParams' => QuickFilter::derivedParamsMap(),
            'hasFilters' => FilterParser::hasAnyFilters($filters),
            'showFilterPanel' => false,
            'hasActiveFilters' => FilterParser::hasActiveFilters($filters),
            'analysisMap' => $analysisMap,
            'tagsMap' => $tagsMap,
            'colorsMap' => $colorsMap,
            'dupCountsMap' => $dupCountsMap,
            'similarityMap' => $similarityMap,
            'similarToAsset' => $similarToAsset,
        ]);
    }

    /**
     * Export search results as CSV.
     */
    public function actionExport(): Response
    {
        $this->requireCpRequest();
        $this->requirePermission('accessPlugin-lens');
        Plugin::getInstance()->requireProEdition();

        $plugin = Plugin::getInstance();
        $request = Craft::$app->getRequest();

        $filters = FilterParser::fromRequest($request);
        unset($filters['offset']);
        $filters['limit'] = self::EXPORT_ROW_LIMIT;

        try {
            $results = $plugin->search->search($filters);
        } catch (\Throwable $e) {
            Logger::error(LogCategory::AssetProcessing, 'CSV export query failed', exception: $e);
            throw $e;
        }

        if (count($results['assets']) >= self::EXPORT_ROW_LIMIT) {
            Logger::warning(LogCategory::AssetProcessing, 'CSV export hit row limit', context: ['limit' => self::EXPORT_ROW_LIMIT]);
        }

        $assetIds = array_map(fn($asset) => $asset->id, $results['assets']);

        if (!empty($assetIds)) {
            $results['assets'] = Asset::find()->id($assetIds)->siteId(Craft::$app->getSites()->getPrimarySite()->id)->with('volume')->all();
        }

        $output = fopen('php://temp', 'r+');

        try {
            fputcsv($output, [
                'Asset ID',
                'Title',
                'Filename',
                'URL',
                'Alt Text',
                'Alt Text Confidence',
                'Suggested Title',
                'Title Confidence',
                'Long Description',
                'Long Description Confidence',
                'Tags',
                'Colors',
                'Status',
                'Contains People',
                'Face Count',
                'Has Watermark',
                'Watermark Type',
                'Contains Brand Logo',
                'Detected Brands',
                'Sharpness Score',
                'Exposure Score',
                'Noise Score',
                'Overall Quality Score',
                'NSFW Score',
                'NSFW Flagged',
                'NSFW Categories',
                'Extracted Text',
                'Focal Point X',
                'Focal Point Y',
                'Focal Point Confidence',
                'Provider',
                'Model',
                'Input Tokens',
                'Output Tokens',
                'Cost',
                'Processed At',
            ]);

            $analysisMap = !empty($assetIds)
                ? AssetAnalysisRecord::find()->where(['assetId' => $assetIds])->indexBy('assetId')->all()
                : [];

            $analysisIds = array_map(fn($a) => $a->id, $analysisMap);

            $tagsByAnalysis = [];
            $colorsByAnalysis = [];

            if (!empty($analysisIds)) {
                foreach (AssetTagRecord::find()->where(['analysisId' => $analysisIds])->all() as $tag) {
                    $tagsByAnalysis[$tag->analysisId][] = $tag->tag;
                }

                foreach (AssetColorRecord::find()->where(['analysisId' => $analysisIds])->orderBy(['percentage' => SORT_DESC])->all() as $color) {
                    $colorsByAnalysis[$color->analysisId][] = $color->hex . ($color->percentage !== null ? ' (' . round($color->percentage * 100) . '%)' : '');
                }
            }

            foreach ($results['assets'] as $asset) {
                $analysis = $analysisMap[$asset->id] ?? null;

                if ($analysis === null && !empty($assetIds) && $asset->kind === Asset::KIND_IMAGE) {
                    Logger::warning(
                        LogCategory::AssetProcessing,
                        'Image asset missing from analysis map during CSV export',
                        assetId: $asset->id
                    );
                }

                $tags = '';
                $colors = '';
                $detectedBrands = '';
                $nsfwCategories = '';

                if ($analysis !== null) {
                    $tags = implode(', ', $tagsByAnalysis[$analysis->id] ?? []);
                    $colors = implode(', ', $colorsByAnalysis[$analysis->id] ?? []);
                    $detectedBrands = self::flattenJsonColumn($analysis->detectedBrands);
                    $nsfwCategories = self::flattenJsonColumn($analysis->nsfwCategories);
                }

                fputcsv($output, [
                    $asset->id,
                    $asset->title,
                    $asset->filename,
                    $asset->getUrl(),
                    $analysis?->altText ?? '',
                    self::formatConfidence($analysis?->altTextConfidence),
                    $analysis?->suggestedTitle ?? '',
                    self::formatConfidence($analysis?->titleConfidence),
                    $analysis?->longDescription ?? '',
                    self::formatConfidence($analysis?->longDescriptionConfidence),
                    $tags,
                    $colors,
                    $analysis ? AnalysisStatus::from($analysis->status)->label() : '',
                    $analysis !== null ? ($analysis->containsPeople ? 'Yes' : 'No') : '',
                    $analysis?->faceCount ?? '',
                    $analysis !== null ? ($analysis->hasWatermark ? 'Yes' : 'No') : '',
                    $analysis?->watermarkType ?? '',
                    $analysis !== null ? ($analysis->containsBrandLogo ? 'Yes' : 'No') : '',
                    $detectedBrands,
                    self::formatConfidence($analysis?->sharpnessScore),
                    self::formatConfidence($analysis?->exposureScore),
                    self::formatConfidence($analysis?->noiseScore),
                    self::formatConfidence($analysis?->overallQualityScore),
                    self::formatConfidence($analysis?->nsfwScore),
                    $analysis !== null ? ($analysis->isFlaggedNsfw ? 'Yes' : 'No') : '',
                    $nsfwCategories,
                    $analysis?->extractedText ?? '',
                    $analysis?->focalPointX ?? '',
                    $analysis?->focalPointY ?? '',
                    self::formatConfidence($analysis?->focalPointConfidence),
                    $analysis?->provider ?? '',
                    $analysis?->providerModel ?? '',
                    $analysis?->inputTokens ?? '',
                    $analysis?->outputTokens ?? '',
                    $analysis?->actualCost !== null ? '$' . number_format((float) $analysis->actualCost, 4) : '',
                    $analysis?->processedAt ?? '',
                ]);
            }

            rewind($output);
            $csv = stream_get_contents($output);
        } finally {
            fclose($output);
        }

        $response = Craft::$app->getResponse();
        $response->format = Response::FORMAT_RAW;
        $response->headers->set('Content-Type', 'text/csv; charset=utf-8');
        $response->headers->set('Content-Disposition', 'attachment; filename="lens-export-' . date('Y-m-d-His') . '.csv"');
        $response->data = $csv;

        Logger::info(LogCategory::AssetProcessing, 'CSV export completed', context: ['rowCount' => count($results['assets'])]);

        return $response;
    }

    /**
     * Resolve a duplicate pair (keep, delete, or ignore).
     */
    public function actionResolveDuplicate(): Response
    {
        $this->requireCpRequest();
        $this->requirePostRequest();
        $this->requireAcceptsJson();
        $this->requirePermission('accessPlugin-lens');
        Plugin::getInstance()->requireProEdition();

        $groupId = (int) $this->request->getRequiredBodyParam('groupId');
        $resolution = $this->request->getRequiredBodyParam('resolution');

        if ($groupId < 1) {
            throw new BadRequestHttpException('Invalid group ID');
        }

        $allowedResolutions = ['kept', 'deleted', 'ignored'];

        if (!in_array($resolution, $allowedResolutions, true)) {
            throw new BadRequestHttpException('Invalid resolution value');
        }

        $userId = Craft::$app->getUser()->getId();
        $success = Plugin::getInstance()->duplicateDetection->resolve($groupId, $resolution, $userId);

        return $this->asJson(['success' => $success]);
    }

    /**
     * Flatten a JSON-decoded array column to a CSV-friendly string.
     * Yii2 auto-decodes JSON columns, so $value is always array|null.
     * Elements may be scalar ("violence") or nested ({"category":"violence","score":0.8}).
     */
    private static function formatConfidence(?float $value): string
    {
        return $value !== null ? round($value * 100) . '%' : '';
    }

    private static function flattenJsonColumn(?array $value): string
    {
        if (empty($value)) {
            return '';
        }

        return implode(', ', array_map(
            fn($item) => is_scalar($item) ? (string) $item : json_encode($item),
            $value,
        ));
    }
}
