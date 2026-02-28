<?php

declare(strict_types=1);

namespace vitordiniz22\craftlens\records;

use craft\db\ActiveRecord;
use vitordiniz22\craftlens\migrations\Install;
use yii\db\ActiveQueryInterface;

/**
 * Search index record.
 *
 * Stores pre-stemmed tokens for BM25 full-text search. Each row represents
 * one unique (token, field) pair for a given asset/analysis. The `tf` column
 * holds the term frequency count within that field.
 *
 * @property int $id
 * @property int $assetId
 * @property int $analysisId
 * @property string $token        Stemmed lowercase token
 * @property string $tokenRaw     Unstemmed lowercase token (used for fuzzy matching)
 * @property string $field        Source field name (title, altText, tag, etc.)
 * @property float $fieldWeight   Pre-stored importance multiplier
 * @property int $tf              Term frequency (occurrences within this field)
 * @property \DateTime $dateCreated
 * @property \DateTime $dateUpdated
 * @property string $uid
 * @property-read AssetAnalysisRecord $analysis
 */
class SearchIndexRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return Install::TABLE_SEARCH_INDEX;
    }

    public function getAnalysis(): ActiveQueryInterface
    {
        return $this->hasOne(AssetAnalysisRecord::class, ['id' => 'analysisId']);
    }

    public function rules(): array
    {
        return [
            [['assetId', 'analysisId', 'token', 'tokenRaw', 'field', 'fieldWeight'], 'required'],
            [['token', 'tokenRaw'], 'string', 'max' => 100],
            [['field'], 'string', 'max' => 30],
            [['fieldWeight'], 'number', 'min' => 0],
            [['tf'], 'integer', 'min' => 1],
        ];
    }
}
