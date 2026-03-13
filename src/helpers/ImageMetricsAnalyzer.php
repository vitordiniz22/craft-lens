<?php

declare(strict_types=1);

namespace vitordiniz22\craftlens\helpers;

use Craft;
use craft\elements\Asset;
use Imagick;
use vitordiniz22\craftlens\enums\LogCategory;

/**
 * Computes image quality metrics locally using Imagick — no AI required.
 * Measures sharpness, brightness, contrast, JPEG compression, and color profile.
 */
class ImageMetricsAnalyzer
{
    // Sharpness thresholds (normalized 0-1 via sigmoid of Laplacian variance)
    public const SHARPNESS_BLURRY = 0.3;
    public const SHARPNESS_SOFT = 0.6;

    // Brightness thresholds (normalized channel mean 0-1)
    public const BRIGHTNESS_DARK = 0.2;
    public const BRIGHTNESS_BRIGHT = 0.85;

    // Contrast thresholds (normalized std deviation 0-1)
    public const CONTRAST_FLAT = 0.1;
    public const CONTRAST_LOW = 0.15;

    // JPEG quality thresholds (0-100)
    public const JPEG_HEAVY_ARTIFACTS = 30;
    public const JPEG_COMPRESSED = 60;

    // Sigmoid parameters for sharpness normalization
    private const SHARPNESS_SIGMOID_RATE = 0.01;
    private const SHARPNESS_SIGMOID_MIDPOINT = 200;

    // AI overall quality threshold (kept from old system)
    public const OVERALL_QUALITY_AI_THRESHOLD = 0.4;

    /**
     * Check if Imagick extension is available.
     */
    public static function isAvailable(): bool
    {
        return extension_loaded('imagick');
    }

    /**
     * Run full Imagick analysis on an asset file.
     * Returns raw scores for DB storage and display-ready checks.
     *
     * @return array{raw: array{sharpnessScore: float, exposureScore: float, contrastScore: float, jpegQuality: int|null, colorProfile: string|null}, checks: array}|null
     */
    public static function analyze(Asset $asset): ?array
    {
        if (!self::isAvailable()) {
            return null;
        }

        $tempPath = null;

        try {
            $tempPath = $asset->getCopyOfFile();

            if ($tempPath === null || !file_exists($tempPath)) {
                return null;
            }

            $imagick = new Imagick($tempPath);

            // Ensure we work in RGB colorspace for consistent metrics
            $originalColorspace = $imagick->getImageColorspace();
            if ($originalColorspace === Imagick::COLORSPACE_CMYK) {
                $imagick->transformImageColorspace(Imagick::COLORSPACE_SRGB);
            }

            $raw = [
                'sharpnessScore' => self::measureSharpness($imagick),
                'exposureScore' => self::measureBrightness($imagick),
                'contrastScore' => self::measureContrast($imagick),
                'jpegQuality' => self::measureJpegQuality($imagick, $asset),
                'colorProfile' => self::detectColorProfile($imagick, $originalColorspace),
            ];

            $imagick->clear();

            return [
                'raw' => $raw,
                'checks' => self::buildChecks($raw),
            ];
        } catch (\Throwable $e) {
            Logger::warning(
                LogCategory::AssetProcessing,
                'Local image metrics analysis failed: ' . $e->getMessage(),
                assetId: $asset->id,
            );

            return null;
        } finally {
            if ($tempPath !== null && file_exists($tempPath)) {
                unlink($tempPath);
            }
        }
    }

    /**
     * Build display-ready checks from stored record scores.
     * Used by LensAnalysisElement on render — no Imagick needed.
     *
     * @return array<string, array{status: string, icon: string, label: string, value: string, verdict: string}>
     */
    public static function assessFromRecord(
        ?float $sharpnessScore,
        ?float $exposureScore,
        ?float $contrastScore,
        ?int $jpegQuality,
        ?string $colorProfile,
    ): array {
        $raw = [
            'sharpnessScore' => $sharpnessScore,
            'exposureScore' => $exposureScore,
            'contrastScore' => $contrastScore,
            'jpegQuality' => $jpegQuality,
            'colorProfile' => $colorProfile,
        ];

        return self::buildChecks($raw);
    }

    /**
     * Measure image sharpness using Laplacian variance.
     * Higher variance = sharper image.
     */
    private static function measureSharpness(Imagick $imagick): float
    {
        $clone = clone $imagick;

        // Convert to grayscale for sharpness analysis
        $clone->transformImageColorspace(Imagick::COLORSPACE_GRAY);

        // Resize to a consistent size to normalize variance across resolutions
        $clone->resizeImage(800, 800, Imagick::FILTER_LANCZOS, 1, true);

        // Apply Laplacian kernel for edge detection (3x3 flat array)
        $laplacian = [0, -1, 0, -1, 4, -1, 0, -1, 0];
        $clone->convolveImage($laplacian);

        // Get standard deviation after edge detection
        $channelStats = $clone->getImageChannelMean(Imagick::CHANNEL_GRAY);
        $stdDev = $channelStats['standardDeviation'] ?? 0.0;

        // Normalize std dev by quantum range
        $quantumRange = $clone->getQuantumRange();
        $maxQuantum = (float) ($quantumRange['quantumRangeLong'] ?? 65535);
        $normalizedStdDev = $stdDev / $maxQuantum;

        // Square to get variance-like metric
        $variance = $normalizedStdDev * $normalizedStdDev * 10000;

        $clone->clear();

        // Normalize to 0-1 using sigmoid
        return 1.0 / (1.0 + \exp(-self::SHARPNESS_SIGMOID_RATE * ($variance - self::SHARPNESS_SIGMOID_MIDPOINT)));
    }

    /**
     * Measure image brightness using weighted channel means (luminance).
     */
    private static function measureBrightness(Imagick $imagick): float
    {
        $quantumRange = $imagick->getQuantumRange();
        $maxQuantum = (float) ($quantumRange['quantumRangeLong'] ?? 65535);

        // Get mean for each channel
        $redStats = $imagick->getImageChannelMean(Imagick::CHANNEL_RED);
        $greenStats = $imagick->getImageChannelMean(Imagick::CHANNEL_GREEN);
        $blueStats = $imagick->getImageChannelMean(Imagick::CHANNEL_BLUE);

        $redMean = ($redStats['mean'] ?? 0.0) / $maxQuantum;
        $greenMean = ($greenStats['mean'] ?? 0.0) / $maxQuantum;
        $blueMean = ($blueStats['mean'] ?? 0.0) / $maxQuantum;

        // ITU-R BT.709 luminance formula
        return 0.2126 * $redMean + 0.7152 * $greenMean + 0.0722 * $blueMean;
    }

    /**
     * Measure image contrast using standard deviation of pixel values.
     */
    private static function measureContrast(Imagick $imagick): float
    {
        $quantumRange = $imagick->getQuantumRange();
        $maxQuantum = (float) ($quantumRange['quantumRangeLong'] ?? 65535);

        // Use grayscale channel for overall contrast measurement
        $clone = clone $imagick;
        $clone->transformImageColorspace(Imagick::COLORSPACE_GRAY);

        $stats = $clone->getImageChannelMean(Imagick::CHANNEL_GRAY);
        $stdDev = ($stats['standardDeviation'] ?? 0.0) / $maxQuantum;

        $clone->clear();

        return \min(1.0, $stdDev);
    }

    /**
     * Get JPEG compression quality (0-100). NULL for non-JPEG.
     */
    private static function measureJpegQuality(Imagick $imagick, Asset $asset): ?int
    {
        $ext = strtolower($asset->getExtension());

        if (!\in_array($ext, ['jpg', 'jpeg'], true)) {
            return null;
        }

        $quality = $imagick->getImageCompressionQuality();

        // Imagick returns 0 when quality can't be determined
        if ($quality === 0) {
            return null;
        }

        return $quality;
    }

    /**
     * Detect the image's color profile/space.
     */
    private static function detectColorProfile(Imagick $imagick, int $originalColorspace): ?string
    {
        // Check for embedded ICC profile
        try {
            $profiles = $imagick->getImageProfiles('icc', false);

            if (!empty($profiles)) {
                // Read ICC profile to determine color space name
                $iccData = $imagick->getImageProfile('icc');

                if ($iccData !== false && \strlen($iccData) > 52) {
                    // ICC profile description is at offset 48-52 (length) then the string
                    // But simpler: check known signatures in the profile data
                    $profileLower = strtolower($iccData);

                    if (str_contains($profileLower, 'srgb') || str_contains($profileLower, 's r g b')) {
                        return 'srgb';
                    }

                    if (str_contains($profileLower, 'adobe') && str_contains($profileLower, 'rgb')) {
                        return 'adobe-rgb';
                    }

                    if (str_contains($profileLower, 'prophoto')) {
                        return 'prophoto';
                    }

                    if (str_contains($profileLower, 'display p3') || str_contains($profileLower, 'p3')) {
                        return 'display-p3';
                    }
                }
            }
        } catch (\Throwable) {
            // Some formats don't support ICC profiles
        }

        // Fall back to colorspace detection
        return match ($originalColorspace) {
            Imagick::COLORSPACE_SRGB => 'srgb',
            Imagick::COLORSPACE_RGB => 'srgb',
            Imagick::COLORSPACE_CMYK => 'cmyk',
            Imagick::COLORSPACE_GRAY => 'grayscale',
            default => null,
        };
    }

    /**
     * Build display-ready check arrays from raw metric values.
     *
     * @return array<string, array{status: string, icon: string, label: string, value: string, verdict: string}>
     */
    private static function buildChecks(array $raw): array
    {
        $checks = [];

        // Sharpness
        if ($raw['sharpnessScore'] !== null) {
            $score = (float) $raw['sharpnessScore'];

            if ($score < self::SHARPNESS_BLURRY) {
                $checks['sharpness'] = [
                    'status' => 'warning',
                    'icon' => 'eye',
                    'label' => Craft::t('lens', 'Sharpness'),
                    'value' => null,
                    'verdict' => Craft::t('lens', 'Blurry'),
                    'recommendation' => Craft::t('lens', 'Sharpness is low, consider replacing this image'),
                ];
            } elseif ($score < self::SHARPNESS_SOFT) {
                $checks['sharpness'] = [
                    'status' => 'warning',
                    'icon' => 'eye',
                    'label' => Craft::t('lens', 'Sharpness'),
                    'value' => null,
                    'verdict' => Craft::t('lens', 'Soft'),
                    'recommendation' => Craft::t('lens', 'Image is slightly soft, may lack detail at full size'),
                ];
            } else {
                $checks['sharpness'] = [
                    'status' => 'pass',
                    'icon' => 'eye',
                    'label' => Craft::t('lens', 'Sharpness'),
                    'value' => null,
                    'verdict' => Craft::t('lens', 'Sharp'),
                    'recommendation' => null,
                ];
            }
        }

        // Brightness
        if ($raw['exposureScore'] !== null) {
            $score = (float) $raw['exposureScore'];

            if ($score < self::BRIGHTNESS_DARK) {
                $checks['brightness'] = [
                    'status' => 'warning',
                    'icon' => 'sun',
                    'label' => Craft::t('lens', 'Brightness'),
                    'value' => null,
                    'verdict' => Craft::t('lens', 'Too dark'),
                    'recommendation' => Craft::t('lens', 'Image appears underexposed, consider adjusting levels'),
                ];
            } elseif ($score > self::BRIGHTNESS_BRIGHT) {
                $checks['brightness'] = [
                    'status' => 'warning',
                    'icon' => 'sun',
                    'label' => Craft::t('lens', 'Brightness'),
                    'value' => null,
                    'verdict' => Craft::t('lens', 'Too bright'),
                    'recommendation' => Craft::t('lens', 'Image appears overexposed, highlights may be clipped'),
                ];
            } else {
                $checks['brightness'] = [
                    'status' => 'pass',
                    'icon' => 'sun',
                    'label' => Craft::t('lens', 'Brightness'),
                    'value' => null,
                    'verdict' => Craft::t('lens', 'Good'),
                    'recommendation' => null,
                ];
            }
        }

        // Contrast
        if ($raw['contrastScore'] !== null) {
            $score = (float) $raw['contrastScore'];

            if ($score < self::CONTRAST_FLAT) {
                $checks['contrast'] = [
                    'status' => 'warning',
                    'icon' => 'circle-half-stroke',
                    'label' => Craft::t('lens', 'Contrast'),
                    'value' => null,
                    'verdict' => Craft::t('lens', 'Flat'),
                    'recommendation' => Craft::t('lens', 'Very low contrast, image may appear washed out'),
                ];
            } elseif ($score < self::CONTRAST_LOW) {
                $checks['contrast'] = [
                    'status' => 'warning',
                    'icon' => 'circle-half-stroke',
                    'label' => Craft::t('lens', 'Contrast'),
                    'value' => null,
                    'verdict' => Craft::t('lens', 'Low contrast'),
                    'recommendation' => Craft::t('lens', 'Low contrast may reduce visual impact'),
                ];
            } else {
                $checks['contrast'] = [
                    'status' => 'pass',
                    'icon' => 'circle-half-stroke',
                    'label' => Craft::t('lens', 'Contrast'),
                    'value' => null,
                    'verdict' => Craft::t('lens', 'Good'),
                    'recommendation' => null,
                ];
            }
        }

        // JPEG Quality (only shown for JPEG files)
        if ($raw['jpegQuality'] !== null) {
            $quality = (int) $raw['jpegQuality'];

            if ($quality < self::JPEG_HEAVY_ARTIFACTS) {
                $checks['compression'] = [
                    'status' => 'error',
                    'icon' => 'file-zipper',
                    'label' => Craft::t('lens', 'Compression'),
                    'value' => $quality . '%',
                    'verdict' => Craft::t('lens', 'Heavy artifacts'),
                    'recommendation' => Craft::t('lens', 'JPEG quality is critically low, image is visibly degraded'),
                ];
            } elseif ($quality < self::JPEG_COMPRESSED) {
                $checks['compression'] = [
                    'status' => 'warning',
                    'icon' => 'file-zipper',
                    'label' => Craft::t('lens', 'Compression'),
                    'value' => $quality . '%',
                    'verdict' => Craft::t('lens', 'Compressed'),
                    'recommendation' => Craft::t('lens', 'Noticeable compression, consider using a higher quality source'),
                ];
            } else {
                $checks['compression'] = [
                    'status' => 'pass',
                    'icon' => 'file-zipper',
                    'label' => Craft::t('lens', 'Compression'),
                    'value' => $quality . '%',
                    'verdict' => Craft::t('lens', 'Good'),
                    'recommendation' => null,
                ];
            }
        }

        // Color Profile
        if ($raw['colorProfile'] !== null) {
            $profile = $raw['colorProfile'];
            $displayName = self::profileDisplayName($profile);

            if ($profile === 'cmyk') {
                $checks['colorProfile'] = [
                    'status' => 'warning',
                    'icon' => 'palette',
                    'label' => Craft::t('lens', 'Color Profile'),
                    'value' => $displayName,
                    'verdict' => Craft::t('lens', 'Not web-ready'),
                    'recommendation' => Craft::t('lens', 'CMYK is for print, convert to sRGB for web display'),
                ];
            } elseif ($profile === 'srgb') {
                $checks['colorProfile'] = [
                    'status' => 'pass',
                    'icon' => 'palette',
                    'label' => Craft::t('lens', 'Color Profile'),
                    'value' => $displayName,
                    'verdict' => Craft::t('lens', 'Web standard'),
                    'recommendation' => null,
                ];
            } else {
                $checks['colorProfile'] = [
                    'status' => 'warning',
                    'icon' => 'palette',
                    'label' => Craft::t('lens', 'Color Profile'),
                    'value' => $displayName,
                    'verdict' => Craft::t('lens', 'Not sRGB'),
                    'recommendation' => Craft::t('lens', 'May render with shifted colors in some browsers'),
                ];
            }
        }

        return $checks;
    }

    private static function profileDisplayName(string $profile): string
    {
        return match ($profile) {
            'srgb' => 'sRGB',
            'adobe-rgb' => 'Adobe RGB',
            'prophoto' => 'ProPhoto',
            'display-p3' => 'Display P3',
            'cmyk' => 'CMYK',
            'grayscale' => 'Grayscale',
            default => ucfirst($profile),
        };
    }
}
