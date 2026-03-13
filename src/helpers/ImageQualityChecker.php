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

        // File size check
        $fileSize = $asset->size ?? 0;
        $formattedSize = self::formatFileSize($fileSize);

        if ($fileSize >= self::FILE_SIZE_CRITICAL) {
            $issues[] = WebReadinessRecommendation::FileVeryLarge;
            $checks['fileSize'] = [
                'status' => 'error',
                'icon' => 'file',
                'label' => Craft::t('lens', 'File Size'),
                'value' => $formattedSize,
                'verdict' => Craft::t('lens', 'Too large'),
                'recommendation' => Craft::t('lens', 'Compress or resize to improve page load times'),
            ];
        } elseif ($fileSize >= self::FILE_SIZE_WARNING) {
            $issues[] = WebReadinessRecommendation::FileLarge;
            $checks['fileSize'] = [
                'status' => 'warning',
                'icon' => 'file',
                'label' => Craft::t('lens', 'File Size'),
                'value' => $formattedSize,
                'verdict' => Craft::t('lens', 'Large'),
                'recommendation' => Craft::t('lens', 'Compress or resize to improve page load times'),
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

        // Resolution check
        $width = $asset->width ?? 0;
        $height = $asset->height ?? 0;

        if ($width > 0) {
            $dimensions = "{$width} × {$height}";

            if ($width < self::MIN_WIDTH_RECOMMENDED) {
                $issues[] = WebReadinessRecommendation::ResolutionTooSmall;
                $checks['resolution'] = [
                    'status' => 'warning',
                    'icon' => 'arrows-maximize',
                    'label' => Craft::t('lens', 'Resolution'),
                    'value' => $dimensions,
                    'verdict' => Craft::t('lens', 'Too small'),
                    'recommendation' => Craft::t('lens', 'May appear pixelated when displayed at larger sizes'),
                ];
            } elseif ($width > self::MAX_WIDTH_RECOMMENDED) {
                $issues[] = WebReadinessRecommendation::ResolutionOversized;
                $checks['resolution'] = [
                    'status' => 'warning',
                    'icon' => 'arrows-maximize',
                    'label' => Craft::t('lens', 'Resolution'),
                    'value' => $dimensions,
                    'verdict' => Craft::t('lens', 'Oversized'),
                    'recommendation' => Craft::t('lens', 'Exceeds recommended web resolution'),
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

        // Format check — only shown when there's a problem (TIFF)
        $extension = strtolower($asset->getExtension());
        $formatDisplay = strtoupper($extension);

        if ($extension === 'tiff' || $extension === 'tif') {
            $issues[] = WebReadinessRecommendation::BrowserUnsupportedFormat;
            $checks['format'] = [
                'status' => 'warning',
                'icon' => 'image',
                'label' => Craft::t('lens', 'Format'),
                'value' => $formatDisplay,
                'verdict' => Craft::t('lens', 'Unsupported'),
                'recommendation' => Craft::t('lens', 'Convert to JPEG, PNG, or WebP for broad browser support'),
            ];
        }

        // Modern format — only shown when asset is already WebP/AVIF (positive signal)
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

        // Derive overall status from checks
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
