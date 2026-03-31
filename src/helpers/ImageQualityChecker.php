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
    public const MAX_WIDTH_RECOMMENDED = 4096;   // Beyond 4K is overkill for web

    private const MODERN_FORMATS = ['webp', 'avif'];

    /**
     * Returns web readiness verdicts for the given asset.
     *
     * @return array{issues: WebReadinessRecommendation[], checks: array<string, array{status: string, icon: string, label: string, value: string, verdict: string}>, overallStatus: string}
     */
    public static function assess(Asset $asset): array
    {
        $issues = [];
        $checks = [];

        $fileSize = $asset->size ?? 0;
        $formattedSize = self::formatFileSize($fileSize);

        if ($fileSize > 0) {
            if ($fileSize >= self::FILE_SIZE_CRITICAL) {
                $issues[] = WebReadinessRecommendation::FileVeryLarge;
                $checks['fileSize'] = [
                    'status' => 'error',
                    'icon' => 'file',
                    'label' => Craft::t('lens', 'File Size'),
                    'value' => $formattedSize,
                    'verdict' => Craft::t('lens', 'Too large'),
                    'recommendation' => Craft::t('lens', 'This file may cause slow page loads if served at full size.'),
                ];
            } elseif ($fileSize >= self::FILE_SIZE_WARNING) {
                $issues[] = WebReadinessRecommendation::FileLarge;
                $checks['fileSize'] = [
                    'status' => 'warning',
                    'icon' => 'file',
                    'label' => Craft::t('lens', 'File Size'),
                    'value' => $formattedSize,
                    'verdict' => Craft::t('lens', 'Large'),
                    'recommendation' => Craft::t('lens', 'Large for web delivery. If your site doesn\'t resize images automatically, consider uploading a smaller version.'),
                ];
            } else {
                $checks['fileSize'] = [
                    'status' => 'pass',
                    'icon' => 'file',
                    'label' => Craft::t('lens', 'File Size'),
                    'value' => $formattedSize,
                    'verdict' => Craft::t('lens', 'Good'),
                    'recommendation' => null,
                ];
            }
        }

        $extension = strtolower($asset->getExtension());
        $formatDisplay = strtoupper($extension);

        $width = $asset->width ?? 0;
        $height = $asset->height ?? 0;

        if ($width > 0 && $extension !== 'svg') {
            $dimensions = "{$width} × {$height}";

            if ($width < self::MIN_WIDTH_RECOMMENDED) {
                $issues[] = WebReadinessRecommendation::ResolutionTooSmall;
                $checks['resolution'] = [
                    'status' => 'warning',
                    'icon' => 'arrows-maximize',
                    'label' => Craft::t('lens', 'Resolution'),
                    'value' => $dimensions,
                    'verdict' => Craft::t('lens', 'Small'),
                    'recommendation' => Craft::t('lens', 'May be too small for full-width layouts. 1200px or wider is recommended.'),
                ];
            } elseif ($width > self::MAX_WIDTH_RECOMMENDED) {
                $issues[] = WebReadinessRecommendation::ResolutionOversized;
                $checks['resolution'] = [
                    'status' => 'warning',
                    'icon' => 'arrows-maximize',
                    'label' => Craft::t('lens', 'Resolution'),
                    'value' => $dimensions,
                    'verdict' => Craft::t('lens', 'Oversized'),
                    'recommendation' => Craft::t('lens', 'Larger than most screens need. This is fine if your site resizes images automatically.'),
                ];
            } else {
                $checks['resolution'] = [
                    'status' => 'pass',
                    'icon' => 'arrows-maximize',
                    'label' => Craft::t('lens', 'Resolution'),
                    'value' => $dimensions,
                    'verdict' => Craft::t('lens', 'Good'),
                    'recommendation' => null,
                ];
            }
        }

        if ($extension === 'tiff' || $extension === 'tif') {
            $issues[] = WebReadinessRecommendation::BrowserUnsupportedFormat;
            $checks['format'] = [
                'status' => 'error',
                'icon' => 'image',
                'label' => Craft::t('lens', 'Format'),
                'value' => $formatDisplay,
                'verdict' => Craft::t('lens', 'Unsupported'),
                'recommendation' => Craft::t('lens', 'This format cannot be displayed by browsers. Convert to JPEG, PNG, or WebP.'),
            ];
        }

        $standardFormats = ['jpg', 'jpeg', 'jfif', 'png', 'gif', 'svg'];

        if (in_array($extension, $standardFormats, true) && !isset($checks['format'])) {
            $checks['format'] = [
                'status' => 'pass',
                'icon' => 'image',
                'label' => Craft::t('lens', 'Format'),
                'value' => $formatDisplay,
                'verdict' => Craft::t('lens', 'Widely supported'),
                'recommendation' => null,
            ];
        }

        if (in_array($extension, self::MODERN_FORMATS, true)) {
            $checks['format'] = [
                'status' => 'pass',
                'icon' => 'wand-sparkles',
                'label' => Craft::t('lens', 'Format'),
                'value' => $formatDisplay,
                'verdict' => Craft::t('lens', 'Modern format'),
                'recommendation' => null,
            ];
        }

        if (!isset($checks['format'])) {
            $checks['format'] = [
                'status' => 'warning',
                'icon' => 'image',
                'label' => Craft::t('lens', 'Format'),
                'value' => $formatDisplay,
                'verdict' => Craft::t('lens', 'Check compatibility'),
                'recommendation' => Craft::t('lens', 'This format may not be supported by all browsers. Consider converting to JPEG, PNG, or WebP.'),
            ];
        }

        $hasError = false;
        $hasWarning = false;

        foreach ($checks as $check) {
            if ($check['status'] === 'error') {
                $hasError = true;
            } elseif ($check['status'] === 'warning') {
                $hasWarning = true;
            }
        }

        return [
            'issues' => $issues,
            'checks' => $checks,
            'overallStatus' => $hasError ? 'error' : ($hasWarning ? 'warning' : 'pass'),
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
