<?php

declare(strict_types=1);

namespace vitordiniz22\craftlens\records;

use craft\db\ActiveRecord;
use craft\records\Asset;
use craft\records\User;
use vitordiniz22\craftlens\enums\DuplicateResolution;
use vitordiniz22\craftlens\migrations\Install;
use yii\db\ActiveQueryInterface;

/**
 * Duplicate Group record.
 *
 * @property int $id
 * @property int $canonicalAssetId
 * @property int $duplicateAssetId
 * @property int $hammingDistance
 * @property float $similarity
 * @property \DateTime|null $resolvedAt
 * @property int|null $resolvedBy
 * @property string|null $resolution
 * @property \DateTime $dateCreated
 * @property \DateTime $dateUpdated
 * @property string $uid
 * @property-read Asset $canonicalAsset
 * @property-read Asset $duplicateAsset
 * @property-read User|null $resolver
 */
class DuplicateGroupRecord extends ActiveRecord
{

    public static function tableName(): string
    {
        return Install::TABLE_DUPLICATE_GROUPS;
    }

    public function getCanonicalAsset(): ActiveQueryInterface
    {
        return $this->hasOne(Asset::class, ['id' => 'canonicalAssetId']);
    }

    public function getDuplicateAsset(): ActiveQueryInterface
    {
        return $this->hasOne(Asset::class, ['id' => 'duplicateAssetId']);
    }

    public function getResolver(): ActiveQueryInterface
    {
        return $this->hasOne(User::class, ['id' => 'resolvedBy']);
    }

    public function rules(): array
    {
        return [
            [['canonicalAssetId', 'duplicateAssetId', 'hammingDistance'], 'required'],
            [['canonicalAssetId', 'duplicateAssetId', 'hammingDistance'], 'integer'],
            [['similarity'], 'number', 'min' => 0, 'max' => 1],
            [['resolution'], 'in', 'range' => array_column(DuplicateResolution::cases(), 'value')],
        ];
    }
}
