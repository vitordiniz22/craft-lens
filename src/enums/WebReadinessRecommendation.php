<?php

declare(strict_types=1);

namespace vitordiniz22\craftlens\enums;

use Craft;

/**
 * Web readiness verdicts derived from asset properties.
 * Runs locally without AI — works on every asset.
 */
enum WebReadinessRecommendation: string
{
    case FileLarge = 'file_large';
    case FileVeryLarge = 'file_very_large';
    case ResolutionTooSmall = 'resolution_too_small';
    case BrowserUnsupportedFormat = 'browser_unsupported_format';

    public function label(): string
    {
        return match ($this) {
            self::FileLarge => Craft::t('lens', 'File size may impact performance'),
            self::FileVeryLarge => Craft::t('lens', 'File is very large'),
            self::ResolutionTooSmall => Craft::t('lens', 'Resolution may be too small'),
            self::BrowserUnsupportedFormat => Craft::t('lens', 'Format not supported by browsers'),
        };
    }

    public function recommendation(): string
    {
        return match ($this) {
            self::FileLarge => Craft::t('lens', 'For best web performance, keep images under 1MB or use Craft transforms for delivery.'),
            self::FileVeryLarge => Craft::t('lens', 'May cause slow loading, especially on mobile. Optimize the source or configure Craft transforms.'),
            self::ResolutionTooSmall => Craft::t('lens', 'Image may be too small for full-width layouts (1200px+ recommended).'),
            self::BrowserUnsupportedFormat => Craft::t('lens', 'TIFF format is not supported by most web browsers. Ensure Craft transforms convert this asset for delivery.'),
        };
    }

    /**
     * @return 'warning'|'error'
     */
    public function severity(): string
    {
        return match ($this) {
            self::FileVeryLarge => 'error',
            default => 'warning',
        };
    }
}
