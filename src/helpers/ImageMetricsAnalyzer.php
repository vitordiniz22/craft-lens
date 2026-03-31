<?php

declare(strict_types=1);

namespace vitordiniz22\craftlens\helpers;

use Craft;
use craft\elements\Asset;
use Imagick;
use vitordiniz22\craftlens\enums\LogCategory;

/**
 * Computes image quality metrics locally using Imagick -- no AI required.
 * Measures sharpness, brightness, contrast, compression quality, and color profile.
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

    // Compression quality threshold (0-100) — below this, degradation is visible to non-technical users
    public const COMPRESSION_VISIBLE_DEGRADATION = 50;

    // Sharpness: multi-sigma blur decay parameters
    private const SHARPNESS_DECAY_SIGMOID_RATE = 20.0;
    private const SHARPNESS_DECAY_SIGMOID_MIDPOINT = 0.71;
    private const SHARPNESS_MIN_DIMENSION = 100;
    private const SHARPNESS_ANALYSIS_MAX_SIZE = 500;
    // Below this stdDev, image is near-featureless and decay curve is unreliable
    private const SHARPNESS_MIN_DETAIL_STDDEV = 0.015;
    // Sobel gradient stdDev below this = noise, not real edges
    private const SHARPNESS_NOISE_SOBEL_THRESHOLD = 0.03;
    // Patch analysis blend zone: patches contribute 0% below low, 30% above high
    private const SHARPNESS_PATCH_BLEND_LOW = 200;
    private const SHARPNESS_PATCH_BLEND_HIGH = 400;
    private const SHARPNESS_PATCH_MAX_WEIGHT = 0.3;

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
     * @return array{raw: array{sharpnessScore: ?float, exposureScore: float, contrastScore: float, compressionQuality: int|null, colorProfile: string|null}, checks: array}|null
     */
    public static function analyze(Asset $asset): ?array
    {
        if (!self::isAvailable()) {
            return null;
        }

        $tempPath = null;
        $imagick = null;

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
                'compressionQuality' => self::measureCompressionQuality($imagick, $asset, $tempPath),
                'colorProfile' => self::detectColorProfile($imagick, $originalColorspace),
            ];

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
            $imagick?->clear();

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
        ?int $compressionQuality,
        ?string $colorProfile,
    ): array {
        $raw = [
            'sharpnessScore' => $sharpnessScore,
            'exposureScore' => $exposureScore,
            'contrastScore' => $contrastScore,
            'compressionQuality' => $compressionQuality,
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
        ?int $compressionQuality,
        ?string $colorProfile,
    ): ?float {
        $components = [];

        // Sharpness: higher = better, used directly
        if ($sharpnessScore !== null) {
            $components[] = ['score' => $sharpnessScore, 'weight' => self::WEIGHT_SHARPNESS];
        }

        // Brightness: sweet spot is 0.2-0.85, linear penalty outside
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

        // Compression: quality 0-100 mapped to 0-1
        if ($compressionQuality !== null) {
            $components[] = ['score' => $compressionQuality / 100, 'weight' => self::WEIGHT_COMPRESSION];
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
     * Measure image sharpness using multi-sigma blur decay analysis.
     *
     * Applies Gaussian blur at 3 sigma levels and measures how much Laplacian variance drops.
     * Sharp images have high variance loss at sigma 0.3 (fine detail destroyed); blurry images
     * retain ~98% at sigma 0.3 (nothing fine to lose). Format-independent, single code path.
     *
     * Patch-based 3x3 grid analysis catches partially blurry images (sharp center, soft edges).
     * Sobel gradient check penalizes noise that mimics sharpness in the Laplacian.
     *
     * Returns null for images with shortest side < 100px.
     */
    private static function measureSharpness(Imagick $imagick): ?float
    {
        $width = $imagick->getImageWidth();
        $height = $imagick->getImageHeight();
        $minDim = \min($width, $height);

        if ($minDim < self::SHARPNESS_MIN_DIMENSION) {
            return null;
        }

        // Prepare grayscale clone at analysis resolution (capped at 500px)
        $gray = clone $imagick;
        $gray->transformImageColorspace(Imagick::COLORSPACE_GRAY);
        if ($minDim > self::SHARPNESS_ANALYSIS_MAX_SIZE) {
            $gray->resizeImage(
                self::SHARPNESS_ANALYSIS_MAX_SIZE,
                self::SHARPNESS_ANALYSIS_MAX_SIZE,
                Imagick::FILTER_LANCZOS,
                1,
                true,
            );
        }

        $baseVariance = self::laplacianVarianceRaw($gray);
        if ($baseVariance <= 0) {
            $gray->clear();
            return 0.0;
        }

        // Near-featureless images produce unreliable decay curves; short-circuit with low score
        if (\sqrt($baseVariance) < self::SHARPNESS_MIN_DETAIL_STDDEV) {
            $gray->clear();
            return 0.05;
        }

        // Measure variance retention after progressive blur at 3 sigma levels
        // Explicit radius = ceil(3 * sigma) for full Gaussian coverage
        $sigmas = [0.3, 0.7, 1.5];
        $retentions = [];

        foreach ($sigmas as $sigma) {
            $blurred = clone $gray;
            $blurred->gaussianBlurImage((int) \ceil($sigma * 3), $sigma);
            $blurredVariance = self::laplacianVarianceInPlace($blurred);
            $blurred->clear();
            $retentions[] = $blurredVariance / $baseVariance;
        }

        // Decay score: sigma 0.7 is the primary discriminator, sigma 0.3 has minimal weight
        $decayScore = 0.05 * (1.0 - $retentions[0])
                    + 0.65 * (1.0 - $retentions[1])
                    + 0.30 * (1.0 - $retentions[2]);

        // Patch-based analysis using actual width/height for full image coverage
        $actualWidth = $gray->getImageWidth();
        $actualHeight = $gray->getImageHeight();
        $patchMinSide = \min($actualWidth, $actualHeight);

        // Blend zone: patches contribute 0% below BLEND_LOW, up to PATCH_MAX_WEIGHT above BLEND_HIGH
        if ($patchMinSide >= self::SHARPNESS_PATCH_BLEND_LOW) {
            $patchW = (int) ($actualWidth / 3);
            $patchH = (int) ($actualHeight / 3);

            // Only analyze if patches are large enough to be meaningful (min side >= 50px)
            if ($patchW >= 50 && $patchH >= 50) {
                // Pre-blur at full resolution, then crop patches from both original and blurred
                $grayBlurred = clone $gray;
                $grayBlurred->gaussianBlurImage((int) \ceil(0.5 * 3), 0.5);

                $patchScores = [];

                for ($row = 0; $row < 3; $row++) {
                    for ($col = 0; $col < 3; $col++) {
                        $x = $col * $patchW;
                        $y = $row * $patchH;

                        $patch = clone $gray;
                        $patch->cropImage($patchW, $patchH, $x, $y);
                        $patch->setImagePage($patchW, $patchH, 0, 0);
                        $patchBase = self::laplacianVarianceInPlace($patch);
                        $patch->clear();

                        if ($patchBase > 0) {
                            $patchBlur = clone $grayBlurred;
                            $patchBlur->cropImage($patchW, $patchH, $x, $y);
                            $patchBlur->setImagePage($patchW, $patchH, 0, 0);
                            $blurredVar = self::laplacianVarianceInPlace($patchBlur);
                            $patchBlur->clear();
                            $patchScores[] = 1.0 - ($blurredVar / $patchBase);
                        }
                    }
                }

                $grayBlurred->clear();

                if (\count($patchScores) >= 5) {
                    sort($patchScores);
                    // 25th percentile catches "sharp center, blurry edges"
                    $patchDecay = $patchScores[(int) (\count($patchScores) * 0.25)];

                    $patchWeight = self::SHARPNESS_PATCH_MAX_WEIGHT;
                    if ($patchMinSide < self::SHARPNESS_PATCH_BLEND_HIGH) {
                        $t = ($patchMinSide - self::SHARPNESS_PATCH_BLEND_LOW)
                           / (self::SHARPNESS_PATCH_BLEND_HIGH - self::SHARPNESS_PATCH_BLEND_LOW);
                        $patchWeight *= $t;
                    }

                    $decayScore = (1.0 - $patchWeight) * $decayScore + $patchWeight * $patchDecay;
                }
            }
        }

        // Sobel gradient check: high Laplacian decay + low Sobel stdDev = noise, not detail
        $sobelScore = self::sobelMagnitude($gray);
        $gray->clear();

        if ($decayScore > 0.3 && $sobelScore < self::SHARPNESS_NOISE_SOBEL_THRESHOLD) {
            $noisePenalty = 1.0 - ($sobelScore / self::SHARPNESS_NOISE_SOBEL_THRESHOLD);
            $decayScore *= (1.0 - 0.4 * $noisePenalty);
        }

        return 1.0 / (1.0 + \exp(-self::SHARPNESS_DECAY_SIGMOID_RATE * ($decayScore - self::SHARPNESS_DECAY_SIGMOID_MIDPOINT)));
    }

    /**
     * Compute Laplacian variance of a grayscale image (non-destructive).
     * Clones internally so the source image is preserved for reuse.
     * Returns squared normalized standard deviation of edge-detected pixels.
     */
    private static function laplacianVarianceRaw(Imagick $grayImage): float
    {
        $clone = clone $grayImage;
        $variance = self::laplacianVarianceInPlace($clone);
        $clone->clear();
        return $variance;
    }

    /**
     * Compute Laplacian variance by convolving the image in-place.
     * The image pixel data is mutated (convolved) but NOT freed -- the caller
     * is responsible for clearing the Imagick object when done with it.
     */
    private static function laplacianVarianceInPlace(Imagick $grayImage): float
    {
        $grayImage->convolveImage([0, -1, 0, -1, 4, -1, 0, -1, 0]);

        $stats = $grayImage->getImageChannelMean(Imagick::CHANNEL_GRAY);
        $stdDev = $stats['standardDeviation'] ?? 0.0;

        $quantumRange = $grayImage->getQuantumRange();
        $maxQuantum = (float) ($quantumRange['quantumRangeLong'] ?? 65535);

        $normalized = $stdDev / $maxQuantum;
        return $normalized * $normalized;
    }

    /**
     * Compute Sobel gradient magnitude of a grayscale image.
     * Uses stdDev (not mean) because Sobel is a derivative filter where positive/negative
     * gradients cancel in the mean, making it near-zero regardless of image content.
     * StdDev captures the spread of gradient values, which correlates with edge presence.
     */
    private static function sobelMagnitude(Imagick $grayImage): float
    {
        $cloneX = clone $grayImage;
        $cloneY = clone $grayImage;

        $cloneX->convolveImage([-1, 0, 1, -2, 0, 2, -1, 0, 1]);
        $cloneY->convolveImage([-1, -2, -1, 0, 0, 0, 1, 2, 1]);

        $statsX = $cloneX->getImageChannelMean(Imagick::CHANNEL_GRAY);
        $statsY = $cloneY->getImageChannelMean(Imagick::CHANNEL_GRAY);

        $quantumRange = $cloneX->getQuantumRange();
        $maxQuantum = (float) ($quantumRange['quantumRangeLong'] ?? 65535);

        $cloneX->clear();
        $cloneY->clear();

        $stdDevX = ($statsX['standardDeviation'] ?? 0.0) / $maxQuantum;
        $stdDevY = ($statsY['standardDeviation'] ?? 0.0) / $maxQuantum;

        return \sqrt($stdDevX * $stdDevX + $stdDevY * $stdDevY);
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
     * Measure compression quality (0-100) for any image format.
     * JPEG/TIFF-JPEG: read embedded quality. Lossless formats: 100.
     * AVIF/HEIC: try Imagick first, fall back to file-size ratio.
     * WebP: detect lossy vs lossless from RIFF header.
     */
    private static function measureCompressionQuality(Imagick $imagick, Asset $asset, string $tempPath): ?int
    {
        $ext = strtolower($asset->getExtension());

        // JPEG: use Imagick's embedded quality
        if (\in_array($ext, ['jpg', 'jpeg', 'jpe', 'jfif'], true)) {
            $quality = $imagick->getImageCompressionQuality();
            return $quality === 0 ? null : $quality;
        }

        // PNG/BMP: always lossless
        if (\in_array($ext, ['png', 'bmp'], true)) {
            return 100;
        }

        // TIFF: check for JPEG-in-TIFF compression
        if (\in_array($ext, ['tiff', 'tif'], true)) {
            if ($imagick->getImageCompression() === Imagick::COMPRESSION_JPEG) {
                $quality = $imagick->getImageCompressionQuality();
                return $quality === 0 ? null : $quality;
            }
            return 100;
        }

        // WebP: lossless gets 100, lossy returns null (WebP doesn't embed quality metadata
        // and file-size estimation is too unreliable to show to users)
        if ($ext === 'webp') {
            return self::measureWebPQuality($tempPath, $imagick);
        }

        // AVIF, HEIC, HEIF: use Imagick quality if available, otherwise null
        // (file-size estimation is too unreliable across these codecs)
        if (\in_array($ext, ['avif', 'heic', 'heif'], true)) {
            $quality = $imagick->getImageCompressionQuality();
            return $quality > 0 ? $quality : null;
        }

        return null;
    }

    /**
     * Determine WebP compression quality.
     * Validates RIFF/WEBP magic bytes, then checks chunk type.
     * VP8L = lossless (100). VP8X scans for lossless sub-chunk.
     * Lossy WebP returns null — the format doesn't embed quality metadata.
     */
    private static function measureWebPQuality(string $tempPath, Imagick $imagick): ?int
    {
        $handle = fopen($tempPath, 'rb');
        if ($handle === false) {
            return null;
        }

        $header = fread($handle, 20);
        fclose($handle);

        if ($header === false || \strlen($header) < 16) {
            return null;
        }

        // Validate RIFF container and WEBP format
        if (substr($header, 0, 4) !== 'RIFF' || substr($header, 8, 4) !== 'WEBP') {
            return null;
        }

        $chunkType = substr($header, 12, 4);

        if ($chunkType === 'VP8L') {
            return 100;
        }

        // VP8X is the extended format that can contain either lossy or lossless bitstreams.
        // Scan for a VP8L sub-chunk to determine if the actual content is lossless.
        if ($chunkType === 'VP8X') {
            $handle = fopen($tempPath, 'rb');
            if ($handle !== false) {
                $data = fread($handle, 4096);
                fclose($handle);
                if ($data !== false && str_contains($data, 'VP8L')) {
                    return 100;
                }
            }
        }

        // Lossy WebP — no reliable quality signal available
        return null;
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
            $checks['sharpness'] = self::checkResult('warning', 'eye', 'Sharpness', null, 'Blurry', 'This image will look blurry on the page. Try uploading a sharper version.');
        } elseif ($score < self::SHARPNESS_SOFT) {
            $checks['sharpness'] = self::checkResult('warning', 'eye', 'Sharpness', null, 'Soft', 'May appear blurry at full size. If a sharper version is available, use that instead.');
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
            $checks['brightness'] = self::checkResult('warning', 'sun', 'Brightness', null, 'Too dark', 'This image is quite dark and may not display well. If a brighter version is available, consider using that. If this is intentional, you can safely ignore this.');
        } elseif ($score > self::BRIGHTNESS_BRIGHT) {
            $checks['brightness'] = self::checkResult('warning', 'sun', 'Brightness', null, 'Too bright', 'Very bright — some detail may be lost in highlights. If a better-exposed version is available, consider using that. If this is intentional, you can safely ignore this.');
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
            $checks['contrast'] = self::checkResult('warning', 'circle-half-stroke', 'Contrast', null, 'Washed out', 'This image looks washed out and may not stand out on the page. If this is intentional, you can safely ignore this.');
        } elseif ($score < self::CONTRAST_LOW) {
            $checks['contrast'] = self::checkResult('warning', 'circle-half-stroke', 'Contrast', null, 'Low contrast', 'Low contrast may make this image look flat on the page. If this is intentional, you can safely ignore this.');
        } else {
            $checks['contrast'] = self::checkResult('pass', 'circle-half-stroke', 'Contrast', null, 'Good', null);
        }
    }

    private static function addCompressionCheck(array &$checks, array $raw): void
    {
        if ($raw['compressionQuality'] === null) {
            return;
        }

        $quality = (int) $raw['compressionQuality'];

        // Only show when there's a problem — no row for healthy images
        if ($quality < self::COMPRESSION_VISIBLE_DEGRADATION) {
            $checks['compression'] = self::checkResult('warning', 'file-zipper', 'Compression', null, 'Low quality', 'This image has been heavily compressed and may show visible quality loss. Replace with a higher quality source.');
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
        } elseif ($profile === 'srgb' || $profile === 'grayscale') {
            $checks['colorProfile'] = self::checkResult('pass', 'palette', 'Color Profile', $displayName, 'Good', null);
        } else {
            $checks['colorProfile'] = self::checkResult('warning', 'palette', 'Color Profile', $displayName, 'Colors may shift', 'Colors may look slightly different across browsers. For consistent colors, convert to sRGB before uploading.');
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
