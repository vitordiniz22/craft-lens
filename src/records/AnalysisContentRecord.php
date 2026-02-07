<?php

declare(strict_types=1);

namespace vitordiniz22\craftlens\records;

use craft\db\ActiveRecord;
use vitordiniz22\craftlens\migrations\Install;
use yii\db\ActiveQueryInterface;

/**
 * Analysis Content record.
 *
 * Stores heavy AI response data that was moved out of the main analysis table
 * for better query performance and lazy loading.
 *
 * @property int $id
 * @property int $analysisId
 * @property array|null $rawResponse Full API response from the AI provider
 * @property string|null $customPromptResult Result from custom analysis prompt
 * @property string|null $errorMessage Error message if analysis failed
 * @property \DateTime $dateCreated
 * @property \DateTime $dateUpdated
 * @property string $uid
 * @property-read AssetAnalysisRecord $analysis
 */
class AnalysisContentRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return Install::TABLE_ANALYSIS_CONTENT;
    }

    /**
     * Returns the parent analysis record.
     */
    public function getAnalysis(): ActiveQueryInterface
    {
        return $this->hasOne(AssetAnalysisRecord::class, ['id' => 'analysisId']);
    }

    public function rules(): array
    {
        return [
            [['analysisId'], 'required'],
            [['analysisId'], 'integer'],
        ];
    }
}
