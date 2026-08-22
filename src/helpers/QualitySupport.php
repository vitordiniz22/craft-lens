<?php

declare(strict_types=1);

namespace vitordiniz22\craftlens\helpers;

use vitordiniz22\craftlens\Plugin;

/**
 * Gates image-quality features (sharpness, exposure, contrast, compression, color
 * profile). These metrics are Imagick-exclusive — sharpness needs Laplacian/Sobel
 * convolution and color profile needs ICC extraction, neither of which GD supports.
 * On servers without Imagick, quality UI is hidden rather than partially populated.
 */
class QualitySupport
{
    private static ?bool $available = null;

    public static function isAvailable(): bool
    {
        return self::isSupported()
            && Plugin::getInstance()->getSettings()->enableQualityAnalysis;
    }

    public static function isSupported(): bool
    {
        return self::$available ??= extension_loaded('imagick');
    }
}
