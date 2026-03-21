<?php

declare(strict_types=1);

namespace vitordiniz22\craftlens\services\traits;

use Craft;
use yii\base\InvalidArgumentException;

/**
 * Shared field validation and sanitization for services that edit analysis data.
 *
 * Consumers must implement getFieldValidationRules() to return their field-specific
 * validation rules array (type, max, min constraints).
 */
trait ValidatesFieldInput
{
    /**
     * Return the field validation rules for this service.
     *
     * @return array<string, array{type: string, max?: int|float, min?: int|float}>
     */
    abstract protected function getFieldValidationRules(): array;

    protected function validateAndSanitize(string $field, mixed $value): mixed
    {
        $rules = $this->getFieldValidationRules()[$field] ?? null;

        if ($rules === null) {
            return $value;
        }

        return match ($rules['type']) {
            'string' => $this->sanitizeString($value, $rules['max'] ?? null),
            'int' => max($rules['min'] ?? 0, (int)$value),
            'float' => min($rules['max'] ?? 1.0, max($rules['min'] ?? 0.0, (float)$value)),
            'bool' => (bool)$value,
        };
    }

    protected function sanitizeString(mixed $value, ?int $maxLength): string
    {
        $value = trim((string)$value);

        if ($maxLength !== null && mb_strlen($value) > $maxLength) {
            throw new InvalidArgumentException(
                Craft::t('lens', 'Value exceeds maximum length of {max} characters.', ['max' => $maxLength])
            );
        }

        return $value;
    }
}
