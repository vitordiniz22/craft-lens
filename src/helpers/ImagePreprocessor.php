<?php

declare(strict_types=1);

namespace vitordiniz22\craftlens\helpers;

use craft\elements\Asset;
use Imagick;
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

    public static function preprocess(
        AnalysisImageContext $context,
        int $maxDimension = self::DEFAULT_MAX_DIMENSION,
        int $quality = self::DEFAULT_QUALITY,
    ): PreprocessResult {
        // Defensive clamps in case a caller passes an out-of-range value.
        $maxDimension = max(256, min(4096, $maxDimension));
        $quality = max(50, min(100, $quality));

        $asset = $context->asset;
        $mimeType = $asset->getMimeType() ?? 'application/octet-stream';

        $rawBytes = $context->getRawBytes();

        if ($rawBytes === null) {
            return PreprocessResult::passthrough('', $mimeType, 'stream_unavailable');
        }

        if (!extension_loaded('imagick')) {
            return PreprocessResult::passthrough($rawBytes, $mimeType, 'no_driver');
        }

        $skipReason = self::shouldSkip($asset, $mimeType, strlen($rawBytes), $maxDimension);

        if ($skipReason !== null) {
            return PreprocessResult::passthrough($rawBytes, $mimeType, $skipReason);
        }

        $sourceImagick = $context->getImagick();

        if ($sourceImagick === null) {
            return PreprocessResult::passthrough($rawBytes, $mimeType, 'imagick_unavailable');
        }

        $clone = null;

        try {
            // Use the original asset dimensions for the pixel-budget guard and
            // for reporting; the shared context handle is already downscaled.
            $originalWidth = (int) ($asset->getWidth() ?? $sourceImagick->getImageWidth());
            $originalHeight = (int) ($asset->getHeight() ?? $sourceImagick->getImageHeight());

            if ($originalWidth * $originalHeight > self::PIXEL_BUDGET) {
                return PreprocessResult::passthrough($rawBytes, $mimeType, 'pixel_budget_exceeded');
            }

            $keepAsPng = str_starts_with($mimeType, 'image/png');
            $outMime = $keepAsPng ? 'image/png' : 'image/jpeg';

            $clone = clone $sourceImagick;

            if (max($clone->getImageWidth(), $clone->getImageHeight()) > $maxDimension) {
                $clone->resizeImage(
                    $maxDimension,
                    $maxDimension,
                    Imagick::FILTER_LANCZOS,
                    1,
                    true,
                );
            }

            $clone->setImageFormat($keepAsPng ? 'png' : 'jpeg');
            $clone->setImageCompressionQuality($quality);
            $clone->stripImage();

            $processedBytes = $clone->getImageBlob();

            if ($processedBytes === '') {
                return PreprocessResult::passthrough($rawBytes, $mimeType, 'encode_failed');
            }

            return PreprocessResult::processed(
                bytes: $processedBytes,
                mimeType: $outMime,
                originalBytes: strlen($rawBytes),
                processedBytes: strlen($processedBytes),
                originalWidth: $originalWidth,
                originalHeight: $originalHeight,
                processedWidth: $clone->getImageWidth(),
                processedHeight: $clone->getImageHeight(),
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
            $clone?->clear();
        }
    }

    /**
     * Kept for backwards compatibility with existing test setUp() calls.
     * The cached driver state was removed when preprocessing moved to the
     * shared `AnalysisImageContext` Imagick handle.
     */
    public static function resetStaticState(): void
    {
        // No state to reset.
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
