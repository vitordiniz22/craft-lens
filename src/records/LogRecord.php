<?php

declare(strict_types=1);

namespace vitordiniz22\craftlens\records;

use craft\db\ActiveRecord;
use craft\records\Asset;
use vitordiniz22\craftlens\enums\LogCategory;
use vitordiniz22\craftlens\enums\LogLevel;
use vitordiniz22\craftlens\migrations\Install;
use yii\db\ActiveQueryInterface;

/**
 * Log record for tracking plugin events, errors, and API calls.
 *
 * @property int $id
 * @property string $level
 * @property string $category
 * @property string $message
 * @property int|null $assetId
 * @property string|null $provider
 * @property string|null $jobType
 * @property bool $isRetryable
 * @property array|null $retryJobData
 * @property int|null $httpStatusCode
 * @property int|null $responseTimeMs
 * @property int|null $inputTokens
 * @property int|null $outputTokens
 * @property array|null $requestPayload
 * @property array|null $responsePayload
 * @property string|null $stackTrace
 * @property array|null $context
 * @property \DateTime $dateCreated
 * @property \DateTime $dateUpdated
 * @property string $uid
 * @property-read Asset|null $asset
 */
class LogRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return Install::TABLE_LOGS;
    }

    public function getAsset(): ActiveQueryInterface
    {
        return $this->hasOne(Asset::class, ['id' => 'assetId']);
    }

    public function rules(): array
    {
        return [
            [['level', 'category', 'message'], 'required'],
            [['level'], 'in', 'range' => array_column(LogLevel::cases(), 'value')],
            [['category'], 'in', 'range' => array_column(LogCategory::cases(), 'value')],
            [['assetId', 'httpStatusCode', 'responseTimeMs', 'inputTokens', 'outputTokens'], 'integer'],
            [['isRetryable'], 'boolean'],
        ];
    }
}
