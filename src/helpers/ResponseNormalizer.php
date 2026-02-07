<?php

declare(strict_types=1);

namespace vitordiniz22\craftlens\helpers;

use vitordiniz22\craftlens\exceptions\AnalysisException;
/**
 * Helper for normalizing AI provider responses.
 *
 * Consolidates shared normalization logic used across all AI providers.
 */
final class ResponseNormalizer
{
    private const VALID_NSFW_CATEGORIES = ['adult', 'violence', 'hate', 'self-harm', 'sexual-minors', 'drugs'];
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
        $normalized = [];

        foreach ($tags as $index => $tag) {
            if (!is_array($tag)) {
                throw AnalysisException::invalidResponse(
                    $providerName,
                    null,
                    "Tag at index {$index} is not an array: " . gettype($tag)
                );
            }

            if (!isset($tag['tag'])) {
                throw AnalysisException::invalidResponse(
                    $providerName,
                    null,
                    "Tag at index {$index} missing 'tag' field"
                );
            }

            $normalized[] = [
                'tag' => strtolower(trim((string) $tag['tag'])),
                'confidence' => self::clampConfidence($tag['confidence'] ?? 0.5),
            ];
        }

        usort($normalized, fn($a, $b) => $b['confidence'] <=> $a['confidence']);

        return $normalized;
    }

    /**
     * Normalize colors from API response.
     *
     * @param array $colors Raw colors from API
     * @param string $providerName Provider name for error messages
     * @return array<array{hex: string, percentage: float}>
     * @throws AnalysisException If color structure is invalid
     */
    public static function normalizeColors(array $colors, string $providerName): array
    {
        $normalized = [];

        foreach ($colors as $index => $color) {
            if (!is_array($color)) {
                throw AnalysisException::invalidResponse(
                    $providerName,
                    null,
                    "Color at index {$index} is not an array: " . gettype($color)
                );
            }

            if (!isset($color['hex'])) {
                throw AnalysisException::invalidResponse(
                    $providerName,
                    null,
                    "Color at index {$index} missing 'hex' field"
                );
            }

            $hex = $color['hex'];
            if (!str_starts_with($hex, '#')) {
                $hex = '#' . $hex;
            }

            $normalized[] = [
                'hex' => strtoupper($hex),
                'percentage' => self::clampConfidence($color['percentage'] ?? 0.0),
            ];
        }

        usort($normalized, fn($a, $b) => $b['percentage'] <=> $a['percentage']);

        return array_slice($normalized, 0, 5);
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
        $normalized = [];

        foreach ($categories as $index => $cat) {
            if (!is_array($cat)) {
                throw AnalysisException::invalidResponse(
                    $providerName,
                    null,
                    "NSFW category at index {$index} is not an array: " . gettype($cat)
                );
            }

            if (!isset($cat['category'])) {
                throw AnalysisException::invalidResponse(
                    $providerName,
                    null,
                    "NSFW category at index {$index} missing 'category' field"
                );
            }

            $category = strtolower(trim((string) $cat['category']));

            if (!in_array($category, self::VALID_NSFW_CATEGORIES, true)) {
                throw AnalysisException::invalidResponse(
                    $providerName,
                    null,
                    "NSFW category at index {$index} has invalid category: '{$category}'"
                );
            }

            $normalized[] = [
                'category' => $category,
                'confidence' => self::clampConfidence($cat['confidence'] ?? 0.0),
            ];
        }

        usort($normalized, fn($a, $b) => $b['confidence'] <=> $a['confidence']);

        return $normalized;
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
        $normalized = [];

        foreach ($brands as $index => $brand) {
            if (!is_array($brand)) {
                throw AnalysisException::invalidResponse(
                    $providerName,
                    null,
                    "Brand at index {$index} is not an array: " . gettype($brand)
                );
            }

            if (!isset($brand['brand'])) {
                throw AnalysisException::invalidResponse(
                    $providerName,
                    null,
                    "Brand at index {$index} missing 'brand' field"
                );
            }

            $confidence = self::clampConfidence($brand['confidence'] ?? 0.5);

            $normalized[] = [
                'brand' => trim((string) $brand['brand']),
                'confidence' => $confidence,
                'position' => trim((string) ($brand['position'] ?? 'unknown')),
            ];
        }

        usort($normalized, fn($a, $b) => $b['confidence'] <=> $a['confidence']);

        return $normalized;
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
    public static function normalizeWatermarkDetails(array $details): array
    {
        if (empty($details)) {
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

        $controlChars = array_map('chr', [...range(0, 31), 127]);
        $sanitized = str_replace($controlChars, '', $content);
        $data = json_decode($sanitized, true);

        if (json_last_error() === JSON_ERROR_NONE && is_array($data)) {
            return $data;
        }

        Logger::error(
            LogCategory::NormalizationError,
            "Failed to parse {$provider} JSON response: " . json_last_error_msg(),
            $assetId,
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

        return $content;
    }

    /**
     * Clamp a value to valid confidence range (0.0 to 1.0).
     */
    public static function clampConfidence(mixed $value): float
    {
        return min(1.0, max(0.0, (float) $value));
    }
}
