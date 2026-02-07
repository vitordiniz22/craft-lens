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
        $queueIds = $reviewService->getPendingReviewIds();
        $pendingCount = count($queueIds);

        return $this->renderTemplate('lens/_review/index', [
            'queueIds' => $queueIds,
            'pendingCount' => $pendingCount,
        ]);
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

        // Boolean/numeric fields
        foreach (['faceCount', 'containsPeople', 'nsfwScore', 'hasWatermark', 'containsBrandLogo'] as $field) {
            $value = $this->request->getBodyParam($field);
            if ($value !== null) {
                $modifications[$field] = $value;
            }
        }

        // Focal point
        $focalX = $this->request->getBodyParam('focalPointX');
        $focalY = $this->request->getBodyParam('focalPointY');
        if ($focalX !== null && $focalY !== null) {
            $modifications['focalPointX'] = $focalX;
            $modifications['focalPointY'] = $focalY;
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

        Craft::$app->getSession()->setNotice(Craft::t('lens', 'Analysis approved.'));

        return $this->redirectToPostedUrl();
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

        try {
            Plugin::getInstance()->review->reject($analysisId, $userId);
        } catch (\Throwable $e) {
            Logger::error(LogCategory::Review, "Reject failed for analysis {$analysisId}", exception: $e);
            throw $e;
        }

        if ($this->request->getAcceptsJson()) {
            return $this->asJson(['success' => true]);
        }

        Craft::$app->getSession()->setNotice(Craft::t('lens', 'Analysis rejected.'));

        return $this->redirectToPostedUrl();
    }

    public function actionBulkApprove(): Response
    {
        $this->requireCpRequest();
        $this->requirePostRequest();
        $this->requirePermission('accessPlugin-lens');

        $ids = $this->request->getRequiredBodyParam('ids');
        $userId = Craft::$app->getUser()->getId();

        if (!is_array($ids)) {
            $ids = [$ids];
        }

        $ids = array_filter(array_map('intval', $ids), fn($id) => $id > 0);

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

        if (!is_array($ids)) {
            $ids = [$ids];
        }

        $ids = array_filter(array_map('intval', $ids), fn($id) => $id > 0);

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

    /**
     * Accept the AI value for a specific field, clearing the user edit.
     */
    public function actionAcceptAiValue(): Response
    {
        $this->requireCpRequest();
        $this->requirePostRequest();
        $this->requirePermission('accessPlugin-lens');

        $analysisId = (int) $this->request->getRequiredBodyParam('analysisId');
        $fieldName = $this->request->getRequiredBodyParam('fieldName');

        if ($analysisId < 1) {
            throw new BadRequestHttpException('Invalid analysis ID');
        }

        try {
            Plugin::getInstance()->review->acceptAiValue($analysisId, $fieldName);
        } catch (\Throwable $e) {
            Logger::error(LogCategory::Review, "Accept AI value failed for analysis {$analysisId}, field {$fieldName}", exception: $e);
            throw $e;
        }

        return $this->asJson(['success' => true]);
    }

    /**
     * AJAX endpoint: get full analysis data for the single-review panel.
     */
    public function actionGetAnalysis(): Response
    {
        $this->requireCpRequest();
        $this->requireAcceptsJson();
        $this->requirePermission('accessPlugin-lens');

        $analysisId = (int) $this->request->getRequiredParam('analysisId');

        if ($analysisId < 1) {
            throw new BadRequestHttpException('Invalid analysis ID');
        }

        $reviewService = Plugin::getInstance()->review;
        $data = $reviewService->getFullAnalysis($analysisId);

        if ($data === null || $data['status'] !== AnalysisStatus::PendingReview->value) {
            return $this->asJson([
                'success' => false,
                'error' => 'not_found',
                'message' => Craft::t('lens', 'Analysis not found or already reviewed.'),
            ]);
        }

        // Add queue navigation context
        $queueIds = $reviewService->getPendingReviewIds();
        $currentIndex = array_search($analysisId, $queueIds);

        $data['queue'] = [
            'currentIndex' => $currentIndex !== false ? $currentIndex : 0,
            'totalCount' => count($queueIds),
            'prevId' => ($currentIndex !== false && $currentIndex > 0) ? $queueIds[$currentIndex - 1] : null,
            'nextId' => ($currentIndex !== false && $currentIndex < count($queueIds) - 1) ? $queueIds[$currentIndex + 1] : null,
            'ids' => $queueIds,
        ];

        return $this->asJson([
            'success' => true,
            'data' => $data,
        ]);
    }

    /**
     * AJAX endpoint: get queue summary for grid/bulk mode.
     */
    public function actionGetQueue(): Response
    {
        $this->requireCpRequest();
        $this->requireAcceptsJson();
        $this->requirePermission('accessPlugin-lens');

        $reviewService = Plugin::getInstance()->review;

        $page = max(1, (int) ($this->request->getParam('page') ?? 1));
        $perPage = max(1, min(100, (int) ($this->request->getParam('perPage') ?? 24)));
        $offset = ($page - 1) * $perPage;

        $totalCount = $reviewService->getPendingReviewCount();
        $totalPages = max(1, (int) ceil($totalCount / $perPage));
        $pendingReviews = $reviewService->getPendingReviews($perPage, $offset);

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
                'assetId' => $analysis->assetId,
                'thumbnailUrl' => Craft::$app->getAssets()->getThumbUrl($asset, 200, 200),
                'filename' => $asset->filename,
                'suggestedTitle' => $analysis->suggestedTitle,
                'avgConfidence' => round($avgConfidence, 2),
                'tagCount' => $tagCounts[$analysis->id] ?? 0,
            ];
        }

        return $this->asJson([
            'success' => true,
            'items' => $items,
            'totalCount' => $totalCount,
            'page' => $page,
            'perPage' => $perPage,
            'totalPages' => $totalPages,
        ]);
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

        return $this->redirectToPostedUrl();
    }
}
