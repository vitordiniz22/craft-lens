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

    // Multiplier to scale normalized variance into a useful range for sigmoid input
    private const VARIANCE_SCALE_FACTOR = 10000;

    // Overall quality threshold — below this is considered "low quality"
    public const LOW_QUALITY_THRESHOLD = 0.3;

    // Component weights for overall quality score computation
    private const WEIGHT_SHARPNESS = 0.30;
    private const WEIGHT_BRIGHTNESS = 0.20;
    private const WEIGHT_CONTRAST = 0.15;
    private const WEIGHT_COMPRESSION = 0.25;
    private const WEIGHT_COLOR_PROFILE = 0.10;

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
     * Compute overall quality score (0-1) from local metrics.
     * Weighted average of normalized quality components, redistributed when some metrics are unavailable.
     * Returns null if no metrics are available (e.g., Imagick not installed).
     */
    public static function computeOverallQuality(
        ?float $sharpnessScore,
        ?float $exposureScore,
        ?float $contrastScore,
        ?int $jpegQuality,
        ?string $colorProfile,
    ): ?float {
        $components = [];

        // Sharpness: higher = better, used directly
        if ($sharpnessScore !== null) {
            $components[] = ['score' => $sharpnessScore, 'weight' => self::WEIGHT_SHARPNESS];
        }

        // Brightness: sweet spot is 0.2–0.85, linear penalty outside
        if ($exposureScore !== null) {
            if ($exposureScore >= self::BRIGHTNESS_DARK && $exposureScore <= self::BRIGHTNESS_BRIGHT) {
                $brightnessQuality = 1.0;
            } elseif ($exposureScore < self::BRIGHTNESS_DARK) {
                $brightnessQuality = $exposureScore / self::BRIGHTNESS_DARK;
            } else {
                $brightnessQuality = \max(0.0, 1.0 - ($exposureScore - self::BRIGHTNESS_BRIGHT) / (1.0 - self::BRIGHTNESS_BRIGHT));
            }
            $components[] = ['score' => $brightnessQuality, 'weight' => self::WEIGHT_BRIGHTNESS];
        }

        // Contrast: reaches full quality at CONTRAST_LOW threshold
        if ($contrastScore !== null) {
            $contrastQuality = \min(1.0, $contrastScore / self::CONTRAST_LOW);
            $components[] = ['score' => $contrastQuality, 'weight' => self::WEIGHT_CONTRAST];
        }

        // Compression: JPEG quality 0-100 mapped to 0-1 (non-JPEG = no penalty)
        if ($jpegQuality !== null) {
            $components[] = ['score' => $jpegQuality / 100, 'weight' => self::WEIGHT_COMPRESSION];
        }

        // Color profile: sRGB is ideal, CMYK is worst for web
        if ($colorProfile !== null) {
            $colorQuality = match ($colorProfile) {
                'srgb' => 1.0,
                'grayscale' => 0.9,
                'display-p3', 'adobe-rgb', 'prophoto' => 0.8,
                'cmyk' => 0.4,
                default => 0.8,
            };
            $components[] = ['score' => $colorQuality, 'weight' => self::WEIGHT_COLOR_PROFILE];
        }

        if (empty($components)) {
            return null;
        }

        // Weighted average, redistributed across available components
        $totalWeight = 0.0;
        $weightedSum = 0.0;

        foreach ($components as $component) {
            $weightedSum += $component['score'] * $component['weight'];
            $totalWeight += $component['weight'];
        }

        return \round($weightedSum / $totalWeight, 4);
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
        $variance = $normalizedStdDev * $normalizedStdDev * self::VARIANCE_SCALE_FACTOR;

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

        self::addSharpnessCheck($checks, $raw);
        self::addBrightnessCheck($checks, $raw);
        self::addContrastCheck($checks, $raw);
        self::addCompressionCheck($checks, $raw);
        self::addColorProfileCheck($checks, $raw);

        return $checks;
    }

    /**
     * Build a check result array with translated strings.
     */
    private static function checkResult(string $status, string $icon, string $label, ?string $value, string $verdict, ?string $recommendation): array
    {
        return [
            'status' => $status,
            'icon' => $icon,
            'label' => Craft::t('lens', $label),
            'value' => $value,
            'verdict' => Craft::t('lens', $verdict),
            'recommendation' => $recommendation !== null ? Craft::t('lens', $recommendation) : null,
        ];
    }

    private static function addSharpnessCheck(array &$checks, array $raw): void
    {
        if ($raw['sharpnessScore'] === null) {
            return;
        }

        $score = (float) $raw['sharpnessScore'];

        if ($score < self::SHARPNESS_BLURRY) {
            $checks['sharpness'] = self::checkResult('warning', 'eye', 'Sharpness', null, 'Blurry', 'Sharpness is low, consider replacing this image');
        } elseif ($score < self::SHARPNESS_SOFT) {
            $checks['sharpness'] = self::checkResult('warning', 'eye', 'Sharpness', null, 'Soft', 'Image is slightly soft, may lack detail at full size');
        } else {
            $checks['sharpness'] = self::checkResult('pass', 'eye', 'Sharpness', null, 'Sharp', null);
        }
    }

    private static function addBrightnessCheck(array &$checks, array $raw): void
    {
        if ($raw['exposureScore'] === null) {
            return;
        }

        $score = (float) $raw['exposureScore'];

        if ($score < self::BRIGHTNESS_DARK) {
            $checks['brightness'] = self::checkResult('warning', 'sun', 'Brightness', null, 'Too dark', 'Image may be too dark for web use. Consider replacing or editing the source.');
        } elseif ($score > self::BRIGHTNESS_BRIGHT) {
            $checks['brightness'] = self::checkResult('warning', 'sun', 'Brightness', null, 'Too bright', 'Image may be too bright, some detail may be lost in light areas.');
        } else {
            $checks['brightness'] = self::checkResult('pass', 'sun', 'Brightness', null, 'Good', null);
        }
    }

    private static function addContrastCheck(array &$checks, array $raw): void
    {
        if ($raw['contrastScore'] === null) {
            return;
        }

        $score = (float) $raw['contrastScore'];

        if ($score < self::CONTRAST_FLAT) {
            $checks['contrast'] = self::checkResult('warning', 'circle-half-stroke', 'Contrast', null, 'Flat', 'Very low contrast, image may appear washed out');
        } elseif ($score < self::CONTRAST_LOW) {
            $checks['contrast'] = self::checkResult('warning', 'circle-half-stroke', 'Contrast', null, 'Low contrast', 'Low contrast may reduce visual impact');
        } else {
            $checks['contrast'] = self::checkResult('pass', 'circle-half-stroke', 'Contrast', null, 'Good', null);
        }
    }

    private static function addCompressionCheck(array &$checks, array $raw): void
    {
        if ($raw['jpegQuality'] === null) {
            return;
        }

        $quality = (int) $raw['jpegQuality'];
        $value = $quality . '%';

        if ($quality < self::JPEG_HEAVY_ARTIFACTS) {
            $checks['compression'] = self::checkResult('error', 'file-zipper', 'Compression', $value, 'Heavy artifacts', 'JPEG quality is very low with visible artifacts. Replace with a higher quality source.');
        } elseif ($quality < self::JPEG_COMPRESSED) {
            $checks['compression'] = self::checkResult('warning', 'file-zipper', 'Compression', $value, 'Compressed', 'Noticeable compression, consider using a higher quality source');
        } else {
            $checks['compression'] = self::checkResult('pass', 'file-zipper', 'Compression', $value, 'Good', null);
        }
    }

    private static function addColorProfileCheck(array &$checks, array $raw): void
    {
        if ($raw['colorProfile'] === null) {
            return;
        }

        $profile = $raw['colorProfile'];
        $displayName = self::profileDisplayName($profile);

        if ($profile === 'cmyk') {
            $checks['colorProfile'] = self::checkResult('warning', 'palette', 'Color Profile', $displayName, 'Not web-ready', 'CMYK is for print, convert to sRGB for web display');
        } elseif ($profile === 'srgb') {
            $checks['colorProfile'] = self::checkResult('pass', 'palette', 'Color Profile', $displayName, 'Web standard', null);
        } else {
            $checks['colorProfile'] = self::checkResult('warning', 'palette', 'Color Profile', $displayName, 'Not sRGB', 'Colors may look different across browsers. Convert to sRGB for consistent display.');
        }
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
