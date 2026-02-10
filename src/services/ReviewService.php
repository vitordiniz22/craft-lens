<?php

declare(strict_types=1);

namespace vitordiniz22\craftlens\services;

use Craft;
use craft\elements\Asset;
use craft\helpers\DateTimeHelper;
use vitordiniz22\craftlens\enums\AnalysisStatus;
use vitordiniz22\craftlens\enums\LogCategory;
use vitordiniz22\craftlens\helpers\Logger;
use vitordiniz22\craftlens\Plugin;
use vitordiniz22\craftlens\records\AssetAnalysisRecord;
use vitordiniz22\craftlens\records\AssetColorRecord;
use vitordiniz22\craftlens\records\AssetTagRecord;
use vitordiniz22\craftlens\records\DuplicateGroupRecord;
use vitordiniz22\craftlens\records\ExifMetadataRecord;
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
    public function approve(int $analysisId, ?int $userId = null): void
    {
        $record = AssetAnalysisRecord::findOne($analysisId);

        if ($record === null) {
            throw new InvalidArgumentException("Analysis record {$analysisId} not found");
        }

        $record->status = AnalysisStatus::Approved->value;
        $this->validateRecordSave($record, $analysisId);

        Logger::info(LogCategory::Review, 'Analysis approved', assetId: $record->assetId);

        $this->autoApplyAfterApproval($record);
    }

    /**
     * Reject an analysis.
     *
     * @throws InvalidArgumentException If analysis record not found
     * @throws \RuntimeException If save fails
     */
    public function reject(int $analysisId, ?int $userId = null): void
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
    public function bulkApprove(array $analysisIds, ?int $userId = null): int
    {
        if (empty($analysisIds)) {
            return 0;
        }

        $count = 0;
        $transaction = Craft::$app->getDb()->beginTransaction();

        try {
            foreach ($analysisIds as $id) {
                $this->approve((int)$id, $userId);
                $count++;
            }

            $transaction->commit();

            Logger::info(LogCategory::Review, "Bulk approved {$count} analyses", context: ['count' => $count]);
        } catch (\Throwable $e) {
            $transaction->rollBack();
            Logger::error(LogCategory::Review, 'Bulk approve failed, transaction rolled back', exception: $e, context: ['processedBeforeFailure' => $count]);
            throw $e;
        }

        return $count;
    }

    /**
     * Reject multiple analyses.
     *
     * @param int[] $analysisIds
     * @throws \Throwable If any rejection fails (rolls back all changes)
     */
    public function bulkReject(array $analysisIds, ?int $userId = null): int
    {
        if (empty($analysisIds)) {
            return 0;
        }

        $count = 0;
        $transaction = Craft::$app->getDb()->beginTransaction();

        try {
            foreach ($analysisIds as $id) {
                $this->reject((int)$id, $userId);
                $count++;
            }
            $transaction->commit();

            Logger::info(LogCategory::Review, "Bulk rejected {$count} analyses", context: ['count' => $count]);
        } catch (\Throwable $e) {
            $transaction->rollBack();
            Logger::error(LogCategory::Review, 'Bulk reject failed, transaction rolled back', exception: $e, context: ['processedBeforeFailure' => $count]);
            throw $e;
        }

        return $count;
    }

    /**
     * Edit analysis fields and approve.
     *
     * Writes user edits to the main columns and sets *EditedBy/*EditedAt.
     * The *Ai columns remain untouched (they hold the original AI values).
     *
     * @param array{altText?: string, suggestedTitle?: string, longDescription?: string, tags?: array, dominantColors?: array, faceCount?: int, containsPeople?: bool, nsfwScore?: float, hasWatermark?: bool, containsBrandLogo?: bool, focalPointX?: float, focalPointY?: float} $modifications
     * @throws InvalidArgumentException If analysis record not found
     * @throws \RuntimeException If save fails
     */
    public function editAndApprove(int $analysisId, array $modifications, ?int $userId = null): void
    {
        $record = AssetAnalysisRecord::findOne($analysisId);

        if ($record === null) {
            throw new InvalidArgumentException("Analysis record {$analysisId} not found");
        }

        $editService = Plugin::getInstance()->analysisEdit;

        // Update individual fields via the shared edit service
        $fieldKeys = array_intersect_key(
            $modifications,
            AssetAnalysisRecord::EDITABLE_FIELDS
        );

        // Handle focal point as a pair
        if (isset($modifications['focalPointX']) && isset($modifications['focalPointY'])) {
            $editService->updateSingleField($analysisId, 'focalPointX', $modifications['focalPointX'], $userId);
            $editService->updateSingleField($analysisId, 'focalPointY', $modifications['focalPointY'], $userId);
            unset($fieldKeys['focalPointX'], $fieldKeys['focalPointY']);
        }

        foreach ($fieldKeys as $field => $value) {
            $editService->updateSingleField($analysisId, $field, $value, $userId);
        }

        // Handle tags modifications
        if (isset($modifications['tags']) && is_array($modifications['tags'])) {
            $editService->updateTags($analysisId, $modifications['tags']);
        }

        // Handle colors modifications
        if (isset($modifications['dominantColors']) && is_array($modifications['dominantColors'])) {
            $editService->updateColors($analysisId, $modifications['dominantColors']);
        }

        Logger::info(LogCategory::Review, 'Analysis edited and approved', assetId: $record->assetId, context: [
            'editedFields' => array_keys($modifications),
        ]);

        $this->approve($analysisId, $userId);
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
     * Skip an analysis (keep pending for later).
     *
     * @throws InvalidArgumentException If analysis record not found
     * @throws \RuntimeException If save fails
     */
    public function skip(int $analysisId): void
    {
        $record = AssetAnalysisRecord::findOne($analysisId);

        if ($record === null) {
            throw new InvalidArgumentException("Analysis record {$analysisId} not found");
        }

        $record->dateUpdated = DateTimeHelper::now();
        $this->validateRecordSave($record, $analysisId);
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

        // Tags ordered by confidence DESC
        $tags = AssetTagRecord::find()
            ->where(['analysisId' => $record->id])
            ->orderBy(['confidence' => SORT_DESC])
            ->all();

        // Colors
        $colors = AssetColorRecord::find()
            ->where(['analysisId' => $record->id])
            ->all();



        // EXIF metadata (conditional)
        $exifData = null;
        if ($record->hasExifMetadata) {
            $exifRecord = ExifMetadataRecord::findOne(['analysisId' => $record->id]);
            if ($exifRecord !== null) {
                $exifData = [
                    'cameraMake' => $exifRecord->cameraMake,
                    'cameraModel' => $exifRecord->cameraModel,
                    'lens' => $exifRecord->lens,
                    'focalLength' => $exifRecord->focalLength,
                    'aperture' => $exifRecord->aperture,
                    'shutterSpeed' => $exifRecord->shutterSpeed,
                    'iso' => $exifRecord->iso,
                    'exposureMode' => $exifRecord->exposureMode,
                    'dateTaken' => $exifRecord->dateTaken ? (string)$exifRecord->dateTaken : null,
                    'width' => $exifRecord->width,
                    'height' => $exifRecord->height,
                    'latitude' => $exifRecord->latitude,
                    'longitude' => $exifRecord->longitude,
                    'altitude' => $exifRecord->altitude,
                    'cameraDisplay' => $exifRecord->getCameraDisplayString(),
                    'gpsDisplay' => $exifRecord->getGpsDisplayString(),
                ];
            }
        }

        // Unresolved duplicates involving this asset (max 3, highest similarity)
        $duplicates = DuplicateGroupRecord::find()
            ->where(['and',
                ['resolvedAt' => null],
                ['or',
                    ['canonicalAssetId' => $record->assetId],
                    ['duplicateAssetId' => $record->assetId],
                ],
            ])
            ->orderBy(['similarity' => SORT_DESC])
            ->limit(3)
            ->all();

        $otherAssetIds = array_map(function ($dup) use ($record) {
            return ($dup->canonicalAssetId === $record->assetId)
                ? $dup->duplicateAssetId
                : $dup->canonicalAssetId;
        }, $duplicates);

        $otherAssets = !empty($otherAssetIds)
            ? Asset::find()->id($otherAssetIds)->indexBy('id')->all()
            : [];

        $similarImages = [];
        foreach ($duplicates as $dup) {
            $otherAssetId = ($dup->canonicalAssetId === $record->assetId)
                ? $dup->duplicateAssetId
                : $dup->canonicalAssetId;

            $otherAsset = $otherAssets[$otherAssetId] ?? null;
            if ($otherAsset !== null) {
                $similarImages[] = [
                    'assetId' => $otherAssetId,
                    'filename' => $otherAsset->filename,
                    'thumbnailUrl' => Craft::$app->getAssets()->getThumbUrl($otherAsset, 120, 120),
                    'editUrl' => $otherAsset->getCpEditUrl(),
                    'similarity' => $dup->similarity,
                ];
            }
        }

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

            // Editable content fields
            'suggestedTitle' => $record->suggestedTitle,
            'suggestedTitleAi' => $record->suggestedTitleAi,
            'titleConfidence' => $record->titleConfidence,
            'suggestedTitleEditedBy' => $record->suggestedTitleEditedBy,
            'altText' => $record->altText,
            'altTextAi' => $record->altTextAi,
            'altTextConfidence' => $record->altTextConfidence,
            'altTextEditedBy' => $record->altTextEditedBy,
            'longDescription' => $record->longDescription,
            'longDescriptionAi' => $record->longDescriptionAi,
            'longDescriptionConfidence' => $record->longDescriptionConfidence,
            'longDescriptionEditedBy' => $record->longDescriptionEditedBy,
            'extractedText' => $record->extractedText,
            'extractedTextAi' => $record->extractedTextAi,
            'extractedTextEditedBy' => $record->extractedTextEditedBy,

            // Tags and colors
            'tags' => array_map(fn($t) => [
                'tag' => $t->tag,
                'confidence' => $t->confidence,
                'isAi' => (bool)$t->isAi,
            ], $tags),
            'colors' => array_map(fn($c) => [
                'hex' => $c->hex,
                'percentage' => $c->percentage,
                'isAi' => (bool)$c->isAi,
            ], $colors),

            // Focal point
            'focalPointX' => $record->focalPointX,
            'focalPointY' => $record->focalPointY,
            'focalPointXAi' => $record->focalPointXAi,
            'focalPointYAi' => $record->focalPointYAi,
            'focalPointConfidence' => $record->focalPointConfidence,
            'focalPointEditedBy' => $record->focalPointEditedBy,

            // Detection fields (editable in review area)
            'faceCount' => $record->faceCount,
            'faceCountAi' => $record->faceCountAi,
            'faceCountEditedBy' => $record->faceCountEditedBy,
            'faceCountEditedAt' => $record->faceCountEditedAt,
            'faceCountEditedByName' => $record->faceCountEditedBy ? Craft::$app->getUsers()->getUserById($record->faceCountEditedBy)?->friendlyName : null,
            'faceCountEditedAtFormatted' => $record->faceCountEditedAt ? DateTimeHelper::toDateTime($record->faceCountEditedAt)?->format('M j, Y') : null,
            'containsPeople' => (bool)$record->containsPeople,
            'containsPeopleAi' => (bool)$record->containsPeopleAi,
            'containsPeopleEditedBy' => $record->containsPeopleEditedBy,
            'containsPeopleEditedAt' => $record->containsPeopleEditedAt,
            'containsPeopleEditedByName' => $record->containsPeopleEditedBy ? Craft::$app->getUsers()->getUserById($record->containsPeopleEditedBy)?->friendlyName : null,
            'containsPeopleEditedAtFormatted' => $record->containsPeopleEditedAt ? DateTimeHelper::toDateTime($record->containsPeopleEditedAt)?->format('M j, Y') : null,
            'nsfwScore' => $record->nsfwScore,
            'nsfwCategories' => $record->nsfwCategories,
            'isFlaggedNsfw' => (bool)$record->isFlaggedNsfw,
            'hasWatermark' => (bool)$record->hasWatermark,
            'watermarkType' => $record->watermarkType,
            'watermarkConfidence' => $record->watermarkConfidence,
            'watermarkDetails' => $record->watermarkDetails,
            'containsBrandLogo' => (bool)$record->containsBrandLogo,
            'detectedBrands' => $record->detectedBrands,

            // Quality scores
            'sharpnessScore' => $record->sharpnessScore,
            'exposureScore' => $record->exposureScore,
            'noiseScore' => $record->noiseScore,
            'overallQualityScore' => $record->overallQualityScore,

            // EXIF, duplicates
            'exif' => $exifData,
            'similarImages' => $similarImages,
        ];
    }

    /**
     * Auto-apply title, alt text, and focal point to the Craft asset after approval.
     *
     * Only applies fields that haven't been manually set on the asset.
     */
    private function autoApplyAfterApproval(AssetAnalysisRecord $record): void
    {
        $asset = Asset::find()->id($record->assetId)->one();

        if ($asset === null) {
            return;
        }

        Plugin::getInstance()->assetAnalysis->autoApplyFromRecord($asset, $record);
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
