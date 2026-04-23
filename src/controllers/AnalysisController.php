<?php

declare(strict_types=1);

namespace vitordiniz22\craftlens\controllers;

use Craft;
use craft\elements\Asset;
use craft\web\Controller;
use vitordiniz22\craftlens\controllers\traits\ValidatesIdsTrait;
use vitordiniz22\craftlens\enums\AnalysisStatus;
use vitordiniz22\craftlens\enums\LogCategory;
use vitordiniz22\craftlens\helpers\Logger;
use vitordiniz22\craftlens\helpers\MultisiteHelper;
use vitordiniz22\craftlens\Plugin;
use yii\web\BadRequestHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * Controller for asset analysis actions.
 */
class AnalysisController extends Controller
{
    use ValidatesIdsTrait;

    protected array|int|bool $allowAnonymous = false;

    public function actionCancel(): Response
    {
        $this->requireCpRequest();
        $this->requirePostRequest();
        $this->requirePermission('accessPlugin-lens');

        $assetId = $this->requireValidId('assetId', 'asset ID');

        try {
            $result = Plugin::getInstance()->analysisCancellation->cancel($assetId);

            return $this->asJson($result);
        } catch (\Throwable $e) {
            Logger::error(
                LogCategory::Cancellation,
                "Error cancelling analysis for asset {$assetId}: {$e->getMessage()}",
                assetId: $assetId,
                exception: $e,
            );

            $this->response->setStatusCode(500);

            return $this->asJson([
                'error' => Craft::t('lens', 'An error occurred while cancelling the analysis.'),
                'success' => false,
            ]);
        }
    }

    public function actionReprocess(): Response
    {
        $this->requireCpRequest();
        $this->requirePostRequest();
        $this->requirePermission('accessPlugin-lens');

        $asset = $this->getRequiredAsset();

        if (!Plugin::getInstance()->getSettings()->isVolumeEnabled($asset->getVolume()->id)) {
            if ($this->request->getAcceptsJson()) {
                return $this->asJson([
                    'success' => false,
                    'error' => Craft::t('lens', 'This volume is not enabled for Lens.'),
                ]);
            }

            throw new BadRequestHttpException('This volume is not enabled for Lens.');
        }

        Plugin::getInstance()->assetAnalysis->reprocessAsset($asset);

        Logger::info(LogCategory::AssetProcessing, 'Asset queued for reprocessing', assetId: $asset->id);

        if ($this->request->getAcceptsJson()) {
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
        $siteId = $this->request->getBodyParam('siteId');

        $analysis = Plugin::getInstance()->assetAnalysis->getAnalysis($asset->id);

        if ($analysis === null) {
            throw new BadRequestHttpException('No analysis available');
        }

        if ($siteId !== null) {
            $siteId = (int) $siteId;

            if (!MultisiteHelper::isTitleTranslatable($asset->getVolume()->id)) {
                throw new BadRequestHttpException('Title is not translatable for this volume');
            }

            $title = Plugin::getInstance()->siteContent->resolveSuggestedTitle($analysis, $siteId);
            $asset = Asset::find()->id($asset->id)->siteId($siteId)->status(null)->one();

            if ($asset === null) {
                throw new BadRequestHttpException('Asset not found for this site');
            }
        } else {
            $title = $analysis->suggestedTitle;
        }

        if (empty($title)) {
            throw new BadRequestHttpException('No suggested title available');
        }

        $asset->title = $title;
        $success = Craft::$app->getElements()->saveElement($asset);

        if ($this->request->getAcceptsJson()) {
            return $this->asJson([
                'success' => $success,
                'title' => $title,
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

    public function actionApplyAlt(): Response
    {
        $this->requireCpRequest();
        $this->requirePostRequest();
        $this->requirePermission('accessPlugin-lens');

        $asset = $this->getRequiredAsset();
        $siteId = $this->request->getBodyParam('siteId');

        $analysis = Plugin::getInstance()->assetAnalysis->getAnalysis($asset->id);

        if ($analysis === null) {
            throw new BadRequestHttpException('No analysis available');
        }

        // Resolve alt text for specific site or primary
        if ($siteId !== null) {
            $siteId = (int) $siteId;
            if (!MultisiteHelper::isAltTranslatable($asset->getVolume()->id)) {
                throw new BadRequestHttpException('Alt text is not translatable for this volume');
            }
            $altText = Plugin::getInstance()->siteContent->resolveAltText($analysis, $siteId);
            $asset = Asset::find()->id($asset->id)->siteId($siteId)->status(null)->one();
            if ($asset === null) {
                throw new BadRequestHttpException('Asset not found for this site');
            }
        } else {
            $altText = $analysis->altText;
        }

        if (empty($altText)) {
            throw new BadRequestHttpException('No suggested alt text available');
        }

        $asset->alt = $altText;
        $success = Craft::$app->getElements()->saveElement($asset);

        if ($this->request->getAcceptsJson()) {
            return $this->asJson([
                'success' => $success,
                'alt' => $altText,
            ]);
        }

        if ($success) {
            Craft::$app->getSession()->setNotice(Craft::t('lens', 'Alt text applied.'));
        } else {
            Logger::warning(LogCategory::AssetProcessing, 'Failed to apply suggested alt text', assetId: $asset->id);
            Craft::$app->getSession()->setError(Craft::t('lens', 'Failed to apply alt text.'));
        }

        return $this->redirectToPostedUrl();
    }

    public function actionUpdateAssetAlt(): Response
    {
        $this->requireCpRequest();
        $this->requirePostRequest();
        $this->requirePermission('accessPlugin-lens');

        $asset = $this->getRequiredAsset();
        $value = (string) $this->request->getRequiredBodyParam('value');

        $asset->alt = $value;
        $success = Craft::$app->getElements()->saveElement($asset);

        if ($this->request->getAcceptsJson()) {
            return $this->asJson([
                'success' => $success,
                'alt' => $value,
            ]);
        }

        if ($success) {
            Craft::$app->getSession()->setNotice(Craft::t('lens', 'Alt text updated.'));
        } else {
            Logger::warning(LogCategory::AssetProcessing, 'Failed to update asset alt text', assetId: $asset->id);
            Craft::$app->getSession()->setError(Craft::t('lens', 'Failed to update alt text.'));
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

            $cancellation = Plugin::getInstance()->analysisCancellation;
            
            if (
                in_array($analysis->status, [AnalysisStatus::Pending->value, AnalysisStatus::Processing->value], true)
                && $analysis->queueJobId !== null
                && !$cancellation->isQueueJobAlive($analysis->queueJobId)
            ) {
                $cancelResult = $cancellation->cancel($assetId);

                if ($cancelResult['success'] && $cancelResult['restored']) {
                    $analysis = Plugin::getInstance()->assetAnalysis->getAnalysis($assetId);
                } else {
                    return $this->asJson([
                        'status' => 'not_found',
                        'processedAt' => null,
                    ]);
                }
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
                'error' => 'An error occurred while processing your request.',
                'status' => 'error',
            ]);
        }
    }

    /**
     * Update a single editable field on an analysis record.
     */
    public function actionUpdateField(): Response
    {
        $this->requireCpRequest();
        $this->requirePostRequest();
        $this->requirePermission('accessPlugin-lens');

        $analysisId = $this->requireValidId('analysisId', 'analysis ID');
        $field = $this->request->getRequiredBodyParam('field');
        $value = $this->request->getRequiredBodyParam('value');
        $siteId = $this->request->getBodyParam('siteId');

        try {
            if ($siteId !== null && in_array($field, ['altText', 'suggestedTitle'], true)) {
                $result = Plugin::getInstance()->siteContent->updateSiteField(
                    $analysisId, (int) $siteId, $field, $value
                );
            } else {
                $result = Plugin::getInstance()->analysisEdit->updateSingleField($analysisId, $field, $value);
            }
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

        $analysisId = $this->requireValidId('analysisId', 'analysis ID');
        $field = $this->request->getRequiredBodyParam('field');
        $siteId = $this->request->getBodyParam('siteId');

        try {
            if ($siteId !== null && in_array($field, ['altText', 'suggestedTitle'], true)) {
                $result = Plugin::getInstance()->siteContent->revertSiteField(
                    $analysisId, (int) $siteId, $field
                );
            } else {
                $result = Plugin::getInstance()->analysisEdit->revertField($analysisId, $field);
            }
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

        $analysisId = $this->requireValidId('analysisId', 'analysis ID');
        $tags = $this->request->getRequiredBodyParam('tags');
        $tags = is_string($tags) ? (json_decode($tags, true) ?? []) : (array) $tags;

        try {
            $result = Plugin::getInstance()->analysisEdit->updateTags($analysisId, $tags);
            return $this->asJson(['success' => true, 'tags' => $result]);
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
        $assetId = $this->requireValidId('assetId', 'asset ID');

        $asset = Asset::find()->id($assetId)->one();

        if ($asset === null) {
            throw new NotFoundHttpException('Asset not found');
        }

        return $asset;
    }
}
