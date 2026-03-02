<?php

declare(strict_types=1);

namespace vitordiniz22\craftlens\controllers;

use Craft;
use craft\web\Controller;
use vitordiniz22\craftlens\controllers\traits\RequiresAiProviderTrait;
use vitordiniz22\craftlens\enums\AnalysisStatus;
use vitordiniz22\craftlens\enums\LogCategory;
use vitordiniz22\craftlens\exceptions\ConfigurationException;
use vitordiniz22\craftlens\helpers\Logger;
use vitordiniz22\craftlens\jobs\BulkAnalyzeAssetsJob;
use vitordiniz22\craftlens\Plugin;
use vitordiniz22\craftlens\records\AssetAnalysisRecord;
use yii\web\Response;

class BulkController extends Controller
{
    use RequiresAiProviderTrait;

    protected array|int|bool $allowAnonymous = false;

    public function actionIndex(): Response
    {
        $this->requireCpRequest();
        $this->requirePermission('accessPlugin-lens');

        $volumeId = $this->request->getQueryParam('volumeId');
        $volumeId = $volumeId ? (int) $volumeId : null;

        $settings = Plugin::getInstance()->getSettings();
        $enabledVolumes = $settings->enabledVolumes;
        $allVolumes = Craft::$app->getVolumes()->getAllVolumes();

        if (in_array('*', $enabledVolumes, true)) {
            $volumes = $allVolumes;
        } else {
            $volumes = array_filter($allVolumes, fn($v) => in_array($v->uid, $enabledVolumes, true));
        }

        $enabledVolumeIds = array_values(array_map(fn($v) => $v->id, $volumes));

        if ($volumeId !== null && !in_array($volumeId, $enabledVolumeIds, true)) {
            $volumeId = null;
        }

        $statusService = Plugin::getInstance()->bulkProcessingStatus;
        $statsVolumeScope = $volumeId ?? (count($volumes) < count($allVolumes) ? $enabledVolumeIds : null);
        $stats = $statusService->getStats($statsVolumeScope);
        $state = $statusService->determineState($stats);
        $session = $statusService->getSessionData();

        $templateVars = [
            'stats' => $stats,
            'volumes' => $volumes,
            'state' => $state,
            'selectedVolumeId' => $volumeId,
            'autoProcessOnUpload' => $settings->autoProcessOnUpload,
        ];

        if ($state === 'ready') {
            $templateVars['estimatedCost'] = $statusService->getEstimatedCost($stats['unprocessed']);
        }

        if ($state === 'processing') {
            $templateVars['progress'] = $statusService->getProgress($session, $stats);
            $templateVars['queueInfo'] = $statusService->getQueueInfo();
            $templateVars['session'] = $statusService->formatSession($session);
        }

        if ($state === 'complete') {
            $templateVars['session'] = $statusService->formatSession($session);
            if (($stats['failed'] ?? 0) > 0) {
                $templateVars['failureReasons'] = $statusService->getFailureReasons();
            }
        }

        return $this->renderTemplate('lens/_bulk/index', $templateVars);
    }

    /**
     * Returns an HTML fragment for progress polling.
     */
    public function actionProgress(): Response
    {
        $this->requireCpRequest();
        $this->requirePermission('accessPlugin-lens');

        $statusService = Plugin::getInstance()->bulkProcessingStatus;
        $session = $statusService->getSessionData();
        $volumeId = $session['volumeId'] ?? null;
        $stats = $statusService->getStats($volumeId);
        $state = $statusService->determineState($stats);

        $html = Craft::$app->getView()->renderTemplate('lens/_bulk/_progress', [
            'progress' => $statusService->getProgress($session, $stats),
            'queueInfo' => $statusService->getQueueInfo(),
            'session' => $statusService->formatSession($session),
        ]);

        $response = Craft::$app->getResponse();
        $response->getHeaders()->set('X-Lens-State', $state);
        $response->getHeaders()->set('X-Lens-Stats', json_encode($stats));
        $response->format = Response::FORMAT_HTML;
        $response->data = $html;

        return $response;
    }

    /**
     * Clear the session so state reverts to ready.
     */
    public function actionDismiss(): Response
    {
        $this->requireCpRequest();
        $this->requirePermission('accessPlugin-lens');

        Plugin::getInstance()->bulkProcessingStatus->clearSession();

        return $this->redirect('lens/bulk');
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

        try {
            Plugin::getInstance()->aiProvider->testConnection();
        } catch (ConfigurationException $e) {
            Craft::$app->getSession()->setError($e->getMessage());
            return $this->redirect('lens/bulk');
        }

        $volumeId = $this->request->getBodyParam('volumeId');
        $volumeId = $volumeId ? (int) $volumeId : null;
        $statusService = Plugin::getInstance()->bulkProcessingStatus;
        $statusService->startSession($volumeId);

        Craft::$app->getQueue()->push(new BulkAnalyzeAssetsJob([
            'volumeId' => $volumeId,
            'reprocess' => false,
        ]));

        Logger::info(LogCategory::JobStarted, 'Bulk processing started from CP', context: ['volumeId' => $volumeId]);

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

        try {
            Plugin::getInstance()->aiProvider->testConnection();
        } catch (ConfigurationException $e) {
            Craft::$app->getSession()->setError($e->getMessage());
            return $this->redirect('lens/bulk');
        }

        $failedAssetIds = AssetAnalysisRecord::find()
            ->select('assetId')
            ->where(['status' => AnalysisStatus::Failed->value])
            ->column();

        if (empty($failedAssetIds)) {
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

        Craft::$app->getSession()->setNotice(
            Craft::t('lens', '{count} failed analyses queued for retry.', ['count' => count($failedAssetIds)])
        );
        return $this->redirect('lens/bulk');
    }
}
