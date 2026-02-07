<?php

declare(strict_types=1);

namespace vitordiniz22\craftlens\services;

use craft\helpers\DateTimeHelper;
use craft\helpers\StringHelper;
use vitordiniz22\craftlens\dto\AnalysisResult;
use vitordiniz22\craftlens\enums\LogCategory;
use vitordiniz22\craftlens\helpers\Logger;
use vitordiniz22\craftlens\records\AnalysisContentRecord;
use vitordiniz22\craftlens\records\AssetAnalysisRecord;
use yii\base\Component;

/**
 * Content Storage Service.
 *
 * Centralized service for reading and writing to content tables.
 * This service abstracts the content table operations and ensures
 * proper flag management on the main analysis record.
 */
class ContentStorageService extends Component
{
    // -------------------------------------------------------------------------
    // Analysis Content (rawResponse, longDescription, customPromptResult, errorMessage)
    // -------------------------------------------------------------------------

    /**
     * Get analysis content for an analysis record.
     */
    public function getAnalysisContent(int $analysisId): ?AnalysisContentRecord
    {
        return AnalysisContentRecord::findOne(['analysisId' => $analysisId]);
    }

    /**
     * Get analysis content by asset ID.
     */
    public function getAnalysisContentByAssetId(int $assetId): ?AnalysisContentRecord
    {
        $analysis = AssetAnalysisRecord::findOne(['assetId' => $assetId]);
        if ($analysis === null) {
            return null;
        }

        return $this->getAnalysisContent($analysis->id);
    }

    /**
     * Save analysis content from an AnalysisResult.
     */
    public function saveAnalysisContent(
        AssetAnalysisRecord $analysisRecord,
        AnalysisResult $result,
        ?string $errorMessage = null
    ): AnalysisContentRecord {
        $record = $this->getAnalysisContent($analysisRecord->id);

        if ($record === null) {
            $record = new AnalysisContentRecord();
            $record->analysisId = $analysisRecord->id;
            $record->uid = StringHelper::UUID();
            $record->dateCreated = DateTimeHelper::now();
        }

        $record->rawResponse = $result->rawResponse;
        $record->customPromptResult = $result->customPromptResult;
        $record->errorMessage = $errorMessage;
        $record->dateUpdated = DateTimeHelper::now();

        if (!$record->save()) {
            Logger::error(LogCategory::AssetProcessing, 'Failed to save analysis content', assetId: $analysisRecord->assetId, context: [
                'errors' => $record->getErrorSummary(true),
            ]);
        }

        // Update flag on main record
        $analysisRecord->hasAnalysisContent = true;
        $analysisRecord->save(false, ['hasAnalysisContent', 'dateUpdated']);

        return $record;
    }

    /**
     * Save error message to analysis content.
     */
    public function saveErrorMessage(AssetAnalysisRecord $analysisRecord, string $errorMessage): AnalysisContentRecord
    {
        $record = $this->getAnalysisContent($analysisRecord->id);

        if ($record === null) {
            $record = new AnalysisContentRecord();
            $record->analysisId = $analysisRecord->id;
            $record->uid = StringHelper::UUID();
            $record->dateCreated = DateTimeHelper::now();
        }

        $record->errorMessage = $errorMessage;
        $record->dateUpdated = DateTimeHelper::now();

        if (!$record->save()) {
            Logger::error(LogCategory::AssetProcessing, 'Failed to save error message', assetId: $analysisRecord->assetId, context: [
                'errors' => $record->getErrorSummary(true),
            ]);
        }

        // Update flag on main record
        $analysisRecord->hasAnalysisContent = true;
        $analysisRecord->save(false, ['hasAnalysisContent', 'dateUpdated']);

        Logger::warning(LogCategory::AssetProcessing, 'Error message stored for analysis', assetId: $analysisRecord->assetId, context: ['errorMessage' => $errorMessage]);

        return $record;
    }

    // -------------------------------------------------------------------------
    // Bulk Operations
    // -------------------------------------------------------------------------

    /**
     * Delete all content for an analysis record.
     * Called when deleting an analysis.
     */
    public function deleteAllContent(int $analysisId): void
    {
        AnalysisContentRecord::deleteAll(['analysisId' => $analysisId]);
    }

    /**
     * Get all content records for an analysis.
     *
     * @return array{
     *     analysis: AnalysisContentRecord|null,
     * }
     */
    public function getAllContent(int $analysisId): array
    {
        return [
            'analysis' => $this->getAnalysisContent($analysisId),
        ];
    }

    // -------------------------------------------------------------------------
    // Convenience Methods
    // -------------------------------------------------------------------------

    /**
     * Get the long description for an analysis.
     * Long description now lives on the main analysis record.
     */
    public function getLongDescription(int $analysisId): ?string
    {
        $record = AssetAnalysisRecord::findOne($analysisId);

        return $record?->longDescription;
    }
}
