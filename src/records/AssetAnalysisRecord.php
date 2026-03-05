<?php

declare(strict_types=1);

namespace vitordiniz22\craftlens\records;

use craft\db\ActiveRecord;
use craft\records\Asset;
use vitordiniz22\craftlens\enums\AnalysisStatus;
use vitordiniz22\craftlens\enums\WatermarkType;
use vitordiniz22\craftlens\migrations\Install;
use yii\db\ActiveQueryInterface;

/**
 * Asset Analysis record.
 *
 * Stores analysis metadata for assets. Each editable field has a dual-column pattern:
 * - The main column (e.g. `altText`) holds the effective/user-facing value
 * - The `*Ai` column (e.g. `altTextAi`) holds the raw AI-generated value
 * - `*EditedBy` and `*EditedAt` track user edits (null = not edited)
 *
 * Golden rule: never override fields where `*EditedBy` is set.
 *
 * @property int $id
 * @property int $assetId
 * @property string $status
 * @property string|null $provider
 * @property string|null $providerModel
 *
 * Alt text (editable):
 * @property string|null $altText
 * @property string|null $altTextAi
 * @property float|null $altTextConfidence
 * @property int|null $altTextEditedBy
 * @property \DateTime|null $altTextEditedAt
 *
 * Suggested title (editable):
 * @property string|null $suggestedTitle
 * @property string|null $suggestedTitleAi
 * @property float|null $titleConfidence
 * @property int|null $suggestedTitleEditedBy
 * @property \DateTime|null $suggestedTitleEditedAt
 *
 * Long description (editable, moved from analysis_content):
 * @property string|null $longDescription
 * @property string|null $longDescriptionAi
 * @property float|null $longDescriptionConfidence
 * @property int|null $longDescriptionEditedBy
 * @property \DateTime|null $longDescriptionEditedAt
 *
 * Face detection (editable):
 * @property int $faceCount
 * @property int|null $faceCountAi
 * @property bool $containsPeople
 * @property bool|null $containsPeopleAi
 * @property float|null $containsPeopleConfidence
 * @property int|null $faceCountEditedBy
 * @property \DateTime|null $faceCountEditedAt
 * @property int|null $containsPeopleEditedBy
 * @property \DateTime|null $containsPeopleEditedAt
 *
 * NSFW detection (editable):
 * @property float|null $nsfwScore
 * @property float|null $nsfwScoreAi
 * @property array|null $nsfwCategories
 * @property bool $isFlaggedNsfw
 * @property int|null $nsfwScoreEditedBy
 * @property \DateTime|null $nsfwScoreEditedAt
 *
 * Watermark detection (editable):
 * @property bool $hasWatermark
 * @property bool|null $hasWatermarkAi
 * @property float|null $watermarkConfidence
 * @property string|null $watermarkType
 * @property array|null $watermarkDetails
 * @property int|null $hasWatermarkEditedBy
 * @property \DateTime|null $hasWatermarkEditedAt
 *
 * Brand detection (editable):
 * @property bool $containsBrandLogo
 * @property bool|null $containsBrandLogoAi
 * @property float|null $containsBrandLogoConfidence
 * @property array|null $detectedBrands
 * @property int|null $containsBrandLogoEditedBy
 * @property \DateTime|null $containsBrandLogoEditedAt
 *
 * Image quality scores:
 * @property float|null $sharpnessScore
 * @property float|null $exposureScore
 * @property float|null $noiseScore
 * @property float|null $overallQualityScore
 *
 * Focal point detection (editable):
 * @property float|null $focalPointX
 * @property float|null $focalPointXAi
 * @property float|null $focalPointY
 * @property float|null $focalPointYAi
 * @property float|null $focalPointConfidence
 * @property int|null $focalPointEditedBy
 * @property \DateTime|null $focalPointEditedAt
 *
 * Extracted text from image (editable):
 * @property string|null $extractedText
 * @property string|null $extractedTextAi
 * @property int|null $extractedTextEditedBy
 * @property \DateTime|null $extractedTextEditedAt
 *
 * Hashes for duplicate detection:
 * @property string|null $perceptualHash
 * @property string|null $fileContentHash
 *
 * Content table flags (for lazy loading):
 * @property bool $hasAnalysisContent
 * @property bool $hasExifMetadata
 *
 * Token usage and cost:
 * @property int|null $inputTokens
 * @property int|null $outputTokens
 * @property float|null $actualCost
 *
 * Timestamps:
 * @property \DateTime|null $processedAt
 * @property \DateTime $dateCreated
 * @property \DateTime $dateUpdated
 * @property string $uid
 *
 * Relations:
 * @property-read Asset $asset
 * @property-read AnalysisContentRecord|null $analysisContent
 * @property-read ExifMetadataRecord|null $exifMetadata
 */
class AssetAnalysisRecord extends ActiveRecord
{
    public const ALT_TEXT_MAX_LENGTH = 500;
    public const SUGGESTED_TITLE_MAX_LENGTH = 255;
    public const LONG_DESCRIPTION_MAX_LENGTH = 5000;
    public const EXTRACTED_TEXT_MAX_LENGTH = 50000;

    /**
     * List of editable field names and their corresponding EditedBy/EditedAt column prefixes.
     * Fields sharing edit tracking (e.g. focalPointX/Y) map to the same prefix.
     */
    public const EDITABLE_FIELDS = [
        'altText' => 'altText',
        'suggestedTitle' => 'suggestedTitle',
        'longDescription' => 'longDescription',
        'faceCount' => 'faceCount',
        'containsPeople' => 'containsPeople',
        'nsfwScore' => 'nsfwScore',
        'hasWatermark' => 'hasWatermark',
        'containsBrandLogo' => 'containsBrandLogo',
        'focalPointX' => 'focalPoint',
        'focalPointY' => 'focalPoint',
        'extractedText' => 'extractedText',
    ];

    public static function tableName(): string
    {
        return Install::TABLE_ASSET_ANALYSES;
    }

    /**
     * Check if a given field has been edited by a user.
     */
    public function isFieldEdited(string $fieldName): bool
    {
        $prefix = self::EDITABLE_FIELDS[$fieldName] ?? $fieldName;
        $editedByColumn = $prefix . 'EditedBy';

        return $this->$editedByColumn !== null;
    }

    public function getAsset(): ActiveQueryInterface
    {
        return $this->hasOne(Asset::class, ['id' => 'assetId']);
    }

    /**
     * Returns the related analysis content record (heavy AI data).
     */
    public function getAnalysisContent(): ActiveQueryInterface
    {
        return $this->hasOne(AnalysisContentRecord::class, ['analysisId' => 'id']);
    }

    /**
     * Returns the related EXIF metadata record.
     */
    public function getExifMetadata(): ActiveQueryInterface
    {
        return $this->hasOne(ExifMetadataRecord::class, ['analysisId' => 'id']);
    }

    public function rules(): array
    {
        return [
            [['assetId'], 'required'],
            [['status'], 'in', 'range' => array_column(AnalysisStatus::cases(), 'value')],
            [['provider', 'providerModel'], 'string', 'max' => 50],
            [['altText', 'altTextAi'], 'string', 'max' => self::ALT_TEXT_MAX_LENGTH],
            [['suggestedTitle', 'suggestedTitleAi'], 'string', 'max' => self::SUGGESTED_TITLE_MAX_LENGTH],
            [['longDescription', 'longDescriptionAi'], 'string', 'max' => self::LONG_DESCRIPTION_MAX_LENGTH],
            [['altTextConfidence', 'titleConfidence', 'longDescriptionConfidence', 'containsPeopleConfidence', 'containsBrandLogoConfidence', 'nsfwScore', 'nsfwScoreAi', 'watermarkConfidence', 'sharpnessScore', 'exposureScore', 'noiseScore', 'overallQualityScore', 'focalPointX', 'focalPointXAi', 'focalPointY', 'focalPointYAi', 'focalPointConfidence'], 'number', 'min' => 0, 'max' => 1],
            [['faceCount'], 'integer', 'min' => 0],
            [['faceCountAi'], 'integer', 'min' => 0],
            [['containsPeople', 'containsPeopleAi', 'isFlaggedNsfw', 'hasWatermark', 'hasWatermarkAi', 'containsBrandLogo', 'containsBrandLogoAi'], 'boolean'],
            [['watermarkType'], 'string', 'max' => 30],
            [['watermarkType'], 'in', 'range' => array_column(WatermarkType::cases(), 'value')],
            [['extractedText', 'extractedTextAi'], 'string', 'max' => self::EXTRACTED_TEXT_MAX_LENGTH],
            [['hasAnalysisContent', 'hasExifMetadata'], 'boolean'],
            [['perceptualHash', 'fileContentHash'], 'string', 'max' => 64],
            [['inputTokens', 'outputTokens'], 'integer', 'min' => 0],
            [['actualCost'], 'number', 'min' => 0],
            [['altTextEditedBy', 'suggestedTitleEditedBy', 'longDescriptionEditedBy', 'faceCountEditedBy', 'containsPeopleEditedBy', 'nsfwScoreEditedBy', 'hasWatermarkEditedBy', 'containsBrandLogoEditedBy', 'focalPointEditedBy', 'extractedTextEditedBy'], 'integer'],
            [['nsfwCategories', 'watermarkDetails', 'detectedBrands'], function(string $attribute): void {
                if ($this->$attribute !== null && !is_array($this->$attribute)) {
                    $this->addError($attribute, "{$attribute} must be an array or null.");
                }
            }],
        ];
    }

    /**
     * Check if this is a stock photo watermark.
     */
    public function isStockWatermark(): bool
    {
        return $this->hasWatermark && $this->watermarkType === WatermarkType::Stock->value;
    }

    /**
     * Get the stock provider name if detected.
     */
    public function getStockProvider(): ?string
    {
        if (!$this->isStockWatermark() || !is_array($this->watermarkDetails)) {
            return null;
        }

        return $this->watermarkDetails['stockProvider'] ?? null;
    }
}
