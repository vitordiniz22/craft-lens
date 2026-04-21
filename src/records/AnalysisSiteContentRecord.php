<?php

declare(strict_types=1);

namespace vitordiniz22\craftlens\records;

use craft\db\ActiveRecord;
use vitordiniz22\craftlens\migrations\Install;
use yii\db\ActiveQueryInterface;

/**
 * Per-site content record for multilingual alt text and title.
 *
 * Stores site-specific alt text and suggested title for non-primary sites.
 * Primary site values remain on the main AssetAnalysisRecord.
 *
 * Each editable field follows the dual-column pattern:
 * - The main column (e.g. `altText`) holds the effective/user-facing value
 * - The `*Ai` column (e.g. `altTextAi`) holds the raw AI-generated value
 *
 * Editing is detected by comparing the main column to its `*Ai` counterpart.
 *
 * @property int $id
 * @property int $analysisId
 * @property int $siteId
 * @property string $language
 *
 * Alt text (editable, per-site):
 * @property string|null $altText
 * @property string|null $altTextAi
 * @property float|null $altTextConfidence
 *
 * Suggested title (editable, per-site):
 * @property string|null $suggestedTitle
 * @property string|null $suggestedTitleAi
 * @property float|null $titleConfidence
 *
 * Timestamps:
 * @property \DateTime $dateCreated
 * @property \DateTime $dateUpdated
 * @property string $uid
 *
 * Relations:
 * @property-read AssetAnalysisRecord $analysis
 */
class AnalysisSiteContentRecord extends ActiveRecord
{
    /**
     * List of editable field names (fields with a corresponding *Ai column).
     */
    public const EDITABLE_FIELDS = [
        'altText',
        'suggestedTitle',
    ];

    public static function tableName(): string
    {
        return Install::TABLE_ANALYSIS_SITE_CONTENT;
    }

    /**
     * Check if a given field has been edited by a user (value differs from AI).
     */
    public function isFieldEdited(string $fieldName): bool
    {
        $aiColumn = $fieldName . 'Ai';

        if (!$this->hasAttribute($aiColumn) || $this->{$aiColumn} === null) {
            return false;
        }

        return $this->$fieldName != $this->{$aiColumn};
    }

    public function getAnalysis(): ActiveQueryInterface
    {
        return $this->hasOne(AssetAnalysisRecord::class, ['id' => 'analysisId']);
    }

    public function rules(): array
    {
        return [
            [['analysisId', 'siteId', 'language'], 'required'],
            [['language'], 'string', 'max' => 10],
            [['altText', 'altTextAi'], 'string', 'max' => AssetAnalysisRecord::ALT_TEXT_MAX_LENGTH],
            [['suggestedTitle', 'suggestedTitleAi'], 'string', 'max' => AssetAnalysisRecord::SUGGESTED_TITLE_MAX_LENGTH],
            [['altTextConfidence', 'titleConfidence'], 'number', 'min' => 0, 'max' => 1],
        ];
    }
}
