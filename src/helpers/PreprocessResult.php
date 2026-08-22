<?php

declare(strict_types=1);

namespace vitordiniz22\craftlens\helpers;

/**
 * Result of an image preprocessing attempt.
 *
 * Carries processed bytes, an intentional passthrough, a deferred original
 * request, or an empty payload with a reason when preparation failed.
 */
final readonly class PreprocessResult
{
    private function __construct(
        public string $bytes,
        public string $mimeType,
        public bool $wasProcessed,
        public bool $useOriginal,
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
            useOriginal: false,
            reason: $reason,
            originalBytes: null,
            processedBytes: null,
            originalWidth: null,
            originalHeight: null,
            processedWidth: null,
            processedHeight: null,
        );
    }

    public static function original(string $mimeType, string $reason): self
    {
        return new self(
            bytes: '',
            mimeType: $mimeType,
            wasProcessed: false,
            useOriginal: true,
            reason: $reason,
            originalBytes: null,
            processedBytes: null,
            originalWidth: null,
            originalHeight: null,
            processedWidth: null,
            processedHeight: null,
        );
    }

    public static function failed(string $mimeType, string $reason): self
    {
        return self::passthrough('', $mimeType, $reason);
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
            useOriginal: false,
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
