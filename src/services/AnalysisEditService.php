<?php

declare(strict_types=1);

namespace vitordiniz22\craftlens\services;

use Craft;
use vitordiniz22\craftlens\enums\LogCategory;
use vitordiniz22\craftlens\helpers\ColorSupport;
use vitordiniz22\craftlens\helpers\Logger;
use vitordiniz22\craftlens\Plugin;
use vitordiniz22\craftlens\records\AssetAnalysisRecord;
use vitordiniz22\craftlens\records\AssetColorRecord;
use vitordiniz22\craftlens\records\AssetTagRecord;
use vitordiniz22\craftlens\services\traits\ValidatesFieldInput;
use yii\base\Component;
use yii\base\InvalidArgumentException;

/**
 * Service for inline editing of analysis fields from the asset panel.
 *
 * Provides atomic, single-field update and revert operations that follow
 * the dual-column pattern (field + fieldAi). Editing is detected by comparing values.
 */
class AnalysisEditService extends Component
{
    use ValidatesFieldInput;

    private const MAX_TAGS = 50;
    private const MAX_COLORS = 20;

    private const FIELD_VALIDATION = [
        'suggestedTitle' => ['max' => AssetAnalysisRecord::SUGGESTED_TITLE_MAX_LENGTH, 'type' => 'string'],
        'altText' => ['max' => AssetAnalysisRecord::ALT_TEXT_MAX_LENGTH, 'type' => 'string'],
        'longDescription' => ['max' => AssetAnalysisRecord::LONG_DESCRIPTION_MAX_LENGTH, 'type' => 'string'],
        'faceCount' => ['min' => 0, 'type' => 'int'],
        'containsPeople' => ['type' => 'bool'],
        'nsfwScore' => ['min' => 0.0, 'max' => 1.0, 'type' => 'float'],
        'hasWatermark' => ['type' => 'bool'],
        'containsBrandLogo' => ['type' => 'bool'],
        'focalPointX' => ['min' => 0.0, 'max' => 1.0, 'type' => 'float'],
        'focalPointY' => ['min' => 0.0, 'max' => 1.0, 'type' => 'float'],
    ];

    protected function getFieldValidationRules(): array
    {
        return self::FIELD_VALIDATION;
    }

    /**
     * Update a single editable field on an analysis record.
     *
     * @return array{value: mixed, aiValue: mixed}
     * @throws InvalidArgumentException If record not found or field not editable
     * @throws \RuntimeException If save fails
     */
    public function updateSingleField(int $analysisId, string $field, mixed $value, ?AssetAnalysisRecord $record = null): array
    {
        if ($record === null) {
            $record = AssetAnalysisRecord::findOne($analysisId);
        }

        if ($record === null) {
            throw new InvalidArgumentException("Analysis record {$analysisId} not found");
        }

        if (!in_array($field, AssetAnalysisRecord::EDITABLE_FIELDS, true)) {
            throw new InvalidArgumentException("Field '{$field}' is not editable");
        }

        $value = $this->validateAndSanitize($field, $value);

        $record->$field = $value;

        if (!$record->save()) {
            $errors = implode(', ', $record->getErrorSummary(true));
            throw new \RuntimeException("Failed to save analysis record {$analysisId}: {$errors}");
        }

        $aiColumn = $field . 'Ai';

        Logger::info(LogCategory::Review, "Field '{$field}' updated via panel", assetId: $record->assetId);

        try {
            Plugin::getInstance()->searchIndex->reindexField($record, $field);
        } catch (\Throwable $e) {
            Logger::warning(LogCategory::SearchIndex, 'Search index update failed (non-fatal): ' . $e->getMessage(), assetId: $record->assetId);
        }

        return [
            'value' => $record->$field,
            'aiValue' => $record->$aiColumn ?? null,
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

        if (!in_array($field, AssetAnalysisRecord::EDITABLE_FIELDS, true)) {
            throw new InvalidArgumentException("Field '{$field}' is not editable");
        }

        $aiColumn = $field . 'Ai';

        $record->$field = $record->$aiColumn;

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

        if (count($tags) > self::MAX_TAGS) {
            throw new InvalidArgumentException(
                Craft::t('lens', 'Maximum of {max} tags allowed.', ['max' => self::MAX_TAGS])
            );
        }

        $transaction = Craft::$app->getDb()->beginTransaction();

        try {
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

            $transaction->commit();
        } catch (\Throwable $e) {
            $transaction->rollBack();
            throw $e;
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
     * @param array<array{hex: string, percentage?: float, isAutoGenerated?: bool}> $colors
     * @return array<array{hex: string, percentage: float|null, isAutoGenerated: bool}>
     * @throws InvalidArgumentException If record not found
     */
    public function updateColors(int $analysisId, array $colors): array
    {
        if (!ColorSupport::isAvailable()) {
            throw new InvalidArgumentException('Color support is unavailable: install the Imagick or GD PHP extension.');
        }

        $record = AssetAnalysisRecord::findOne($analysisId);

        if ($record === null) {
            throw new InvalidArgumentException("Analysis record {$analysisId} not found");
        }

        if (count($colors) > self::MAX_COLORS) {
            throw new InvalidArgumentException(
                Craft::t('lens', 'Maximum of {max} colors allowed.', ['max' => self::MAX_COLORS])
            );
        }

        $transaction = Craft::$app->getDb()->beginTransaction();

        try {
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

                $isAutoGenerated = filter_var($colorData['isAutoGenerated'] ?? false, FILTER_VALIDATE_BOOLEAN);

                $colorRecord = new AssetColorRecord();
                $colorRecord->assetId = $record->assetId;
                $colorRecord->analysisId = $record->id;
                $colorRecord->hex = $hex;
                $colorRecord->percentage = $colorData['percentage'] ?? null;
                $colorRecord->isAutoGenerated = $isAutoGenerated;

                if (!$colorRecord->save(false)) {
                    Logger::warning(LogCategory::Review, 'Failed to save color record', assetId: $record->assetId, context: [
                        'hex' => $hex,
                    ]);
                    continue;
                }

                $result[] = [
                    'hex' => $hex,
                    'percentage' => $colorRecord->percentage,
                    'isAutoGenerated' => (bool)$colorRecord->isAutoGenerated,
                ];
            }

            $transaction->commit();
        } catch (\Throwable $e) {
            $transaction->rollBack();
            throw $e;
        }

        Logger::info(LogCategory::Review, 'Colors updated via panel', assetId: $record->assetId, context: [
            'colorCount' => count($result),
        ]);

        return $result;
    }
}
