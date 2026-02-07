<?php

declare(strict_types=1);

namespace vitordiniz22\craftlens\enums;

use Craft;

enum SetupSeverity: string
{
    use EnumOptionsTrait;

    case Critical = 'critical';
    case Warning = 'warning';
    case Info = 'info';

    public function label(): string
    {
        return match ($this) {
            self::Critical => Craft::t('lens', 'Critical'),
            self::Warning => Craft::t('lens', 'Warning'),
            self::Info => Craft::t('lens', 'Info'),
        };
    }
}
