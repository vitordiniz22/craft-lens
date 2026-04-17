<?php

declare(strict_types=1);

namespace vitordiniz22\craftlens\enums;

use Craft;

enum AnalysisStatus: string
{
    use EnumOptionsTrait;

    case Pending = 'pending';
    case Processing = 'processing';
    case Completed = 'completed';
    case Failed = 'failed';
    case PendingReview = 'pending_review';
    case Approved = 'approved';
    case Rejected = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::Pending => Craft::t('lens', 'Pending'),
            self::Processing => Craft::t('lens', 'Processing'),
            self::Completed => Craft::t('lens', 'Analyzed'),
            self::Failed => Craft::t('lens', 'Failed'),
            self::PendingReview => Craft::t('lens', 'Pending Review'),
            self::Approved => Craft::t('lens', 'Approved'),
            self::Rejected => Craft::t('lens', 'Rejected'),
        };
    }

    /**
     * Statuses considered "successfully analyzed" (for queries and display).
     * Includes pending_review because those assets have full AI metadata
     * even though the review workflow hasn't approved them yet.
     *
     * @return string[]
     */
    public static function analyzedValues(): array
    {
        return [self::Completed->value, self::PendingReview->value, self::Approved->value];
    }

    /**
     * Statuses where AI metadata exists and can be measured for coverage.
     * Includes pending_review because those assets have tags, alt text, etc.
     * Used by dashboard coverage metrics.
     *
     * @return string[]
     */
    public static function withMetadataValues(): array
    {
        return [self::Completed->value, self::PendingReview->value, self::Approved->value];
    }

    /**
     * Statuses where the AI provider was called and cost was incurred.
     * Used for usage/cost tracking — includes pending_review and rejected
     * assets that analyzedValues() excludes.
     *
     * @return string[]
     */
    public static function processedValues(): array
    {
        return [
            self::Completed->value,
            self::PendingReview->value,
            self::Approved->value,
            self::Rejected->value,
        ];
    }

    /**
     * Statuses where the asset still needs analysis work.
     *
     * This is the positive match set used by unprocessed counts and the browser
     * filter: `status IN unprocessedStatuses()` means "unprocessed." Assets with
     * no analysis record are also unprocessed, handled at each call site.
     *
     * @return string[]
     */
    public static function unprocessedStatuses(): array
    {
        return [
            self::Pending->value,
            self::Failed->value,
            self::Rejected->value,
        ];
    }

    /**
     * Whether an asset in this status should be picked up by analysis.
     */
    public function needsProcessing(): bool
    {
        return in_array($this, [self::Pending, self::Failed, self::Rejected], true);
    }

    /**
     * Whether the analysis has reached a terminal (final) state.
     */
    public function isTerminal(): bool
    {
        return in_array($this, [self::Completed, self::Approved, self::Rejected], true);
    }

    /**
     * Whether the record is available for review actions.
     */
    public function isReviewable(): bool
    {
        return $this === self::PendingReview;
    }
}
