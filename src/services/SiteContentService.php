<?php

declare(strict_types=1);

namespace vitordiniz22\craftlens\services;

use Craft;
use craft\helpers\DateTimeHelper;
use vitordiniz22\craftlens\dto\AnalysisResult;
use vitordiniz22\craftlens\enums\LogCategory;
use vitordiniz22\craftlens\helpers\Logger;
use vitordiniz22\craftlens\helpers\MultisiteHelper;
use vitordiniz22\craftlens\Plugin;
use vitordiniz22\craftlens\records\AnalysisSiteContentRecord;
use vitordiniz22\craftlens\records\AssetAnalysisRecord;
use yii\base\Component;
use yii\base\InvalidArgumentException;

/**
 * Service for managing per-site alt text and title content.
 *
 * Handles CRUD for the site content table (non-primary sites only).
 * Primary site values remain on the main AssetAnalysisRecord.
 */
class SiteContentService extends Component
{
    /**
     * Field-specific validation rules.
     */
    private const FIELD_VALIDATION = [
        'altText' => ['max' => AssetAnalysisRecord::ALT_TEXT_MAX_LENGTH, 'type' => 'string'],
        'suggestedTitle' => ['max' => AssetAnalysisRecord::SUGGESTED_TITLE_MAX_LENGTH, 'type' => 'string'],
    ];

    /**
     * Save per-site content rows from an AI analysis result.
     *
     * Creates or updates site content records for each non-primary site
     * that received translated alt text and title from the AI provider.
     * Only saves fields whose volume translation method is translatable.
     *
     * Sites sharing a base language (e.g. fr-FR and fr-CA) receive the
     * same AI translation. The result is keyed by the representative locale
     * returned by MultisiteHelper::getAdditionalLanguages(), so we look up
     * by exact locale first, then fall back to base-language match.
     *
     * @param array<int, array{siteId: int, language: string}> $sites
     */
    public function saveFromAnalysisResult(
        AssetAnalysisRecord $record,
        array $sites,
        AnalysisResult $result,
        bool $altTranslatable = true,
        bool $titleTranslatable = true,
    ): void {
        if (!Plugin::getInstance()->getIsPro()) {
            return;
        }

        if (empty($result->siteContent)) {
            return;
        }

        foreach ($sites as $siteInfo) {
            $lang = $siteInfo['language'];
            $content = $this->resolveResultContent($result->siteContent, $lang);

            if ($content === null) {
                continue;
            }

            $altText = $altTranslatable ? ($content['altText'] ?? '') : '';
            $suggestedTitle = $titleTranslatable ? ($content['suggestedTitle'] ?? '') : '';

            if ($altText === '' && $suggestedTitle === '') {
                continue;
            }

            $siteRecord = AnalysisSiteContentRecord::find()
                ->where(['analysisId' => $record->id, 'siteId' => $siteInfo['siteId']])
                ->one();

            if ($siteRecord === null) {
                $siteRecord = new AnalysisSiteContentRecord();
                $siteRecord->analysisId = $record->id;
                $siteRecord->siteId = $siteInfo['siteId'];
                $siteRecord->language = $lang;
            }

            if ($altTranslatable) {
                $siteRecord->altText = $altText;
                $siteRecord->altTextAi = $altText;
                $siteRecord->altTextConfidence = $content['altTextConfidence'] ?? null;
            }

            if ($titleTranslatable) {
                $siteRecord->suggestedTitle = $suggestedTitle;
                $siteRecord->suggestedTitleAi = $suggestedTitle;
                $siteRecord->titleConfidence = $content['titleConfidence'] ?? null;
            }

            if (!$siteRecord->save()) {
                Logger::warning(
                    LogCategory::AssetProcessing,
                    sprintf(
                        'Failed to save site content for analysis %d, site %d: %s',
                        $record->id,
                        $siteInfo['siteId'],
                        implode(', ', $siteRecord->getErrorSummary(true))
                    ),
                    assetId: $record->assetId,
                );
            }
        }

        Logger::info(
            LogCategory::AssetProcessing,
            sprintf('Saved per-site content for %d language(s)', count($result->siteContent)),
            assetId: $record->assetId,
        );
    }

    /**
     * Find AI result content for a locale, falling back to base-language match.
     *
     * The AI result may be keyed by a representative locale (e.g. "fr-FR")
     * while the site uses a regional variant (e.g. "fr-CA"). This method
     * tries exact match first, then falls back to any key sharing the same
     * base language.
     *
     * @param array<string, array> $siteContent AI result keyed by locale
     * @return array|null The matching content array, or null if not found
     */
    private function resolveResultContent(array $siteContent, string $locale): ?array
    {
        // Exact locale match
        if (isset($siteContent[$locale])) {
            return $siteContent[$locale];
        }

        // Fall back to base-language match
        $base = MultisiteHelper::getBaseLanguage($locale);

        foreach ($siteContent as $key => $content) {
            if (MultisiteHelper::getBaseLanguage($key) === $base) {
                return $content;
            }
        }

        return null;
    }

    /**
     * Get site content record for a specific analysis and site.
     */
    public function getSiteContent(int $analysisId, int $siteId): ?AnalysisSiteContentRecord
    {
        return AnalysisSiteContentRecord::find()
            ->where(['analysisId' => $analysisId, 'siteId' => $siteId])
            ->one();
    }

    /**
     * Get all site content records for an analysis, indexed by siteId.
     *
     * @return array<int, AnalysisSiteContentRecord>
     */
    public function getAllSiteContent(int $analysisId): array
    {
        $records = AnalysisSiteContentRecord::find()
            ->where(['analysisId' => $analysisId])
            ->all();

        $indexed = [];
        foreach ($records as $record) {
            $indexed[$record->siteId] = $record;
        }

        return $indexed;
    }

    /**
     * Resolve the effective alt text for a given site.
     *
     * Returns site-specific alt text for non-primary sites,
     * or the main record's alt text for the primary site.
     */
    public function resolveAltText(AssetAnalysisRecord $record, int $siteId): ?string
    {
        $primarySiteId = Craft::$app->getSites()->getPrimarySite()->id;

        if ($siteId === $primarySiteId) {
            return $record->altText;
        }

        $siteContent = $this->getSiteContent($record->id, $siteId);

        return $siteContent?->altText ?? $record->altText;
    }

    /**
     * Resolve the effective suggested title for a given site.
     *
     * Returns site-specific title for non-primary sites,
     * or the main record's title for the primary site.
     */
    public function resolveSuggestedTitle(AssetAnalysisRecord $record, int $siteId): ?string
    {
        $primarySiteId = Craft::$app->getSites()->getPrimarySite()->id;

        if ($siteId === $primarySiteId) {
            return $record->suggestedTitle;
        }

        $siteContent = $this->getSiteContent($record->id, $siteId);

        return $siteContent?->suggestedTitle ?? $record->suggestedTitle;
    }

    /**
     * Update a single editable field on a site content record.
     *
     * @return array{value: mixed, aiValue: mixed, editedBy: string|null, editedAt: string|null}
     */
    public function updateSiteField(
        int $analysisId,
        int $siteId,
        string $field,
        mixed $value,
        ?int $userId = null,
    ): array {
        if (!isset(AnalysisSiteContentRecord::EDITABLE_FIELDS[$field])) {
            throw new InvalidArgumentException("Field '{$field}' is not editable on site content");
        }

        $siteRecord = $this->getSiteContent($analysisId, $siteId);

        if ($siteRecord === null) {
            throw new InvalidArgumentException(
                "Site content not found for analysis {$analysisId}, site {$siteId}"
            );
        }

        $userId = $userId ?? Craft::$app->getUser()->getId();
        $now = DateTimeHelper::now();

        $value = $this->validateAndSanitize($field, $value);

        $siteRecord->$field = $value;

        $prefix = AnalysisSiteContentRecord::EDITABLE_FIELDS[$field];
        $siteRecord->{$prefix . 'EditedBy'} = $userId;
        $siteRecord->{$prefix . 'EditedAt'} = $now;

        if (!$siteRecord->save()) {
            $errors = implode(', ', $siteRecord->getErrorSummary(true));
            throw new \RuntimeException(
                "Failed to save site content for analysis {$analysisId}, site {$siteId}: {$errors}"
            );
        }

        $aiColumn = $field . 'Ai';
        $editor = $userId ? Craft::$app->getUsers()->getUserById($userId) : null;

        Logger::info(
            LogCategory::Review,
            "Site content field '{$field}' updated for site {$siteId}",
            assetId: $siteRecord->analysis->assetId ?? null,
        );

        return [
            'value' => $siteRecord->$field,
            'aiValue' => $siteRecord->$aiColumn ?? null,
            'editedBy' => $editor?->friendlyName ?? 'Unknown',
            'editedByUrl' => $editor?->getCpEditUrl(),
            'editedAt' => $now->format('c'),
            'editedAtFormatted' => Craft::$app->getFormatter()->asDate($now, 'short'),
        ];
    }

    /**
     * Revert a site content field to its AI-generated value.
     *
     * @return array{value: mixed, aiValue: mixed}
     */
    public function revertSiteField(int $analysisId, int $siteId, string $field): array
    {
        $prefix = AnalysisSiteContentRecord::EDITABLE_FIELDS[$field] ?? null;

        if ($prefix === null) {
            throw new InvalidArgumentException("Field '{$field}' is not editable on site content");
        }

        $siteRecord = $this->getSiteContent($analysisId, $siteId);

        if ($siteRecord === null) {
            throw new InvalidArgumentException(
                "Site content not found for analysis {$analysisId}, site {$siteId}"
            );
        }

        $aiColumn = $field . 'Ai';

        $siteRecord->$field = $siteRecord->$aiColumn;
        $siteRecord->{$prefix . 'EditedBy'} = null;
        $siteRecord->{$prefix . 'EditedAt'} = null;

        if (!$siteRecord->save()) {
            $errors = implode(', ', $siteRecord->getErrorSummary(true));
            throw new \RuntimeException(
                "Failed to save site content for analysis {$analysisId}, site {$siteId}: {$errors}"
            );
        }

        Logger::info(
            LogCategory::Review,
            "Site content field '{$field}' reverted for site {$siteId}",
            assetId: $siteRecord->analysis->assetId ?? null,
        );

        return [
            'value' => $siteRecord->$field,
            'aiValue' => $siteRecord->$aiColumn,
        ];
    }

    /**
     * Delete all site content records for an analysis.
     */
    public function deleteAllForAnalysis(int $analysisId): void
    {
        AnalysisSiteContentRecord::deleteAll(['analysisId' => $analysisId]);
    }

    private function validateAndSanitize(string $field, mixed $value): mixed
    {
        $rules = self::FIELD_VALIDATION[$field] ?? null;

        if ($rules === null) {
            return $value;
        }

        $value = trim((string) $value);

        if (isset($rules['max']) && mb_strlen($value) > $rules['max']) {
            throw new InvalidArgumentException(
                Craft::t('lens', 'Value exceeds maximum length of {max} characters.', ['max' => $rules['max']])
            );
        }

        return $value;
    }
}
