<?php

declare(strict_types=1);

namespace vitordiniz22\craftlens\controllers;

use Craft;
use craft\elements\Asset;
use craft\helpers\UrlHelper;
use craft\web\Controller;
use vitordiniz22\craftlens\enums\AnalysisStatus;
use vitordiniz22\craftlens\enums\LogCategory;
use vitordiniz22\craftlens\helpers\Logger;
use vitordiniz22\craftlens\Plugin;
use vitordiniz22\craftlens\records\AssetTagRecord;
use yii\web\BadRequestHttpException;
use yii\web\Response;

/**
 * Controller for the AI suggestions review workflow.
 */
class ReviewController extends Controller
{
    private const PER_PAGE = 50;

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

        $reviewService = Plugin::getInstance()->review;
        $page = max(1, (int) ($this->request->getParam('page') ?? 1));
        $offset = ($page - 1) * self::PER_PAGE;

        $totalCount = $reviewService->getPendingReviewCount();
        $totalPages = max(1, (int) ceil($totalCount / self::PER_PAGE));

        if ($totalCount === 0) {
            return $this->renderTemplate('lens/_review/browse', [
                'pendingCount' => 0,
            ]);
        }

        $pendingReviews = $reviewService->getPendingReviews(self::PER_PAGE, $offset);
        $ids = $this->extractIdsFromAnalyses($pendingReviews);
        $assets = Asset::find()->id($ids['assetIds'])->indexBy('id')->all();
        $tagCounts = $this->getTagCounts($ids['analysisIds']);
        $items = $this->buildReviewItems($pendingReviews, $assets, $tagCounts);

        $analysisMap = [];

        foreach ($pendingReviews as $analysis) {
            $analysisMap[$analysis->assetId] = $analysis;
        }

        return $this->renderTemplate('lens/_review/browse', [
            'items' => $items,
            'assets' => $assets,
            'analysisMap' => $analysisMap,
            'page' => $page,
            'totalPages' => $totalPages,
            'totalCount' => $totalCount,
            'pendingCount' => $totalCount,
        ]);
    }

    /**
     * Single review view for a specific analysis
     */
    public function actionView(int $analysisId): Response
    {
        $this->requireCpRequest();
        $this->requirePermission('accessPlugin-lens');

        $reviewService = Plugin::getInstance()->review;
        $data = $reviewService->getFullAnalysis($analysisId);

        if ($data === null || $data['status'] !== AnalysisStatus::PendingReview->value) {
            Craft::$app->getSession()->setError(
                Craft::t('lens', 'Analysis not found or already reviewed.')
            );
            return $this->redirect('lens/review');
        }

        // Get queue context for navigation
        $queueIds = $reviewService->getPendingReviewIds();
        $currentIndex = array_search($analysisId, $queueIds, true);

        $prevId = ($currentIndex !== false && $currentIndex > 0) ? $queueIds[$currentIndex - 1] : null;
        $nextId = ($currentIndex !== false && $currentIndex < count($queueIds) - 1) ? $queueIds[$currentIndex + 1] : null;

        // Normalize: add 'id' field for component compatibility (components expect analysis.id)
        $data['id'] = $data['analysisId'];

        return $this->renderTemplate('lens/_review/view', [
            'analysis' => $data,
            'prevId' => $prevId,
            'nextId' => $nextId,
            'currentIndex' => $currentIndex !== false ? $currentIndex + 1 : 1,
            'totalCount' => count($queueIds),
        ]);
    }

    /**
     * Focus view - redirects to first pending review or browse if queue is empty
     */
    public function actionFocus(): Response
    {
        $this->requireCpRequest();
        $this->requirePermission('accessPlugin-lens');

        $reviewService = Plugin::getInstance()->review;
        $queueIds = $reviewService->getPendingReviewIds();

        if (!empty($queueIds)) {
            return $this->redirect(UrlHelper::cpUrl("lens/review/{$queueIds[0]}"));
        }

        return $this->redirect(UrlHelper::cpUrl('lens/review'));
    }

    public function actionApprove(): Response
    {
        $this->requireCpRequest();
        $this->requirePostRequest();
        $this->requirePermission('accessPlugin-lens');

        $analysisId = (int) $this->request->getRequiredBodyParam('analysisId');

        if ($analysisId < 1) {
            throw new BadRequestHttpException('Invalid analysis ID');
        }

        $modifications = [];

        // Text fields — skip empty strings to avoid clearing data when form submits unmodified hidden inputs
        foreach (['suggestedTitle', 'altText', 'longDescription', 'extractedText'] as $field) {
            $value = $this->request->getBodyParam($field);
            if ($value !== null && $value !== '') {
                $modifications[$field] = $value;
            }
        }

        // Tags (JSON-encoded array from hidden input)
        $tagsJson = $this->request->getBodyParam('tags');
        if ($tagsJson !== null) {
            $tags = is_string($tagsJson) ? json_decode($tagsJson, true) : $tagsJson;
            if (is_array($tags)) {
                $modifications['tags'] = $tags;
            }
        }

        // Colors (JSON-encoded array from hidden input)
        $colorsJson = $this->request->getBodyParam('dominantColors');
        if ($colorsJson !== null) {
            $colors = is_string($colorsJson) ? json_decode($colorsJson, true) : $colorsJson;
            if (is_array($colors)) {
                $modifications['dominantColors'] = $colors;
            }
        }

        // Numeric fields
        $faceCount = $this->request->getBodyParam('faceCount');
        if ($faceCount !== null) {
            $modifications['faceCount'] = (int) $faceCount;
        }

        $nsfwScore = $this->request->getBodyParam('nsfwScore');
        if ($nsfwScore !== null) {
            $modifications['nsfwScore'] = (float) $nsfwScore;
        }

        // Boolean fields
        foreach (['containsPeople', 'hasWatermark', 'containsBrandLogo'] as $field) {
            $value = $this->request->getBodyParam($field);
            if ($value !== null) {
                $modifications[$field] = (bool) $value;
            }
        }

        // Focal point
        $focalX = $this->request->getBodyParam('focalPointX');
        $focalY = $this->request->getBodyParam('focalPointY');
        if ($focalX !== null && $focalY !== null) {
            $modifications['focalPointX'] = (float) $focalX;
            $modifications['focalPointY'] = (float) $focalY;
        }

        $userId = Craft::$app->getUser()->getId();
        $reviewService = Plugin::getInstance()->review;

        try {
            if (!empty($modifications)) {
                $reviewService->editAndApprove($analysisId, $modifications, $userId);
            } else {
                $reviewService->approve($analysisId, $userId);
            }
        } catch (\Throwable $e) {
            Logger::error(LogCategory::Review, "Approve failed for analysis {$analysisId}", exception: $e);
            throw $e;
        }

        if ($this->request->getAcceptsJson()) {
            return $this->asJson(['success' => true]);
        }

        return $this->redirectToNextOrBrowse('Analysis approved.');
    }

    public function actionReject(): Response
    {
        $this->requireCpRequest();
        $this->requirePostRequest();
        $this->requirePermission('accessPlugin-lens');

        $analysisId = (int) $this->request->getRequiredBodyParam('analysisId');

        if ($analysisId < 1) {
            throw new BadRequestHttpException('Invalid analysis ID');
        }

        $userId = Craft::$app->getUser()->getId();

        $reviewService = Plugin::getInstance()->review;

        try {
            $reviewService->reject($analysisId, $userId);
        } catch (\Throwable $e) {
            Logger::error(LogCategory::Review, "Reject failed for analysis {$analysisId}", exception: $e);
            throw $e;
        }

        if ($this->request->getAcceptsJson()) {
            return $this->asJson(['success' => true]);
        }

        return $this->redirectToNextOrBrowse('Analysis rejected.');
    }

    public function actionBulkApprove(): Response
    {
        return $this->handleBulkAction('bulkApprove', 'Bulk approve from CP', 'approved');
    }

    public function actionBulkReject(): Response
    {
        return $this->handleBulkAction('bulkReject', 'Bulk reject from CP', 'rejected');
    }

    private function handleBulkAction(string $serviceMethod, string $logMessage, string $actionLabel): Response
    {
        $this->requireCpRequest();
        $this->requirePostRequest();
        $this->requirePermission('accessPlugin-lens');

        $ids = $this->validateAndCastIds($this->request->getRequiredBodyParam('ids'));

        if (empty($ids)) {
            throw new BadRequestHttpException('No valid IDs provided');
        }

        $userId = Craft::$app->getUser()->getId();
        $count = Plugin::getInstance()->review->{$serviceMethod}($ids, $userId);

        Logger::info(LogCategory::Review, $logMessage, context: ['count' => $count]);

        if ($this->request->getAcceptsJson()) {
            return $this->asJson(['success' => true, 'count' => $count]);
        }

        Craft::$app->getSession()->setNotice(
            Craft::t('lens', '{count} analyses {action}.', ['count' => $count, 'action' => $actionLabel])
        );

        return $this->redirectToPostedUrl();
    }

    public function actionSkip(): Response
    {
        $this->requireCpRequest();
        $this->requirePostRequest();
        $this->requirePermission('accessPlugin-lens');

        $analysisId = (int) $this->request->getRequiredBodyParam('analysisId');

        if ($analysisId < 1) {
            throw new BadRequestHttpException('Invalid analysis ID');
        }

        Plugin::getInstance()->review->skip($analysisId);

        if ($this->request->getAcceptsJson()) {
            return $this->asJson(['success' => true]);
        }

        return $this->redirectToNextOrBrowse('Analysis skipped.');
    }

    /**
     * Bulk review mode - grid view with checkboxes
     */
    public function actionBulk(): Response
    {
        $this->requireCpRequest();
        $this->requirePermission('accessPlugin-lens');

        $reviewService = Plugin::getInstance()->review;
        $totalCount = $reviewService->getPendingReviewCount();

        if ($totalCount === 0) {
            return $this->renderTemplate('lens/_review/bulk', [
                'totalCount' => 0,
            ]);
        }

        // Load all pending reviews for bulk mode (up to 100)
        $pendingReviews = $reviewService->getPendingReviews(100, 0);
        $ids = $this->extractIdsFromAnalyses($pendingReviews);

        $assets = Asset::find()->id($ids['assetIds'])->indexBy('id')->all();
        $tagCounts = $this->getTagCounts($ids['analysisIds']);

        $items = $this->buildReviewItems($pendingReviews, $assets, $tagCounts);

        return $this->renderTemplate('lens/_review/bulk', [
            'items' => $items,
            'totalCount' => $totalCount,
        ]);
    }

    /**
     * Extract asset IDs and analysis IDs from analysis records.
     */
    private function extractIdsFromAnalyses(array $analyses): array
    {
        return [
            'assetIds' => array_map(fn($a) => $a->assetId, $analyses),
            'analysisIds' => array_map(fn($a) => $a->id, $analyses),
        ];
    }

    /**
     * Get tag counts for the given analysis IDs.
     */
    private function getTagCounts(array $analysisIds): array
    {
        $tagCounts = [];

        if (empty($analysisIds)) {
            return $tagCounts;
        }

        $rows = AssetTagRecord::find()
            ->select(['analysisId', 'COUNT(*) AS cnt'])
            ->where(['analysisId' => $analysisIds])
            ->groupBy(['analysisId'])
            ->asArray()
            ->all();

        foreach ($rows as $row) {
            $tagCounts[(int) $row['analysisId']] = (int) $row['cnt'];
        }

        return $tagCounts;
    }

    /**
     * Build review items array from pending reviews, assets, and tag counts.
     */
    private function buildReviewItems(array $pendingReviews, array $assets, array $tagCounts): array
    {
        $items = [];

        foreach ($pendingReviews as $analysis) {
            $asset = $assets[$analysis->assetId] ?? null;

            if ($asset === null) {
                continue;
            }

            $items[] = [
                'analysisId' => $analysis->id,
                'assetId' => $analysis->assetId,
                'thumbnailUrl' => Craft::$app->getAssets()->getThumbUrl($asset, 200, 200),
                'filename' => $asset->filename,
                'suggestedTitle' => $analysis->suggestedTitle,
                'avgConfidence' => $this->calculateAverageConfidence(
                    $analysis->titleConfidence !== null ? (float) $analysis->titleConfidence : null,
                    $analysis->altTextConfidence !== null ? (float) $analysis->altTextConfidence : null,
                    $analysis->longDescriptionConfidence !== null ? (float) $analysis->longDescriptionConfidence : null
                ),
                'tagCount' => $tagCounts[$analysis->id] ?? 0,
            ];
        }

        return $items;
    }

    /**
     * Calculate average confidence from multiple confidence values.
     */
    private function calculateAverageConfidence(?float ...$values): float
    {
        $filtered = array_filter($values, fn($v) => $v !== null);
        return empty($filtered) ? 0 : round(array_sum($filtered) / count($filtered), 2);
    }

    /**
     * Validate and cast IDs to integers, filtering out invalid values.
     */
    private function validateAndCastIds(array|int|string $ids): array
    {
        if (!is_array($ids)) {
            $ids = [$ids];
        }

        return array_filter(array_map('intval', $ids), fn($id) => $id > 0);
    }

    /**
     * Redirect to next review or browse page with appropriate message.
     */
    private function redirectToNextOrBrowse(string $successMessage): Response
    {
        $queueIds = Plugin::getInstance()->review->getPendingReviewIds();

        if (!empty($queueIds)) {
            Craft::$app->getSession()->setNotice(Craft::t('lens', $successMessage));
            return $this->redirect(UrlHelper::cpUrl("lens/review/{$queueIds[0]}"));
        }

        Craft::$app->getSession()->setNotice(Craft::t('lens', 'All reviews complete!'));
        return $this->redirect('lens/review');
    }
}
