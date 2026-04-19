<?php

declare(strict_types=1);

namespace vitordiniz22\craftlens\helpers;

use Craft;
use craft\elements\Asset;
use craft\helpers\Assets;
use vitordiniz22\craftlens\enums\LogCategory;

/**
 * Resizes and recompresses asset images before they are uploaded to an AI
 * provider. Targets are set by the caller (typically 1568 px longest edge,
 * JPEG q85) to reduce token cost and avoid provider file-size rejections.
 *
 * The helper never throws. On any failure (no image driver, corrupt file,
 * unsupported format, IO error) it returns a PreprocessResult carrying the
 * original bytes and a reason string, so downstream analysis still runs.
 */
final class ImagePreprocessor
{
    /**
     * Longest-edge pixel cap applied before uploading to the AI provider.
     * 1568 matches Anthropic's native ceiling; larger than this yields no
     * quality gain because every current provider downscales server-side.
     */
    public const DEFAULT_MAX_DIMENSION = 1568;

    /**
     * JPEG quality used when recompressing. 85 is the standard sweet spot
     * between file size and visual fidelity for AI analysis.
     */
    public const DEFAULT_QUALITY = 85;

    /**
     * Longest-edge pixel cap below which preprocessing is bypassed entirely
     * (if the byte size is also under the threshold below).
     */
    private const SKIP_BYTE_THRESHOLD = 500_000;

    /**
     * Decoded-pixel ceiling. Files whose actual (post-decode) dimensions
     * exceed this are treated as decompression bombs and passed through
     * without resize.
     */
    private const PIXEL_BUDGET = 100_000_000;

    /**
     * MIME types preprocessing refuses to touch.
     */
    private const UNSUPPORTED_MIME_TYPES = [
        'image/svg+xml',
        'image/gif',
        'application/pdf',
    ];

    /**
     * Extensions Imagine / Imagick / GD handle unreliably. Raw camera
     * formats routinely fail or produce garbage through the standard
     * image pipeline.
     */
    private const UNSUPPORTED_EXTENSIONS = [
        'svg',
        'cr2', 'nef', 'dng', 'arw', 'rw2', 'orf', 'crw', 'raf',
    ];

    private static ?bool $driverCache = null;
    private static bool $driverWarningEmitted = false;

    public static function preprocess(
        Asset $asset,
        int $maxDimension = self::DEFAULT_MAX_DIMENSION,
        int $quality = self::DEFAULT_QUALITY,
    ): PreprocessResult {
        // Defensive clamps in case a caller passes an out-of-range value.
        $maxDimension = max(256, min(4096, $maxDimension));
        $quality = max(50, min(100, $quality));

        $mimeType = $asset->getMimeType() ?? 'application/octet-stream';

        $rawBytes = self::readRawBytes($asset);
       
        if ($rawBytes === null) {
            return PreprocessResult::passthrough('', $mimeType, 'stream_unavailable');
        }

        if (!self::hasDriver()) {
            return PreprocessResult::passthrough($rawBytes, $mimeType, 'no_driver');
        }

        $skipReason = self::shouldSkip($asset, $mimeType, strlen($rawBytes), $maxDimension);
       
        if ($skipReason !== null) {
            return PreprocessResult::passthrough($rawBytes, $mimeType, $skipReason);
        }

        $inputPath = null;
        $outputPath = null;

        try {
            $inputPath = $asset->getCopyOfFile();

            $keepAsPng = str_starts_with($mimeType, 'image/png');
            $outputExt = $keepAsPng ? 'png' : 'jpg';
            $outputPath = Assets::tempFilePath($outputExt);

            $image = Craft::$app->getImages()->loadImage($inputPath);

            $originalWidth = $image->getWidth();
            $originalHeight = $image->getHeight();

            if ($originalWidth * $originalHeight > self::PIXEL_BUDGET) {
                return PreprocessResult::passthrough($rawBytes, $mimeType, 'pixel_budget_exceeded');
            }

            $image->scaleToFit($maxDimension, $maxDimension);
            $image->setQuality($quality);

            $imagineImage = $image->getImagineImage();
            
            if ($imagineImage !== null) {
                $imagineImage->strip();
            }

            if (!$image->saveAs($outputPath)) {
                return PreprocessResult::passthrough($rawBytes, $mimeType, 'save_failed');
            }

            $processedBytes = @file_get_contents($outputPath);
            
            if ($processedBytes === false || $processedBytes === '') {
                return PreprocessResult::passthrough($rawBytes, $mimeType, 'read_failed');
            }

            $outMime = $keepAsPng ? 'image/png' : 'image/jpeg';

            return PreprocessResult::processed(
                bytes: $processedBytes,
                mimeType: $outMime,
                originalBytes: strlen($rawBytes),
                processedBytes: strlen($processedBytes),
                originalWidth: $originalWidth,
                originalHeight: $originalHeight,
                processedWidth: $image->getWidth(),
                processedHeight: $image->getHeight(),
            );
        } catch (\Throwable $e) {
            Logger::warning(
                LogCategory::AssetProcessing,
                'Image preprocessing failed, using original bytes',
                $asset->id,
                $e,
                ['mimeType' => $mimeType],
            );

            return PreprocessResult::passthrough($rawBytes, $mimeType, 'exception');
        } finally {
            if ($inputPath !== null && file_exists($inputPath)) {
                @unlink($inputPath);
            }
            if ($outputPath !== null && file_exists($outputPath)) {
                @unlink($outputPath);
            }
        }
    }

    /**
     * Primarily for tests: resets the cached driver + warning state so each
     * test can exercise the driver-absent branch independently.
     */
    public static function resetStaticState(): void
    {
        self::$driverCache = null;
        self::$driverWarningEmitted = false;
    }

    private static function readRawBytes(Asset $asset): ?string
    {
        $stream = $asset->getStream();
        if ($stream === null) {
            return null;
        }

        try {
            $contents = stream_get_contents($stream);
            return $contents === false ? null : $contents;
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }

    private static function hasDriver(): bool
    {
        if (self::$driverCache !== null) {
            return self::$driverCache;
        }

        $images = Craft::$app->getImages();
        $available = $images->getCanUseImagick() || $images->getIsGd();

        if (!$available && !self::$driverWarningEmitted) {
            self::$driverWarningEmitted = true;
            
            Logger::warning(
                LogCategory::AssetProcessing,
                'Image preprocessing disabled: neither Imagick nor GD available',
            );
        }

        return self::$driverCache = $available;
    }

    private static function shouldSkip(
        Asset $asset,
        string $mimeType,
        int $byteLength,
        int $maxDimension,
    ): ?string {
        if ($asset->kind !== Asset::KIND_IMAGE) {
            return 'not_image';
        }

        if (in_array($mimeType, self::UNSUPPORTED_MIME_TYPES, true)) {
            return 'mime_unsupported';
        }

        $extension = strtolower($asset->getExtension() ?? '');
        
        if ($extension !== '' && in_array($extension, self::UNSUPPORTED_EXTENSIONS, true)) {
            return 'raw_format_unsupported';
        }

        if (($asset->size ?? 0) === 0 || $byteLength === 0) {
            return 'empty_file';
        }

        $width = (int) ($asset->getWidth() ?? 0);
        $height = (int) ($asset->getHeight() ?? 0);
        
        if ($width > 0 && $height > 0
            && max($width, $height) <= $maxDimension
            && $byteLength <= self::SKIP_BYTE_THRESHOLD
        ) {
            return 'already_small';
        }

        return null;
    }
}
