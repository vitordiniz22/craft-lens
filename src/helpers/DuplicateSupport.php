<?php

declare(strict_types=1);

namespace vitordiniz22\craftlens\helpers;

/**
 * Gates duplicate detection. Perceptual hashing is implemented in pure GD
 * (imagescale + per-pixel read), so without GD there is no way to compute
 * the aHash used by DuplicateDetectionService.
 */
class DuplicateSupport
{
    private static ?bool $available = null;

    public static function isAvailable(): bool
    {
        return self::$available ??= extension_loaded('gd');
    }
}
