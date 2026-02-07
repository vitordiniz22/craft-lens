<?php

declare(strict_types=1);

namespace vitordiniz22\craftlens\enums;

/**
 * Trait for enums that provide options arrays for UI components.
 * Provides a consistent way to generate key-value arrays from enum cases.
 */
trait EnumOptionsTrait
{
    /**
     * Returns an associative array of enum values to labels.
     * Keys are the enum values, values are the translated labels.
     *
     * @return array<string, string>
     */
    public static function options(): array
    {
        return array_combine(
            array_column(self::cases(), 'value'),
            array_map(fn(self $case) => $case->label(), self::cases()),
        );
    }
}
