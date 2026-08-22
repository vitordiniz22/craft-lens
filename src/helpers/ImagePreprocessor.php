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
 * The helper never throws. Images that cannot or need not be transformed are
 * marked for provider-aware original-file checks before any bytes are read.
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
     * exceed this are rejected as decompression bombs.
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
     * Extensions Imagick handles unreliably. Raw camera
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
        return self::process($context, $maxDimension, $quality);
    }

    private static function process(
        AnalysisImageContext $context,
        int $maxDimension,
        int $quality,
    ): PreprocessResult {
        // Defensive clamps in case a caller passes an out-of-range value.
        $maxDimension = max(256, min(4096, $maxDimension));
        $quality = max(50, min(100, $quality));

        $asset = $context->asset;
        $mimeType = $asset->getMimeType() ?? 'application/octet-stream';

        if ($context->getLocalPath() === null) {
            return PreprocessResult::failed($mimeType, 'stream_unavailable');
        }

        if (!extension_loaded('imagick')) {
            return PreprocessResult::original($mimeType, 'no_driver');
        }

        $skipReason = self::shouldSkip($asset, $mimeType, $maxDimension);

        if ($skipReason !== null) {
            return PreprocessResult::original($mimeType, $skipReason);
        }

        $sourceImagick = $context->getWorkingImagick();

        if ($sourceImagick === null) {
            return PreprocessResult::original($mimeType, 'normalization_failed');
        }

        $clone = null;

        try {
            // Use the original asset dimensions for the pixel-budget guard and
            // for reporting; the shared context handle is already downscaled.
            $originalWidth = (int) ($asset->getWidth() ?? $sourceImagick->getImageWidth());
            $originalHeight = (int) ($asset->getHeight() ?? $sourceImagick->getImageHeight());

            if ($originalWidth * $originalHeight > self::PIXEL_BUDGET) {
                return PreprocessResult::original($mimeType, 'pixel_budget_exceeded');
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
                return PreprocessResult::original($mimeType, 'encode_failed');
            }

            return PreprocessResult::processed(
                bytes: $processedBytes,
                mimeType: $outMime,
                originalBytes: (int) ($asset->size ?? 0),
                processedBytes: strlen($processedBytes),
                originalWidth: $originalWidth,
                originalHeight: $originalHeight,
                processedWidth: $clone->getImageWidth(),
                processedHeight: $clone->getImageHeight(),
            );
        } catch (\Throwable $e) {
            Logger::warning(
                LogCategory::AssetProcessing,
                'Image preprocessing failed',
                $asset->id,
                $e,
                ['mimeType' => $mimeType],
            );

            return PreprocessResult::original($mimeType, 'exception');
        } finally {
            $clone?->clear();
        }
    }

    /**
     * Passthrough ships the file as-is while the asset type comes from its
     * extension. Providers validate the declared type against the payload, so
     * a JPEG saved as .png would be rejected.
     */
    public static function detectMimeType(string $bytes, string $fallback): string
    {
        if ($bytes === '' || !class_exists(\finfo::class)) {
            return $fallback;
        }

        $detected = (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes);

        return is_string($detected) && str_starts_with($detected, 'image/') ? $detected : $fallback;
    }

    private static function shouldSkip(
        Asset $asset,
        string $mimeType,
        int $maxDimension,
    ): ?string {
        if ($asset->kind !== Asset::KIND_IMAGE) {
            return 'not_image';
        }

        if (in_array($mimeType, self::UNSUPPORTED_MIME_TYPES, true)) {
            return 'mime_unsupported';
        }

        $extension = strtolower($asset->getExtension());

        if ($extension !== '' && in_array($extension, self::UNSUPPORTED_EXTENSIONS, true)) {
            return 'raw_format_unsupported';
        }

        $byteLength = (int) ($asset->size ?? 0);

        if ($byteLength === 0) {
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
