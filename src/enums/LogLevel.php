<?php

declare(strict_types=1);

namespace vitordiniz22\craftlens\enums;

use Craft;

enum LogLevel: string
{
    use EnumOptionsTrait;

    case Info = 'info';
    case Warning = 'warning';
    case Error = 'error';
    case Critical = 'critical';

    public function label(): string
    {
        return match ($this) {
            self::Info => Craft::t('lens', 'Info'),
            self::Warning => Craft::t('lens', 'Warning'),
            self::Error => Craft::t('lens', 'Error'),
            self::Critical => Craft::t('lens', 'Critical'),
        };
    }

    /**
     * Whether this level represents an error condition.
     */
    public function isError(): bool
    {
        return in_array($this, [self::Error, self::Critical], true);
    }
}
