<?php

declare(strict_types=1);

namespace vitordiniz22\craftlens\helpers;

/**
 * Result of an image preprocessing attempt.
 *
 * Carries either the processed bytes (when resize + recompress succeeded)
 * or the original bytes plus a reason (when preprocessing was skipped or
 * failed). Callers read `bytes` / `mimeType` unconditionally and consult
 * `wasProcessed` / `reason` only for logging.
 */
final readonly class PreprocessResult
{
    private function __construct(
        public string $bytes,
        public string $mimeType,
        public bool $wasProcessed,
        public ?string $reason,
        public ?int $originalBytes,
        public ?int $processedBytes,
        public ?int $originalWidth,
        public ?int $originalHeight,
        public ?int $processedWidth,
        public ?int $processedHeight,
    ) {
    }

    public static function passthrough(
        string $bytes,
        string $mimeType,
        ?string $reason = null,
    ): self {
        return new self(
            bytes: $bytes,
            mimeType: $mimeType,
            wasProcessed: false,
            reason: $reason,
            originalBytes: null,
            processedBytes: null,
            originalWidth: null,
            originalHeight: null,
            processedWidth: null,
            processedHeight: null,
        );
    }

    public static function processed(
        string $bytes,
        string $mimeType,
        int $originalBytes,
        int $processedBytes,
        int $originalWidth,
        int $originalHeight,
        int $processedWidth,
        int $processedHeight,
    ): self {
        return new self(
            bytes: $bytes,
            mimeType: $mimeType,
            wasProcessed: true,
            reason: null,
            originalBytes: $originalBytes,
            processedBytes: $processedBytes,
            originalWidth: $originalWidth,
            originalHeight: $originalHeight,
            processedWidth: $processedWidth,
            processedHeight: $processedHeight,
        );
    }
}
