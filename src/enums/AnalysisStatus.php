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
            self::Completed => Craft::t('lens', 'Completed'),
            self::Failed => Craft::t('lens', 'Failed'),
            self::PendingReview => Craft::t('lens', 'Pending Review'),
            self::Approved => Craft::t('lens', 'Approved'),
            self::Rejected => Craft::t('lens', 'Rejected'),
        };
    }

    /**
     * Statuses considered "successfully analyzed" (for queries and display).
     *
     * @return string[]
     */
    public static function analyzedValues(): array
    {
        return [self::Completed->value, self::Approved->value];
    }

    /**
     * Statuses where an asset should not be re-queued for analysis.
     *
     * @return string[]
     */
    public static function shouldNotReprocessValues(): array
    {
        return [
            self::Processing->value,
            self::Completed->value,
            self::Approved->value,
            self::PendingReview->value,
        ];
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
