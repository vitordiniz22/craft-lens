<?php

declare(strict_types=1);

namespace vitordiniz22\craftlens\helpers;

use Craft;
use craft\base\Field;
use craft\elements\Asset;

/**
 * Stateless utility for multisite content determination.
 *
 * Centralizes all logic for deciding whether per-site alt text and title
 * generation is needed, based on site languages and volume configuration.
 */
class MultisiteHelper
{
    /**
     * Check if multisite content generation is needed for an asset.
     *
     * Returns true when multiple sites exist with different languages
     * and the volume has at least one translatable text field (alt or title).
     */
    public static function needsMultisiteContent(Asset $asset): bool
    {
        return !empty(self::getSitesNeedingContent($asset));
    }

    /**
     * Determine which sites need per-site content for a given asset.
     *
     * Returns site list when: multiple sites exist, sites have different
     * languages, and the volume has at least one translatable text field.
     *
     * @return array<int, array{siteId: int, language: string}>
     *         Empty array = single-site behavior (no per-site content needed)
     */
    public static function getSitesNeedingContent(Asset $asset): array
    {
        $allSites = Craft::$app->getSites()->getAllSites();

        if (count($allSites) <= 1) {
            return [];
        }

        $volume = $asset->getVolume();
        $altTranslatable = $volume->altTranslationMethod !== Field::TRANSLATION_METHOD_NONE;
        $titleTranslatable = $volume->titleTranslationMethod !== Field::TRANSLATION_METHOD_NONE;

        if (!$altTranslatable && !$titleTranslatable) {
            return [];
        }

        $primaryLanguage = self::getPrimarySiteLanguage();
        $sites = [];

        foreach ($allSites as $site) {
            if ($site->language === $primaryLanguage) {
                continue;
            }

            $sites[] = [
                'siteId' => $site->id,
                'language' => $site->language,
            ];
        }

        return $sites;
    }

    /**
     * Get the primary site's language code (e.g., "fr-FR", "en-US").
     */
    public static function getPrimarySiteLanguage(): string
    {
        return Craft::$app->getSites()->getPrimarySite()->language;
    }

    /**
     * Check if the alt field is translatable for a given volume.
     */
    public static function isAltTranslatable(int $volumeId): bool
    {
        $volume = Craft::$app->getVolumes()->getVolumeById($volumeId);

        if ($volume === null) {
            return false;
        }

        return $volume->altTranslationMethod !== Field::TRANSLATION_METHOD_NONE;
    }

    /**
     * Check if the title field is translatable for a given volume.
     */
    public static function isTitleTranslatable(int $volumeId): bool
    {
        $volume = Craft::$app->getVolumes()->getVolumeById($volumeId);

        if ($volume === null) {
            return false;
        }

        return $volume->titleTranslationMethod !== Field::TRANSLATION_METHOD_NONE;
    }

    /**
     * Get unique additional languages (non-primary) from sites that need content.
     *
     * @return string[] Unique language codes (e.g., ["fr-FR", "pt-BR"])
     */
    public static function getAdditionalLanguages(Asset $asset): array
    {
        $sites = self::getSitesNeedingContent($asset);
        $languages = [];
        $seen = [];

        foreach ($sites as $siteInfo) {
            $lang = $siteInfo['language'];
            if (!isset($seen[$lang])) {
                $languages[] = $lang;
                $seen[$lang] = true;
            }
        }

        return $languages;
    }
}
