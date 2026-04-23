<?php

declare(strict_types=1);

namespace vitordiniz22\craftlens\enums;

/**
 * Canonical labels for filter fields on the search page.
 *
 * Used by the filter form, the chip bar, and any other surface that needs
 * to display a human-readable name for a filter param. One source of truth
 * keeps form labels and their chips from drifting apart.
 */
enum FilterField: string
{
    case Query = 'query';
    case Tags = 'tags';
    case TagOperator = 'tagOperator';
    case ContainsPeople = 'containsPeople';
    case FaceCountPreset = 'faceCountPreset';
    case QualityIssue = 'qualityIssue';
    case FileSizePreset = 'fileSizePreset';
    case HasTextInImage = 'hasTextInImage';
    case HasWatermark = 'hasWatermark';
    case WatermarkType = 'watermarkType';
    case ContainsBrandLogo = 'containsBrandLogo';
    case Status = 'status';
    case Provider = 'provider';
    case ProviderModel = 'providerModel';
    case NsfwScoreMin = 'nsfwScoreMin';
    case NsfwScoreMax = 'nsfwScoreMax';
    case ProcessedDate = 'processedDate';
    case HasDuplicates = 'hasDuplicates';
    case SimilarTo = 'similarTo';
    case HasFocalPoint = 'hasFocalPoint';
    case MissingAltText = 'missingAltText';
    case Unprocessed = 'unprocessed';

    public function label(): string
    {
        return match ($this) {
            self::Query => 'Search',
            self::Tags => 'Tags',
            self::TagOperator => 'Tag match',
            self::ContainsPeople => 'People',
            self::FaceCountPreset => 'Face Count',
            self::QualityIssue => 'Quality Issue',
            self::FileSizePreset => 'File Size',
            self::HasTextInImage => 'Text in Image',
            self::HasWatermark => 'Watermark',
            self::WatermarkType => 'Watermark Type',
            self::ContainsBrandLogo => 'Brand Logo',
            self::Status => 'Status',
            self::Provider => 'Provider',
            self::ProviderModel => 'Model',
            self::NsfwScoreMin => 'NSFW min',
            self::NsfwScoreMax => 'NSFW max',
            self::ProcessedDate => 'Date Analyzed',
            self::HasDuplicates => 'Duplicates',
            self::SimilarTo => 'Similar to',
            self::HasFocalPoint => 'Focal Point',
            self::MissingAltText => 'Alt Text',
            self::Unprocessed => 'Unprocessed',
        };
    }
}
