<?php

declare(strict_types=1);

namespace vitordiniz22\craftlens\services;

use Craft;
use craft\helpers\Queue;
use vitordiniz22\craftlens\enums\LogCategory;
use vitordiniz22\craftlens\enums\LogLevel;
use vitordiniz22\craftlens\helpers\Logger;
use vitordiniz22\craftlens\jobs\AnalyzeAssetJob;
use vitordiniz22\craftlens\jobs\BulkAnalyzeAssetsJob;
use vitordiniz22\craftlens\Plugin;
use vitordiniz22\craftlens\records\LogRecord;
use yii\base\Component;

/**
 * Service for structured logging to both the database and Craft's file logger.
 *
 * All log methods write to Craft's native logger first, then persist to the
 * lens_logs table. Database failures never break the main pipeline.
 */
class LogService extends Component
{
    /**
     * Job classes that are allowed to be retried from log entries.
     */
    private const RETRYABLE_JOB_CLASSES = [
        AnalyzeAssetJob::class,
        BulkAnalyzeAssetsJob::class,
    ];
    /**
     * Core log method. Writes to Craft's file log, then to the database.
     * Dev-only fields are silently ignored when not in dev mode.
     */
    public function log(
        string $level,
        string $category,
        string $message,
        ?int $assetId = null,
        ?string $provider = null,
        ?string $jobType = null,
        bool $isRetryable = false,
        ?array $retryJobData = null,
        ?int $httpStatusCode = null,
        ?int $responseTimeMs = null,
        ?int $inputTokens = null,
        ?int $outputTokens = null,
        ?array $requestPayload = null,
        ?array $responsePayload = null,
        ?string $stackTrace = null,
        ?array $context = null,
    ): void {
        $this->forwardToCraftLogger($level, $message, $category, $assetId, $stackTrace, $context);

        if (!Plugin::isDevInstall()) {
            return;
        }

        $record = new LogRecord();
        $record->level = $level;
        $record->category = $category;
        $record->message = $message;
        $record->assetId = $assetId;
        $record->provider = $provider;
        $record->jobType = $jobType;
        $record->isRetryable = $isRetryable;
        $record->retryJobData = $retryJobData;
        $record->stackTrace = $stackTrace;
        $record->context = $context;

        $record->httpStatusCode = $httpStatusCode;
        $record->responseTimeMs = $responseTimeMs;
        $record->inputTokens = $inputTokens;
        $record->outputTokens = $outputTokens;
        $record->requestPayload = $requestPayload;
        $record->responsePayload = $responsePayload;

        $record->save(false);
    }

    public function info(string $category, string $message, ?int $assetId = null, ?array $context = null): void
    {
        $this->log(LogLevel::Info->value, $category, $message, assetId: $assetId, context: $context);
    }

    public function warning(string $category, string $message, ?int $assetId = null, ?\Throwable $exception = null, ?array $context = null): void
    {
        $this->log(
            LogLevel::Warning->value,
            $category,
            $message,
            assetId: $assetId,
            stackTrace: $exception?->getTraceAsString(),
            context: $context,
        );
    }

    public function error(string $category, string $message, ?int $assetId = null, ?\Throwable $exception = null, ?array $context = null): void
    {
        $this->log(
            LogLevel::Error->value,
            $category,
            $message,
            assetId: $assetId,
            stackTrace: $exception?->getTraceAsString(),
            context: $context,
        );
    }

    /**
     * Log an API call with timing, tokens, and optional payloads.
     */
    public function logApiCall(
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
        $this->log(
            level: $level,
            category: LogCategory::ApiRequest->value,
            message: $message,
            assetId: $assetId,
            provider: $provider,
            httpStatusCode: $httpStatusCode,
            responseTimeMs: $responseTimeMs,
            inputTokens: $inputTokens,
            outputTokens: $outputTokens,
            requestPayload: $requestPayload,
            responsePayload: $responsePayload,
        );
    }

    /**
     * Log a failed job with retry capability.
     */
    public function logJobFailure(
        string $jobType,
        string $message,
        ?int $assetId = null,
        ?array $retryJobData = null,
        ?\Throwable $exception = null,
    ): void {
        $this->log(
            level: LogLevel::Error->value,
            category: LogCategory::JobFailed->value,
            message: $message,
            assetId: $assetId,
            jobType: $jobType,
            isRetryable: $retryJobData !== null,
            retryJobData: $retryJobData,
            stackTrace: $exception?->getTraceAsString(),
            context: $exception !== null ? ['exceptionClass' => get_class($exception)] : null,
        );
    }

    /**
     * Get paginated logs with optional filters.
     */
    public function getLogs(
        ?string $level = null,
        ?string $category = null,
        ?int $assetId = null,
        int $page = 1,
        int $perPage = 50,
    ): array {
        $query = LogRecord::find()->orderBy(['dateCreated' => SORT_DESC]);

        if ($level !== null) {
            $query->andWhere(['level' => $level]);
        }

        if ($category !== null) {
            $query->andWhere(['category' => $category]);
        }

        if ($assetId !== null) {
            $query->andWhere(['assetId' => $assetId]);
        }

        if (!Plugin::isDevInstall()) {
            $query->select([
                'id', 'level', 'category', 'message', 'assetId',
                'provider', 'jobType', 'isRetryable', 'retryJobData',
                'stackTrace', 'context',
                'dateCreated', 'dateUpdated', 'uid',
            ]);
        }

        $total = (int) $query->count();
        $records = $query->offset(($page - 1) * $perPage)->limit($perPage)->all();

        return [
            'logs' => $records,
            'total' => $total,
            'page' => $page,
            'perPage' => $perPage,
            'totalPages' => max(1, (int) ceil($total / $perPage)),
        ];
    }

    /**
     * Get count of error/critical logs in the last N hours (for badge display).
     */
    public function getRecentErrorCount(int $hours = 24): int
    {
        $since = (new \DateTime())->modify("-{$hours} hours")->format('Y-m-d H:i:s');

        return (int) LogRecord::find()
            ->where(['in', 'level', [LogLevel::Error->value, LogLevel::Critical->value]])
            ->andWhere(['>=', 'dateCreated', $since])
            ->count();
    }

    /**
     * Delete all log entries unconditionally.
     */
    public function deleteAll(): int
    {
        return LogRecord::deleteAll();
    }

    /**
     * Delete logs older than the given number of days.
     */
    public function cleanup(int $retainDays = 30): int
    {
        $cutoff = (new \DateTime())->modify("-{$retainDays} days")->format('Y-m-d H:i:s');

        return LogRecord::deleteAll(['<', 'dateCreated', $cutoff]);
    }

    /**
     * Retry a failed job from a log entry.
     */
    public function retryFromLog(int $logId): bool
    {
        $record = LogRecord::findOne($logId);

        if ($record === null || !$record->isRetryable || $record->retryJobData === null) {
            return false;
        }

        $data = is_string($record->retryJobData)
            ? json_decode($record->retryJobData, true)
            : $record->retryJobData;

        if (!is_array($data)) {
            Logger::warning(LogCategory::JobFailed, 'Invalid JSON in retry job data', context: ['logId' => $logId]);
            return false;
        }

        $jobClass = $data['class'] ?? null;
        $jobParams = $data['params'] ?? [];

        if ($jobClass === null || !in_array($jobClass, self::RETRYABLE_JOB_CLASSES, true)) {
            Logger::warning(LogCategory::JobFailed->value, "Refused to retry unknown job class: {$jobClass}");
            return false;
        }

        Queue::push(new $jobClass($jobParams));

        $this->info(
            LogCategory::JobStarted->value,
            "Retried job {$jobClass} from log entry #{$logId}",
            assetId: $jobParams['assetId'] ?? null,
        );

        return true;
    }

    private function forwardToCraftLogger(
        string $level,
        string $message,
        ?string $category = null,
        ?int $assetId = null,
        ?string $stackTrace = null,
        ?array $context = null,
    ): void {
        $parts = [];
        if ($category !== null) {
            $parts[] = "[{$category}]";
        }
        $parts[] = $message;
        if ($assetId !== null) {
            $parts[] = "(asset:{$assetId})";
        }
        if (!empty($context)) {
            $parts[] = json_encode($context, JSON_UNESCAPED_SLASHES);
        }
        if ($stackTrace !== null) {
            $parts[] = "\n" . $stackTrace;
        }

        $formatted = implode(' ', $parts);

        match ($level) {
            LogLevel::Info->value => Craft::info($formatted, 'lens'),
            LogLevel::Warning->value => Craft::warning($formatted, 'lens'),
            LogLevel::Error->value, LogLevel::Critical->value => Craft::error($formatted, 'lens'),
            default => Craft::info($formatted, 'lens'),
        };
    }
}
