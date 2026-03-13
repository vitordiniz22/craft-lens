<?php

declare(strict_types=1);

namespace vitordiniz22\craftlens\helpers;

use Craft;
use craft\elements\Asset;
use vitordiniz22\craftlens\enums\WebReadinessRecommendation;

/**
 * Checks web readiness of an asset using local PHP — no AI required.
 * Works on every asset, even unanalyzed ones.
 */
class ImageQualityChecker
{
    public const FILE_SIZE_WARNING = 1_000_000;  // 1MB
    public const FILE_SIZE_CRITICAL = 3_000_000; // 3MB
    public const MIN_WIDTH_RECOMMENDED = 1200;

    /**
     * Returns web readiness verdicts for the given asset.
     *
     * @return array{issues: WebReadinessRecommendation[], checks: array<string, array{status: 'pass'|'warning'|'error', label: string}>}
     */
    public static function assess(Asset $asset): array
    {
        $issues = [];
        $checks = [];

        // File size check
        $fileSize = $asset->size ?? 0;
        if ($fileSize >= self::FILE_SIZE_CRITICAL) {
            $issues[] = WebReadinessRecommendation::FileVeryLarge;
            $checks['fileSize'] = [
                'status' => 'error',
                'label' => Craft::t('lens', '{size} — may cause slow loading. Optimize or use Craft transforms', [
                    'size' => self::formatFileSize($fileSize),
                ]),
            ];
        } elseif ($fileSize >= self::FILE_SIZE_WARNING) {
            $issues[] = WebReadinessRecommendation::FileLarge;
            $checks['fileSize'] = [
                'status' => 'warning',
                'label' => Craft::t('lens', '{size} — consider optimizing or using Craft transforms', [
                    'size' => self::formatFileSize($fileSize),
                ]),
            ];
        } else {
            $checks['fileSize'] = [
                'status' => 'pass',
                'label' => Craft::t('lens', '{size} — good for web delivery', [
                    'size' => self::formatFileSize($fileSize),
                ]),
            ];
        }

        // Resolution check
        $width = $asset->width ?? 0;
        $height = $asset->height ?? 0;
        $dimensions = "{$width}×{$height}";

        if ($width > 0 && $width < self::MIN_WIDTH_RECOMMENDED) {
            $issues[] = WebReadinessRecommendation::ResolutionTooSmall;
            $checks['resolution'] = [
                'status' => 'warning',
                'label' => Craft::t('lens', '{dimensions} — may be too small for full-width layouts', [
                    'dimensions' => $dimensions,
                ]),
            ];
        } elseif ($width > 0) {
            $checks['resolution'] = [
                'status' => 'pass',
                'label' => Craft::t('lens', '{dimensions} — adequate for web', [
                    'dimensions' => $dimensions,
                ]),
            ];
        }

        // Format check
        $extension = strtolower($asset->getExtension());
        if ($extension === 'tiff' || $extension === 'tif') {
            $issues[] = WebReadinessRecommendation::BrowserUnsupportedFormat;
            $checks['format'] = [
                'status' => 'warning',
                'label' => Craft::t('lens', '{format} — not supported by most browsers', [
                    'format' => strtoupper($extension),
                ]),
            ];
        } else {
            $checks['format'] = [
                'status' => 'pass',
                'label' => Craft::t('lens', '{format} — browser compatible', [
                    'format' => strtoupper($extension),
                ]),
            ];
        }

        return [
            'issues' => $issues,
            'checks' => $checks,
        ];
    }

    private static function formatFileSize(int $bytes): string
    {
        if ($bytes >= 1_000_000) {
            return round($bytes / 1_000_000, 1) . 'MB';
        }

        return round($bytes / 1_000) . 'KB';
    }
}
