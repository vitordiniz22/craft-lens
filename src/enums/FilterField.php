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
    case ContainsPeople = 'containsPeople';
    case FaceCountPreset = 'faceCountPreset';
    case QualityIssues = 'qualityIssues';
    case IsTooLarge = 'isTooLarge';
    case IsBlurry = 'isBlurry';
    case IsTooDark = 'isTooDark';
    case HasTextInImage = 'hasTextInImage';
    case HasWatermark = 'hasWatermark';
    case ContainsBrandLogo = 'containsBrandLogo';
    case Color = 'color';
    case Status = 'status';
    case ConfidenceMax = 'confidenceMax';
    case NsfwScoreMin = 'nsfwScoreMin';
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
            self::ContainsPeople => 'People',
            self::FaceCountPreset => 'Face Count',
            self::QualityIssues => 'Quality Issues',
            self::IsTooLarge => 'File Too Large',
            self::IsBlurry => 'Blurry',
            self::IsTooDark => 'Too Dark',
            self::HasTextInImage => 'Text in Image',
            self::HasWatermark => 'Watermark',
            self::ContainsBrandLogo => 'Brand Logo',
            self::Color => 'Color',
            self::Status => 'Status',
            self::ConfidenceMax => 'Confidence',
            self::NsfwScoreMin => 'NSFW',
            self::ProcessedDate => 'Date Analyzed',
            self::HasDuplicates => 'Duplicates',
            self::SimilarTo => 'Similar to',
            self::HasFocalPoint => 'Focal Point',
            self::MissingAltText => 'Alt Text',
            self::Unprocessed => 'Unprocessed',
        };
    }
}
