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

    public function label(): string
    {
        return match ($this) {
            self::Pending => Craft::t('lens', 'Pending'),
            self::Processing => Craft::t('lens', 'Processing'),
            self::Completed => Craft::t('lens', 'Analyzed'),
            self::Failed => Craft::t('lens', 'Failed'),
        };
    }

    /**
     * Statuses where the asset still needs analysis work.
     *
     * @return string[]
     */
    public static function unprocessedStatuses(): array
    {
        return [
            self::Pending->value,
            self::Failed->value,
        ];
    }

    /**
     * Statuses where the asset has finished the processing pipeline, whether
     * successfully or not. Used by bulk progress tracking to distinguish
     * "done" assets from those still Pending or Processing.
     *
     * @return string[]
     */
    public static function terminalValues(): array
    {
        return [
            self::Completed->value,
            self::Failed->value,
        ];
    }

    /**
     * Whether an asset in this status should be picked up by analysis.
     */
    public function needsProcessing(): bool
    {
        return in_array($this, [self::Pending, self::Failed], true);
    }

    /**
     * Whether the analysis has reached a terminal (final) state.
     */
    public function isTerminal(): bool
    {
        return $this === self::Completed || $this === self::Failed;
    }
}
