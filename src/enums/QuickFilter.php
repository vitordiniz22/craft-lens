<?php

declare(strict_types=1);

namespace vitordiniz22\craftlens\enums;

enum QuickFilter: string
{
    case Untagged = 'untagged';
    case LowConfidence = 'low-confidence';
    case NeedsReview = 'needs-review';
    case WithPeople = 'with-people';
    case NsfwFlagged = 'nsfw-flagged';
    case NsfwCaution = 'nsfw-caution';
    case Recent7d = 'recent-7d';
    case HasDuplicates = 'has-duplicates';

    public function label(): string
    {
        return match ($this) {
            self::Untagged => 'Untagged',
            self::LowConfidence => 'Low Confidence',
            self::NeedsReview => 'Needs Review',
            self::WithPeople => 'With People',
            self::NsfwFlagged => 'NSFW Flagged',
            self::NsfwCaution => 'NSFW Caution',
            self::Recent7d => 'Last 7 Days',
            self::HasDuplicates => 'Has Duplicates',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Untagged => 'tag',
            self::LowConfidence => 'alert',
            self::NeedsReview => 'eye',
            self::WithPeople => 'users',
            self::NsfwFlagged, self::NsfwCaution => 'warning',
            self::Recent7d => 'clock',
            self::HasDuplicates => 'copy',
        };
    }

    /**
     * Apply this quick filter's parameters to a filters array.
     *
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    public function applyToFilters(array $filters): array
    {
        return match ($this) {
            self::Untagged => [...$filters, 'noTags' => true],
            self::LowConfidence => [...$filters, 'confidenceMax' => 0.7],
            self::NeedsReview => [...$filters, 'status' => [AnalysisStatus::PendingReview->value]],
            self::WithPeople => [...$filters, 'containsPeople' => true],
            self::NsfwFlagged => [...$filters, 'nsfwScoreMin' => 0.5],
            self::NsfwCaution => [...$filters, 'nsfwScoreMin' => 0.2, 'nsfwScoreMax' => 0.499],
            self::Recent7d => [...$filters, 'processedFrom' => (new \DateTime())->modify('-7 days')],
            self::HasDuplicates => [...$filters, 'hasDuplicates' => true],
        };
    }

    /**
     * The filter param key this quick filter implicitly sets, used to suppress
     * its individual chip when the quick filter chip is already shown.
     * Returns null when no individual chip corresponds to this quick filter.
     */
    public function derivedParam(): ?string
    {
        return match ($this) {
            self::NeedsReview => 'status',
            self::WithPeople => 'containsPeople',
            self::Recent7d => 'processedFrom',
            self::HasDuplicates => 'hasDuplicates',
            default => null,
        };
    }

    /**
     * Builds a reverse map of [filterParam => quickFilterValue] for chip suppression.
     *
     * @return array<string, string>
     */
    public static function derivedParamsMap(): array
    {
        $map = [];

        foreach (self::cases() as $case) {
            $param = $case->derivedParam();
            if ($param !== null) {
                $map[$param] = $case->value;
            }
        }

        return $map;
    }
}
