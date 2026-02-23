<?php

declare(strict_types=1);

namespace vitordiniz22\craftlens\records;

use craft\db\ActiveRecord;
use craft\records\Asset;
use vitordiniz22\craftlens\migrations\Install;
use yii\db\ActiveQueryInterface;

/**
 * EXIF Metadata record.
 *
 * Stores EXIF metadata extracted from images including camera settings,
 * date taken, and GPS coordinates.
 *
 * @property int $id
 * @property int $analysisId
 * @property int $assetId
 * @property string|null $cameraMake Camera manufacturer
 * @property string|null $cameraModel Camera model
 * @property string|null $lens Lens model
 * @property string|null $focalLength Focal length (e.g., "50mm")
 * @property string|null $aperture Aperture (e.g., "f/2.8")
 * @property string|null $shutterSpeed Shutter speed (e.g., "1/125")
 * @property int|null $iso ISO sensitivity
 * @property string|null $exposureMode Exposure mode (Auto, Manual, etc.)
 * @property string|null $dateTaken EXIF DateTimeOriginal (stored as Y-m-d H:i:s)
 * @property int|null $orientation EXIF orientation (1-8)
 * @property int|null $width Original image width
 * @property int|null $height Original image height
 * @property float|null $latitude GPS latitude
 * @property float|null $longitude GPS longitude
 * @property float|null $altitude GPS altitude in meters
 * @property array|null $rawExif Full EXIF data for debugging
 * @property \DateTime $dateCreated
 * @property \DateTime $dateUpdated
 * @property string $uid
 * @property-read AssetAnalysisRecord $analysis
 * @property-read Asset $asset
 */
class ExifMetadataRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return Install::TABLE_EXIF_METADATA;
    }

    /**
     * Returns the parent analysis record.
     */
    public function getAnalysis(): ActiveQueryInterface
    {
        return $this->hasOne(AssetAnalysisRecord::class, ['id' => 'analysisId']);
    }

    /**
     * Returns the related asset.
     */
    public function getAsset(): ActiveQueryInterface
    {
        return $this->hasOne(Asset::class, ['id' => 'assetId']);
    }

    public function rules(): array
    {
        return [
            [['analysisId', 'assetId'], 'required'],
            [['analysisId', 'assetId', 'iso', 'orientation', 'width', 'height'], 'integer'],
            [['latitude', 'longitude', 'altitude'], 'number'],
            [['cameraMake', 'cameraModel'], 'string', 'max' => 100],
            [['lens'], 'string', 'max' => 200],
            [['focalLength', 'aperture', 'shutterSpeed'], 'string', 'max' => 20],
            [['exposureMode'], 'string', 'max' => 30],
            [['dateTaken'], 'safe'],
            [['rawExif'], 'safe'],
        ];
    }

    /**
     * Check if GPS coordinates are available.
     */
    public function hasGpsCoordinates(): bool
    {
        return $this->latitude !== null && $this->longitude !== null;
    }

    /**
     * Check if camera information is available.
     */
    public function hasCamera(): bool
    {
        return $this->cameraMake !== null || $this->cameraModel !== null;
    }

    /**
     * Get a formatted camera display string.
     *
     * Example: "Canon EOS R5 • 50mm • f/2.8 • 1/125s • ISO 400"
     */
    public function getCameraDisplayString(): string
    {
        $parts = [];

        if ($this->cameraModel !== null) {
            $parts[] = $this->cameraModel;
        } elseif ($this->cameraMake !== null) {
            $parts[] = $this->cameraMake;
        }

        if ($this->focalLength !== null) {
            $parts[] = $this->focalLength;
        }

        if ($this->aperture !== null) {
            $parts[] = $this->aperture;
        }

        if ($this->shutterSpeed !== null) {
            $parts[] = $this->shutterSpeed;
        }

        if ($this->iso !== null) {
            $parts[] = 'ISO ' . $this->iso;
        }

        return implode(' • ', $parts);
    }

    /**
     * Get GPS coordinates as a formatted string.
     *
     * Example: "40.7128, -74.0060"
     */
    public function getGpsDisplayString(): ?string
    {
        if (!$this->hasGpsCoordinates()) {
            return null;
        }

        return sprintf('%.6f, %.6f', $this->latitude, $this->longitude);
    }
}
