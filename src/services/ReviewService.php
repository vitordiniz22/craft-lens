<?php

declare(strict_types=1);

namespace vitordiniz22\craftlens\services;

use Craft;
use craft\elements\Asset;
use vitordiniz22\craftlens\enums\AnalysisStatus;
use vitordiniz22\craftlens\enums\LogCategory;
use vitordiniz22\craftlens\helpers\AssetTitleHelper;
use vitordiniz22\craftlens\helpers\FieldLayoutHelper;
use vitordiniz22\craftlens\helpers\Logger;
use vitordiniz22\craftlens\helpers\MultisiteHelper;
use vitordiniz22\craftlens\Plugin;
use vitordiniz22\craftlens\records\AssetAnalysisRecord;
use vitordiniz22\craftlens\records\AssetColorRecord;
use vitordiniz22\craftlens\records\AssetTagRecord;
use yii\base\Component;
use yii\base\InvalidArgumentException;

/**
 * Service for managing the AI suggestions review workflow.
 */
class ReviewService extends Component
{
    /**
     * Approve an analysis and apply results to asset.
     *
     * @throws InvalidArgumentException If analysis record not found
     * @throws \RuntimeException If save fails
     */
    public function approve(int $analysisId, array $applyOverrides = []): void
    {
        $record = AssetAnalysisRecord::findOne($analysisId);

        if ($record === null) {
            throw new InvalidArgumentException("Analysis record {$analysisId} not found");
        }

        $record->status = AnalysisStatus::Approved->value;
        $this->validateRecordSave($record, $analysisId);

        Logger::info(LogCategory::Review, 'Analysis approved', assetId: $record->assetId);

        $this->autoApplyAfterApproval($record, $applyOverrides);

        try {
            Plugin::getInstance()->searchIndex->reindexField($record, 'title');
            Plugin::getInstance()->searchIndex->reindexField($record, 'alt');
        } catch (\Throwable $e) {
            Logger::warning(LogCategory::SearchIndex, 'Search index update failed (non-fatal): ' . $e->getMessage(), assetId: $record->assetId);
        }
    }

    /**
     * Reject an analysis.
     *
     * @throws InvalidArgumentException If analysis record not found
     * @throws \RuntimeException If save fails
     */
    public function reject(int $analysisId): void
    {
        $record = AssetAnalysisRecord::findOne($analysisId);

        if ($record === null) {
            throw new InvalidArgumentException("Analysis record {$analysisId} not found");
        }

        $record->status = AnalysisStatus::Rejected->value;
        $this->validateRecordSave($record, $analysisId);

        Logger::info(LogCategory::Review, 'Analysis rejected', assetId: $record->assetId);
    }

    /**
     * Approve multiple analyses.
     *
     * @param int[] $analysisIds
     * @throws \Throwable If any approval fails (rolls back all changes)
     */
    public function bulkApprove(array $analysisIds): int
    {
        return $this->bulkAction($analysisIds, 'approve', 'approved');
    }

    /**
     * Reject multiple analyses.
     *
     * @param int[] $analysisIds
     * @throws \Throwable If any rejection fails (rolls back all changes)
     */
    public function bulkReject(array $analysisIds): int
    {
        return $this->bulkAction($analysisIds, 'reject', 'rejected');
    }

    /**
     * Execute a bulk action (approve/reject) within a transaction.
     *
     * @throws \Throwable If any action fails (rolls back all changes)
     */
    private function bulkAction(array $analysisIds, string $method, string $label): int
    {
        if (empty($analysisIds)) {
            return 0;
        }

        $count = 0;
        $transaction = Craft::$app->getDb()->beginTransaction();

        try {
            foreach ($analysisIds as $id) {
                $this->$method((int) $id);
                $count++;
            }
            $transaction->commit();

            Logger::info(LogCategory::Review, "Bulk {$label} {$count} analyses", context: ['count' => $count]);
        } catch (\Throwable $e) {
            $transaction->rollBack();
            Logger::error(LogCategory::Review, "Bulk {$label} failed, transaction rolled back", exception: $e, context: ['processedBeforeFailure' => $count]);
            throw $e;
        }

        return $count;
    }

    /**
     * Edit analysis fields and approve.
     *
     * Writes user edits to the main columns.
     * The *Ai columns remain untouched (they hold the original AI values).
     *
     * @param array{altText?: string, suggestedTitle?: string, longDescription?: string, tags?: array, dominantColors?: array, faceCount?: int, containsPeople?: bool, nsfwScore?: float, hasWatermark?: bool, containsBrandLogo?: bool, focalPointX?: float, focalPointY?: float} $modifications
     * @throws InvalidArgumentException If analysis record not found
     * @throws \RuntimeException If save fails
     */
    public function editAndApprove(int $analysisId, array $modifications, array $applyOverrides = []): void
    {
        $record = AssetAnalysisRecord::findOne($analysisId);

        if ($record === null) {
            throw new InvalidArgumentException("Analysis record {$analysisId} not found");
        }

        $editService = Plugin::getInstance()->analysisEdit;

        // Update individual fields via the shared edit service
        $editableSet = array_flip(AssetAnalysisRecord::EDITABLE_FIELDS);
        $fieldKeys = array_intersect_key($modifications, $editableSet);

        // Handle focal point as a pair — pass $record to avoid redundant queries
        if (isset($modifications['focalPointX']) && isset($modifications['focalPointY'])) {
            $editService->updateSingleField($analysisId, 'focalPointX', $modifications['focalPointX'], $record);
            $editService->updateSingleField($analysisId, 'focalPointY', $modifications['focalPointY'], $record);
            unset($fieldKeys['focalPointX'], $fieldKeys['focalPointY']);
        }

        foreach ($fieldKeys as $field => $value) {
            $editService->updateSingleField($analysisId, $field, $value, $record);
        }

        // Handle tags modifications
        if (isset($modifications['tags']) && is_array($modifications['tags'])) {
            $editService->updateTags($analysisId, $modifications['tags']);
        }

        // Handle colors modifications
        if (isset($modifications['dominantColors']) && is_array($modifications['dominantColors'])) {
            $editService->updateColors($analysisId, $modifications['dominantColors']);
        }

        // Handle per-site content modifications (respecting per-field translatability)
        if (isset($modifications['siteContent']) && is_array($modifications['siteContent'])) {
            $asset = Asset::find()->id($record->assetId)->one();

            if ($asset === null) {
                throw new InvalidArgumentException("Asset not found for analysis record {$analysisId}");
            }

            $volumeId = $asset->getVolume()->id;
            $altTranslatable = $volumeId !== null && MultisiteHelper::isAltTranslatable($volumeId);
            $titleTranslatable = $volumeId !== null && MultisiteHelper::isTitleTranslatable($volumeId);

            $siteContentService = Plugin::getInstance()->siteContent;

            foreach ($modifications['siteContent'] as $siteId => $fields) {
                $siteId = (int) $siteId;

                if (!is_array($fields)) {
                    continue;
                }

                foreach ($fields as $field => $value) {
                    if ($field === 'altText' && $altTranslatable) {
                        $siteContentService->updateSiteField($analysisId, $siteId, $field, $value);
                    } elseif ($field === 'suggestedTitle' && $titleTranslatable) {
                        $siteContentService->updateSiteField($analysisId, $siteId, $field, $value);
                    }
                }
            }
        }

        Logger::info(LogCategory::Review, 'Analysis edited and approved', assetId: $record->assetId, context: [
            'editedFields' => array_keys($modifications),
        ]);

        $this->approve($analysisId, $applyOverrides);
    }

    /**
     * Get pending reviews.
     *
     * @return AssetAnalysisRecord[]
     */
    public function getPendingReviews(int $limit = 50, int $offset = 0): array
    {
        return AssetAnalysisRecord::find()
            ->where(['status' => AnalysisStatus::PendingReview->value])
            ->orderBy(['dateCreated' => SORT_ASC])
            ->limit($limit)
            ->offset($offset)
            ->all();
    }

    /**
     * Get count of pending reviews.
     */
    public function getPendingReviewCount(): int
    {
        return (int) AssetAnalysisRecord::find()
            ->where(['status' => AnalysisStatus::PendingReview->value])
            ->count();
    }


    /**
     * Get IDs of all pending review records, ordered by dateCreated ASC.
     *
     * @return int[]
     */
    public function getPendingReviewIds(): array
    {
        return AssetAnalysisRecord::find()
            ->select(['id'])
            ->where(['status' => AnalysisStatus::PendingReview->value])
            ->orderBy(['dateCreated' => SORT_ASC])
            ->column();
    }

    /**
     * Load full analysis data for the single-review AJAX endpoint.
     *
     * @return array|null Structured data array, or null if not found
     */
    public function getFullAnalysis(int $analysisId): ?array
    {
        $record = AssetAnalysisRecord::findOne($analysisId);

        if ($record === null) {
            return null;
        }

        $asset = Asset::find()->id($record->assetId)->one();

        if ($asset === null) {
            return null;
        }

        return array_merge(
            $this->getAnalysisBaseData($record, $asset),
            $this->getAnalysisContentData($record),
            $this->getAnalysisTaxonomyData($record),
            $this->getAnalysisDetectionData($record),
            $this->getAnalysisQualityAndRelationsData($record, $asset),
        );
    }

    /**
     * Asset metadata and native field context for review.
     */
    private function getAnalysisBaseData(AssetAnalysisRecord $record, Asset $asset): array
    {
        return [
            'analysisId' => $record->id,
            'assetId' => $record->assetId,
            'status' => $record->status,
            'filename' => $asset->filename,
            'editUrl' => $asset->getCpEditUrl(),
            'thumbnailUrl' => Craft::$app->getAssets()->getThumbUrl($asset, 800, 800),
            'previewUrl' => $asset->getUrl(),
            'uploadDate' => $asset->dateCreated ? $asset->dateCreated->format('Y-m-d H:i') : null,
            'fileSize' => $asset->size,
            'kind' => $asset->kind,
            'assetTitle' => $asset->title,
            'assetAlt' => $asset->alt ?? '',
            'isAutoGeneratedTitle' => AssetTitleHelper::isAutoGenerated($asset),
            'isAltEmpty' => empty($asset->alt),
            'altFieldInLayout' => FieldLayoutHelper::hasAltField($asset),
            'assetHasFocalPoint' => $asset->getHasFocalPoint(),
            'assetFocalPoint' => $asset->getHasFocalPoint() ? $asset->getFocalPoint() : null,
            'provider' => $record->provider,
            'providerModel' => $record->providerModel,
            'processedAt' => $record->processedAt,
        ];
    }

    /**
     * Editable content fields (title, alt, description, extracted text).
     */
    private function getAnalysisContentData(AssetAnalysisRecord $record): array
    {
        return [
            'suggestedTitle' => $record->suggestedTitle,
            'suggestedTitleAi' => $record->suggestedTitleAi,
            'titleConfidence' => $record->titleConfidence,
            'altText' => $record->altText,
            'altTextAi' => $record->altTextAi,
            'altTextConfidence' => $record->altTextConfidence,
            'longDescription' => $record->longDescription,
            'longDescriptionAi' => $record->longDescriptionAi,
            'longDescriptionConfidence' => $record->longDescriptionConfidence,
            'extractedText' => $record->extractedText,
            'extractedTextAi' => $record->extractedTextAi,
        ];
    }

    /**
     * Tags and colors with focal point data.
     */
    private function getAnalysisTaxonomyData(AssetAnalysisRecord $record): array
    {
        $tags = AssetTagRecord::find()
            ->where(['analysisId' => $record->id])
            ->orderBy(['confidence' => SORT_DESC])
            ->all();

        $colors = AssetColorRecord::find()
            ->where(['analysisId' => $record->id])
            ->all();

        return [
            'tags' => array_map(fn($t) => [
                'tag' => $t->tag,
                'confidence' => $t->confidence,
                'isAi' => (bool)$t->isAi,
            ], $tags),
            'colors' => array_map(fn($c) => [
                'hex' => $c->hex,
                'percentage' => $c->percentage,
                'isAutoGenerated' => (bool)$c->isAutoGenerated,
            ], $colors),
            'focalPointX' => $record->focalPointX,
            'focalPointY' => $record->focalPointY,
            'focalPointXAi' => $record->focalPointXAi,
            'focalPointYAi' => $record->focalPointYAi,
            'focalPointConfidence' => $record->focalPointConfidence,
        ];
    }

    /**
     * Detection fields: people, NSFW, watermark, brand logo.
     */
    private function getAnalysisDetectionData(AssetAnalysisRecord $record): array
    {
        return [
            'faceCount' => $record->faceCount,
            'faceCountAi' => $record->faceCountAi,
            'containsPeople' => (bool)$record->containsPeople,
            'containsPeopleAi' => (bool)$record->containsPeopleAi,
            'containsPeopleConfidence' => $record->containsPeopleConfidence,
            'nsfwScore' => $record->nsfwScore,
            'nsfwScoreAi' => $record->nsfwScoreAi,
            'nsfwConfidence' => $record->nsfwConfidence,
            'nsfwCategories' => $record->nsfwCategories,
            'isFlaggedNsfw' => (bool)$record->isFlaggedNsfw,
            'hasWatermark' => (bool)$record->hasWatermark,
            'hasWatermarkAi' => (bool)($record->hasWatermarkAi ?? false),
            'watermarkType' => $record->watermarkType,
            'watermarkConfidence' => $record->watermarkConfidence,
            'watermarkDetails' => $record->watermarkDetails,
            'containsBrandLogo' => (bool)$record->containsBrandLogo,
            'containsBrandLogoAi' => (bool)($record->containsBrandLogoAi ?? false),
            'containsBrandLogoConfidence' => $record->containsBrandLogoConfidence,
            'detectedBrands' => $record->detectedBrands,
        ];
    }

    /**
     * Quality scores, duplicates, and per-site translation content.
     */
    private function getAnalysisQualityAndRelationsData(AssetAnalysisRecord $record, Asset $asset): array
    {
        $siteContentData = $this->loadSiteContentData($record->id, $asset);

        return [
            'sharpnessScore' => $record->sharpnessScore,
            'exposureScore' => $record->exposureScore,
            'noiseScore' => $record->noiseScore,
            'overallQualityScore' => $record->overallQualityScore,
            'compressionQuality' => $record->compressionQuality,
            'colorProfile' => $record->colorProfile,
            'similarImages' => Plugin::getInstance()->duplicateDetection->getSimilarAssetsForDisplay($record->assetId),
            'siteContent' => $siteContentData,
            'hasMultisiteContent' => !empty($siteContentData),
            'isAltTranslatable' => MultisiteHelper::isAltTranslatable($asset->getVolume()->id),
            'isTitleTranslatable' => MultisiteHelper::isTitleTranslatable($asset->getVolume()->id),
        ];
    }

    /**
     * Calculate average confidence from multiple confidence values.
     */
    public function calculateAverageConfidence(?float ...$values): float
    {
        $filtered = array_filter($values, fn($v) => $v !== null);
        return empty($filtered) ? 0 : round(array_sum($filtered) / count($filtered), 2);
    }

    /**
     * Load per-site content data for an analysis, structured for templates/JS.
     *
     * @return array<int, array{siteId: int, language: string, siteName: string, altText: string|null, altTextAi: string|null, altTextConfidence: float|null, suggestedTitle: string|null, suggestedTitleAi: string|null, titleConfidence: float|null}>
     */
    private function loadSiteContentData(int $analysisId, Asset $asset): array
    {
        if (!MultisiteHelper::needsMultisiteContent($asset)) {
            return [];
        }

        $records = Plugin::getInstance()->siteContent->getAllSiteContent($analysisId);
        $sitesNeeded = MultisiteHelper::getSitesNeedingContent($asset);

        if (empty($sitesNeeded)) {
            return [];
        }

        $allSites = Craft::$app->getSites()->getAllSites();
        $siteNames = [];
        foreach ($allSites as $site) {
            $siteNames[$site->id] = $site->name;
        }

        $data = [];
        foreach ($sitesNeeded as $siteInfo) {
            $siteId = $siteInfo['siteId'];
            $record = $records[$siteId] ?? null;

            $data[] = [
                'siteId' => $siteId,
                'language' => $record->language ?? $siteInfo['language'],
                'siteName' => $siteNames[$siteId] ?? "Site {$siteId}",
                'altText' => $record->altText ?? null,
                'altTextAi' => $record->altTextAi ?? null,
                'altTextConfidence' => $record->altTextConfidence ?? null,
                'suggestedTitle' => $record->suggestedTitle ?? null,
                'suggestedTitleAi' => $record->suggestedTitleAi ?? null,
                'titleConfidence' => $record->titleConfidence ?? null,
            ];
        }

        return $data;
    }

    /**
     * Auto-apply title, alt text, and focal point to the Craft asset after approval.
     *
     * Only applies fields that haven't been manually set on the asset.
     */
    private function autoApplyAfterApproval(AssetAnalysisRecord $record, array $applyOverrides = []): void
    {
        $asset = Asset::find()->id($record->assetId)->one();

        if ($asset === null) {
            return;
        }

        Plugin::getInstance()->assetAnalysis->autoApplyFromRecord($asset, $record, $applyOverrides);
    }

    /**
     * Validate that a record was saved successfully and throw exception if not.
     *
     * @throws \RuntimeException If save failed
     */
    private function validateRecordSave(AssetAnalysisRecord $record, int $analysisId): void
    {
        if (!$record->save()) {
            $errors = implode(', ', $record->getErrorSummary(true));
            throw new \RuntimeException("Failed to save analysis record {$analysisId}: {$errors}");
        }
    }
}
