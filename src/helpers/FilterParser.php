<?php

declare(strict_types=1);

namespace vitordiniz22\craftlens\helpers;

use craft\web\Request;
use vitordiniz22\craftlens\enums\LogCategory;
use vitordiniz22\craftlens\enums\QuickFilter;
use vitordiniz22\craftlens\Plugin;

/**
 * Parses and validates search filter parameters from HTTP requests.
 */
class FilterParser
{
    /** @var string[] Filter keys that indicate active filtering. */
    private const FILTER_KEYS = [
        'query', 'tags', 'tagOperator', 'status', 'containsPeople',
        'faceCountPreset',
        'confidenceMin', 'confidenceMax',
        'nsfwScoreMin', 'nsfwScoreMax',
        'processedFrom', 'processedTo',
        'color', 'colorTolerance', 'hasDuplicates', 'quickFilter',
        'hasWatermark', 'watermarkType', 'containsBrandLogo',
        'hasFocalPoint',
        'nsfwFlagged', 'missingAltText', 'unprocessed',
        'similarTo',
        'qualityIssues', 'isTooLarge', 'isBlurry', 'isTooDark', 'hasTextInImage',
    ];

    /**
     * Parse all filters from a request.
     */
    public static function fromRequest(Request $request): array
    {
        $filters = [];

        self::parseTextQuery($request, $filters);
        self::parseTags($request, $filters);
        self::parseStatus($request, $filters);
        self::parsePeopleFilters($request, $filters);
        self::parseRangeFilters($request, $filters);
        self::parseDateFilters($request, $filters);
        self::parseColorFilters($request, $filters);
        self::parseBooleanFilters($request, $filters);
        self::parseMissingAltText($request, $filters);
        self::parseEnumFilters($request, $filters);
        self::parseArrayFilters($request, $filters);
        self::parseQuickFilter($request, $filters);
        self::parsePagination($request, $filters);
        self::parseNsfwFlagged($request, $filters);
        self::parseSimilarTo($request, $filters);

        return $filters;
    }

    /**
     * Check if any filter is active, including quick filters.
     */
    public static function hasAnyFilters(array $filters): bool
    {
        return !empty(array_intersect_key($filters, array_flip(self::FILTER_KEYS)));
    }

    /**
     * Check if manual (non-quick) filters are active — used for auto-opening the filter panel.
     */
    public static function hasActiveFilters(array $filters): bool
    {
        if (isset($filters['quickFilter'])) {
            return false;
        }

        return !empty(array_intersect_key($filters, array_flip(self::FILTER_KEYS)));
    }

    private static function parseTextQuery(Request $request, array &$filters): void
    {
        $query = $request->getQueryParam('q');

        if ($query !== null && trim($query) !== '') {
            $filters['query'] = trim($query);
        }
    }

    private static function parseTags(Request $request, array &$filters): void
    {
        self::parseCommaSeparatedArray($request, 'tags', $filters);

        $tagOperator = $request->getQueryParam('tagOperator');

        if ($tagOperator === 'and' || $tagOperator === 'or') {
            $filters['tagOperator'] = $tagOperator;
        }
    }

    private static function parseStatus(Request $request, array &$filters): void
    {
        self::parseCommaSeparatedArray($request, 'status', $filters);
    }

    private static function parsePeopleFilters(Request $request, array &$filters): void
    {
        $containsPeople = $request->getQueryParam('containsPeople');

        if ($containsPeople !== null && $containsPeople !== '') {
            $filters['containsPeople'] = filter_var($containsPeople, FILTER_VALIDATE_BOOLEAN);
        }

        $faceCountPreset = $request->getQueryParam('faceCountPreset');

        if ($faceCountPreset !== null && in_array($faceCountPreset, ['0', '1', '2-5', '6+'], true)) {
            $filters['faceCountPreset'] = $faceCountPreset;
        }
    }

    private static function parseRangeFilters(Request $request, array &$filters): void
    {
        $rangeFilters = [
            'confidence' => [
                'min' => 'confidenceMin',
                'max' => 'confidenceMax',
                'transform' => fn($v) => (float) $v,
            ],
            'nsfwScore' => [
                'min' => 'nsfwScoreMin',
                'max' => 'nsfwScoreMax',
                'transform' => fn($v) => max(0, min(100, (int) $v)) / 100,
            ],
        ];

        foreach ($rangeFilters as $config) {
            foreach (['min', 'max'] as $bound) {
                $value = $request->getQueryParam($config[$bound]);

                if ($value !== null && is_numeric($value)) {
                    $filters[$config[$bound]] = $config['transform']($value);
                }
            }
        }
    }

    private static function parseDateFilters(Request $request, array &$filters): void
    {
        foreach (['processedFrom', 'processedTo'] as $key) {
            $param = $request->getQueryParam($key);

            if ($param !== null) {
                $date = self::parseDateParam($param);
                if ($date !== null) {
                    $filters[$key] = $date;
                }
            }
        }
    }

    private static function parseColorFilters(Request $request, array &$filters): void
    {
        $color = $request->getQueryParam('color');

        if ($color !== null && trim($color) !== '') {
            $hex = ltrim(trim($color), '#');

            if (preg_match('/^[0-9a-fA-F]{3}([0-9a-fA-F]{3})?$/', $hex)) {
                $filters['color'] = '#' . $hex;
            }
        }

        $colorTolerance = $request->getQueryParam('colorTolerance');

        if ($colorTolerance !== null && is_numeric($colorTolerance) && isset($filters['color'])) {
            $filters['colorTolerance'] = max(0, min(100, (int) $colorTolerance));
        }
    }

    private static function parseBooleanFilters(Request $request, array &$filters): void
    {
        $booleanFilterKeys = [
            'hasDuplicates', 'hasWatermark', 'containsBrandLogo',
            'hasFocalPoint', 'unprocessed', 'hasTextInImage',
            'isTooLarge', 'isBlurry', 'isTooDark',
        ];

        foreach ($booleanFilterKeys as $key) {
            $value = $request->getQueryParam($key);

            if ($value !== null && $value !== '') {
                $filters[$key] = filter_var($value, FILTER_VALIDATE_BOOLEAN);
            }
        }
    }

    private static function parseMissingAltText(Request $request, array &$filters): void
    {
        $value = $request->getQueryParam('missingAltText');

        if ($value !== null && $value !== '') {
            $filters['missingAltText'] = filter_var($value, FILTER_VALIDATE_BOOLEAN);
        }
    }

    private static function parseEnumFilters(Request $request, array &$filters): void
    {
        $enumFilters = [
            'watermarkType' => ['stock', 'logo', 'text', 'copyright'],
        ];

        foreach ($enumFilters as $key => $allowedValues) {
            $value = $request->getQueryParam($key);

            if ($value !== null && in_array($value, $allowedValues, true)) {
                $filters[$key] = $value;
            }
        }
    }

    private static function parseArrayFilters(Request $request, array &$filters): void
    {
        $arrayFilters = [
            'qualityIssues' => ['blurry', 'tooDark', 'tooBright', 'lowContrast'],
        ];

        foreach ($arrayFilters as $key => $allowedValues) {
            $value = $request->getQueryParam($key);

            if (is_array($value)) {
                $filtered = array_values(array_intersect($value, $allowedValues));

                if (!empty($filtered)) {
                    $filters[$key] = $filtered;
                }
            }
        }
    }

    private static function parseQuickFilter(Request $request, array &$filters): void
    {
        $quickFilter = $request->getQueryParam('quickFilter');

        if ($quickFilter !== null && QuickFilter::tryFrom($quickFilter) !== null) {
            $plugin = Plugin::getInstance();
            $filters = $plugin->search->applyQuickFilter($quickFilter, $filters);
            $filters['quickFilter'] = $quickFilter;
        }
    }

    private static function parsePagination(Request $request, array &$filters): void
    {
        $offset = $request->getQueryParam('offset');

        if ($offset !== null && is_numeric($offset)) {
            $filters['offset'] = max(0, (int) $offset);
        }

        $limit = $request->getQueryParam('limit');

        if ($limit !== null && is_numeric($limit)) {
            $filters['limit'] = min(100, max(1, (int) $limit));
        }
    }

    /**
     * Parse nsfwFlagged=1 shorthand into nsfwScoreMin=0.5.
     */
    private static function parseNsfwFlagged(Request $request, array &$filters): void
    {
        $nsfwFlagged = $request->getQueryParam('nsfwFlagged');

        if ($nsfwFlagged !== null && filter_var($nsfwFlagged, FILTER_VALIDATE_BOOLEAN) && !isset($filters['nsfwScoreMin'])) {
            $filters['nsfwScoreMin'] = 0.5;
            $filters['nsfwFlagged'] = true;
        }
    }

    private static function parseSimilarTo(Request $request, array &$filters): void
    {
        $similarTo = $request->getQueryParam('similarTo');

        if ($similarTo !== null && is_numeric($similarTo) && (int) $similarTo > 0) {
            $filters['similarTo'] = (int) $similarTo;
        }
    }

    /**
     * Parse date from Craft's date picker format or string.
     */
    private static function parseDateParam(array|string $param): ?\DateTime
    {
        if (is_array($param) && isset($param['date'])) {
            $dateStr = $param['date'];

            if (empty($dateStr)) {
                return null;
            }

            $timezone = $param['timezone'] ?? \Craft::$app->getTimeZone();

            try {
                return new \DateTime($dateStr, new \DateTimeZone($timezone));
            } catch (\Exception $e) {
                Logger::warning(LogCategory::AssetProcessing, 'Failed to parse date parameter', context: ['input' => $dateStr, 'error' => $e->getMessage()]);
                return null;
            }
        }

        if (is_string($param) && trim($param) !== '') {
            try {
                return new \DateTime($param);
            } catch (\Exception $e) {
                Logger::warning(LogCategory::AssetProcessing, 'Failed to parse date parameter', context: ['input' => $param, 'error' => $e->getMessage()]);
                return null;
            }
        }

        return null;
    }

    /**
     * Parse a comma-separated array parameter from request.
     */
    private static function parseCommaSeparatedArray(Request $request, string $paramName, array &$filters): void
    {
        $value = $request->getQueryParam($paramName);

        if ($value !== null) {
            if (is_string($value)) {
                $value = array_filter(explode(',', $value));
            }

            if (!empty($value)) {
                $filters[$paramName] = $value;
            }
        }
    }
}
