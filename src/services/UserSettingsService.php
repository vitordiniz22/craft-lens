<?php

declare(strict_types=1);

namespace vitordiniz22\craftlens\services;

use Craft;
use vitordiniz22\craftlens\enums\AssetBrowserLayout;
use vitordiniz22\craftlens\records\UserSettingRecord;
use yii\base\Component;

/**
 * Reads and writes per-user preferences stored in the lens_user_settings table.
 * Generic key/value store keyed by (userId, settingKey); typed accessors wrap
 * specific settings (e.g. the asset browser layout).
 */
class UserSettingsService extends Component
{
    public function get(string $key, ?int $userId = null): ?string
    {
        $userId ??= Craft::$app->getUser()->getId();

        if ($userId === null) {
            return null;
        }

        $record = UserSettingRecord::findOne(['userId' => $userId, 'settingKey' => $key]);

        return $record?->settingValue;
    }

    public function set(string $key, ?string $value, ?int $userId = null): void
    {
        $userId ??= Craft::$app->getUser()->getId();

        if ($userId === null) {
            return;
        }

        $record = UserSettingRecord::findOne(['userId' => $userId, 'settingKey' => $key])
            ?? new UserSettingRecord(['userId' => $userId, 'settingKey' => $key]);

        $record->settingValue = $value;
        $record->save();
    }

    public function getAssetBrowserLayout(?int $userId = null): AssetBrowserLayout
    {
        return AssetBrowserLayout::fromValueOrDefault(
            $this->get(AssetBrowserLayout::SETTING_KEY, $userId)
        );
    }

    public function setAssetBrowserLayout(AssetBrowserLayout $layout, ?int $userId = null): void
    {
        $this->set(AssetBrowserLayout::SETTING_KEY, $layout->value, $userId);
    }
}
