<?php

declare(strict_types=1);

namespace vitordiniz22\craftlens\exceptions;

use Exception;

/**
 * Exception thrown when image analysis fails.
 */
class AnalysisException extends Exception
{
    public function __construct(
        string $message,
        public readonly ?string $provider = null,
        public readonly ?int $assetId = null,
        public readonly ?int $statusCode = null,
        public readonly ?string $userMessage = null,
        int $code = 0,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $code, $previous);
    }

    /**
     * Get user-friendly error message suitable for display in UI
     */
    public function getUserMessage(): string
    {
        return $this->userMessage ?? $this->getMessage();
    }

    public static function apiError(string $provider, string $message, ?int $assetId = null): self
    {
        return new self(
            message: "API error from {$provider}: {$message}",
            provider: $provider,
            assetId: $assetId,
        );
    }

    public static function invalidResponse(string $provider, ?int $assetId = null, ?string $detail = null): self
    {
        $message = "Invalid response from {$provider}";
        if ($detail !== null) {
            $message .= ": {$detail}";
        }

        return new self(
            message: $message,
            provider: $provider,
            assetId: $assetId,
        );
    }

    public static function assetNotReadable(int $assetId): self
    {
        return new self(
            message: "Asset {$assetId} is not readable or does not exist",
            assetId: $assetId,
        );
    }

    public static function fileTooLarge(
        string $providerName,
        int $assetId,
        int $fileSize,
        int $maxSize
    ): self {
        $fileSizeMB = round($fileSize / 1024 / 1024, 1);
        $maxSizeMB = round($maxSize / 1024 / 1024, 1);

        $technicalMessage = "File size ({$fileSizeMB}MB) exceeds {$providerName} limit ({$maxSizeMB}MB)";

        $userMessage = "This image is {$fileSizeMB}MB, which is too large for {$providerName}. " .
                       "The maximum allowed size is {$maxSizeMB}MB. " .
                       "Please resize the image or try a different AI provider.";

        return new self(
            message: $technicalMessage,
            provider: $providerName,
            assetId: $assetId,
            statusCode: 413, // HTTP 413 Payload Too Large
            userMessage: $userMessage
        );
    }
}
