<?php

declare(strict_types=1);

namespace vitordiniz22\craftlens\records;

use craft\db\ActiveRecord;
use vitordiniz22\craftlens\migrations\Install;
use yii\db\ActiveQueryInterface;

/**
 * Asset Tag record.
 *
 * Stores individual tags extracted from asset analyses for efficient querying.
 * Tags can be AI-generated (isAi=true) or user-added (isAi=false).
 * On reprocess, only AI tags are replaced; user tags are preserved.
 *
 * @property int $id
 * @property int $assetId
 * @property int $analysisId
 * @property string $tag
 * @property string $tagNormalized
 * @property float|null $confidence
 * @property bool $isAi Whether the tag was AI-generated (true) or user-added (false)
 * @property \DateTime $dateCreated
 * @property \DateTime $dateUpdated
 * @property string $uid
 * @property-read AssetAnalysisRecord $analysis
 */
class AssetTagRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return Install::TABLE_ASSET_TAGS;
    }

    public function getAnalysis(): ActiveQueryInterface
    {
        return $this->hasOne(AssetAnalysisRecord::class, ['id' => 'analysisId']);
    }

    public function rules(): array
    {
        return [
            [['assetId', 'analysisId', 'tag', 'tagNormalized'], 'required'],
            [['tag', 'tagNormalized'], 'string', 'max' => 255],
            [['confidence'], 'number', 'min' => 0, 'max' => 1],
            [['isAi'], 'boolean'],
        ];
    }
}
