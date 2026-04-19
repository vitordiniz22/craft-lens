<?php

declare(strict_types=1);

namespace vitordiniz22\craftlens\exceptions;

use Exception;
use vitordiniz22\craftlens\enums\ErrorCode;

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
        public readonly ?ErrorCode $errorCode = null,
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
            errorCode: self::codeFor($statusCode, $message),
        );
    }

    /**
     * Build a user-friendly error message based on error patterns.
     */
    private static function buildUserMessage(string $provider, string $message, ?int $statusCode): string
    {
        if ($statusCode !== null) {
            return match ($statusCode) {
                401 => "Invalid or expired {$provider} API key. Check your key in Settings.",
                403 => "Access denied by {$provider}. Verify your API key permissions in Settings.",
                404 => "The AI model configured for {$provider} was not found. Check your model in Settings.",
                429 => "Too many requests to {$provider}. Wait a moment and try again.",
                413 => "This image is too large for {$provider}. Resize or compress the image and try again.",
                500, 502, 503 => "{$provider} is temporarily unavailable. Try again in a few minutes.",
                default => "Unexpected error from {$provider} (HTTP {$statusCode}). Try again or check the logs.",
            };
        }

        $lowerMessage = strtolower($message);

        if (str_contains($lowerMessage, 'timed out') || str_contains($lowerMessage, 'timeout')) {
            return "The request to {$provider} timed out. The service may be overloaded. Try again in a few moments.";
        }

        if (str_contains($lowerMessage, 'connection failed') || str_contains($lowerMessage, 'could not resolve') || str_contains($lowerMessage, 'connection refused')) {
            return "Could not connect to {$provider}. Check your internet connection and try again.";
        }

        return "Unexpected error from {$provider}. Try again or check the logs.";
    }

    /**
     * Map an HTTP status and/or raw message to a stable ErrorCode.
     */
    private static function codeFor(?int $statusCode, string $message): ErrorCode
    {
        if ($statusCode !== null) {
            return match ($statusCode) {
                401 => ErrorCode::InvalidApiKey,
                403 => ErrorCode::AccessDenied,
                404 => ErrorCode::ModelNotFound,
                413 => ErrorCode::FileTooLarge,
                429 => ErrorCode::RateLimit,
                500, 502, 503 => ErrorCode::ProviderUnavailable,
                default => ErrorCode::Unknown,
            };
        }

        $lower = strtolower($message);

        if (str_contains($lower, 'timed out') || str_contains($lower, 'timeout')) {
            return ErrorCode::Timeout;
        }

        if (str_contains($lower, 'connection failed') || str_contains($lower, 'could not resolve') || str_contains($lower, 'connection refused')) {
            return ErrorCode::ConnectionFailed;
        }

        return ErrorCode::Unknown;
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
            userMessage: "{$provider} returned an unreadable response. Try again. If this keeps happening, try a different model or provider.",
            errorCode: ErrorCode::InvalidResponse,
        );
    }

    public static function assetNotReadable(int $assetId): self
    {
        return new self(
            message: "Asset {$assetId} is not readable or does not exist",
            assetId: $assetId,
            userMessage: "The asset could not be read from storage. The file may be missing or corrupted.",
            errorCode: ErrorCode::AssetNotReadable,
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
            statusCode: 413,
            userMessage: $userMessage,
            errorCode: ErrorCode::FileTooLarge,
        );
    }
}
