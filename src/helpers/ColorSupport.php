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
    public const DRIVER_IMAGICK = 'imagick';
    public const DRIVER_GD = 'gd';

    private static ?string $driver = null;
    private static bool $driverResolved = false;

    public static function isAvailable(): bool
    {
        return self::preferredDriver() !== null;
    }

    /**
     * Which extension the extractor should use. Imagick preferred when both
     * are present (faster, higher quality resampling); GD used as a fallback.
     * Returns null when neither is loaded.
     */
    public static function preferredDriver(): ?string
    {
        if (!self::$driverResolved) {
            self::$driver = match (true) {
                extension_loaded('imagick') => self::DRIVER_IMAGICK,
                extension_loaded('gd') => self::DRIVER_GD,
                default => null,
            };
            self::$driverResolved = true;
        }

        return self::$driver;
    }
}
