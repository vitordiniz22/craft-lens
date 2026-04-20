<?php

declare(strict_types=1);

namespace vitordiniz22\craftlens\controllers;

use Craft;
use craft\elements\Asset;
use craft\web\Controller;
use vitordiniz22\craftlens\controllers\traits\RequiresAiProviderTrait;
use vitordiniz22\craftlens\controllers\traits\ValidatesIdsTrait;
use vitordiniz22\craftlens\helpers\FilterParser;
use vitordiniz22\craftlens\Plugin;
use yii\web\BadRequestHttpException;
use yii\web\Response;

/**
 * Controller for the Lens Asset Search.
 */
class SearchController extends Controller
{
    use RequiresAiProviderTrait;
    use ValidatesIdsTrait;

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
        $dupCountsMap = $plugin->duplicateDetection->getUnresolvedDuplicateCountsForAssets($assetIds);

        $similarityMap = [];

        if ($similarToAsset !== null) {
            $similarityMap = $plugin->duplicateDetection->getSimilarityMapForAsset($similarToAsset->id);
        }

        $clusterMap = [];

        if (!empty($filters['hasDuplicates']) && !empty($assetIds)) {
            $clusterMap = $plugin->duplicateDetection->getClusterKeysForAssets($assetIds);
        }

        return $this->renderTemplate('lens/_search/index', [
            'assets' => $results['assets'],
            'total' => $results['total'],
            'offset' => $results['offset'],
            'limit' => $results['limit'],
            'filters' => $filters,
            'filterRegistry' => $plugin->search->getFilterRegistry($filters),
            'filterSectionLabels' => $plugin->search->getFilterSectionLabels(),
            'activeFilterSnapshot' => $plugin->search->getActiveFilterSnapshot($filters),
            'quickFilters' => $plugin->search->getQuickFilters($filters, $request->getQueryParams()),
            'activeFilterCount' => $plugin->search->countActiveFilterChips($filters),
            'hasActiveFilters' => FilterParser::hasActiveFilters($filters),
            'analysisMap' => $analysisMap,
            'tagsMap' => $tagsMap,
            'dupCountsMap' => $dupCountsMap,
            'similarityMap' => $similarityMap,
            'similarToAsset' => $similarToAsset,
            'clusterMap' => $clusterMap,
            'assetBrowserLayout' => $plugin->userSettings->getAssetBrowserLayout()->value,
        ]);
    }

    /**
     * Return distinct provider models for the given provider as JSON. Powers
     * the dependent Model select in the filter dropdown.
     */
    public function actionProviderModels(): Response
    {
        $this->requireCpRequest();
        $this->requireAcceptsJson();
        $this->requirePermission('accessPlugin-lens');
        Plugin::getInstance()->requireProEdition();

        $provider = Craft::$app->getRequest()->getQueryParam('provider');

        if (!is_string($provider) || trim($provider) === '') {
            $provider = null;
        }

        $options = Plugin::getInstance()->search->getProviderModelOptions($provider);

        return $this->asJson(['options' => $options]);
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

        $groupId = $this->requireValidId('groupId', 'group ID');
        $resolution = $this->request->getRequiredBodyParam('resolution');

        $allowedResolutions = ['kept', 'deleted', 'ignored'];

        if (!in_array($resolution, $allowedResolutions, true)) {
            throw new BadRequestHttpException('Invalid resolution value');
        }

        $userId = Craft::$app->getUser()->getId();
        $success = Plugin::getInstance()->duplicateDetection->resolve($groupId, $resolution, $userId);

        return $this->asJson(['success' => $success]);
    }
}
