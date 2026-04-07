<?php

declare(strict_types=1);

namespace vitordiniz22\craftlens\helpers;

use vitordiniz22\craftlens\enums\LogCategory;
use vitordiniz22\craftlens\exceptions\AnalysisException;

/**
 * Helper for normalizing AI provider responses.
 *
 * Consolidates shared normalization logic used across all AI providers.
 */
final class ResponseNormalizer
{
    private const VALID_NSFW_CATEGORIES = ['adult', 'violence', 'hate', 'self-harm', 'sexual', 'drugs'];
    private const VALID_WATERMARK_TYPES = ['stock', 'logo', 'text', 'copyright', 'unknown'];
    /**
     * Normalize tags from API response.
     *
     * @param array $tags Raw tags from API
     * @param string $providerName Provider name for error messages
     * @return array<array{tag: string, confidence: float}>
     * @throws AnalysisException If tag structure is invalid
     */
    public static function normalizeTags(array $tags, string $providerName): array
    {
        return self::normalizeItems(
            $tags,
            $providerName,
            'Tag',
            'tag',
            'confidence',
            fn($tag) => [
                'tag' => strtolower(trim((string) $tag['tag'])),
                'confidence' => self::clampConfidence($tag['confidence'] ?? 0.5),
            ]
        );
    }

    /**
     * Normalize NSFW categories from API response.
     *
     * @param array $categories Raw NSFW categories from API
     * @param string $providerName Provider name for error messages
     * @return array<array{category: string, confidence: float}>
     * @throws AnalysisException If category structure is invalid
     */
    public static function normalizeNsfwCategories(array $categories, string $providerName): array
    {
        return self::normalizeItems(
            $categories,
            $providerName,
            'NSFW category',
            'category',
            'confidence',
            function($cat) use ($providerName) {
                $category = strtolower(trim((string) $cat['category']));

                if (!in_array($category, self::VALID_NSFW_CATEGORIES, true)) {
                    throw AnalysisException::invalidResponse(
                        $providerName,
                        null,
                        "NSFW category has invalid category: '{$category}'"
                    );
                }

                return [
                    'category' => $category,
                    'confidence' => self::clampConfidence($cat['confidence'] ?? 0.0),
                ];
            }
        );
    }

    /**
     * Normalize detected brands from API response.
     *
     * @param array $brands Raw brands from API
     * @param string $providerName Provider name for error messages
     * @return array<array{brand: string, confidence: float, position: string}>
     * @throws AnalysisException If brand structure is invalid
     */
    public static function normalizeDetectedBrands(array $brands, string $providerName): array
    {
        return self::normalizeItems(
            $brands,
            $providerName,
            'Brand',
            'brand',
            'confidence',
            fn($brand) => [
                'brand' => is_array($brand['brand']) ? trim((string) ($brand['brand']['name'] ?? 'unknown')) : trim((string) $brand['brand']),
                'confidence' => self::clampConfidence($brand['confidence'] ?? 0.5),
                'position' => is_array($brand['position'] ?? null) ? 'unknown' : trim((string) ($brand['position'] ?? 'unknown')),
            ]
        );
    }

    /**
     * Normalize watermark type to valid values.
     */
    public static function normalizeWatermarkType(?string $type): ?string
    {
        if ($type === null) {
            return null;
        }

        $type = strtolower(trim($type));

        return in_array($type, self::VALID_WATERMARK_TYPES, true) ? $type : 'unknown';
    }

    /**
     * Normalize watermark details from API response.
     *
     * @return array{position?: string, detectedText?: string, stockProvider?: string, isObtrusive?: bool}
     */
    public static function normalizeWatermarkDetails(?array $details = null): array
    {
        if ($details === null || !is_array($details) || empty($details)) {
            return [];
        }

        $normalized = [];

        if (isset($details['position']) && is_string($details['position'])) {
            $normalized['position'] = trim($details['position']);
        }

        if (isset($details['detectedText']) && is_string($details['detectedText'])) {
            $normalized['detectedText'] = trim($details['detectedText']);
        }

        if (isset($details['stockProvider']) && is_string($details['stockProvider'])) {
            $normalized['stockProvider'] = trim($details['stockProvider']);
        }

        if (isset($details['isObtrusive'])) {
            $normalized['isObtrusive'] = (bool) $details['isObtrusive'];
        }

        return $normalized;
    }

    /**
     * Safely decode a JSON string, retrying with control character sanitization on failure.
     *
     * @return array The decoded JSON data
     * @throws AnalysisException If JSON cannot be parsed even after sanitization
     */
    public static function safeJsonDecode(string $content, string $provider, ?int $assetId = null): array
    {
        $data = json_decode($content, true);

        if (json_last_error() === JSON_ERROR_NONE && is_array($data)) {
            return $data;
        }

        $sanitized = str_replace(array_map('chr', array_merge(range(0, 31), [127])), '', $content);
        $data = json_decode($sanitized, true);

        if (json_last_error() === JSON_ERROR_NONE && is_array($data)) {
            return $data;
        }

        $preview = mb_substr($content, 0, 500);

        Logger::error(
            LogCategory::NormalizationError,
            "Failed to parse {$provider} JSON response: " . json_last_error_msg(),
            $assetId,
            context: ['rawContentPreview' => $preview],
        );

        throw AnalysisException::invalidResponse(
            $provider,
            $assetId,
            'Failed to parse AI response: ' . json_last_error_msg()
        );
    }

    /**
     * Strip markdown code blocks from response content.
     */
    public static function stripMarkdownCodeBlocks(string $content): string
    {
        $content = preg_replace('/^```json\s*/', '', $content);
        $content = preg_replace('/\s*```$/', '', $content);

        if ($content === null) {
            throw new \RuntimeException('Failed to strip markdown code blocks');
        }

        return $content;
    }

    /**
     * Clamp a value to valid confidence range (0.0 to 1.0).
     */
    public static function clampConfidence(float|int|string $value): float
    {
        if (is_string($value) && !is_numeric($value)) {
            return 0.0;
        }

        return min(1.0, max(0.0, (float) $value));
    }

    /**
     * Generic normalizer for array-based items with confidence/percentage scores.
     *
     * @param array $items Raw items from API
     * @param string $providerName Provider name for error messages
     * @param string $itemName Human-readable item name (e.g., "Tag", "Color")
     * @param string $requiredField Field name that must exist in each item
     * @param string $scoreField Field name used for sorting (e.g., "confidence", "percentage")
     * @param callable $transformer Callback to transform each item
     * @return array Normalized and sorted items
     * @throws AnalysisException If item structure is invalid
     */
    private static function normalizeItems(
        array $items,
        string $providerName,
        string $itemName,
        string $requiredField,
        string $scoreField,
        callable $transformer,
    ): array {
        $normalized = [];

        foreach ($items as $index => $item) {
            if (!is_array($item)) {
                throw AnalysisException::invalidResponse(
                    $providerName,
                    null,
                    "{$itemName} at index {$index} is not an array: " . gettype($item)
                );
            }

            if (!isset($item[$requiredField])) {
                throw AnalysisException::invalidResponse(
                    $providerName,
                    null,
                    "{$itemName} at index {$index} missing '{$requiredField}' field"
                );
            }

            $normalized[] = $transformer($item);
        }

        usort($normalized, fn($a, $b) => $b[$scoreField] <=> $a[$scoreField]);

        return $normalized;
    }
}
