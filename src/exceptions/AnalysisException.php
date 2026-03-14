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

    public static function apiError(string $provider, string $message, ?int $assetId = null, ?int $statusCode = null): self
    {
        return new self(
            message: "API error from {$provider}: {$message}",
            provider: $provider,
            assetId: $assetId,
            statusCode: $statusCode,
            userMessage: self::buildUserMessage($provider, $message, $statusCode),
        );
    }

    /**
     * Build a user-friendly error message based on error patterns.
     */
    private static function buildUserMessage(string $provider, string $message, ?int $statusCode): string
    {
        if ($statusCode !== null) {
            return match ($statusCode) {
                401 => "Your {$provider} API key appears to be invalid or expired. Please check your API key in the plugin settings.",
                403 => "Access denied by {$provider}. Please verify your API key has the correct permissions.",
                404 => "The AI model configured for {$provider} was not found. Please check your model settings.",
                429 => "{$provider} rate limit exceeded. Please wait a moment and try again.",
                413 => "The image is too large for {$provider} to process. Please resize or compress the image.",
                500, 502, 503 => "{$provider} is temporarily unavailable. Please try again in a few minutes.",
                default => "Analysis failed due to an error with {$provider}. Please try again or check the logs for more details.",
            };
        }

        $lowerMessage = strtolower($message);

        if (str_contains($lowerMessage, 'timed out') || str_contains($lowerMessage, 'timeout')) {
            return "The request to {$provider} timed out. This usually means the service is slow or temporarily overloaded. Please try again in a few moments.";
        }

        if (str_contains($lowerMessage, 'connection failed') || str_contains($lowerMessage, 'could not resolve') || str_contains($lowerMessage, 'connection refused')) {
            return "Could not connect to {$provider}. Please check your internet connection and try again.";
        }

        return "Analysis failed due to an error with {$provider}. Please try again or check the logs for more details.";
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
            userMessage: "{$provider} returned an unexpected response that couldn't be processed. This is usually a temporary issue. Please try again.",
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
        int $maxSize,
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
