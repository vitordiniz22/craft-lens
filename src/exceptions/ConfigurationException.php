<?php

declare(strict_types=1);

namespace vitordiniz22\craftlens\exceptions;

use Exception;
use vitordiniz22\craftlens\enums\ErrorCode;

/**
 * Exception thrown when plugin configuration is invalid.
 */
class ConfigurationException extends Exception
{
    public function __construct(
        string $message,
        public readonly ?ErrorCode $errorCode = null,
        int $code = 0,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $code, $previous);
    }

    public static function missingApiKey(string $provider): self
    {
        return new self(
            "The API key for {$provider} is not configured. Please add your API key in the plugin settings.",
            ErrorCode::MissingApiKey,
        );
    }

    public static function invalidApiKey(string $provider): self
    {
        return new self(
            "The API key for {$provider} appears to be invalid. Please check your API key in the plugin settings.",
            ErrorCode::InvalidApiKey,
        );
    }

    public static function missingField(string $fieldName): self
    {
        return new self(
            "Required field '{$fieldName}' is not configured",
            ErrorCode::MissingConfigField,
        );
    }

    public static function apiKeyInvalidForProvider(string $provider): self
    {
        return new self(
            "Your {$provider} API key is invalid. Please check your credentials in the plugin settings.",
            ErrorCode::InvalidApiKey,
        );
    }

    public static function rateLimitExceededForProvider(string $provider): self
    {
        return new self(
            "{$provider} rate limit exceeded. Please wait a moment and try again.",
            ErrorCode::RateLimit,
        );
    }

    public static function providerUnavailable(string $provider, ?int $statusCode = null): self
    {
        $suffix = $statusCode !== null ? " (HTTP {$statusCode})" : '';

        return new self(
            "{$provider} is experiencing issues{$suffix}. Please try again later.",
            ErrorCode::ProviderUnavailable,
        );
    }

    public static function connectionFailed(string $provider): self
    {
        return new self(
            "Could not connect to {$provider}. Please check your internet connection and plugin settings.",
            ErrorCode::ConnectionFailed,
        );
    }

    public static function providerNotRegistered(string $name): self
    {
        return new self(
            "AI provider '{$name}' is not registered",
            ErrorCode::ProviderNotRegistered,
        );
    }
}
