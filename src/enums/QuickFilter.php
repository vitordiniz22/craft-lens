<?php

declare(strict_types=1);

namespace vitordiniz22\craftlens\enums;

enum QuickFilter: string
{
    case NeedsReview = 'needs-review';
    case MissingAltText = 'missing-alt-text';
    case Unprocessed = 'unprocessed';
    case Failed = 'failed';
    case WithPeople = 'with-people';
    case HasWatermark = 'has-watermark';
    case Nsfw = 'nsfw';
    case HasDuplicates = 'has-duplicates';
    case NeedsFocalPoint = 'needs-focal-point';

    public function label(): string
    {
        return match ($this) {
            self::NeedsReview => 'Needs Review',
            self::MissingAltText => 'Missing Alt Text',
            self::Unprocessed => 'Unprocessed',
            self::Failed => 'Failed',
            self::WithPeople => 'With People',
            self::HasWatermark => 'Has Watermark',
            self::Nsfw => 'NSFW',
            self::HasDuplicates => 'Has Duplicates',
            self::NeedsFocalPoint => 'Needs Focal Point',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::NeedsReview => 'eye',
            self::MissingAltText => 'universal-access',
            self::Unprocessed => 'clock',
            self::Failed => 'triangle-exclamation',
            self::WithPeople => 'users',
            self::HasWatermark => 'stamp',
            self::Nsfw => 'warning',
            self::HasDuplicates => 'copy',
            self::NeedsFocalPoint => 'crosshairs',
        };
    }

    /**
     * The raw filter params this preset represents. Used both for URL
     * generation (clicking the button) and activation detection.
     *
     * @return array<string, mixed>
     */
    public function params(): array
    {
        return match ($this) {
            self::NeedsReview => ['status' => [AnalysisStatus::PendingReview->value]],
            self::MissingAltText => ['missingAltText' => true],
            self::Unprocessed => ['unprocessed' => true],
            self::Failed => ['status' => [AnalysisStatus::Failed->value]],
            self::WithPeople => ['containsPeople' => true],
            self::HasWatermark => ['hasWatermark' => true],
            self::Nsfw => ['nsfwScoreMin' => 0.5],
            self::HasDuplicates => ['hasDuplicates' => true],
            self::NeedsFocalPoint => ['hasFocalPoint' => false],
        };
    }

    /**
     * True when the given filters contain every param this preset sets with matching values.
     *
     * @param array<string, mixed> $filters
     */
    public function matches(array $filters): bool
    {
        foreach ($this->params() as $key => $value) {
            if (!array_key_exists($key, $filters)) {
                return false;
            }

            if ($filters[$key] != $value) {
                return false;
            }
        }

        return true;
    }
}
