<?php

declare(strict_types=1);

namespace vitordiniz22\craftlens\services;

use Craft;
use craft\elements\Asset;
use craft\helpers\DateTimeHelper;
use craft\helpers\ElementHelper;
use craft\helpers\Queue;
use vitordiniz22\craftlens\dto\AnalysisResult;
use vitordiniz22\craftlens\enums\AiProvider;
use vitordiniz22\craftlens\enums\AnalysisStatus;
use vitordiniz22\craftlens\enums\LogCategory;
use vitordiniz22\craftlens\enums\WatermarkType;
use vitordiniz22\craftlens\exceptions\AnalysisException;
use vitordiniz22\craftlens\exceptions\ConfigurationException;
use vitordiniz22\craftlens\helpers\AssetTitleHelper;
use vitordiniz22\craftlens\helpers\Logger;
use vitordiniz22\craftlens\helpers\MultisiteHelper;
use vitordiniz22\craftlens\helpers\PerceptualHashHelper;
use vitordiniz22\craftlens\jobs\AnalyzeAssetJob;
use vitordiniz22\craftlens\jobs\BulkAnalyzeAssetsJob;
use vitordiniz22\craftlens\models\Settings;
use vitordiniz22\craftlens\Plugin;
use vitordiniz22\craftlens\records\AssetAnalysisRecord;
use vitordiniz22\craftlens\records\AssetColorRecord;
use vitordiniz22\craftlens\records\AssetTagRecord;
use vitordiniz22\craftlens\records\DuplicateGroupRecord;
use yii\base\Component;

/**
 * Service for managing asset analysis workflow.
 */
class AssetAnalysisService extends Component
{
    /**
     * Queue an asset for analysis.
     */
    public function queueAsset(Asset $asset): void
    {
        if (!$this->shouldProcess($asset)) {
            return;
        }

        $this->createPendingRecord($asset);

        Queue::push(new AnalyzeAssetJob([
            'assetId' => $asset->id,
        ]));

        Logger::info(LogCategory::JobStarted, 'Asset queued for analysis', assetId: $asset->id);
    }

    /**
     * Queue multiple assets for analysis.
     *
     * @param int[] $assetIds
     */
    public function queueAssets(array $assetIds, ?int $volumeId = null): void
    {
        Queue::push(new BulkAnalyzeAssetsJob([
            'assetIds' => $assetIds,
            'volumeId' => $volumeId,
        ]));

        Logger::info(LogCategory::JobStarted, 'Bulk analysis queued', context: ['assetCount' => count($assetIds), 'volumeId' => $volumeId]);
    }

    /**
     * Process an asset immediately (called by queue job).
     */
    public function processAsset(Asset $asset): AssetAnalysisRecord
    {
        $record = $this->getOrCreateRecord($asset);
        $record->status = AnalysisStatus::Processing->value;
        $record->save();

        try {
            $this->processImageAsset($asset, $record);
        } catch (ConfigurationException $e) {
            $record->status = AnalysisStatus::Failed->value;
            $record->processedAt = DateTimeHelper::now();
            $record->save();

            $this->getContentStorage()->saveErrorMessage($record, $e->getMessage());

            Logger::error(
                LogCategory::Configuration,
                "Configuration error: {$e->getMessage()}",
                $asset->id,
                $e
            );

            throw $e;
        } catch (AnalysisException $e) {
            $record->status = AnalysisStatus::Failed->value;
            $record->processedAt = DateTimeHelper::now();
            $record->save();

            $this->getContentStorage()->saveErrorMessage($record, $e->getUserMessage());

            Logger::error(
                LogCategory::AssetProcessing,
                "Analysis failed: {$e->getMessage()}",
                $asset->id,
                $e,
                [
                    'provider' => $e->provider,
                    'statusCode' => $e->statusCode,
                ]
            );
        } catch (\Throwable $e) {
            $record->status = AnalysisStatus::Failed->value;
            $record->processedAt = DateTimeHelper::now();
            $record->save();

            $userMessage = "Analysis failed due to an unexpected error. Please try again later or contact support.";
            $this->getContentStorage()->saveErrorMessage($record, $userMessage);

            Logger::error(
                LogCategory::AssetProcessing,
                "Unexpected analysis error: {$e->getMessage()}",
                $asset->id,
                $e
            );
        }

        return $record;
    }

    /**
     * Get analysis record for an asset.
     */
    public function getAnalysis(int $assetId): ?AssetAnalysisRecord
    {
        return AssetAnalysisRecord::findOne(['assetId' => $assetId]);
    }

    /**
     * Get analysis records for multiple assets in a single query.
     *
     * @param int[] $assetIds
     * @return array<int, AssetAnalysisRecord> Map of assetId => AssetAnalysisRecord
     */
    public function getAnalysesByAssetIds(array $assetIds): array
    {
        if (empty($assetIds)) {
            return [];
        }

        $records = AssetAnalysisRecord::find()
            ->where(['assetId' => $assetIds])
            ->all();

        $map = [];

        foreach ($records as $record) {
            $map[$record->assetId] = $record;
        }

        return $map;
    }

    /**
     * Check if an asset should be processed.
     */
    public function shouldProcess(Asset $asset): bool
    {
        if ($asset->kind !== Asset::KIND_IMAGE) {
            return false;
        }

        if (!$this->isVolumeEnabled($asset->volumeId)) {
            return false;
        }

        $existing = $this->getAnalysis($asset->id);

        if ($existing !== null && in_array($existing->status, AnalysisStatus::shouldNotReprocessValues(), true)) {
            return false;
        }

        return true;
    }

    /**
     * Check if an asset should be auto-processed on upload.
     */
    public function shouldAutoProcessOnUpload(Asset $asset, bool $isNew): bool
    {
        if (!$isNew) {
            return false;
        }

        if ($asset->kind !== Asset::KIND_IMAGE) {
            return false;
        }

        if (!$this->getSettings()->autoProcessOnUpload) {
            return false;
        }

        if (ElementHelper::isDraftOrRevision($asset)) {
            return false;
        }

        return $this->shouldProcess($asset);
    }

    /**
     * Force reprocess an asset.
     */
    public function reprocessAsset(Asset $asset): void
    {
        $record = $this->getAnalysis($asset->id);

        if ($record !== null) {
            if ($record->status === AnalysisStatus::Pending->value && $record->dateUpdated) {
                $dateUpdated = $record->dateUpdated instanceof \DateTime
                    ? $record->dateUpdated
                    : new \DateTime($record->dateUpdated);

                $minutesStuck = (time() - $dateUpdated->getTimestamp()) / 60;

                if ($minutesStuck > 10) {
                    Logger::warning(
                        LogCategory::AssetProcessing,
                        "Asset {$asset->id} was stuck in pending status for " . round($minutesStuck) . " minutes - resetting",
                        $asset->id
                    );
                }
            }

            $record->status = AnalysisStatus::Pending->value;
            $record->save();
        }

        Queue::push(new AnalyzeAssetJob([
            'assetId' => $asset->id,
        ]));
    }

    /**
     * Queue an asset for reprocessing due to file content change.
     */
    public function queueReprocessForFileChange(Asset $asset): void
    {
        if (!$this->shouldProcessForReplace($asset)) {
            return;
        }

        $existingRecord = $this->getAnalysis($asset->id);
        $newHash = $this->computeFileContentHash($asset);

        // Skip if content hasn't actually changed
        if ($existingRecord?->fileContentHash === $newHash) {
            Logger::info(
                LogCategory::AssetProcessing,
                "Skipping reprocess for asset {$asset->id}: file content unchanged",
                $asset->id,
            );
            return;
        }

        $this->reprocessAsset($asset);

        Logger::info(
            LogCategory::AssetProcessing,
            "Queued asset {$asset->id} for reprocessing due to file replacement",
            $asset->id,
        );
    }

    /**
     * Compute SHA-256 hash of asset file content.
     */
    public function computeFileContentHash(Asset $asset): string
    {
        $tempPath = $asset->getCopyOfFile();

        try {
            return hash_file('sha256', $tempPath);
        } finally {
            if (file_exists($tempPath)) {
                unlink($tempPath);
            }
        }
    }

    /**
     * Check if an asset should be processed on file replace.
     */
    public function shouldProcessForReplace(Asset $asset): bool
    {
        if ($asset->kind !== Asset::KIND_IMAGE) {
            return false;
        }

        if (!$this->isVolumeEnabled($asset->volumeId)) {
            return false;
        }

        return true;
    }

    /**
     * Get count of unprocessed assets.
     */
    public function getUnprocessedCount(): int
    {
        $processedSubQuery = AssetAnalysisRecord::find()
            ->select('assetId')
            ->where(['in', 'status', AnalysisStatus::shouldNotReprocessValues()]);

        return (int) Asset::find()
            ->kind(Asset::KIND_IMAGE)
            ->andWhere(['not in', 'elements.id', $processedSubQuery])
            ->count();
    }

    /**
     * Delete analysis record for an asset.
     */
    public function deleteAnalysis(int $assetId): void
    {
        $record = $this->getAnalysis($assetId);
        $hadAnalysis = $record !== null;
        $previousStatus = $record?->status;

        // Delete content from content tables first (FK cascade should handle this,
        // but explicit deletion ensures cleanup even if cascades fail)
        if ($record !== null) {
            Plugin::getInstance()->siteContent->deleteAllForAnalysis($record->id);
            $this->getContentStorage()->deleteAllContent($record->id);
        }

        Plugin::getInstance()->searchIndex->deleteIndex($assetId);
        AssetAnalysisRecord::deleteAll(['assetId' => $assetId]);

        if ($hadAnalysis) {
            Logger::info(
                LogCategory::AssetProcessing,
                "Deleted analysis for asset {$assetId} (previous status: {$previousStatus})",
                $assetId,
            );
        }
    }

    private function processImageAsset(Asset $asset, AssetAnalysisRecord $record): void
    {
        // Extract EXIF metadata first (fast, local, no API cost)
        $this->extractExifMetadata($asset, $record);

        // Determine language context for AI call
        $primaryLanguage = MultisiteHelper::getPrimarySiteLanguage();
        $additionalLanguages = MultisiteHelper::getAdditionalLanguages($asset);
        $sites = MultisiteHelper::getSitesNeedingContent($asset);

        // AI call is external HTTP — keep outside transaction to avoid long-held locks
        $result = Plugin::getInstance()->aiProvider->analyzeAsset($asset, $primaryLanguage, $additionalLanguages);
        $settings = $this->getSettings();
        $providerModel = $this->getProviderModel();
        $contentStorage = $this->getContentStorage();
        $fileContentHash = $this->computeFileContentHash($asset);

        // Wrap all DB writes in a transaction so a mid-way failure doesn't leave partial data
        $transaction = Craft::$app->getDb()->beginTransaction();

        try {
            // Apply AI results with dual-write pattern (respects user edits)
            $record->status = $this->determinePostAnalysisStatus();
            $record->provider = $settings->aiProvider;
            $record->providerModel = $providerModel;
            $record->inputTokens = $result->inputTokens;
            $record->outputTokens = $result->outputTokens;
            $record->actualCost = $this->calculateActualCost($result, $settings, $providerModel);
            $this->applyAiResultToRecord($record, $result);
            $record->processedAt = DateTimeHelper::now();
            $record->fileContentHash = $fileContentHash;
            $record->save();

            $contentStorage->saveAnalysisContent($record, $result);

            // Long description to main record (with dual-write)
            $this->applyAiLongDescription($record, $result);

            // Extracted text to main record (with dual-write)
            $this->applyAiExtractedText($record, $result);

            // Sync tags and colors to indexed tables (respects user-added items)
            $this->syncTagsFromAiResult($record, $result->tags);
            $this->syncColorsFromAiResult($record, $result->dominantColors);

            // Save per-site content (translated alt text and title for non-primary sites)
            if (!empty($sites) && !empty($result->siteContent)) {
                $volumeId = $asset->getVolume()->id;
                Plugin::getInstance()->siteContent->saveFromAnalysisResult(
                    $record,
                    $sites,
                    $result,
                    altTranslatable: MultisiteHelper::isAltTranslatable($volumeId),
                    titleTranslatable: MultisiteHelper::isTitleTranslatable($volumeId),
                );
            }

            $this->computePerceptualHash($asset, $record);
            $this->findDuplicatesForAsset($asset, $record);

            $transaction->commit();
        } catch (\Throwable $e) {
            $transaction->rollBack();
            Logger::warning(LogCategory::AssetProcessing, 'Transaction rolled back during analysis', assetId: $asset->id, exception: $e);
            throw $e;
        }

        Logger::info(LogCategory::AssetProcessing, 'Analysis completed', assetId:

        $asset->id, context: array_merge(
            ['provider' => $settings->aiProvider, 'model' => $providerModel, 'status' => $record->status, 'cost' => $record->actualCost],
            $result->toLogContext(),
        ));

        try {
            Plugin::getInstance()->searchIndex->indexAsset($record);
        } catch (\Throwable $e) {
            Logger::warning(LogCategory::AssetProcessing, 'Search index update failed (non-fatal): ' . $e->getMessage(), assetId: $asset->id);
        }

        if ($record->status !== AnalysisStatus::PendingReview->value) {
            $this->autoApplyToAsset($asset, $record, $result);
        }
    }

    /**
     * Extract EXIF metadata from an image asset.
     */
    private function extractExifMetadata(Asset $asset, AssetAnalysisRecord $record): void
    {
        $exifRecord = Plugin::getInstance()->exifMetadata->processAsset($asset, $record);

        if ($exifRecord !== null) {
            $record->hasExifMetadata = true;
            $record->save();
        }
    }

    private function createPendingRecord(Asset $asset): AssetAnalysisRecord
    {
        $record = $this->getAnalysis($asset->id);

        if ($record !== null) {
            if (!in_array($record->status, [
                AnalysisStatus::Processing->value,
                AnalysisStatus::Completed->value,
                AnalysisStatus::Approved->value,
            ], true)) {
                $record->status = AnalysisStatus::Pending->value;
                $record->save();
            }
            return $record;
        }

        // Create new record
        $record = new AssetAnalysisRecord();
        $record->assetId = $asset->id;
        $record->status = AnalysisStatus::Pending->value;
        $record->save();

        return $record;
    }

    private function getOrCreateRecord(Asset $asset): AssetAnalysisRecord
    {
        $record = $this->getAnalysis($asset->id);

        if ($record === null) {
            $record = new AssetAnalysisRecord();
            $record->assetId = $asset->id;
        }

        return $record;
    }

    private function determinePostAnalysisStatus(): string
    {
        $settings = $this->getSettings();

        return $settings->requireReviewBeforeApply
            ? AnalysisStatus::PendingReview->value
            : AnalysisStatus::Completed->value;
    }

    private function isVolumeEnabled(int $volumeId): bool
    {
        $settings = $this->getSettings();
        $enabledVolumes = $settings->enabledVolumes;

        if ($enabledVolumes === ['*'] || in_array('*', $enabledVolumes, true)) {
            return true;
        }

        $volume = Craft::$app->getVolumes()->getVolumeById($volumeId);

        if ($volume === null) {
            return false;
        }

        return in_array($volume->uid, $enabledVolumes, true);
    }

    private function computePerceptualHash(Asset $asset, AssetAnalysisRecord $record): void
    {
        if ($asset->kind !== Asset::KIND_IMAGE) {
            return;
        }

        $tempPath = $asset->getCopyOfFile();

        try {
            $newHash = PerceptualHashHelper::compute($tempPath);

            // If hash changed, clear existing duplicate group records
            // They will be regenerated on next duplicate scan
            if ($record->perceptualHash !== null && $record->perceptualHash !== $newHash) {
                DuplicateGroupRecord::deleteAll([
                    'or',
                    ['canonicalAssetId' => $asset->id],
                    ['duplicateAssetId' => $asset->id],
                ]);
            }

            $record->perceptualHash = $newHash;
            $record->save();
        } finally {
            if (file_exists($tempPath)) {
                unlink($tempPath);
            }
        }
    }

    private function findDuplicatesForAsset(Asset $asset, AssetAnalysisRecord $record): void
    {
        if (empty($record->perceptualHash)) {
            return;
        }

        Plugin::getInstance()->duplicateDetection->findDuplicatesForAsset($asset->id);
    }

    /**
     * Auto-apply analysis fields to the asset's native fields from the record.
     *
     * Used after review approval when the AnalysisResult DTO is not available.
     * Reads from the record's current values (which may include user edits).
     *
     * Title: only applied if the current title is auto-generated from filename.
     * Focal point: applied if no focal point is currently set, OR if a reviewer
     *   explicitly edited it during review (focalPointEditedBy is set).
     * Alt text: only applied if the asset's native alt field is empty.
     */
    public function autoApplyFromRecord(Asset $asset, AssetAnalysisRecord $record): void
    {
        $needsSave = false;

        if (!empty($record->suggestedTitle) && AssetTitleHelper::isAutoGenerated($asset)) {
            $asset->title = $record->suggestedTitle;
            $needsSave = true;
        }

        $reviewerEditedFocalPoint = $record->focalPointEditedBy !== null;

        if ($record->focalPointX !== null && $record->focalPointY !== null
            && (!$asset->getHasFocalPoint() || $reviewerEditedFocalPoint)) {
            $asset->setFocalPoint([
                'x' => $record->focalPointX,
                'y' => $record->focalPointY,
            ]);
            $needsSave = true;
        }

        if (!empty($record->altText) && empty($asset->alt)) {
            $asset->alt = $record->altText;
            $needsSave = true;
        }

        if ($needsSave) {
            if (!Craft::$app->getElements()->saveElement($asset)) {
                Logger::warning(LogCategory::AssetProcessing, 'Failed to auto-apply fields after approval', assetId: $asset->id);
            }
        }

        // Auto-apply per-site alt text and title for non-primary sites
        $this->autoApplyPerSiteContent($asset, $record);
    }

    /**
     * Auto-apply AI title and focal point to the asset's native fields.
     *
     * Title: only applied if the current title is auto-generated from filename.
     * Focal point: only applied if no focal point is currently set.
     */
    private function autoApplyToAsset(Asset $asset, AssetAnalysisRecord $record, AnalysisResult $result): void
    {
        $needsSave = false;

        // Auto-apply title if current title is filename-generated
        if (!empty($result->suggestedTitle) && AssetTitleHelper::isAutoGenerated($asset)) {
            $asset->title = $result->suggestedTitle;
            $needsSave = true;
        }

        // Auto-apply focal point if none is set
        if ($result->focalPointX !== null && $result->focalPointY !== null && !$asset->getHasFocalPoint()) {
            $asset->setFocalPoint([
                'x' => $result->focalPointX,
                'y' => $result->focalPointY,
            ]);
            $needsSave = true;
        }

        // Auto-apply alt text to Craft's native alt field if empty
        if (!empty($result->altText) && empty($asset->alt)) {
            $asset->alt = $result->altText;
            $needsSave = true;
        }

        if ($needsSave) {
            if (!Craft::$app->getElements()->saveElement($asset)) {
                Logger::warning(LogCategory::AssetProcessing, 'Failed to auto-apply fields to asset', assetId: $asset->id);
            }
        }

        // Auto-apply per-site alt text and title for non-primary sites
        $this->autoApplyPerSiteContent($asset, $record);
    }

    /**
     * Auto-apply per-site alt text and title to non-primary site assets.
     *
     * Only writes fields that are actually translatable on the volume.
     * Non-translatable fields are handled by Craft's propagation.
     */
    private function autoApplyPerSiteContent(Asset $asset, AssetAnalysisRecord $record): void
    {
        $sites = MultisiteHelper::getSitesNeedingContent($asset);

        if (empty($sites)) {
            return;
        }

        $volumeId = $asset->getVolume()->id;
        $altTranslatable = MultisiteHelper::isAltTranslatable($volumeId);
        $titleTranslatable = MultisiteHelper::isTitleTranslatable($volumeId);
        $siteContentService = Plugin::getInstance()->siteContent;

        foreach ($sites as $siteInfo) {
            $siteAsset = Asset::find()
                ->id($asset->id)
                ->siteId($siteInfo['siteId'])
                ->status(null)
                ->one();

            if ($siteAsset === null) {
                continue;
            }

            $needsSave = false;

            // Apply per-site alt text only if alt is translatable and empty
            if ($altTranslatable && empty($siteAsset->alt)) {
                $altText = $siteContentService->resolveAltText($record, $siteInfo['siteId']);
                if (!empty($altText)) {
                    $siteAsset->alt = $altText;
                    $needsSave = true;
                }
            }

            // Apply per-site title only if title is translatable and auto-generated
            if ($titleTranslatable && AssetTitleHelper::isAutoGenerated($siteAsset)) {
                $title = $siteContentService->resolveSuggestedTitle($record, $siteInfo['siteId']);
                if (!empty($title)) {
                    $siteAsset->title = $title;
                    $needsSave = true;
                }
            }

            if ($needsSave) {
                if (!Craft::$app->getElements()->saveElement($siteAsset)) {
                    Logger::warning(
                        LogCategory::AssetProcessing,
                        "Failed to auto-apply per-site content for site {$siteInfo['siteId']}",
                        assetId: $asset->id,
                    );
                }
            }
        }
    }

    private function getSettings(): Settings
    {
        return Plugin::getInstance()->getSettings();
    }

    private function getContentStorage(): ContentStorageService
    {
        return Plugin::getInstance()->contentStorage;
    }

    private function getProviderModel(): string
    {
        $settings = $this->getSettings();

        return match ($settings->getAiProviderEnum()) {
            AiProvider::OpenAi => $settings->openaiModel,
            AiProvider::Gemini => $settings->geminiModel,
            AiProvider::Claude => $settings->claudeModel,
        };
    }

    private function calculateActualCost(AnalysisResult $result, Settings $settings, string $providerModel): float
    {
        $pricing = Plugin::getInstance()->pricing;

        return match ($settings->getAiProviderEnum()) {
            AiProvider::OpenAi => $pricing->calculateOpenAiCost(
                $providerModel,
                $result->inputTokens,
                $result->outputTokens
            ),
            AiProvider::Gemini => $pricing->calculateGeminiCost(
                $providerModel,
                $result->inputTokens,
                $result->outputTokens
            ),
            AiProvider::Claude => $pricing->calculateClaudeCost(
                $providerModel,
                $result->inputTokens,
                $result->outputTokens
            ),
        };
    }

    /**
     * Apply AI analysis result to the record using the dual-write pattern.
     *
     * Always writes to *Ai columns. Only writes to main columns if the field
     * has not been edited by a user (*EditedBy is null).
     */
    private function applyAiResultToRecord(AssetAnalysisRecord $record, AnalysisResult $result): void
    {
        // Alt text
        $record->altTextAi = $result->altText;
        $record->altTextConfidence = $result->altTextConfidence;
        if (!$record->isFieldEdited('altText')) {
            $record->altText = $result->altText;
        }

        // Suggested title
        $record->suggestedTitleAi = $result->suggestedTitle;
        $record->titleConfidence = $result->titleConfidence;
        if (!$record->isFieldEdited('suggestedTitle')) {
            $record->suggestedTitle = $result->suggestedTitle;
        }

        // Face detection
        $record->faceCountAi = $result->faceCount;
        if (!$record->isFieldEdited('faceCount')) {
            $record->faceCount = $result->faceCount;
        }
        $record->containsPeopleAi = $result->containsPeople;
        if (!$record->isFieldEdited('containsPeople')) {
            $record->containsPeople = $result->containsPeople;
        }

        // NSFW
        $record->nsfwScoreAi = $result->nsfwScore;
        $record->nsfwCategories = $result->nsfwCategories;
        $record->isFlaggedNsfw = $result->isFlaggedNsfw;
        if (!$record->isFieldEdited('nsfwScore')) {
            $record->nsfwScore = $result->nsfwScore;
        }

        // Watermark
        $record->hasWatermarkAi = $result->hasWatermark;
        $record->watermarkConfidence = $result->watermarkConfidence;
        $record->watermarkType = $result->watermarkType;
        $record->watermarkDetails = $result->watermarkDetails;
        if (!$record->isFieldEdited('hasWatermark')) {
            $record->hasWatermark = $result->hasWatermark;
        }

        // Brand detection
        $record->containsBrandLogoAi = $result->containsBrandLogo;
        $record->detectedBrands = $result->detectedBrands;
        if (!$record->isFieldEdited('containsBrandLogo')) {
            $record->containsBrandLogo = $result->containsBrandLogo;
        }

        // Quality scores (not editable, always overwrite)
        $record->sharpnessScore = $result->sharpnessScore;
        $record->exposureScore = $result->exposureScore;
        $record->noiseScore = $result->noiseScore;
        $record->overallQualityScore = $result->overallQualityScore;

        // Focal point
        $record->focalPointXAi = $result->focalPointX;
        $record->focalPointYAi = $result->focalPointY;
        $record->focalPointConfidence = $result->focalPointConfidence;
        if (!$record->isFieldEdited('focalPointX')) {
            $record->focalPointX = $result->focalPointX;
            $record->focalPointY = $result->focalPointY;
        }
    }

    /**
     * Apply AI long description to the main record with dual-write pattern.
     */
    private function applyAiLongDescription(AssetAnalysisRecord $record, AnalysisResult $result): void
    {
        $record->longDescriptionAi = $result->longDescription;
        $record->longDescriptionConfidence = $result->longDescriptionConfidence;
        if (!$record->isFieldEdited('longDescription')) {
            $record->longDescription = $result->longDescription;
        }
        $record->save(false, ['longDescription', 'longDescriptionAi', 'longDescriptionConfidence', 'dateUpdated']);
    }

    /**
     * Apply AI-generated extracted text to the main record using the dual-write pattern.
     */
    private function applyAiExtractedText(AssetAnalysisRecord $record, AnalysisResult $result): void
    {
        if ($result->extractedText === null || trim($result->extractedText) === '') {
            return;
        }

        $record->extractedTextAi = $result->extractedText;
        if (!$record->isFieldEdited('extractedText')) {
            $record->extractedText = $result->extractedText;
        }
        $record->save(false, ['extractedText', 'extractedTextAi', 'dateUpdated']);
    }

    /**
     * Sync AI-generated tags to the indexed tags table.
     *
     * Deletes existing AI tags (isAi=true) and inserts new ones.
     * User-added tags (isAi=false) are preserved.
     *
     * @param array<array{tag: string, confidence: float}> $tags
     */
    public function syncTagsFromAiResult(AssetAnalysisRecord $record, array $tags, bool $mergeWithExisting = false): void
    {
        if (!$mergeWithExisting) {
            // Delete only AI-generated tags; preserve user-added ones
            AssetTagRecord::deleteAll(['analysisId' => $record->id, 'isAi' => true]);
        }

        if (empty($tags)) {
            return;
        }

        // Get existing normalized tags to avoid duplicates
        $existingNormalized = AssetTagRecord::find()
            ->select(['tagNormalized'])
            ->where(['analysisId' => $record->id])
            ->column();
        $existingSet = array_flip($existingNormalized);

        foreach ($tags as $tagData) {
            $tagName = $tagData['tag'] ?? null;

            if ($tagName === null || trim($tagName) === '') {
                continue;
            }

            $normalized = mb_strtolower(trim($tagName));
            if (isset($existingSet[$normalized])) {
                continue;
            }

            $tagRecord = new AssetTagRecord();
            $tagRecord->assetId = $record->assetId;
            $tagRecord->analysisId = $record->id;
            $tagRecord->tag = trim($tagName);
            $tagRecord->tagNormalized = $normalized;
            $tagRecord->confidence = $tagData['confidence'] ?? null;
            $tagRecord->isAi = true;
            $tagRecord->save(false);

            $existingSet[$normalized] = true;
        }
    }

    /**
     * Sync AI-generated colors to the indexed colors table.
     *
     * Deletes existing AI colors (isAi=true) and inserts new ones.
     * User-added colors (isAi=false) are preserved.
     *
     * @param array<array{hex: string, percentage: float}> $colors
     */
    public function syncColorsFromAiResult(AssetAnalysisRecord $record, array $colors): void
    {
        // Delete only AI-generated colors; preserve user-added ones
        AssetColorRecord::deleteAll(['analysisId' => $record->id, 'isAi' => true]);

        if (empty($colors)) {
            return;
        }

        foreach ($colors as $colorData) {
            $hex = $colorData['hex'] ?? null;

            if ($hex === null || trim($hex) === '') {
                continue;
            }

            $colorRecord = new AssetColorRecord();
            $colorRecord->assetId = $record->assetId;
            $colorRecord->analysisId = $record->id;
            $colorRecord->hex = $hex;
            $colorRecord->percentage = $colorData['percentage'] ?? null;
            $colorRecord->isAi = true;
            $colorRecord->save(false);
        }
    }

    /**
     * Reset all failed analyses to pending and queue them for retry.
     *
     * @return int Number of records reset
     */
    public function retryAllFailed(): int
    {
        $failedCount = (int) AssetAnalysisRecord::find()
            ->where(['status' => AnalysisStatus::Failed->value])
            ->count();

        if ($failedCount === 0) {
            return 0;
        }

        $transaction = Craft::$app->getDb()->beginTransaction();

        try {
            AssetAnalysisRecord::updateAll(
                ['status' => AnalysisStatus::Pending->value],
                ['status' => AnalysisStatus::Failed->value]
            );

            Craft::$app->getQueue()->push(new BulkAnalyzeAssetsJob([
                'reprocess' => true,
            ]));

            Logger::info(LogCategory::JobStarted, 'Retry failed analyses queued', context: ['failedCount' => $failedCount]);

            $transaction->commit();
        } catch (\Throwable $e) {
            $transaction->rollBack();
            throw $e;
        }

        return $failedCount;
    }

    /**
     * Reset records stuck in pending status beyond the given threshold.
     *
     * @return array<array{assetId: int, minutesStuck: int}> Info about reset records
     */
    public function resetStuckPending(int $minutes = 10): array
    {
        $cutoffDate = date('Y-m-d H:i:s', time() - ($minutes * 60));

        $stuckRecords = AssetAnalysisRecord::find()
            ->where(['status' => AnalysisStatus::Pending->value])
            ->andWhere(['<', 'dateUpdated', $cutoffDate])
            ->all();

        if (empty($stuckRecords)) {
            return [];
        }

        $resetInfo = [];
        $assetIds = [];

        foreach ($stuckRecords as $record) {
            $dateUpdated = $record->dateUpdated instanceof \DateTime
                ? $record->dateUpdated
                : new \DateTime($record->dateUpdated);

            $minutesStuck = (int) round((time() - $dateUpdated->getTimestamp()) / 60);

            Logger::warning(
                LogCategory::AssetProcessing,
                "Resetting stuck pending record for asset {$record->assetId} (stuck for {$minutesStuck} minutes)",
                $record->assetId
            );

            $assetIds[] = $record->assetId;
            $resetInfo[] = [
                'assetId' => $record->assetId,
                'minutesStuck' => $minutesStuck,
            ];
        }

        AssetAnalysisRecord::updateAll(
            ['status' => AnalysisStatus::Failed->value],
            ['assetId' => $assetIds, 'status' => AnalysisStatus::Pending->value]
        );

        return $resetInfo;
    }

    /**
     * Reset records stuck in processing status beyond the given threshold.
     *
     * @return array<array{assetId: int, minutesStuck: int}> Info about reset records
     */
    public function resetStuckProcessing(int $minutes = 30): array
    {
        $cutoffDate = date('Y-m-d H:i:s', time() - ($minutes * 60));

        $stuckRecords = AssetAnalysisRecord::find()
            ->where(['status' => AnalysisStatus::Processing->value])
            ->andWhere(['<', 'dateUpdated', $cutoffDate])
            ->all();

        if (empty($stuckRecords)) {
            return [];
        }

        $resetInfo = [];
        $assetIds = [];

        foreach ($stuckRecords as $record) {
            $dateUpdated = $record->dateUpdated instanceof \DateTime
                ? $record->dateUpdated
                : new \DateTime($record->dateUpdated);

            $minutesStuck = (int) round((time() - $dateUpdated->getTimestamp()) / 60);

            Logger::warning(
                LogCategory::AssetProcessing,
                "Resetting stuck processing record for asset {$record->assetId} (stuck for {$minutesStuck} minutes)",
                $record->assetId
            );

            $assetIds[] = $record->assetId;
            $resetInfo[] = [
                'assetId' => $record->assetId,
                'minutesStuck' => $minutesStuck,
            ];
        }

        AssetAnalysisRecord::updateAll(
            ['status' => AnalysisStatus::Failed->value],
            ['assetId' => $assetIds, 'status' => AnalysisStatus::Processing->value]
        );

        return $resetInfo;
    }

    /**
     * Get all distinct stock providers detected in the library.
     *
     * @return array<string, string> provider => Provider Label
     */
    public function getDetectedStockProviders(): array
    {
        $records = AssetAnalysisRecord::find()
            ->select(['watermarkDetails'])
            ->where(['not', ['watermarkDetails' => null]])
            ->andWhere(['watermarkType' => WatermarkType::Stock->value])
            ->asArray()
            ->all();

        $providers = [];
        foreach ($records as $record) {
            $details = is_string($record['watermarkDetails'])
                ? json_decode($record['watermarkDetails'], true)
                : $record['watermarkDetails'];

            $provider = $details['stockProvider'] ?? null;

            if ($provider !== null && trim($provider) !== '' && !isset($providers[$provider])) {
                $providers[$provider] = ucwords($provider);
            }
        }

        ksort($providers);

        return $providers;
    }
}
