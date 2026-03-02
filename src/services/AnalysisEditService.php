<?php

declare(strict_types=1);

namespace vitordiniz22\craftlens\services;

use Craft;
use craft\helpers\DateTimeHelper;
use vitordiniz22\craftlens\enums\LogCategory;
use vitordiniz22\craftlens\helpers\Logger;
use vitordiniz22\craftlens\Plugin;
use vitordiniz22\craftlens\records\AssetAnalysisRecord;
use vitordiniz22\craftlens\records\AssetColorRecord;
use vitordiniz22\craftlens\records\AssetTagRecord;
use yii\base\Component;
use yii\base\InvalidArgumentException;

/**
 * Service for inline editing of analysis fields from the asset panel.
 *
 * Provides atomic, single-field update and revert operations that follow
 * the dual-column edit tracking pattern (field + fieldAi + fieldEditedBy + fieldEditedAt).
 */
class AnalysisEditService extends Component
{
    /**
     * Field-specific validation rules: [maxLength, type].
     */
    private const FIELD_VALIDATION = [
        'suggestedTitle' => ['max' => 255, 'type' => 'string'],
        'altText' => ['max' => 1000, 'type' => 'string'],
        'longDescription' => ['max' => 5000, 'type' => 'string'],
        'faceCount' => ['min' => 0, 'type' => 'int'],
        'containsPeople' => ['type' => 'bool'],
        'nsfwScore' => ['min' => 0.0, 'max' => 1.0, 'type' => 'float'],
        'hasWatermark' => ['type' => 'bool'],
        'containsBrandLogo' => ['type' => 'bool'],
        'focalPointX' => ['min' => 0.0, 'max' => 1.0, 'type' => 'float'],
        'focalPointY' => ['min' => 0.0, 'max' => 1.0, 'type' => 'float'],
        'extractedText' => ['type' => 'string'],
    ];

    /**
     * Update a single editable field on an analysis record.
     *
     * @return array{value: mixed, aiValue: mixed, editedBy: string|null, editedAt: string|null}
     * @throws InvalidArgumentException If record not found or field not editable
     * @throws \RuntimeException If save fails
     */
    public function updateSingleField(int $analysisId, string $field, mixed $value, ?int $userId = null, ?AssetAnalysisRecord $record = null): array
    {
        if ($record === null) {
            $record = AssetAnalysisRecord::findOne($analysisId);
        }

        if ($record === null) {
            throw new InvalidArgumentException("Analysis record {$analysisId} not found");
        }

        if (!isset(AssetAnalysisRecord::EDITABLE_FIELDS[$field])) {
            throw new InvalidArgumentException("Field '{$field}' is not editable");
        }

        $userId = $userId ?? Craft::$app->getUser()->getId();
        $now = DateTimeHelper::now();

        $value = $this->validateAndSanitize($field, $value);

        $record->$field = $value;

        $prefix = AssetAnalysisRecord::EDITABLE_FIELDS[$field];
        $record->{$prefix . 'EditedBy'} = $userId;
        $record->{$prefix . 'EditedAt'} = $now;

        if (!$record->save()) {
            $errors = implode(', ', $record->getErrorSummary(true));
            throw new \RuntimeException("Failed to save analysis record {$analysisId}: {$errors}");
        }

        $aiColumn = $field . 'Ai';
        $editor = $userId ? Craft::$app->getUsers()->getUserById($userId) : null;

        Logger::info(LogCategory::Review, "Field '{$field}' updated via panel", assetId: $record->assetId);

        try {
            Plugin::getInstance()->searchIndex->reindexField($record, $field);
        } catch (\Throwable $e) {
            Logger::warning(LogCategory::SearchIndex, 'Search index update failed (non-fatal): ' . $e->getMessage(), assetId: $record->assetId);
        }

        return [
            'value' => $record->$field,
            'aiValue' => $record->$aiColumn ?? null,
            'editedBy' => $editor?->friendlyName ?? 'Unknown',
            'editedAt' => $now->format('Y-m-d'),
        ];
    }

    /**
     * Revert a field to its AI-generated value.
     *
     * @return array{value: mixed, aiValue: mixed}
     * @throws InvalidArgumentException If record not found or field not editable
     * @throws \RuntimeException If save fails
     */
    public function revertField(int $analysisId, string $field): array
    {
        $record = AssetAnalysisRecord::findOne($analysisId);

        if ($record === null) {
            throw new InvalidArgumentException("Analysis record {$analysisId} not found");
        }

        $prefix = AssetAnalysisRecord::EDITABLE_FIELDS[$field] ?? null;

        if ($prefix === null) {
            throw new InvalidArgumentException("Field '{$field}' is not editable");
        }

        $aiColumn = $field . 'Ai';

        $record->$field = $record->$aiColumn;
        $record->{$prefix . 'EditedBy'} = null;
        $record->{$prefix . 'EditedAt'} = null;

        if (!$record->save()) {
            $errors = implode(', ', $record->getErrorSummary(true));
            throw new \RuntimeException("Failed to save analysis record {$analysisId}: {$errors}");
        }

        Logger::info(LogCategory::Review, "Field '{$field}' reverted to AI value", assetId: $record->assetId);

        try {
            Plugin::getInstance()->searchIndex->reindexField($record, $field);
        } catch (\Throwable $e) {
            Logger::warning(LogCategory::SearchIndex, 'Search index update failed (non-fatal): ' . $e->getMessage(), assetId: $record->assetId);
        }

        return [
            'value' => $record->$field,
            'aiValue' => $record->$aiColumn,
        ];
    }

    /**
     * Replace all tags for an analysis.
     *
     * @param array<array{tag: string, confidence?: float, isAi?: bool}> $tags
     * @return array<array{tag: string, confidence: float|null, isAi: bool}>
     * @throws InvalidArgumentException If record not found
     */
    public function updateTags(int $analysisId, array $tags): array
    {
        $record = AssetAnalysisRecord::findOne($analysisId);

        if ($record === null) {
            throw new InvalidArgumentException("Analysis record {$analysisId} not found");
        }

        AssetTagRecord::deleteAll(['analysisId' => $record->id]);

        $result = [];
        foreach ($tags as $tagData) {
            if (!is_array($tagData)) {
                continue;
            }

            $tagName = trim((string)($tagData['tag'] ?? $tagData['name'] ?? ''));

            if ($tagName === '') {
                continue;
            }

            $isAi = filter_var($tagData['isAi'] ?? false, FILTER_VALIDATE_BOOLEAN);

            $tagRecord = new AssetTagRecord();
            $tagRecord->assetId = $record->assetId;
            $tagRecord->analysisId = $record->id;
            $tagRecord->tag = $tagName;
            $tagRecord->tagNormalized = mb_strtolower($tagName);
            $tagRecord->confidence = $isAi ? ($tagData['confidence'] ?? null) : 1.0;
            $tagRecord->isAi = $isAi;

            if (!$tagRecord->save(false)) {
                Logger::warning(LogCategory::Review, 'Failed to save tag record', assetId: $record->assetId, context: [
                    'tag' => $tagName,
                ]);
                continue;
            }

            $result[] = [
                'tag' => $tagName,
                'confidence' => $tagRecord->confidence,
                'isAi' => (bool)$tagRecord->isAi,
            ];
        }

        Logger::info(LogCategory::Review, 'Tags updated via panel', assetId: $record->assetId, context: [
            'tagCount' => count($result),
        ]);

        try {
            Plugin::getInstance()->searchIndex->reindexTags($record);
        } catch (\Throwable $e) {
            Logger::warning(LogCategory::SearchIndex, 'Search index update failed (non-fatal): ' . $e->getMessage(), assetId: $record->assetId);
        }

        return $result;
    }

    /**
     * Replace all colors for an analysis.
     *
     * @param array<array{hex: string, percentage?: float, isAi?: bool}> $colors
     * @return array<array{hex: string, percentage: float|null, isAi: bool}>
     * @throws InvalidArgumentException If record not found
     */
    public function updateColors(int $analysisId, array $colors): array
    {
        $record = AssetAnalysisRecord::findOne($analysisId);

        if ($record === null) {
            throw new InvalidArgumentException("Analysis record {$analysisId} not found");
        }

        AssetColorRecord::deleteAll(['analysisId' => $record->id]);

        $result = [];
        foreach ($colors as $colorData) {
            if (!is_array($colorData) || !isset($colorData['hex'])) {
                continue;
            }

            $hex = trim((string)$colorData['hex']);
            if ($hex === '' || !preg_match('/^#[0-9A-Fa-f]{6}$/', $hex)) {
                continue;
            }

            $isAi = filter_var($colorData['isAi'] ?? false, FILTER_VALIDATE_BOOLEAN);

            $colorRecord = new AssetColorRecord();
            $colorRecord->assetId = $record->assetId;
            $colorRecord->analysisId = $record->id;
            $colorRecord->hex = $hex;
            $colorRecord->percentage = $colorData['percentage'] ?? null;
            $colorRecord->isAi = $isAi;

            if (!$colorRecord->save(false)) {
                Logger::warning(LogCategory::Review, 'Failed to save color record', assetId: $record->assetId, context: [
                    'hex' => $hex,
                ]);
                continue;
            }

            $result[] = [
                'hex' => $hex,
                'percentage' => $colorRecord->percentage,
                'isAi' => (bool)$colorRecord->isAi,
            ];
        }

        Logger::info(LogCategory::Review, 'Colors updated via panel', assetId: $record->assetId, context: [
            'colorCount' => count($result),
        ]);

        return $result;
    }

    /**
     * Validate and sanitize a field value according to its type and constraints.
     */
    private function validateAndSanitize(string $field, mixed $value): mixed
    {
        $rules = self::FIELD_VALIDATION[$field] ?? null;

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

    private function sanitizeString(mixed $value, ?int $maxLength): string
    {
        $value = trim((string)$value);

        if ($maxLength !== null && mb_strlen($value) > $maxLength) {
            $value = mb_substr($value, 0, $maxLength);
        }

        return $value;
    }
}
