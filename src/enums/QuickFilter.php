<?php

declare(strict_types=1);

namespace vitordiniz22\craftlens\enums;

enum QuickFilter: string
{
    case LowConfidence = 'low-confidence';
    case NeedsReview = 'needs-review';
    case MissingAltText = 'missing-alt-text';
    case WithPeople = 'with-people';
    case Nsfw = 'nsfw';
    case HasDuplicates = 'has-duplicates';

    public function label(): string
    {
        return match ($this) {
            self::LowConfidence => 'Low Confidence',
            self::NeedsReview => 'Needs Review',
            self::MissingAltText => 'Missing Alt Text',
            self::WithPeople => 'With People',
            self::Nsfw => 'NSFW',
            self::HasDuplicates => 'Has Duplicates',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::LowConfidence => 'alert',
            self::NeedsReview => 'eye',
            self::MissingAltText => 'universal-access',
            self::WithPeople => 'users',
            self::Nsfw => 'warning',
            self::HasDuplicates => 'copy',
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
            self::LowConfidence => ['confidenceMax' => 0.7],
            self::NeedsReview => ['status' => [AnalysisStatus::PendingReview->value]],
            self::MissingAltText => ['missingAltText' => true],
            self::WithPeople => ['containsPeople' => true],
            self::Nsfw => ['nsfwScoreMin' => 0.5],
            self::HasDuplicates => ['hasDuplicates' => true],
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
