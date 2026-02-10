<?php

declare(strict_types=1);

namespace vitordiniz22\craftlens\controllers;

use Craft;
use craft\helpers\UrlHelper;
use craft\web\Controller;
use vitordiniz22\craftlens\enums\LogCategory;
use vitordiniz22\craftlens\helpers\FilterParser;
use vitordiniz22\craftlens\helpers\Logger;
use vitordiniz22\craftlens\Plugin;
use vitordiniz22\craftlens\records\AssetAnalysisRecord;
use vitordiniz22\craftlens\records\AssetTagRecord;
use yii\web\BadRequestHttpException;
use yii\web\Response;

/**
 * Controller for the Lens Asset Search.
 */
class SearchController extends Controller
{
    protected array|int|bool $allowAnonymous = false;

    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        if (!Plugin::getInstance()->setupStatus->isAiProviderConfigured()) {
            Craft::$app->getSession()->setError(
                Craft::t('lens', 'Please configure an AI provider API key before using this feature.')
            );
            $this->redirect(UrlHelper::cpUrl('lens/settings'));
            return false;
        }

        return true;
    }

    public function actionIndex(): Response
    {
        $this->requireCpRequest();
        $this->requirePermission('accessPlugin-lens');

        $plugin = Plugin::getInstance();
        $request = Craft::$app->getRequest();

        $filters = FilterParser::fromRequest($request);
        $results = $plugin->search->search($filters);

        return $this->renderTemplate('lens/_search/index', [
            'assets' => $results['assets'],
            'total' => $results['total'],
            'offset' => $results['offset'],
            'limit' => $results['limit'],
            'filters' => $filters,
            'allTags' => $plugin->search->getAllTags(),
            'statusOptions' => $plugin->search->getStatusOptions(),
            'colorFamilies' => $plugin->search->getColorFamilies(),
            'quickFilters' => $plugin->search->getQuickFilters(),
            'hasFilters' => FilterParser::hasActiveFilters($filters),
        ]);
    }

    /**
     * Export search results as CSV.
     */
    public function actionExport(): Response
    {
        $this->requireCpRequest();
        $this->requirePermission('accessPlugin-lens');

        $plugin = Plugin::getInstance();
        $request = Craft::$app->getRequest();

        $filters = FilterParser::fromRequest($request);
        unset($filters['offset']);
        $filters['limit'] = 10000;

        try {
            $results = $plugin->search->search($filters);
        } catch (\Throwable $e) {
            Logger::error(LogCategory::AssetProcessing, 'CSV export query failed', exception: $e);
            throw $e;
        }

        $assetIds = array_map(fn($asset) => $asset->id, $results['assets']);

        if (!empty($assetIds)) {
            $results['assets'] = \craft\elements\Asset::find()->id($assetIds)->with('volume')->all();
        }

        $output = fopen('php://temp', 'r+');

        fputcsv($output, [
            'Asset ID',
            'Title',
            'Filename',
            'URL',
            'Alt Text',
            'Confidence',
            'Tags',
            'Status',
            'Contains People',
            'Face Count',
            'Has Watermark',
            'Watermark Type',
            'Contains Brand Logo',
            'Quality Score',
            'NSFW Score',
            'Processed At',
        ]);

        $analysisMap = [];

        if (!empty($assetIds)) {
            foreach (AssetAnalysisRecord::find()->where(['assetId' => $assetIds])->all() as $analysis) {
                $analysisMap[$analysis->assetId] = $analysis;
            }
        }

        $tagsByAnalysis = [];
        $analysisIds = array_map(fn($a) => $a->id, $analysisMap);

        if (!empty($analysisIds)) {
            foreach (AssetTagRecord::find()->where(['analysisId' => $analysisIds])->all() as $tag) {
                $tagsByAnalysis[$tag->analysisId][] = $tag->tag;
            }
        }

        foreach ($results['assets'] as $asset) {
            $analysis = $analysisMap[$asset->id] ?? null;

            if ($analysis === null && !empty($assetIds)) {
                Logger::warning(
                    LogCategory::AssetProcessing,
                    'Asset missing from analysis map during CSV export',
                    assetId: $asset->id
                );
            }

            $tags = '';
            if ($analysis !== null) {
                $tags = implode(', ', $tagsByAnalysis[$analysis->id] ?? []);
            }

            fputcsv($output, [
                $asset->id,
                $asset->title,
                $asset->filename,
                $asset->getUrl(),
                $analysis?->altText ?? '',
                $analysis?->altTextConfidence !== null ? round($analysis->altTextConfidence * 100) . '%' : '',
                $tags,
                $analysis?->status ?? '',
                $analysis?->containsPeople ? 'Yes' : 'No',
                $analysis?->faceCount ?? 0,
                $analysis?->hasWatermark ? 'Yes' : 'No',
                $analysis?->watermarkType ?? '',
                $analysis?->containsBrandLogo ? 'Yes' : 'No',
                $analysis?->overallQualityScore !== null ? round($analysis->overallQualityScore * 100) . '%' : '',
                $analysis?->nsfwScore !== null ? round($analysis->nsfwScore * 100) . '%' : '',
                $analysis?->processedAt?->format('Y-m-d H:i:s') ?? '',
            ]);
        }

        rewind($output);
        $csv = stream_get_contents($output);
        fclose($output);

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
}
