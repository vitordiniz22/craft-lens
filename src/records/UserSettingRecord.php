<?php

declare(strict_types=1);

namespace vitordiniz22\craftlens\records;

use craft\db\ActiveRecord;
use vitordiniz22\craftlens\migrations\Install;

/**
 * User Setting record.
 *
 * Stores per-user key/value preferences (e.g. asset browser layout).
 * One row per (userId, settingKey).
 *
 * @property int $id
 * @property int $userId
 * @property string $settingKey
 * @property string|null $settingValue
 * @property \DateTime $dateCreated
 * @property \DateTime $dateUpdated
 * @property string $uid
 */
class UserSettingRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return Install::TABLE_USER_SETTINGS;
    }

    public function rules(): array
    {
        return [
            [['userId', 'settingKey'], 'required'],
            [['settingKey'], 'string', 'max' => 100],
            [['settingValue'], 'string', 'max' => 255],
        ];
    }
}
