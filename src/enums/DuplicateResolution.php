<?php

declare(strict_types=1);

namespace vitordiniz22\craftlens\enums;

use Craft;

enum DuplicateResolution: string
{
    use EnumOptionsTrait;

    case Kept = 'kept';
    case Deleted = 'deleted';
    case Ignored = 'ignored';

    public function label(): string
    {
        return match ($this) {
            self::Kept => Craft::t('lens', 'Kept'),
            self::Deleted => Craft::t('lens', 'Deleted'),
            self::Ignored => Craft::t('lens', 'Ignored'),
        };
    }
}
