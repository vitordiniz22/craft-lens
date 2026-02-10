<?php

declare(strict_types=1);

namespace vitordiniz22\craftlens\controllers;

use Craft;
use craft\helpers\UrlHelper;
use craft\web\Controller;
use vitordiniz22\craftlens\enums\AnalysisStatus;
use vitordiniz22\craftlens\enums\LogCategory;
use vitordiniz22\craftlens\helpers\Logger;
use vitordiniz22\craftlens\jobs\BulkAnalyzeAssetsJob;
use vitordiniz22\craftlens\Plugin;
use vitordiniz22\craftlens\records\AssetAnalysisRecord;
use yii\web\Response;

class BulkController extends Controller
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

        $statusService = Plugin::getInstance()->bulkProcessingStatus;
        $stats = $statusService->getStats();
        $volumes = Craft::$app->getVolumes()->getAllVolumes();

        // Determine initial state
        $statusData = $statusService->getStatus();
        $initialState = $statusData['state'];

        // Calculate estimated cost
        $estimatedCost = $statusService->getEstimatedCost($stats['unprocessed']);

        return $this->renderTemplate('lens/_bulk/index', [
            'stats' => $stats,
            'volumes' => $volumes,
            'initialState' => $initialState,
            'estimatedCost' => $estimatedCost,
        ]);
    }

    /**
     * AJAX endpoint for bulk processing status.
     */
    public function actionStatus(): Response
    {
        $this->requireCpRequest();
        $this->requirePermission('accessPlugin-lens');

        if (!$this->request->getAcceptsJson()) {
            return $this->redirect('lens/bulk');
        }

        $volumeId = $this->request->getQueryParam('volumeId');
        $volumeId = $volumeId ? (int) $volumeId : null;

        $statusService = Plugin::getInstance()->bulkProcessingStatus;
        $stats = $statusService->getStats($volumeId);

        // For full status (during processing), we need state info too
        $status = $statusService->getStatus();
        $status['stats'] = $stats;

        // Add volume-specific estimated cost
        $status['estimatedCost'] = $statusService->getEstimatedCost($stats['unprocessed']);

        return $this->asJson($status);
    }

    /**
     * Cancel bulk processing.
     */
    public function actionCancel(): Response
    {
        $this->requireCpRequest();
        $this->requirePostRequest();
        $this->requirePermission('accessPlugin-lens');

        $statusService = Plugin::getInstance()->bulkProcessingStatus;
        $cancelled = $statusService->cancelProcessing();

        Logger::info(LogCategory::AssetProcessing, 'Bulk processing cancelled from CP', context: ['jobsCancelled' => $cancelled]);

        if ($this->request->getAcceptsJson()) {
            return $this->asJson([
                'success' => true,
                'cancelled' => $cancelled,
            ]);
        }

        Craft::$app->getSession()->setNotice(
            Craft::t('lens', 'Processing cancelled. {count} jobs removed.', ['count' => $cancelled])
        );
        return $this->redirect('lens/bulk');
    }

    /**
     * Start bulk processing.
     */
    public function actionProcess(): Response
    {
        $this->requireCpRequest();
        $this->requirePostRequest();
        $this->requirePermission('accessPlugin-lens');

        $volumeId = $this->request->getBodyParam('volumeId');
        $volumeId = $volumeId ? (int) $volumeId : null;

        // Start session tracking
        $statusService = Plugin::getInstance()->bulkProcessingStatus;
        $statusService->startSession($volumeId);

        // Queue the job
        Craft::$app->getQueue()->push(new BulkAnalyzeAssetsJob([
            'volumeId' => $volumeId,
            'reprocess' => false,
        ]));

        Logger::info(LogCategory::JobStarted, 'Bulk processing started from CP', context: ['volumeId' => $volumeId]);

        if ($this->request->getAcceptsJson()) {
            return $this->asJson(['success' => true]);
        }

        Craft::$app->getSession()->setNotice(Craft::t('lens', 'Bulk processing started.'));
        return $this->redirect('lens/bulk');
    }

    /**
     * Retry failed analyses.
     */
    public function actionRetryFailed(): Response
    {
        $this->requireCpRequest();
        $this->requirePostRequest();
        $this->requirePermission('accessPlugin-lens');

        $failedAssetIds = AssetAnalysisRecord::find()
            ->select('assetId')
            ->where(['status' => AnalysisStatus::Failed->value])
            ->column();

        if (empty($failedAssetIds)) {
            if ($this->request->getAcceptsJson()) {
                return $this->asJson(['success' => true, 'count' => 0]);
            }
            Craft::$app->getSession()->setNotice(Craft::t('lens', 'No failed analyses to retry.'));
            return $this->redirect('lens/bulk');
        }

        $transaction = Craft::$app->getDb()->beginTransaction();

        try {
            // Reset status to pending
            AssetAnalysisRecord::updateAll(
                ['status' => AnalysisStatus::Pending->value],
                ['status' => AnalysisStatus::Failed->value]
            );

            // Start session tracking
            $statusService = Plugin::getInstance()->bulkProcessingStatus;
            $statusService->startSession();

            // Queue for reprocessing — assets were already reset to Pending above,
            // so loadData() will pick them up automatically
            Craft::$app->getQueue()->push(new BulkAnalyzeAssetsJob());

            $transaction->commit();
        } catch (\Throwable $e) {
            $transaction->rollBack();
            throw $e;
        }

        Logger::info(LogCategory::JobStarted, 'Retry failed analyses started from CP', context: ['failedCount' => count($failedAssetIds)]);

        if ($this->request->getAcceptsJson()) {
            return $this->asJson(['success' => true, 'count' => count($failedAssetIds)]);
        }

        Craft::$app->getSession()->setNotice(
            Craft::t('lens', '{count} failed analyses queued for retry.', ['count' => count($failedAssetIds)])
        );
        return $this->redirect('lens/bulk');
    }
}
