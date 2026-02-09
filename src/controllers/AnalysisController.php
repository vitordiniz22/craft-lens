<?php

declare(strict_types=1);

namespace vitordiniz22\craftlens\controllers;

use Craft;
use craft\elements\Asset;
use craft\web\Controller;
use vitordiniz22\craftlens\enums\LogCategory;
use vitordiniz22\craftlens\helpers\Logger;
use vitordiniz22\craftlens\Plugin;
use yii\web\BadRequestHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * Controller for asset analysis actions.
 */
class AnalysisController extends Controller
{
    protected array|int|bool $allowAnonymous = false;

    public function actionReprocess(): Response
    {
        $this->requireCpRequest();
        $this->requirePostRequest();
        $this->requirePermission('accessPlugin-lens');

        $asset = $this->getRequiredAsset();

        Plugin::getInstance()->assetAnalysis->reprocessAsset($asset);

        Logger::info(LogCategory::AssetProcessing, 'Asset queued for reprocessing', assetId: $asset->id);

        if ($this->request->getAcceptsJson()) {
            register_shutdown_function(function () {
                try {
                    Craft::$app->getQueue()->run();
                } catch (\Throwable $e) {
                    // Silent fail - queue will be processed on next request
                }
            });

            return $this->asJson(['success' => true]);
        }

        Craft::$app->getSession()->setNotice(
            Craft::t('lens', 'Asset queued for reprocessing.')
        );

        return $this->redirectToPostedUrl();
    }

    public function actionApplyTitle(): Response
    {
        $this->requireCpRequest();
        $this->requirePostRequest();
        $this->requirePermission('accessPlugin-lens');

        $asset = $this->getRequiredAsset();

        $analysis = Plugin::getInstance()->assetAnalysis->getAnalysis($asset->id);

        if ($analysis === null || empty($analysis->suggestedTitle)) {
            throw new BadRequestHttpException('No suggested title available');
        }

        $asset->title = $analysis->suggestedTitle;
        $success = Craft::$app->getElements()->saveElement($asset);

        if ($this->request->getAcceptsJson()) {
            return $this->asJson([
                'success' => $success,
                'title' => $analysis->suggestedTitle,
            ]);
        }

        if ($success) {
            Craft::$app->getSession()->setNotice(Craft::t('lens', 'Title applied.'));
        } else {
            Logger::warning(LogCategory::AssetProcessing, 'Failed to apply suggested title', assetId: $asset->id);
            Craft::$app->getSession()->setError(Craft::t('lens', 'Failed to apply title.'));
        }

        return $this->redirectToPostedUrl();
    }

    public function actionApplyFocalPoint(): Response
    {
        $this->requireCpRequest();
        $this->requirePostRequest();
        $this->requirePermission('accessPlugin-lens');

        $asset = $this->getRequiredAsset();

        $analysis = Plugin::getInstance()->assetAnalysis->getAnalysis($asset->id);

        if ($analysis === null || $analysis->focalPointX === null || $analysis->focalPointY === null) {
            throw new BadRequestHttpException('No suggested focal point available');
        }

        $asset->setFocalPoint(['x' => (float) $analysis->focalPointX, 'y' => (float) $analysis->focalPointY]);
        $success = Craft::$app->getElements()->saveElement($asset);

        if ($this->request->getAcceptsJson()) {
            return $this->asJson([
                'success' => $success,
                'focalPoint' => ['x' => $analysis->focalPointX, 'y' => $analysis->focalPointY],
            ]);
        }

        if ($success) {
            Craft::$app->getSession()->setNotice(Craft::t('lens', 'Focal point applied.'));
        } else {
            Logger::warning(LogCategory::AssetProcessing, 'Failed to apply focal point', assetId: $asset->id);
            Craft::$app->getSession()->setError(Craft::t('lens', 'Failed to apply focal point.'));
        }

        return $this->redirectToPostedUrl();
    }

    /**
     * Get analysis status for polling.
     */
    public function actionGetStatus(): Response
    {
        $this->requireCpRequest();
        $this->requirePermission('accessPlugin-lens');

        $assetId = $this->request->getQueryParam('assetId');

        if ($assetId === null || $assetId === '') {
            $this->response->setStatusCode(400);
            return $this->asJson([
                'error' => 'Missing assetId parameter',
                'status' => 'error',
            ]);
        }

        $assetId = (int) $assetId;

        if ($assetId < 1) {
            $this->response->setStatusCode(400);
            return $this->asJson([
                'error' => 'Invalid asset ID',
                'status' => 'error',
            ]);
        }

        try {
            $analysis = Plugin::getInstance()->assetAnalysis->getAnalysis($assetId);

            if (!$analysis) {
                return $this->asJson([
                    'status' => 'not_found',
                    'processedAt' => null,
                ]);
            }

            return $this->asJson([
                'status' => $analysis->status,
                'processedAt' => $analysis->processedAt instanceof \DateTime
                    ? $analysis->processedAt->format('c')
                    : $analysis->processedAt,
            ]);
        } catch (\Throwable $e) {
            Logger::error(
                LogCategory::AssetProcessing,
                'Error in actionGetStatus: ' . $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine(),
                assetId: $assetId
            );

            $this->response->setStatusCode(500);
            return $this->asJson([
                'error' => 'Internal error: ' . $e->getMessage(),
                'status' => 'error',
            ]);
        }
    }

    public function actionRegenerateTitle(): Response
    {
        $this->requireCpRequest();
        $this->requirePostRequest();
        $this->requirePermission('accessPlugin-lens');

        $asset = $this->getRequiredAsset();

        Plugin::getInstance()->assetAnalysis->reprocessAsset($asset);

        Logger::info(LogCategory::AssetProcessing, 'Title regeneration queued', assetId: $asset->id);

        if ($this->request->getAcceptsJson()) {
            return $this->asJson(['success' => true]);
        }

        Craft::$app->getSession()->setNotice(Craft::t('lens', 'Regenerating title...'));
        return $this->redirectToPostedUrl();
    }

    /**
     * Update a single editable field on an analysis record.
     */
    public function actionUpdateField(): Response
    {
        $this->requireCpRequest();
        $this->requirePostRequest();
        $this->requirePermission('accessPlugin-lens');

        $analysisId = (int) $this->request->getRequiredBodyParam('analysisId');
        $field = $this->request->getRequiredBodyParam('field');
        $value = $this->request->getRequiredBodyParam('value');

        if ($analysisId < 1) {
            throw new BadRequestHttpException('Invalid analysis ID');
        }

        try {
            $result = Plugin::getInstance()->analysisEdit->updateSingleField($analysisId, $field, $value);
            return $this->asJson(['success' => true] + $result);
        } catch (\InvalidArgumentException $e) {
            throw new BadRequestHttpException($e->getMessage());
        }
    }

    /**
     * Revert a field to its AI-generated value.
     */
    public function actionRevertField(): Response
    {
        $this->requireCpRequest();
        $this->requirePostRequest();
        $this->requirePermission('accessPlugin-lens');

        $analysisId = (int) $this->request->getRequiredBodyParam('analysisId');
        $field = $this->request->getRequiredBodyParam('field');

        if ($analysisId < 1) {
            throw new BadRequestHttpException('Invalid analysis ID');
        }

        try {
            $result = Plugin::getInstance()->analysisEdit->revertField($analysisId, $field);
            return $this->asJson(['success' => true] + $result);
        } catch (\InvalidArgumentException $e) {
            throw new BadRequestHttpException($e->getMessage());
        }
    }

    /**
     * Update tags for an analysis.
     */
    public function actionUpdateTags(): Response
    {
        $this->requireCpRequest();
        $this->requirePostRequest();
        $this->requirePermission('accessPlugin-lens');

        $analysisId = (int) $this->request->getRequiredBodyParam('analysisId');
        $tags = $this->request->getRequiredBodyParam('tags');

        if ($analysisId < 1) {
            throw new BadRequestHttpException('Invalid analysis ID');
        }

        $tags = $this->decodeJsonParam($tags);

        try {
            $result = Plugin::getInstance()->analysisEdit->updateTags($analysisId, $tags);
            return $this->asJson(['success' => true, 'tags' => $result]);
        } catch (\InvalidArgumentException $e) {
            throw new BadRequestHttpException($e->getMessage());
        }
    }

    /**
     * Update colors for an analysis.
     */
    public function actionUpdateColors(): Response
    {
        $this->requireCpRequest();
        $this->requirePostRequest();
        $this->requirePermission('accessPlugin-lens');

        $analysisId = (int) $this->request->getRequiredBodyParam('analysisId');
        $colors = $this->request->getRequiredBodyParam('colors');

        if ($analysisId < 1) {
            throw new BadRequestHttpException('Invalid analysis ID');
        }

        $colors = $this->decodeJsonParam($colors);

        try {
            $result = Plugin::getInstance()->analysisEdit->updateColors($analysisId, $colors);
            return $this->asJson(['success' => true, 'colors' => $result]);
        } catch (\InvalidArgumentException $e) {
            throw new BadRequestHttpException($e->getMessage());
        }
    }

    /**
     * Search tags for autocomplete suggestions.
     */
    public function actionTagSuggestions(): Response
    {
        $this->requireCpRequest();
        $this->requirePermission('accessPlugin-lens');

        $query = (string) $this->request->getRequiredParam('query');

        $tags = Plugin::getInstance()->tagAggregation->searchTags($query);

        return $this->asJson(['success' => true, 'tags' => $tags]);
    }

    /**
     * Get required asset from request body parameter.
     */
    private function getRequiredAsset(): Asset
    {
        $assetId = (int) $this->request->getRequiredBodyParam('assetId');

        if ($assetId < 1) {
            throw new BadRequestHttpException('Invalid asset ID');
        }

        $asset = Asset::find()->id($assetId)->one();

        if ($asset === null) {
            throw new NotFoundHttpException('Asset not found');
        }

        return $asset;
    }

    /**
     * Decode JSON parameter to array.
     */
    private function decodeJsonParam($value): array
    {
        return is_string($value)
            ? (json_decode($value, true) ?? [])
            : (array) $value;
    }
}
