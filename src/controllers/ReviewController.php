<?php

declare(strict_types=1);

namespace vitordiniz22\craftlens\controllers;

use Craft;
use craft\elements\Asset;
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
    protected array|int|bool $allowAnonymous = false;

    public function actionIndex(): Response
    {
        $this->requireCpRequest();
        $this->requirePermission('accessPlugin-lens');

        $reviewService = Plugin::getInstance()->review;
        $page = max(1, (int) ($this->request->getParam('page') ?? 1));
        $perPage = 24;
        $offset = ($page - 1) * $perPage;

        $totalCount = $reviewService->getPendingReviewCount();
        $totalPages = max(1, (int) ceil($totalCount / $perPage));

        if ($totalCount === 0) {
            return $this->renderTemplate('lens/_review/browse', [
                'pendingCount' => 0,
            ]);
        }

        $pendingReviews = $reviewService->getPendingReviews($perPage, $offset);
        $ids = $this->extractIdsFromAnalyses($pendingReviews);
        $assets = Asset::find()->id($ids['assetIds'])->indexBy('id')->all();
        $tagCounts = $this->getTagCounts($ids['analysisIds']);
        $items = $this->buildReviewItems($pendingReviews, $assets, $tagCounts);

        return $this->renderTemplate('lens/_review/browse', [
            'items' => $items,
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
            // Redirect to first pending review
            return $this->redirect("lens/review/{$queueIds[0]}");
        }

        // Queue empty - redirect to browse
        return $this->redirect('lens/review');
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

        // Text fields
        $suggestedTitle = $this->request->getBodyParam('suggestedTitle');
        if ($suggestedTitle !== null) {
            $modifications['suggestedTitle'] = $suggestedTitle;
        }

        $altText = $this->request->getBodyParam('altText');
        if ($altText !== null) {
            $modifications['altText'] = $altText;
        }

        $longDescription = $this->request->getBodyParam('longDescription');
        if ($longDescription !== null) {
            $modifications['longDescription'] = $longDescription;
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
        foreach (['faceCount'] as $field) {
            $value = $this->request->getBodyParam($field);
            if ($value !== null) {
                $modifications[$field] = (int) $value;
            }
        }

        foreach (['nsfwScore'] as $field) {
            $value = $this->request->getBodyParam($field);
            if ($value !== null) {
                $modifications[$field] = (float) $value;
            }
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
        $this->requireCpRequest();
        $this->requirePostRequest();
        $this->requirePermission('accessPlugin-lens');

        $ids = $this->request->getRequiredBodyParam('ids');
        $userId = Craft::$app->getUser()->getId();

        $ids = $this->validateAndCastIds($ids);

        if (empty($ids)) {
            throw new BadRequestHttpException('No valid IDs provided');
        }

        $count = Plugin::getInstance()->review->bulkApprove($ids, $userId);

        Logger::info(LogCategory::Review, "Bulk approve from CP", context: ['count' => $count]);

        if ($this->request->getAcceptsJson()) {
            return $this->asJson([
                'success' => true,
                'count' => $count,
            ]);
        }

        Craft::$app->getSession()->setNotice(
            Craft::t('lens', '{count} analyses approved.', ['count' => $count])
        );

        return $this->redirectToPostedUrl();
    }

    public function actionBulkReject(): Response
    {
        $this->requireCpRequest();
        $this->requirePostRequest();
        $this->requirePermission('accessPlugin-lens');

        $ids = $this->request->getRequiredBodyParam('ids');
        $userId = Craft::$app->getUser()->getId();

        $ids = $this->validateAndCastIds($ids);

        if (empty($ids)) {
            throw new BadRequestHttpException('No valid IDs provided');
        }

        $count = Plugin::getInstance()->review->bulkReject($ids, $userId);

        Logger::info(LogCategory::Review, "Bulk reject from CP", context: ['count' => $count]);

        if ($this->request->getAcceptsJson()) {
            return $this->asJson([
                'success' => true,
                'count' => $count,
            ]);
        }

        Craft::$app->getSession()->setNotice(
            Craft::t('lens', '{count} analyses rejected.', ['count' => $count])
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

        $reviewService = Plugin::getInstance()->review;

        $reviewService->skip($analysisId);

        if ($this->request->getAcceptsJson()) {
            return $this->asJson(['success' => true]);
        }

        // Redirect to next analysis or back to browse
        $queueIds = $reviewService->getPendingReviewIds();

        if (!empty($queueIds)) {
            $nextId = $queueIds[0];
            Craft::$app->getSession()->setNotice(Craft::t('lens', 'Analysis skipped.'));
            return $this->redirect("lens/review/{$nextId}");
        }

        // Queue empty
        Craft::$app->getSession()->setNotice(Craft::t('lens', 'All reviews complete!'));
        return $this->redirect('lens/review');
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
        $assetIds = array_map(fn($a) => $a->assetId, $pendingReviews);
        $analysisIds = array_map(fn($a) => $a->id, $pendingReviews);

        $assets = Asset::find()->id($assetIds)->indexBy('id')->all();

        $tagCounts = [];
        if (!empty($analysisIds)) {
            $tagCountRows = AssetTagRecord::find()
                ->select(['analysisId', 'COUNT(*) AS cnt'])
                ->where(['analysisId' => $analysisIds])
                ->groupBy(['analysisId'])
                ->asArray()
                ->all();
            foreach ($tagCountRows as $row) {
                $tagCounts[(int) $row['analysisId']] = (int) $row['cnt'];
            }
        }

        $items = [];
        foreach ($pendingReviews as $analysis) {
            $asset = $assets[$analysis->assetId] ?? null;
            if ($asset === null) {
                continue;
            }

            $avgConfidence = 0;
            $confFields = [$analysis->titleConfidence, $analysis->altTextConfidence, $analysis->longDescriptionConfidence];
            $confFields = array_filter($confFields, fn($v) => $v !== null);

            if (!empty($confFields)) {
                $avgConfidence = array_sum($confFields) / count($confFields);
            }

            $items[] = [
                'analysisId' => $analysis->id,
                'thumbnailUrl' => Craft::$app->getAssets()->getThumbUrl($asset, 200, 200),
                'filename' => $asset->filename,
                'suggestedTitle' => $analysis->suggestedTitle,
                'avgConfidence' => round($avgConfidence, 2),
                'tagCount' => $tagCounts[$analysis->id] ?? 0,
            ];
        }

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
    private function validateAndCastIds($ids): array
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
            return $this->redirect("lens/review/{$queueIds[0]}");
        }

        Craft::$app->getSession()->setNotice(Craft::t('lens', 'All reviews complete!'));
        return $this->redirect('lens/review');
    }
}
