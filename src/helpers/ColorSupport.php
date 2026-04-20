<?php

declare(strict_types=1);

namespace vitordiniz22\craftlens\helpers;

/**
 * Single source of truth for color-feature availability.
 *
 * Dominant color extraction requires Imagick or GD. When neither extension is
 * loaded, color UI, filters, and aggregates must be hidden so the user isn't
 * offered features that can never produce data.
 */
class ColorSupport
{
    private static ?bool $available = null;

    public static function isAvailable(): bool
    {
        return self::$available ??= extension_loaded('imagick') || extension_loaded('gd');
    }
}
