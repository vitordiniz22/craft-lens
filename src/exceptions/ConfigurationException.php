<?php

declare(strict_types=1);

namespace vitordiniz22\craftlens\exceptions;

use Exception;

/**
 * Exception thrown when plugin configuration is invalid.
 */
class ConfigurationException extends Exception
{
    public static function missingApiKey(string $provider): self
    {
        return new self("API key for {$provider} is not configured.");
    }

    public static function invalidApiKey(string $provider): self
    {
        return new self("API key for {$provider} is invalid.");
    }

    public static function missingField(string $fieldName): self
    {
        return new self("Required field '{$fieldName}' is not configured");
    }

}
