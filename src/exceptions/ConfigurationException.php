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
        return new self("The API key for {$provider} is not configured. Please add your API key in the plugin settings.");
    }

    public static function invalidApiKey(string $provider): self
    {
        return new self("The API key for {$provider} appears to be invalid. Please check your API key in the plugin settings.");
    }

    public static function missingField(string $fieldName): self
    {
        return new self("Required field '{$fieldName}' is not configured");
    }
}
