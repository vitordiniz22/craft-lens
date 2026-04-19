<?php

declare(strict_types=1);

namespace vitordiniz22\craftlens\enums;

use Craft;

enum ErrorCode: string
{
    use EnumOptionsTrait;

    case FileTooLarge = 'file_too_large';
    case RateLimit = 'rate_limit';
    case InvalidApiKey = 'invalid_api_key';
    case AccessDenied = 'access_denied';
    case ModelNotFound = 'model_not_found';
    case ProviderUnavailable = 'provider_unavailable';
    case Timeout = 'timeout';
    case ConnectionFailed = 'connection_failed';
    case InvalidResponse = 'invalid_response';
    case AssetNotReadable = 'asset_not_readable';
    case MissingApiKey = 'missing_api_key';
    case MissingConfigField = 'missing_config_field';
    case ProviderNotRegistered = 'provider_not_registered';
    case Unknown = 'unknown';

    public function label(): string
    {
        return match ($this) {
            self::FileTooLarge => Craft::t('lens', 'File too large'),
            self::RateLimit => Craft::t('lens', 'Rate limit reached'),
            self::InvalidApiKey => Craft::t('lens', 'Invalid API key'),
            self::AccessDenied => Craft::t('lens', 'Access denied'),
            self::ModelNotFound => Craft::t('lens', 'AI model not found'),
            self::ProviderUnavailable => Craft::t('lens', 'Provider unavailable'),
            self::Timeout => Craft::t('lens', 'Request timed out'),
            self::ConnectionFailed => Craft::t('lens', 'Connection failed'),
            self::InvalidResponse => Craft::t('lens', 'Invalid response'),
            self::AssetNotReadable => Craft::t('lens', 'Asset not readable'),
            self::MissingApiKey => Craft::t('lens', 'Missing API key'),
            self::MissingConfigField => Craft::t('lens', 'Missing configuration'),
            self::ProviderNotRegistered => Craft::t('lens', 'Provider not registered'),
            self::Unknown => Craft::t('lens', 'Unexpected error'),
        };
    }

    public function groupMessage(): string
    {
        return match ($this) {
            self::FileTooLarge => Craft::t('lens', 'These images exceed the AI provider\'s maximum file size. Resize them or switch to a provider with a higher limit.'),
            self::RateLimit => Craft::t('lens', 'The AI provider is rate limiting requests. Wait a moment, then retry the failed assets.'),
            self::InvalidApiKey => Craft::t('lens', 'The API key was rejected by the AI provider. Check your key in Settings.'),
            self::AccessDenied => Craft::t('lens', 'The AI provider denied access for this key. Verify the key\'s permissions in Settings.'),
            self::ModelNotFound => Craft::t('lens', 'The AI model configured for this provider was not found. Pick a valid model in Settings.'),
            self::ProviderUnavailable => Craft::t('lens', 'The AI provider is temporarily unavailable. Try again in a few minutes.'),
            self::Timeout => Craft::t('lens', 'The AI provider took too long to respond. The service may be overloaded.'),
            self::ConnectionFailed => Craft::t('lens', 'Could not reach the AI provider. Check your internet connection and try again.'),
            self::InvalidResponse => Craft::t('lens', 'The AI provider returned an unreadable response. Retry, or try a different model or provider.'),
            self::AssetNotReadable => Craft::t('lens', 'These assets could not be read from storage. The files may be missing or corrupted.'),
            self::MissingApiKey => Craft::t('lens', 'No API key is configured for this AI provider. Add one in Settings before retrying.'),
            self::MissingConfigField => Craft::t('lens', 'A required configuration field is missing. Update your plugin settings and retry.'),
            self::ProviderNotRegistered => Craft::t('lens', 'The configured AI provider is not registered. Pick a supported provider in Settings.'),
            self::Unknown => Craft::t('lens', 'An unexpected error occurred. Check the logs for details.'),
        };
    }

    public function isConfigError(): bool
    {
        return match ($this) {
            self::InvalidApiKey,
            self::AccessDenied,
            self::ModelNotFound,
            self::MissingApiKey,
            self::MissingConfigField,
            self::ProviderNotRegistered => true,
            default => false,
        };
    }

    public function showsAssets(): bool
    {
        return match ($this) {
            self::FileTooLarge,
            self::AssetNotReadable,
            self::InvalidResponse,
            self::Unknown => true,
            default => false,
        };
    }

    public static function fromValueOrUnknown(?string $value): self
    {
        if ($value === null || $value === '') {
            return self::Unknown;
        }

        return self::tryFrom($value) ?? self::Unknown;
    }
}
