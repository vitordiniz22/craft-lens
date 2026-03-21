<?php

declare(strict_types=1);

namespace vitordiniz22\craftlens\services;

use craft\helpers\DateTimeHelper;
use craft\helpers\StringHelper;
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
    // Analysis Content (errorMessage)
    // -------------------------------------------------------------------------

    /**
     * Get analysis content for an analysis record.
     */
    public function getAnalysisContent(int $analysisId): ?AnalysisContentRecord
    {
        return AnalysisContentRecord::findOne(['analysisId' => $analysisId]);
    }

    /**
     * Save analysis content from an AnalysisResult.
     */
    public function saveAnalysisContent(
        AssetAnalysisRecord $analysisRecord,
        ?string $errorMessage = null,
    ): AnalysisContentRecord {
        $record = $this->getOrCreateContentRecord($analysisRecord->id);

        $record->errorMessage = $errorMessage;
        $record->dateUpdated = DateTimeHelper::now();

        if (!$record->save()) {
            Logger::error(LogCategory::AssetProcessing, 'Failed to save analysis content', assetId: $analysisRecord->assetId, context: [
                'errors' => $record->getErrorSummary(true),
            ]);

            return $record;
        }

        return $record;
    }

    /**
     * Save error message to analysis content.
     */
    public function saveErrorMessage(AssetAnalysisRecord $analysisRecord, string $errorMessage): AnalysisContentRecord
    {
        $record = $this->getOrCreateContentRecord($analysisRecord->id);

        $record->errorMessage = $errorMessage;
        $record->dateUpdated = DateTimeHelper::now();

        if (!$record->save()) {
            Logger::error(LogCategory::AssetProcessing, 'Failed to save error message', assetId: $analysisRecord->assetId, context: [
                'errors' => $record->getErrorSummary(true),
            ]);

            return $record;
        }

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

    // -------------------------------------------------------------------------
    // Private Helpers
    // -------------------------------------------------------------------------

    /**
     * Find existing content record or create a new one for the given analysis.
     */
    private function getOrCreateContentRecord(int $analysisId): AnalysisContentRecord
    {
        $record = $this->getAnalysisContent($analysisId);

        if ($record === null) {
            $record = new AnalysisContentRecord();
            $record->analysisId = $analysisId;
            $record->uid = StringHelper::UUID();
            $record->dateCreated = DateTimeHelper::now();
        }

        return $record;
    }
}
