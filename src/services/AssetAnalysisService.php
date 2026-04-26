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
use vitordiniz22\craftlens\enums\ErrorCode;
use vitordiniz22\craftlens\enums\LogCategory;
use vitordiniz22\craftlens\enums\WatermarkType;
use vitordiniz22\craftlens\exceptions\AnalysisCancelledException;
use vitordiniz22\craftlens\exceptions\AnalysisException;
use vitordiniz22\craftlens\exceptions\ConfigurationException;
use vitordiniz22\craftlens\helpers\AssetTitleHelper;
use vitordiniz22\craftlens\helpers\DuplicateSupport;
use vitordiniz22\craftlens\helpers\ImageMetricsAnalyzer;
use vitordiniz22\craftlens\helpers\Logger;
use vitordiniz22\craftlens\helpers\MultisiteHelper;
use vitordiniz22\craftlens\helpers\PerceptualHashHelper;
use vitordiniz22\craftlens\jobs\AnalyzeAssetJob;
use vitordiniz22\craftlens\jobs\BulkAnalyzeAssetsJob;
use vitordiniz22\craftlens\models\Settings;
use vitordiniz22\craftlens\Plugin;
use vitordiniz22\craftlens\records\AssetAnalysisRecord;
use vitordiniz22\craftlens\records\AssetTagRecord;
use vitordiniz22\craftlens\records\DuplicateGroupRecord;
use yii\base\Component;
use yii\db\IntegrityException;

/**
 * Service for managing asset analysis workflow.
 */
class AssetAnalysisService extends Component
{
    private const STUCK_THRESHOLD_MINUTES = 10;

    /**
     * Queue an asset for analysis.
     */
    public function queueAsset(Asset $asset): void
    {
        if (!$this->shouldProcess($asset)) {
            return;
        }

        $record = $this->createPendingRecord($asset);

        $jobId = Queue::push(new AnalyzeAssetJob([
            'assetId' => $asset->id,
        ]));

        if ($jobId !== null) {
            $record->queueJobId = (string) $jobId;
            $record->save();
        }

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
        $previousStatus = $record->status;
        $hadExistingData = $previousStatus === AnalysisStatus::Completed->value;
        $record->status = AnalysisStatus::Processing->value;

        if (!$record->save()) {
            throw new \RuntimeException(
                "Failed to save analysis record for asset {$asset->id}: " . implode(', ', $record->getErrorSummary(true))
            );
        }

        try {
            $this->processImageAsset($asset, $record);
        } catch (ConfigurationException $e) {
            $record->status = AnalysisStatus::Failed->value;
            $record->processedAt = DateTimeHelper::now();

            if ($record->id && AssetAnalysisRecord::find()->where(['id' => $record->id])->exists() && $record->save()) {
                $this->getContentStorage()->saveErrorMessage($record, $e->getMessage(), $e->errorCode ?? ErrorCode::Unknown);
            }

            Logger::error(
                LogCategory::Configuration,
                "Configuration error: {$e->getMessage()}",
                $asset->id,
                $e
            );

            throw $e;
        } catch (AnalysisCancelledException $e) {
            Logger::info(
                LogCategory::Cancellation,
                "Analysis cancelled for asset {$asset->id}",
                assetId: $asset->id,
            );
        } catch (AnalysisException $e) {
            $this->handleAnalysisFailure($record, $previousStatus, $hadExistingData, $e->getUserMessage(), $e->errorCode ?? ErrorCode::Unknown);

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
            $userMessage = "Analysis failed due to an unexpected error. Please try again later or contact support.";
            $this->handleAnalysisFailure($record, $previousStatus, $hadExistingData, $userMessage, ErrorCode::Unknown);

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
    /**
     * Handle analysis failure, preserving previous status when existing data is available.
     */
    private function handleAnalysisFailure(
        AssetAnalysisRecord $record,
        ?string $previousStatus,
        bool $hadExistingData,
        string $errorMessage,
        ?ErrorCode $errorCode = null,
    ): void {
        if ($record->id && !AssetAnalysisRecord::find()->where(['id' => $record->id])->exists()) {
            Logger::warning(LogCategory::AssetProcessing, 'Analysis record deleted during processing, skipping failure handling', assetId: $record->assetId);

            return;
        }

        if ($hadExistingData) {
            $record->status = $previousStatus;
        } else {
            $record->status = AnalysisStatus::Failed->value;
        }

        $record->processedAt = DateTimeHelper::now();

        if (!$record->save()) {
            Logger::error(LogCategory::AssetProcessing, 'Failed to save analysis failure status', assetId: $record->assetId, context: [
                'errors' => $record->getErrorSummary(true),
            ]);
            return;
        }

        $this->getContentStorage()->saveErrorMessage($record, $errorMessage, $errorCode ?? ErrorCode::Unknown);
    }

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

        return AssetAnalysisRecord::find()
            ->where(['assetId' => $assetIds])
            ->indexBy('assetId')
            ->all();
    }

    /**
     * Check if an asset should be processed.
     */
    public function shouldProcess(Asset $asset): bool
    {
        if ($asset->kind !== Asset::KIND_IMAGE) {
            return false;
        }

        if (!$this->getSettings()->isVolumeEnabled($asset->volumeId)) {
            return false;
        }

        $existing = $this->getAnalysis($asset->id);

        if ($existing !== null && !in_array($existing->status, AnalysisStatus::unprocessedStatuses(), true)) {
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

        if (!Plugin::getInstance()->setupStatus->isAiProviderConfigured()) {
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

        if ($record === null) {
            $record = new AssetAnalysisRecord();
            $record->assetId = $asset->id;
            $record->status = AnalysisStatus::Pending->value;
            $record->save();
        } else {
            if ($record->status === AnalysisStatus::Pending->value && $record->dateUpdated) {
                $dateUpdated = $record->dateUpdated instanceof \DateTime
                    ? $record->dateUpdated
                    : new \DateTime($record->dateUpdated);

                $minutesStuck = (time() - $dateUpdated->getTimestamp()) / 60;

                if ($minutesStuck > self::STUCK_THRESHOLD_MINUTES) {
                    Logger::warning(
                        LogCategory::AssetProcessing,
                        "Asset {$asset->id} was stuck in pending status for " . round($minutesStuck) . " minutes - resetting",
                        $asset->id
                    );
                }
            }

            $record->previousStatus = $record->status;
            $record->status = AnalysisStatus::Pending->value;
            $record->save();
        }

        $jobId = Queue::push(new AnalyzeAssetJob([
            'assetId' => $asset->id,
        ]));

        if ($jobId !== null) {
            $record->queueJobId = (string) $jobId;
            $record->save();
        }
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

        if (!$this->getSettings()->isVolumeEnabled($asset->volumeId)) {
            return false;
        }

        return true;
    }

    /**
     * Get count of unprocessed assets: images with no analysis record, or with
     * a status in AnalysisStatus::unprocessedStatuses().
     */
    public function getUnprocessedCount(): int
    {
        $handledSubQuery = AssetAnalysisRecord::find()
            ->select('assetId')
            ->where(['not in', 'status', AnalysisStatus::unprocessedStatuses()]);

        return (int) Asset::find()
            ->kind(Asset::KIND_IMAGE)
            ->andWhere(['not in', 'elements.id', $handledSubQuery])
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
        // Determine language context for AI call
        $primaryLanguage = MultisiteHelper::getPrimarySiteLanguage();
        $additionalLanguages = MultisiteHelper::getAdditionalLanguages($asset);
        $sites = MultisiteHelper::getSitesNeedingContent($asset);

        // Local image quality analysis via Imagick (before AI to avoid holding temp file)
        $localMetrics = ImageMetricsAnalyzer::analyze($asset);

        // Checkpoint 1: abort before expensive AI call if cancelled
        Plugin::getInstance()->analysisCancellation->assertNotCancelled($asset->id);

        // AI call is external HTTP — keep outside transaction to avoid long-held locks
        $result = Plugin::getInstance()->aiProvider->analyzeAsset($asset, $primaryLanguage, $additionalLanguages);
        $settings = $this->getSettings();
        $providerModel = $this->getProviderModel();
        $contentStorage = $this->getContentStorage();
        $fileContentHash = $this->computeFileContentHash($asset);

        // Wrap all DB writes in a transaction so a mid-way failure doesn't leave partial data
        $transaction = Craft::$app->getDb()->beginTransaction();

        try {
            // Checkpoint 2: abort before committing results if cancelled
            Plugin::getInstance()->analysisCancellation->assertNotCancelled($asset->id);

            // Apply AI results with dual-write pattern (respects user edits)
            $record->status = AnalysisStatus::Completed->value;
            $record->previousStatus = null;
            $record->queueJobId = null;
            $record->provider = $settings->aiProvider;
            $record->providerModel = $providerModel;
            $record->inputTokens = $result->inputTokens;
            $record->outputTokens = $result->outputTokens;
            $record->actualCost = $this->calculateActualCost($result, $settings, $providerModel);
            $this->applyAiResultToRecord($record, $result);

            // Apply local Imagick metrics (overwrites AI quality scores with reliable local data)
            if ($localMetrics !== null) {
                $record->sharpnessScore = $localMetrics['raw']['sharpnessScore'];
                $record->exposureScore = $localMetrics['raw']['exposureScore'];
                $record->shadowClipRatio = $localMetrics['raw']['shadowClipRatio'];
                $record->highlightClipRatio = $localMetrics['raw']['highlightClipRatio'];
                $record->noiseScore = $localMetrics['raw']['contrastScore'];
                $record->compressionQuality = $localMetrics['raw']['compressionQuality'];
                $record->colorProfile = $localMetrics['raw']['colorProfile'];
            }

            $record->processedAt = DateTimeHelper::now();
            $record->fileContentHash = $fileContentHash;

            if (!$record->save()) {
                throw new \RuntimeException(
                    "Failed to save analysis results for asset {$record->assetId}: " . implode(', ', $record->getErrorSummary(true))
                );
            }

            $contentStorage->saveAnalysisContent($record);

            // Long description to main record (with dual-write)
            $this->applyAiLongDescription($record, $result);

            // Extracted text to main record (with dual-write)
            $this->applyAiExtractedText($record, $result);

            // Sync tags from AI to indexed table (respects user-added items)
            $this->syncTagsFromAiResult($record, $result->tags);

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
        } catch (AnalysisCancelledException $e) {
            $transaction->rollBack();
            throw $e;
        } catch (\Throwable $e) {
            $transaction->rollBack();
            Logger::warning(LogCategory::AssetProcessing, 'Transaction rolled back during analysis', assetId: $asset->id, exception: $e);
            throw $e;
        }

        Logger::info(LogCategory::AssetProcessing, 'Analysis completed', assetId: $asset->id, context: array_merge(
            ['provider' => $settings->aiProvider, 'model' => $providerModel, 'status' => $record->status, 'cost' => $record->actualCost],
            $result->toLogContext(),
        ));

        try {
            Plugin::getInstance()->searchIndex->indexAsset($record);
        } catch (\Throwable $e) {
            Logger::warning(LogCategory::AssetProcessing, 'Search index update failed (non-fatal): ' . $e->getMessage(), assetId: $asset->id);
        }

        // Auto-apply title, alt text and focal point when asset fields are empty/meaningless
        try {
            $this->autoApplyEmptyNativeFields($asset, $result, $record->id);
        } catch (\Throwable $e) {
            Logger::warning(LogCategory::AssetProcessing, 'Auto-apply empty fields failed (non-fatal): ' . $e->getMessage(), assetId: $asset->id);
        }
    }

    private function createPendingRecord(Asset $asset): AssetAnalysisRecord
    {
        $record = $this->getAnalysis($asset->id);

        if ($record !== null) {
            if (!in_array($record->status, [
                AnalysisStatus::Processing->value,
                AnalysisStatus::Completed->value,
            ], true)) {
                $record->status = AnalysisStatus::Pending->value;
                $record->save();
            }
            return $record;
        }

        try {
            $record = new AssetAnalysisRecord();
            $record->assetId = $asset->id;
            $record->status = AnalysisStatus::Pending->value;
            $record->save();
        } catch (IntegrityException) {
            $record = $this->getAnalysis($asset->id);

            if ($record === null) {
                throw new \RuntimeException("Failed to create or find analysis record for asset {$asset->id}");
            }
        }

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

private function computePerceptualHash(Asset $asset, AssetAnalysisRecord $record): void
    {
        if ($asset->kind !== Asset::KIND_IMAGE) {
            return;
        }

        if (!DuplicateSupport::isAvailable()) {
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
        if (empty($record->perceptualHash) || !Plugin::getInstance()->getIsPro()) {
            return;
        }

        Plugin::getInstance()->duplicateDetection->findDuplicatesForAsset($asset->id);
    }

    /**
     * Auto-apply AI suggestions to asset native fields when they are empty or meaningless.
     *
     * Called unconditionally after analysis — even when review is required,
     * having AI-generated values is better than nothing.
     *
     * Title: only applied if the current title is auto-generated from filename.
     * Alt text: only applied if the native alt field is empty.
     * Focal point: only applied if no focal point is currently set.
     */
    private function autoApplyEmptyNativeFields(Asset $asset, AnalysisResult $result, int $analysisId): void
    {
        $needsSave = false;

        if (!empty($result->suggestedTitle) && AssetTitleHelper::isAutoGenerated($asset)) {
            $asset->title = $result->suggestedTitle;
            $needsSave = true;
        }

        if ($result->focalPointX !== null && $result->focalPointY !== null && !$asset->getHasFocalPoint()) {
            $asset->setFocalPoint([
                'x' => $result->focalPointX,
                'y' => $result->focalPointY,
            ]);
            $needsSave = true;
        }

        if (!empty($result->altText) && empty($asset->alt)) {
            $asset->alt = $result->altText;
            $needsSave = true;
        }

        if ($needsSave) {
            if (!Craft::$app->getElements()->saveElement($asset)) {
                Logger::warning(LogCategory::AssetProcessing, 'Failed to auto-apply empty native fields', assetId: $asset->id);
            }
        }

        $this->autoApplyToSecondarySites(
            assetId: $asset->id,
            primaryTitle: $result->suggestedTitle ?? '',
            primaryAlt: $result->altText ?? '',
            analysisId: $analysisId,
        );
    }

    /**
     * Auto-apply AI-generated title and alt text to non-primary sites
     * where native fields are empty or auto-generated.
     *
     * For same-base-language sites: applies primary site values.
     * For different-language sites: applies translated values from site content records.
     */
    private function autoApplyToSecondarySites(
        int $assetId,
        string $primaryTitle,
        string $primaryAlt,
        int $analysisId,
        bool $forceTitle = false,
        bool $forceAlt = false,
    ): void {
        $primaryAsset = Asset::find()->id($assetId)->one();

        if ($primaryAsset === null) {
            return;
        }

        $secondarySites = MultisiteHelper::getAllNonPrimarySites($primaryAsset);

        if (empty($secondarySites)) {
            return;
        }

        $volume = $primaryAsset->getVolume();
        $titleTranslatable = MultisiteHelper::isTitleTranslatable($volume->id);
        $altTranslatable = MultisiteHelper::isAltTranslatable($volume->id);

        $siteContentRecords = Plugin::getInstance()->siteContent->getAllSiteContent($analysisId);

        foreach ($secondarySites as $siteInfo) {
            $siteId = $siteInfo['siteId'];

            $siteAsset = Asset::find()->id($assetId)->siteId($siteId)->one();

            if ($siteAsset === null) {
                continue;
            }

            if ($siteInfo['usesPrimaryContent']) {
                $titleValue = $primaryTitle;
                $altValue = $primaryAlt;
            } else {
                $siteRecord = $siteContentRecords[$siteId] ?? null;
                $titleValue = $siteRecord?->suggestedTitle ?? $primaryTitle;
                $altValue = $siteRecord?->altText ?? $primaryAlt;
            }

            $needsSave = false;

            if ($titleTranslatable) {
                $shouldApplyTitle = !empty($titleValue)
                    && $titleValue !== $siteAsset->title
                    && ($forceTitle || AssetTitleHelper::isAutoGenerated($siteAsset));

                if ($shouldApplyTitle) {
                    $siteAsset->title = $titleValue;
                    $needsSave = true;
                }
            }

            if ($altTranslatable) {
                $shouldApplyAlt = !empty($altValue)
                    && $altValue !== $siteAsset->alt
                    && ($forceAlt || empty($siteAsset->alt) || $siteAsset->alt === $primaryAlt);

                if ($shouldApplyAlt) {
                    $siteAsset->alt = $altValue;
                    $needsSave = true;
                }
            }

            if ($needsSave) {
                if (!Craft::$app->getElements()->saveElement($siteAsset)) {
                    Logger::warning(
                        LogCategory::AssetProcessing,
                        sprintf('Failed to auto-apply fields for site %d', $siteId),
                        assetId: $assetId,
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
        return $this->getSettings()->getCurrentModel();
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
     * has not been edited by a user (value matches AI value).
     *
     * Important: isFieldEdited() must be called BEFORE writing to *Ai columns,
     * since it compares the main column to the *Ai column.
     */
    private function applyAiResultToRecord(AssetAnalysisRecord $record, AnalysisResult $result): void
    {
        if ($result->altText !== '') {
            $wasEdited = $record->isFieldEdited('altText');
            $record->altTextAi = $result->altText;
            $record->altTextConfidence = $result->altTextConfidence;

            if (!$wasEdited) {
                $record->altText = $result->altText;
            }
        }

        if ($result->suggestedTitle !== '') {
            $wasEdited = $record->isFieldEdited('suggestedTitle');
            $record->suggestedTitleAi = $result->suggestedTitle;
            $record->titleConfidence = $result->titleConfidence;

            if (!$wasEdited) {
                $record->suggestedTitle = $result->suggestedTitle;
            }
        }

        $faceCountEdited = $record->isFieldEdited('faceCount');
        $containsPeopleEdited = $record->isFieldEdited('containsPeople');

        $record->faceCountAi = $result->faceCount;
        $record->containsPeopleAi = $result->containsPeople;
        $record->containsPeopleConfidence = $result->containsPeopleConfidence;

        if (!$faceCountEdited) {
            $record->faceCount = $result->faceCount;
        }

        if (!$containsPeopleEdited) {
            $record->containsPeople = $result->containsPeople;
        }

        $nsfwEdited = $record->isFieldEdited('nsfwScore');

        $record->nsfwScoreAi = $result->nsfwScore;
        $record->nsfwConfidence = $result->nsfwConfidence;
        $record->nsfwCategories = $result->nsfwCategories;

        if (!$nsfwEdited) {
            $record->nsfwScore = $result->nsfwScore;
        }

        $watermarkEdited = $record->isFieldEdited('hasWatermark');

        $record->hasWatermarkAi = $result->hasWatermark;
        $record->watermarkConfidence = $result->watermarkConfidence;
        $record->watermarkType = $result->watermarkType;
        $record->watermarkDetails = $result->watermarkDetails;

        if (!$watermarkEdited) {
            $record->hasWatermark = $result->hasWatermark;
        }

        $brandEdited = $record->isFieldEdited('containsBrandLogo');

        $record->containsBrandLogoAi = $result->containsBrandLogo;
        $record->containsBrandLogoConfidence = $result->containsBrandLogoConfidence;
        $record->detectedBrands = $result->detectedBrands;

        if (!$brandEdited) {
            $record->containsBrandLogo = $result->containsBrandLogo;
        }

        $focalPointEdited = $record->isFieldEdited('focalPointX');

        $record->focalPointXAi = $result->focalPointX;
        $record->focalPointYAi = $result->focalPointY;
        $record->focalPointConfidence = $result->focalPointConfidence;

        if (!$focalPointEdited) {
            $record->focalPointX = $result->focalPointX;
            $record->focalPointY = $result->focalPointY;
        }
    }

    /**
     * Apply AI long description to the main record with dual-write pattern.
     */
    private function applyAiLongDescription(AssetAnalysisRecord $record, AnalysisResult $result): void
    {
        $wasEdited = $record->isFieldEdited('longDescription');
        $record->longDescriptionAi = $result->longDescription;
        $record->longDescriptionConfidence = $result->longDescriptionConfidence;
        if (!$wasEdited) {
            $record->longDescription = $result->longDescription;
        }
        $record->save(false, ['longDescription', 'longDescriptionAi', 'longDescriptionConfidence', 'dateUpdated']);
    }

    /**
     * Apply AI-generated extracted text to the main record using the dual-write pattern.
     */
    private function applyAiExtractedText(AssetAnalysisRecord $record, AnalysisResult $result): void
    {
        $record->extractedTextAi = $result->extractedText;
        $record->save(false, ['extractedTextAi', 'dateUpdated']);
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

            if (!$tagRecord->save(false)) {
                Logger::warning(LogCategory::AssetProcessing, 'Failed to save AI tag record', assetId: $record->assetId, context: [
                    'tag' => $tagName,
                ]);
            }

            $existingSet[$normalized] = true;
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

            Queue::push(new BulkAnalyzeAssetsJob([
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
        return $this->resetStuckByStatus(AnalysisStatus::Pending, $minutes);
    }

    /**
     * Reset records stuck in processing status beyond the given threshold.
     *
     * @return array<array{assetId: int, minutesStuck: int}> Info about reset records
     */
    public function resetStuckProcessing(int $minutes = 30): array
    {
        return $this->resetStuckByStatus(AnalysisStatus::Processing, $minutes);
    }

    /**
     * @return array<array{assetId: int, minutesStuck: int}>
     */
    private function resetStuckByStatus(AnalysisStatus $status, int $minutes): array
    {
        $cutoffDate = date('Y-m-d H:i:s', time() - ($minutes * 60));
        $statusLabel = strtolower($status->value);

        $stuckRows = AssetAnalysisRecord::find()
            ->select(['id', 'assetId', 'dateUpdated'])
            ->where(['status' => $status->value])
            ->andWhere(['<', 'dateUpdated', $cutoffDate])
            ->limit(1000)
            ->asArray()
            ->all();

        if (empty($stuckRows)) {
            return [];
        }

        $resetInfo = [];
        $assetIds = [];

        foreach ($stuckRows as $row) {
            $dateUpdated = $row['dateUpdated'] instanceof \DateTime
                ? $row['dateUpdated']
                : new \DateTime($row['dateUpdated']);

            $minutesStuck = (int) round((time() - $dateUpdated->getTimestamp()) / 60);

            Logger::warning(
                LogCategory::AssetProcessing,
                "Resetting stuck {$statusLabel} record for asset {$row['assetId']} (stuck for {$minutesStuck} minutes)",
                (int) $row['assetId']
            );

            $assetIds[] = $row['assetId'];
            $resetInfo[] = [
                'assetId' => (int) $row['assetId'],
                'minutesStuck' => $minutesStuck,
            ];
        }

        AssetAnalysisRecord::updateAll(
            ['status' => AnalysisStatus::Failed->value],
            ['assetId' => $assetIds, 'status' => $status->value]
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
            ->limit(5000)
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
