<?php

declare(strict_types=1);

namespace vitordiniz22\craftlens\helpers;

use Wamania\Snowball\NotFoundException;
use Wamania\Snowball\Stemmer\Stemmer as SnowballStemmer;
use Wamania\Snowball\StemmerFactory;

/**
 * Multilingual stemmer wrapper around wamania/php-stemmer.
 *
 * Automatically detects the primary site language via MultisiteHelper and
 * selects the appropriate Snowball algorithm. Supports 14 languages: English,
 * French, German, Spanish, Dutch, Italian, Portuguese, Russian, Swedish,
 * Norwegian, Danish, Finnish, Romanian, Catalan.
 *
 * For unsupported languages the stemmer falls back to lowercased exact matching,
 * so search still works — just without morphological reduction.
 */
class Stemmer
{
    /** ISO-639-1 codes accepted by wamania/php-stemmer */
    private const SUPPORTED_LANGUAGES = [
        'ca', 'da', 'nl', 'en', 'fi', 'fr',
        'de', 'it', 'no', 'pt', 'ro', 'ru', 'es', 'sv',
    ];

    /** Cached base language code for this request, or '' for unsupported */
    private static ?string $language = null;

    /** Cached Snowball stemmer instance */
    private static ?SnowballStemmer $stemmerInstance = null;

    /** Per-language stemmer cache: langCode => SnowballStemmer|null */
    private static array $stemmersByLanguage = [];

    /**
     * Resolve and cache the stemmer language from the primary site locale.
     * e.g. "en-US" → "en", "pt-BR" → "pt", "ja" → "" (unsupported)
     */
    private static function getLanguage(): string
    {
        if (self::$language === null) {
            $locale = MultisiteHelper::getPrimarySiteLanguage();
            // Normalise: "en-US" → "en", "fr" → "fr"
            $base = strtolower(substr($locale, 0, 2));
            self::$language = in_array($base, self::SUPPORTED_LANGUAGES, true)
                ? $base
                : '';
        }

        return self::$language;
    }

    /**
     * Lazy-resolve the Snowball stemmer instance (one per request).
     */
    private static function getStemmer(): ?SnowballStemmer
    {
        if (self::$stemmerInstance === null) {
            $lang = self::getLanguage();
            if ($lang === '') {
                return null;
            }
            try {
                self::$stemmerInstance = StemmerFactory::create($lang);
            } catch (NotFoundException) {
                self::$stemmerInstance = null;
            }
        }

        return self::$stemmerInstance;
    }

    /**
     * Stem a single pre-lowercased word.
     * Returns the stemmed form, or the word as-is for unsupported languages.
     */
    public static function stem(string $word): string
    {
        $stemmer = self::getStemmer();
        if ($stemmer === null) {
            return $word;
        }

        return $stemmer->stem($word) ?: $word;
    }

    /**
     * Tokenise a raw text string: lowercase, remove non-alphabetic characters,
     * return unique tokens of at least 2 characters.
     *
     * No hardcoded stopword list — BM25 IDF naturally demotes high-frequency
     * terms like "the" or "a" because they appear in almost every document.
     *
     * @return string[]
     */
    public static function tokenize(string $text): array
    {
        if (trim($text) === '') {
            return [];
        }

        // Lowercase
        $text = mb_strtolower($text, 'UTF-8');

        // Replace non-letter characters (keep letters from any Unicode script)
        $text = preg_replace('/[^\p{L}\s]/u', ' ', $text) ?? $text;

        // Split on whitespace
        $words = preg_split('/\s+/u', trim($text)) ?: [];

        $tokens = [];
        foreach ($words as $word) {
            $len = mb_strlen($word, 'UTF-8');
            // Natural language words don't exceed ~35 chars. Longer strings are
            // URLs, file paths, hashes, etc. — noise, not searchable terms.
            if ($len >= 2 && $len <= 40) {
                $tokens[] = $word;
            }
        }

        return array_values(array_unique($tokens));
    }

    /**
     * Tokenise and stem a raw text string.
     *
     * Returns an array of [raw, stemmed] pairs. When multiple raw tokens
     * reduce to the same stem, each pair is still returned so TF can be
     * computed correctly.
     *
     * @return array<array{string, string}>
     */
    public static function tokenizeAndStem(string $text): array
    {
        $tokens = self::tokenize($text);
        $pairs = [];

        foreach ($tokens as $raw) {
            $pairs[] = [$raw, self::stem($raw)];
        }

        return $pairs;
    }

    /**
     * Stem a single pre-lowercased word using a specific language stemmer.
     */
    public static function stemForLanguage(string $word, string $language): string
    {
        $stemmer = self::getStemmerForLanguage($language);
        if ($stemmer === null) {
            return $word;
        }

        return $stemmer->stem($word) ?: $word;
    }

    /**
     * Tokenise and stem a raw text string using a specific language stemmer.
     *
     * @return array<array{string, string}>
     */
    public static function tokenizeAndStemForLanguage(string $text, string $language): array
    {
        $tokens = self::tokenize($text);
        $pairs = [];

        foreach ($tokens as $raw) {
            $pairs[] = [$raw, self::stemForLanguage($raw, $language)];
        }

        return $pairs;
    }

    /**
     * Get or create a Snowball stemmer for a specific language code.
     */
    private static function getStemmerForLanguage(string $language): ?SnowballStemmer
    {
        $lang = strtolower(substr($language, 0, 2));

        if (!in_array($lang, self::SUPPORTED_LANGUAGES, true)) {
            return null;
        }

        if (!array_key_exists($lang, self::$stemmersByLanguage)) {
            try {
                self::$stemmersByLanguage[$lang] = StemmerFactory::create($lang);
            } catch (NotFoundException) {
                self::$stemmersByLanguage[$lang] = null;
            }
        }

        return self::$stemmersByLanguage[$lang];
    }

    /**
     * Reset cached language/stemmer (useful in tests or when switching sites).
     */
    public static function reset(): void
    {
        self::$language = null;
        self::$stemmerInstance = null;
        self::$stemmersByLanguage = [];
    }
}
