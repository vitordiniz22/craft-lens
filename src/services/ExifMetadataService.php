<?php

declare(strict_types=1);

namespace vitordiniz22\craftlens\services;

use craft\elements\Asset;
use vitordiniz22\craftlens\enums\LogCategory;
use vitordiniz22\craftlens\helpers\Logger;
use vitordiniz22\craftlens\Plugin;
use vitordiniz22\craftlens\records\AssetAnalysisRecord;
use vitordiniz22\craftlens\records\ExifMetadataRecord;
use yii\base\Component;

/**
 * Service for managing EXIF metadata extraction and storage.
 *
 * Orchestrates the extraction process and database persistence.
 */
class ExifMetadataService extends Component
{
    /**
     * Process an asset and extract/store its EXIF metadata.
     */
    public function processAsset(Asset $asset, AssetAnalysisRecord $record): ?ExifMetadataRecord
    {
        $exifExtraction = Plugin::getInstance()->exifExtraction;

        // Check if the asset supports EXIF
        if (!$exifExtraction->hasExifSupport($asset)) {
            return null;
        }

        // Extract EXIF data
        $exifData = $exifExtraction->extractFromAsset($asset);

        if ($exifData === null || !$exifData->hasAnyData()) {
            return null;
        }

        // Check if we already have EXIF data for this asset
        $existingRecord = $this->getExifMetadataByAnalysisId($record->id);

        if ($existingRecord !== null) {
            // Update existing record
            return $this->updateRecord($existingRecord, $exifData);
        }

        // Create new record
        return $this->createRecord($asset, $record, $exifData);
    }

    /**
     * Get EXIF metadata for an asset by asset ID.
     */
    public function getExifMetadata(int $assetId): ?ExifMetadataRecord
    {
        return ExifMetadataRecord::findOne(['assetId' => $assetId]);
    }

    /**
     * Get EXIF metadata by analysis ID.
     */
    public function getExifMetadataByAnalysisId(int $analysisId): ?ExifMetadataRecord
    {
        return ExifMetadataRecord::findOne(['analysisId' => $analysisId]);
    }

    /**
     * Check if an asset has GPS coordinates.
     */
    public function hasGpsCoordinates(int $assetId): bool
    {
        $record = $this->getExifMetadata($assetId);

        return $record !== null && $record->hasGpsCoordinates();
    }

    /**
     * Check if an asset has any EXIF data.
     */
    public function hasExifData(int $assetId): bool
    {
        return $this->getExifMetadata($assetId) !== null;
    }

    /**
     * Delete EXIF metadata for an asset.
     */
    public function deleteForAsset(int $assetId): void
    {
        ExifMetadataRecord::deleteAll(['assetId' => $assetId]);
    }

    /**
     * Delete EXIF metadata by analysis ID.
     */
    public function deleteForAnalysis(int $analysisId): void
    {
        ExifMetadataRecord::deleteAll(['analysisId' => $analysisId]);
    }

    /**
     * Create a new EXIF metadata record.
     */
    private function createRecord(
        Asset $asset,
        AssetAnalysisRecord $analysisRecord,
        \vitordiniz22\craftlens\dto\ExifData $exifData
    ): ?ExifMetadataRecord {
        $record = new ExifMetadataRecord();
        $record->analysisId = $analysisRecord->id;
        $record->assetId = $asset->id;

        $this->populateRecord($record, $exifData);

        if (!$record->save()) {
            Logger::error(
                LogCategory::AssetProcessing,
                "Failed to save EXIF metadata for asset {$asset->id}: " . implode(', ', $record->getErrorSummary(true)),
                $asset->id,
            );

            return null;
        }

        return $record;
    }

    /**
     * Update an existing EXIF metadata record.
     */
    private function updateRecord(
        ExifMetadataRecord $record,
        \vitordiniz22\craftlens\dto\ExifData $exifData
    ): ?ExifMetadataRecord {
        $this->populateRecord($record, $exifData);

        if (!$record->save()) {
            Logger::error(
                LogCategory::AssetProcessing,
                "Failed to update EXIF metadata for analysis {$record->analysisId}: " . implode(', ', $record->getErrorSummary(true)),
            );

            return null;
        }

        return $record;
    }

    /**
     * Populate a record with EXIF data.
     */
    private function populateRecord(
        ExifMetadataRecord $record,
        \vitordiniz22\craftlens\dto\ExifData $exifData
    ): void {
        $record->cameraMake = $exifData->cameraMake;
        $record->cameraModel = $exifData->cameraModel;
        $record->lens = $exifData->lens;
        $record->focalLength = $exifData->focalLength;
        $record->aperture = $exifData->aperture;
        $record->shutterSpeed = $exifData->shutterSpeed;
        $record->iso = $exifData->iso;
        $record->exposureMode = $exifData->exposureMode;
        $record->dateTaken = $exifData->dateTaken?->format('Y-m-d H:i:s');
        $record->orientation = $exifData->orientation;
        $record->width = $exifData->width;
        $record->height = $exifData->height;
        $record->latitude = $exifData->latitude;
        $record->longitude = $exifData->longitude;
        $record->altitude = $exifData->altitude;

        // Store raw EXIF data, but filter out potentially large binary data
        $record->rawExif = $this->filterRawExif($exifData->rawExif);
    }

    /**
     * Filter raw EXIF data to remove large binary content.
     */
    private function filterRawExif(array $rawExif): array
    {
        $filtered = [];

        // Keys that might contain large binary data
        $excludeKeys = [
            'MakerNote',
            'UserComment',
            'THUMBNAIL',
            'Thumbnail',
            'thumbnail',
        ];

        foreach ($rawExif as $section => $data) {
            if (in_array($section, $excludeKeys, true)) {
                continue;
            }

            if (is_array($data)) {
                $filtered[$section] = [];
                foreach ($data as $key => $value) {
                    if (in_array($key, $excludeKeys, true)) {
                        continue;
                    }

                    // Skip binary data (starts with non-printable characters)
                    if (is_string($value) && strlen($value) > 1000) {
                        continue;
                    }

                    $filtered[$section][$key] = $value;
                }
            } else {
                $filtered[$section] = $data;
            }
        }

        return $filtered;
    }
}
