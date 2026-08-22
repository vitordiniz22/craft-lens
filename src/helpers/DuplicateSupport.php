<?php

declare(strict_types=1);

namespace vitordiniz22\craftlens\helpers;

/**
 * Gates duplicate detection. Hashing uses the same bounded Imagick handle as
 * metrics and preprocessing; no second driver or full-resolution decode.
 */
class DuplicateSupport
{
    private static ?bool $available = null;

    public static function isAvailable(): bool
    {
        return self::$available ??= extension_loaded('imagick');
    }
}
