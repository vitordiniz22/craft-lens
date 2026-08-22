<?php

declare(strict_types=1);

namespace vitordiniz22\craftlens\controllers;

use Craft;
use craft\helpers\Queue;
use craft\web\Controller;
use vitordiniz22\craftlens\controllers\traits\RequiresAiProviderTrait;
use vitordiniz22\craftlens\enums\AnalysisStatus;
use vitordiniz22\craftlens\enums\BulkPageView;
use vitordiniz22\craftlens\enums\BulkSessionMode;
use vitordiniz22\craftlens\enums\LogCategory;
use vitordiniz22\craftlens\exceptions\ConfigurationException;
use vitordiniz22\craftlens\helpers\Logger;
use vitordiniz22\craftlens\jobs\BulkAnalyzeAssetsJob;
use vitordiniz22\craftlens\jobs\ProCompletionAnalysisJob;
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

        Plugin::getInstance()->requireProEdition();

        $volumeId = $this->request->getQueryParam('volumeId');
        $volumeId = $volumeId ? (int) $volumeId : null;
        $focus = BulkPageView::tryFrom((string) $this->request->getQueryParam('focus'));

        $settings = Plugin::getInstance()->getSettings();
        $allVolumes = Craft::$app->getVolumes()->getAllVolumes();
        $enabledVolumeIds = $settings->getEnabledVolumeIds();
        $volumes = array_values(array_filter(
            $allVolumes,
            fn($v) => in_array($v->id, $enabledVolumeIds, true)
        ));

        if ($volumeId !== null && !in_array($volumeId, $enabledVolumeIds, true)) {
            $volumeId = null;
        }

        $statusService = Plugin::getInstance()->bulkProcessingStatus;
        $session = $statusService->getSessionData();

        // After actionProcess redirects back here without the volumeId query
        // param, fall back to the session's scope so stats match the session.
        $sessionScope = null;
        if ($volumeId === null && $session !== null && !empty($session['volumeId'])) {
            $sessionScope = is_array($session['volumeId'])
                ? $session['volumeId']
                : (int) $session['volumeId'];
        }

        $statsVolumeScope = $volumeId
            ?? $sessionScope
            ?? (count($volumes) < count($allVolumes) ? $enabledVolumeIds : null);
        $selectedVolumeId = $volumeId ?? (is_int($sessionScope) ? $sessionScope : null);
        $stats = $statusService->getStats($statsVolumeScope);
        $state = $statusService->determineState($stats);

        $proSyncCount = Plugin::getInstance()->statistics->getProSyncCandidatesCount();

        // Active tab: when a session is mid-flight (processing or complete),
        // the tab must match the session's mode so the user knows which flow
        // they're watching. Otherwise the URL focus param drives it.
        $sessionMode = BulkSessionMode::tryFrom((string) ($session['mode'] ?? ''));
        if ($state === 'processing' || $state === 'complete') {
            $activeTab = $sessionMode === BulkSessionMode::ProCompletion
                ? BulkPageView::ProMetadata
                : BulkPageView::Default;
        } else {
            $activeTab = $focus === BulkPageView::ProMetadata
                ? BulkPageView::ProMetadata
                : BulkPageView::Default;
        }

        $templateVars = [
            'stats' => $stats,
            'volumes' => $volumes,
            'state' => $state,
            'selectedVolumeId' => $selectedVolumeId,
            'autoProcessOnUpload' => $settings->autoProcessOnUpload,
            'focus' => $focus?->value,
            'proSyncCount' => $proSyncCount,
            'activeTab' => $activeTab->value,
        ];

        if ($state === 'ready') {
            $costInputCount = $activeTab === BulkPageView::ProMetadata
                ? $proSyncCount
                : $stats['unprocessed'] + $stats['failed'];
            $projection = Plugin::getInstance()->statistics->getCostProjection($costInputCount);
            $templateVars['estimatedCost'] = $projection['estimatedCost'];
            $templateVars['costPerImage'] = $projection['avgCostPerAsset'];

            if ($activeTab === BulkPageView::ProMetadata) {
                $templateVars['proCoverage'] = Plugin::getInstance()->statistics->getProMetadataCoverage();
            }

            try {
                $templateVars['modelName'] = $this->getProviderModelName();
            } catch (\Throwable $e) {
                $templateVars['modelName'] = null;
            }
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

            $templateVars['modelName'] = $this->getProviderModelName();
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
        Plugin::getInstance()->requireProEdition();

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
        Plugin::getInstance()->requireProEdition();

        Plugin::getInstance()->bulkProcessingStatus->clearSession();

        $volumeId = $this->request->getQueryParam('volumeId');
        $focus = BulkPageView::tryFrom((string) $this->request->getQueryParam('focus'));

        // Stay on the focused page only if there's still backfill work to do.
        // Once a Pro-completion run finishes with everything backfilled, the
        // user belongs back on the regular bulk page rather than a transient
        // "all caught up" view they no longer need.
        $stickFocus = $focus === BulkPageView::ProMetadata
            && Plugin::getInstance()->statistics->getProSyncCandidatesCount() > 0;

        $params = [];
        if ($volumeId) {
            $params['volumeId'] = $volumeId;
        }
        if ($stickFocus) {
            $params['focus'] = BulkPageView::ProMetadata->value;
        }

        $url = $params ? 'lens/bulk?' . http_build_query($params) : 'lens/bulk';

        return $this->redirect($url);
    }

    /**
     * Cancel bulk processing.
     */
    public function actionCancel(): Response
    {
        $this->requireCpRequest();
        $this->requirePostRequest();
        $this->requirePermission('accessPlugin-lens');
        Plugin::getInstance()->requireProEdition();

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
        Plugin::getInstance()->requireProEdition();

        try {
            Plugin::getInstance()->aiProvider->testConnection();
        } catch (ConfigurationException $e) {
            Craft::$app->getSession()->setError($e->getMessage());
            return $this->redirect('lens/bulk');
        }

        $volumeId = $this->request->getBodyParam('volumeId');
        $volumeId = $volumeId ? (int) $volumeId : null;

        if ($volumeId === null) {
            $enabledVolumeIds = Plugin::getInstance()->getSettings()->getEnabledVolumeIds();
            $scope = count($enabledVolumeIds) < count(Craft::$app->getVolumes()->getAllVolumes())
                ? $enabledVolumeIds
                : null;
        } else {
            $scope = $volumeId;
        }

        $statusService = Plugin::getInstance()->bulkProcessingStatus;
        $statusService->startSession($scope);

        Queue::push(new BulkAnalyzeAssetsJob([
            'volumeId' => $scope,
            'reprocess' => false,
        ]));

        Logger::info(LogCategory::JobStarted, 'Bulk processing started from CP', context: ['volumeId' => $scope]);

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
        Plugin::getInstance()->requireProEdition();

        try {
            Plugin::getInstance()->aiProvider->testConnection();
        } catch (ConfigurationException $e) {
            Craft::$app->getSession()->setError($e->getMessage());
            return $this->redirect('lens/bulk');
        }

        $volumeId = $this->request->getBodyParam('volumeId');
        $volumeId = $volumeId ? (int) $volumeId : null;
        $enabledVolumeIds = Plugin::getInstance()->getSettings()->getEnabledVolumeIds();

        if ($volumeId !== null && !in_array($volumeId, $enabledVolumeIds, true)) {
            Craft::$app->getSession()->setError(Craft::t('lens', 'The selected volume is not enabled for analysis.'));
            return $this->redirect('lens/bulk');
        }

        $scope = $volumeId;
        if ($scope === null && count($enabledVolumeIds) < count(Craft::$app->getVolumes()->getAllVolumes())) {
            $scope = $enabledVolumeIds;
        }

        $statusService = Plugin::getInstance()->bulkProcessingStatus;
        $failedAssetIds = $statusService->getFailedAssetIds($scope);
        $redirectUrl = $volumeId !== null ? 'lens/bulk?volumeId=' . $volumeId : 'lens/bulk';

        if (empty($failedAssetIds)) {
            Craft::$app->getSession()->setNotice(Craft::t('lens', 'No failed analyses to retry.'));
            return $this->redirect($redirectUrl);
        }

        $transaction = Craft::$app->getDb()->beginTransaction();

        try {
            AssetAnalysisRecord::updateAll(
                ['status' => AnalysisStatus::Pending->value],
                [
                    'and',
                    ['status' => AnalysisStatus::Failed->value],
                    ['in', 'assetId', $failedAssetIds],
                ]
            );

            $retriedIds = array_map('intval', $failedAssetIds);

            // Retry-scoped session: total and progress are based strictly on
            // these IDs, so other unprocessed assets in the library are not
            // swept in. If the user cancels, cancelProcessing restores these
            // to Failed so the retry can be re-initiated.
            $statusService->startSession($scope, $retriedIds);

            // Queue job scoped to the same IDs so Craft's queue progress
            // matches the Lens session counter (N/N retried, not N/total).
            Queue::push(new BulkAnalyzeAssetsJob(['assetIds' => $retriedIds]));

            $transaction->commit();
        } catch (\Throwable $e) {
            $transaction->rollBack();
            throw $e;
        }

        Logger::info(LogCategory::JobStarted, 'Retry failed analyses started from CP', context: [
            'failedCount' => count($failedAssetIds),
            'volumeId' => $scope,
        ]);

        Craft::$app->getSession()->setNotice(
            Craft::t('lens', '{count} failed analyses queued for retry.', ['count' => count($failedAssetIds)])
        );
        return $this->redirect($redirectUrl);
    }

    /**
     * Backfill Pro-only metadata onto Lite-analyzed assets after a Pro upgrade.
     *
     * Mirrors `actionRetryFailed`: pulls the candidate IDs, opens a scoped
     * bulk session so progress and cancel work the same way, and queues a
     * `ProCompletionAnalysisJob` restricted to those IDs.
     */
    public function actionSyncProMetadata(): Response
    {
        $this->requireCpRequest();
        $this->requirePostRequest();
        $this->requirePermission('accessPlugin-lens');
        Plugin::getInstance()->requireProEdition();

        $statusService = Plugin::getInstance()->bulkProcessingStatus;
        $existingSession = $statusService->getSessionData();
        if ($existingSession !== null && !isset($existingSession['completedAt'])) {
            Craft::$app->getSession()->setError(
                Craft::t('lens', 'A bulk job is already running. Wait for it to finish or cancel it before starting a Pro metadata backfill.')
            );
            return $this->redirect('lens/bulk');
        }

        $focusedUrl = 'lens/bulk?focus=' . BulkPageView::ProMetadata->value;

        try {
            Plugin::getInstance()->aiProvider->validateCredentials();
        } catch (ConfigurationException $e) {
            Craft::$app->getSession()->setError($e->getMessage());
            return $this->redirect($focusedUrl);
        }

        $candidateIds = Plugin::getInstance()->statistics->getProSyncCandidateIds();

        if (empty($candidateIds)) {
            Craft::$app->getSession()->setNotice(Craft::t('lens', 'No analyses need backfill.'));
            return $this->redirect($focusedUrl);
        }

        $statusService->startSession(null, $candidateIds, BulkSessionMode::ProCompletion);

        Queue::push(new ProCompletionAnalysisJob(['assetIds' => $candidateIds]));

        Logger::info(LogCategory::JobStarted, 'Pro metadata sync started from CP', context: ['candidateCount' => count($candidateIds)]);

        Craft::$app->getSession()->setNotice(
            Craft::t('lens', '{count} analyses queued for backfill.', ['count' => count($candidateIds)])
        );
        return $this->redirect($focusedUrl);
    }

    /**
     * Get a display string combining provider name and model.
     */
    private function getProviderModelName(): string
    {
        return Plugin::getInstance()->getSettings()->getCurrentModel();
    }
}
