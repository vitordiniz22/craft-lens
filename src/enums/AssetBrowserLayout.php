<?php

declare(strict_types=1);

namespace vitordiniz22\craftlens\enums;

use Craft;

enum AssetBrowserLayout: string
{
    use EnumOptionsTrait;

    public const SETTING_KEY = 'asset_browser_layout';

    case Default = 'default';
    case Mini = 'mini';

    public function label(): string
    {
        return match ($this) {
            self::Default => Craft::t('lens', 'Cards'),
            self::Mini => Craft::t('lens', 'Mini cards'),
        };
    }

    public static function fromValueOrDefault(?string $value): self
    {
        return self::tryFrom((string) $value) ?? self::Default;
    }
}
