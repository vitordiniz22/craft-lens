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
 *
 * Language comparison uses base language (first two chars, e.g. "en" from
 * "en-US") so that same-language regional variants (en-US / en-GB) are
 * treated as a single language and share one AI translation.
 */
class MultisiteHelper
{
    /**
     * Extract the base language from a full locale code.
     *
     * "en-US" → "en", "fr-FR" → "fr", "pt-BR" → "pt"
     */
    public static function getBaseLanguage(string $locale): string
    {
        return strtolower(substr($locale, 0, 2));
    }

    /**
     * Check if two locale codes share the same base language.
     */
    public static function isSameBaseLanguage(string $localeA, string $localeB): bool
    {
        return self::getBaseLanguage($localeA) === self::getBaseLanguage($localeB);
    }

    /**
     * Check if multisite content generation is needed for an asset.
     *
     * Returns true when multiple sites exist with different base languages
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
     * base languages, and the volume has at least one translatable text field.
     * Sites sharing the primary site's base language (e.g. en-GB when primary
     * is en-US) are excluded — they use the primary site's values directly.
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

        $primaryBase = self::getBaseLanguage(self::getPrimarySiteLanguage());
        $sites = [];

        foreach ($allSites as $site) {
            if (self::getBaseLanguage($site->language) === $primaryBase) {
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
     * Get distinct base language codes across all enabled sites.
     *
     * Always contains at least the primary site's base language. Used by
     * search to stem query terms against every language that could appear
     * in the index.
     *
     * @return string[] e.g. ['en', 'pt', 'fr']
     */
    public static function getAllBaseLanguages(): array
    {
        $bases = [];
        foreach (Craft::$app->getSites()->getAllSites() as $site) {
            $bases[] = self::getBaseLanguage($site->language);
        }

        return array_values(array_unique($bases));
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
     * Get all non-primary sites for an asset, with content source flag.
     *
     * Returns every site except the primary, each tagged with whether it
     * shares the primary's base language (uses primary content directly)
     * or has a different base language (uses translated site content).
     *
     * Returns empty array if single-site or no translatable fields.
     *
     * @return array<int, array{siteId: int, language: string, usesPrimaryContent: bool}>
     */
    public static function getAllNonPrimarySites(Asset $asset): array
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

        $primarySiteId = Craft::$app->getSites()->getPrimarySite()->id;
        $primaryBase = self::getBaseLanguage(self::getPrimarySiteLanguage());
        $sites = [];

        foreach ($allSites as $site) {
            if ($site->id === $primarySiteId) {
                continue;
            }

            $sites[] = [
                'siteId' => $site->id,
                'language' => $site->language,
                'usesPrimaryContent' => self::getBaseLanguage($site->language) === $primaryBase,
            ];
        }

        return $sites;
    }

    /**
     * Get unique additional languages (non-primary) from sites that need content.
     *
     * Deduplicates by base language so that fr-FR and fr-CA only produce one
     * entry (the first locale encountered for that base). The AI generates
     * one translation per base language, shared across regional variants.
     *
     * @return string[] Unique language codes, one per base language (e.g., ["fr-FR", "pt-BR"])
     */
    public static function getAdditionalLanguages(Asset $asset): array
    {
        $sites = self::getSitesNeedingContent($asset);
        $languages = [];
        $seenBases = [];

        foreach ($sites as $siteInfo) {
            $lang = $siteInfo['language'];
            $base = self::getBaseLanguage($lang);

            if (!isset($seenBases[$base])) {
                $languages[] = $lang;
                $seenBases[$base] = true;
            }
        }

        return $languages;
    }
}
