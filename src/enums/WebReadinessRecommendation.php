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
    case ResolutionOversized = 'resolution_oversized';
    case BrowserUnsupportedFormat = 'browser_unsupported_format';

    public function label(): string
    {
        return match ($this) {
            self::FileLarge => Craft::t('lens', 'File size may impact performance'),
            self::FileVeryLarge => Craft::t('lens', 'File is very large'),
            self::ResolutionTooSmall => Craft::t('lens', 'Resolution may be too small'),
            self::ResolutionOversized => Craft::t('lens', 'Resolution is oversized for web'),
            self::BrowserUnsupportedFormat => Craft::t('lens', 'Format not supported by browsers'),
        };
    }

    public function recommendation(): string
    {
        return match ($this) {
            self::FileLarge => Craft::t('lens', 'Consider using Craft image transforms in templates to serve an optimized version to visitors.'),
            self::FileVeryLarge => Craft::t('lens', 'Very large source file. Ensure Craft image transforms are used in templates to avoid slow page loads.'),
            self::ResolutionTooSmall => Craft::t('lens', 'May be too small for full-width layouts. 1200px or wider is recommended.'),
            self::ResolutionOversized => Craft::t('lens', 'Larger than needed for web. Use Craft image transforms to serve appropriately sized images.'),
            self::BrowserUnsupportedFormat => Craft::t('lens', 'Convert to JPEG, PNG, or WebP for broad browser support.'),
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
