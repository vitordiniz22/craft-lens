<?php

declare(strict_types=1);

namespace vitordiniz22\craftlens\controllers;

use Craft;
use craft\elements\Asset;
use craft\helpers\UrlHelper;
use craft\web\Controller;
use vitordiniz22\craftlens\controllers\traits\RequiresAiProviderTrait;
use vitordiniz22\craftlens\enums\AnalysisStatus;
use vitordiniz22\craftlens\enums\LogCategory;
use vitordiniz22\craftlens\helpers\Logger;
use vitordiniz22\craftlens\Plugin;
use vitordiniz22\craftlens\records\AssetAnalysisRecord;
use vitordiniz22\craftlens\records\AssetTagRecord;
use yii\web\BadRequestHttpException;
use yii\web\Response;

/**
 * Controller for the AI suggestions review workflow.
 */
class ReviewController extends Controller
{
    use RequiresAiProviderTrait;

    protected array|int|bool $allowAnonymous = false;

    public function actionIndex(): Response
    {
        $this->requireCpRequest();
        $this->requirePermission('accessPlugin-lens');

        Plugin::getInstance()->requireProEdition();

        $queueIds = Plugin::getInstance()->review->getPendingReviewIds();

        if (!empty($queueIds)) {
            return $this->redirect(UrlHelper::cpUrl("lens/review/{$queueIds[0]}"));
        }

        return $this->renderTemplate('lens/_review/empty', [
            'hasAnalyses' => AssetAnalysisRecord::find()->exists(),
        ]);
    }

    /**
     * Single review view for a specific analysis
     */
    public function actionView(int $analysisId): Response
    {
        $this->requireCpRequest();
        $this->requirePermission('accessPlugin-lens');
        Plugin::getInstance()->requireProEdition();

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
     * Focus view - alias for index, kept for backward compatibility.
     */
    public function actionFocus(): Response
    {
        return $this->redirect(UrlHelper::cpUrl('lens/review'));
    }

    public function actionApprove(): Response
    {
        $this->requireCpRequest();
        $this->requirePostRequest();
        $this->requirePermission('accessPlugin-lens');
        Plugin::getInstance()->requireProEdition();

        $analysisId = (int) $this->request->getRequiredBodyParam('analysisId');

        if ($analysisId < 1) {
            throw new BadRequestHttpException('Invalid analysis ID');
        }

        $modifications = $this->parseApprovalModifications();
        $userId = Craft::$app->getUser()->getId();
        $reviewService = Plugin::getInstance()->review;

        $applyOverrides = [];

        if (isset($modifications['applyTitle'])) {
            $applyOverrides['applyTitle'] = $modifications['applyTitle'];
            unset($modifications['applyTitle']);
        }

        if (isset($modifications['applyAlt'])) {
            $applyOverrides['applyAlt'] = $modifications['applyAlt'];
            unset($modifications['applyAlt']);
        }

        try {
            if (!empty($modifications)) {
                $reviewService->editAndApprove($analysisId, $modifications, $userId, $applyOverrides);
            } else {
                $reviewService->approve($analysisId, $userId, $applyOverrides);
            }
        } catch (\InvalidArgumentException $e) {
            throw new BadRequestHttpException($e->getMessage());
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
        Plugin::getInstance()->requireProEdition();

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
        Plugin::getInstance()->requireProEdition();

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
        Plugin::getInstance()->requireProEdition();

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

        Plugin::getInstance()->requireProEdition();

        $reviewService = Plugin::getInstance()->review;
        $totalCount = $reviewService->getPendingReviewCount();

        if ($totalCount === 0) {
            return $this->renderTemplate('lens/_review/bulk', [
                'totalCount' => 0,
                'hasAnalyses' => AssetAnalysisRecord::find()->exists(),
            ]);
        }

        // Load all pending reviews for bulk mode (up to 100)
        $pendingReviews = $reviewService->getPendingReviews(100, 0);
        $ids = $this->extractIdsFromAnalyses($pendingReviews);

        $assets = Asset::find()->id($ids['assetIds'])->siteId(Craft::$app->getSites()->getPrimarySite()->id)->indexBy('id')->all();
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
                'asset' => $asset,
                'analysis' => $analysis,
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
    /**
     * Parse approval modifications from the request body.
     *
     * @return array<string, mixed>
     */
    private function parseApprovalModifications(): array
    {
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

        $faceCount = $this->request->getBodyParam('faceCount');

        if ($faceCount !== null) {
            $faceCountInt = (int) $faceCount;

            if ($faceCountInt < 0 || $faceCountInt > 10000) {
                throw new BadRequestHttpException('Invalid face count');
            }

            $modifications['faceCount'] = $faceCountInt;
        }

        $nsfwScore = $this->request->getBodyParam('nsfwScore');

        if ($nsfwScore !== null) {
            $nsfwScoreFloat = (float) $nsfwScore;
            $modifications['nsfwScore'] = min(1.0, max(0.0, $nsfwScoreFloat));
        }

        foreach (['containsPeople', 'hasWatermark', 'containsBrandLogo'] as $field) {
            $value = $this->request->getBodyParam($field);

            if ($value !== null) {
                $modifications[$field] = (bool) $value;
            }
        }

        $focalX = $this->request->getBodyParam('focalPointX');
        $focalY = $this->request->getBodyParam('focalPointY');

        if ($focalX !== null && $focalY !== null) {
            $modifications['focalPointX'] = (float) $focalX;
            $modifications['focalPointY'] = (float) $focalY;
        }

        $siteContentJson = $this->request->getBodyParam('siteContent');

        if ($siteContentJson !== null) {
            $siteContent = is_string($siteContentJson) ? json_decode($siteContentJson, true) : $siteContentJson;

            if (is_array($siteContent) && !empty($siteContent)) {
                $modifications['siteContent'] = $siteContent;
            }
        }

        $applyTitle = $this->request->getBodyParam('applyTitle');

        if ($applyTitle !== null) {
            $modifications['applyTitle'] = (bool) (int) $applyTitle;
        }

        $applyAlt = $this->request->getBodyParam('applyAlt');

        if ($applyAlt !== null) {
            $modifications['applyAlt'] = (bool) (int) $applyAlt;
        }

        return $modifications;
    }

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
