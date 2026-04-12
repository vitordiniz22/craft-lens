<?php

declare(strict_types=1);

namespace vitordiniz22\craftlens\enums;

use Craft;

enum WatermarkType: string
{
    use EnumOptionsTrait;

    case Stock = 'stock';
    case Logo = 'logo';
    case Text = 'text';
    case Copyright = 'copyright';
    case Ai = 'ai';
    case Unknown = 'unknown';

    public function label(): string
    {
        return match ($this) {
            self::Stock => Craft::t('lens', 'Stock Photo'),
            self::Logo => Craft::t('lens', 'Logo'),
            self::Text => Craft::t('lens', 'Text'),
            self::Copyright => Craft::t('lens', 'Copyright'),
            self::Ai => Craft::t('lens', 'AI-Generated'),
            self::Unknown => Craft::t('lens', 'Unknown'),
        };
    }
}
