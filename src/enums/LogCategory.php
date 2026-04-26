<?php

declare(strict_types=1);

namespace vitordiniz22\craftlens\enums;

use Craft;

enum LogCategory: string
{
    use EnumOptionsTrait;

    case ApiRequest = 'api_request';
    case JobStatus = 'job_status';
    case JobStarted = 'job_started';
    case JobCompleted = 'job_completed';
    case JobFailed = 'job_failed';
    case AssetProcessing = 'asset_processing';
    case Configuration = 'configuration';
    case NormalizationError = 'normalization_error';
    case Duplicate = 'duplicate';
    case SearchIndex = 'search_index';
    case QueryFilter = 'query_filter';
    case Cancellation = 'cancellation';

    public function label(): string
    {
        return match ($this) {
            self::ApiRequest => Craft::t('lens', 'API Request'),
            self::JobStatus => Craft::t('lens', 'Job Status'),
            self::JobStarted => Craft::t('lens', 'Job Started'),
            self::JobCompleted => Craft::t('lens', 'Job Completed'),
            self::JobFailed => Craft::t('lens', 'Job Failed'),
            self::AssetProcessing => Craft::t('lens', 'Asset Processing'),
            self::Configuration => Craft::t('lens', 'Configuration'),
            self::NormalizationError => Craft::t('lens', 'Normalization Error'),
            self::Duplicate => Craft::t('lens', 'Duplicate'),
            self::SearchIndex => Craft::t('lens', 'Search Index'),
            self::QueryFilter => Craft::t('lens', 'Query Filter'),
            self::Cancellation => Craft::t('lens', 'Cancellation'),
        };
    }
}
