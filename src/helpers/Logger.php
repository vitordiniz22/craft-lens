<?php

declare(strict_types=1);

namespace vitordiniz22\craftlens\helpers;

use vitordiniz22\craftlens\enums\LogCategory;
use vitordiniz22\craftlens\Plugin;
use vitordiniz22\craftlens\services\LogService;

/**
 * Static facade for the LogService.
 *
 * Usage:
 *   Logger::error(LogCategory::ApiRequest, 'Connection failed', assetId: 123, exception: $e);
 *   Logger::warning(LogCategory::JobFailed, 'Asset not found');
 *   Logger::info(LogCategory::AssetProcessing, 'Asset processed successfully', assetId: 42);
 *   Logger::apiCall('openai', 'Analysis completed', assetId: 1, responseTimeMs: 340, httpStatusCode: 200);
 *   Logger::jobFailure('AnalyzeAssetJob', 'Failed', assetId: 1, retryJobData: [...], exception: $e);
 */
class Logger
{
    public static function info(LogCategory $category, string $message, ?int $assetId = null, ?array $context = null): void
    {
        self::service()->info($category->value, $message, $assetId, $context);
    }

    public static function warning(LogCategory $category, string $message, ?int $assetId = null, ?\Throwable $exception = null, ?array $context = null): void
    {
        self::service()->warning($category->value, $message, $assetId, $exception, $context);
    }

    public static function error(LogCategory $category, string $message, ?int $assetId = null, ?\Throwable $exception = null, ?array $context = null): void
    {
        self::service()->error($category->value, $message, $assetId, $exception, $context);
    }

    public static function apiCall(
        string $provider,
        string $message,
        ?int $assetId,
        int $responseTimeMs,
        ?int $httpStatusCode,
        ?int $inputTokens = null,
        ?int $outputTokens = null,
        ?array $requestPayload = null,
        ?array $responsePayload = null,
        string $level = 'info',
    ): void {
        self::service()->logApiCall(
            $provider, $message, $assetId, $responseTimeMs, $httpStatusCode,
            $inputTokens, $outputTokens, $requestPayload, $responsePayload, $level,
        );
    }

    public static function jobFailure(
        string $jobType,
        string $message,
        ?int $assetId = null,
        ?array $retryJobData = null,
        ?\Throwable $exception = null,
    ): void {
        self::service()->logJobFailure($jobType, $message, $assetId, $retryJobData, $exception);
    }

    private static function service(): LogService
    {
        return Plugin::getInstance()->log;
    }
}
