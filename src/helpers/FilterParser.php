<?php

declare(strict_types=1);

namespace vitordiniz22\craftlens\helpers;

use craft\web\Request;
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
        'colorFamily', 'colorTolerance', 'hasDuplicates', 'quickFilter',
        'hasWatermark', 'watermarkType', 'containsBrandLogo',
        'qualityPreset', 'hasGps', 'hasFocalPoint',
        'nsfwFlagged',
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
        self::parseEnumFilters($request, $filters);
        self::parseQuickFilter($request, $filters);
        self::parsePagination($request, $filters);
        self::parseNsfwFlagged($request, $filters);

        return $filters;
    }

    /**
     * Check if any filter is active (beyond pagination).
     */
    public static function hasActiveFilters(array $filters): bool
    {
        foreach (self::FILTER_KEYS as $key) {
            if (isset($filters[$key])) {
                return true;
            }
        }

        return false;
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
                'transform' => fn($v) => (float) $v
            ],
            'nsfwScore' => [
                'min' => 'nsfwScoreMin',
                'max' => 'nsfwScoreMax',
                'transform' => fn($v) => max(0, min(100, (int) $v)) / 100
            ],
        ];

        foreach ($rangeFilters as $config) {
            $min = $request->getQueryParam($config['min']);

            if ($min !== null && is_numeric($min)) {
                $filters[$config['min']] = $config['transform']($min);
            }

            $max = $request->getQueryParam($config['max']);

            if ($max !== null && is_numeric($max)) {
                $filters[$config['max']] = $config['transform']($max);
            }
        }
    }

    private static function parseDateFilters(Request $request, array &$filters): void
    {
        $processedFrom = $request->getQueryParam('processedFrom');

        if ($processedFrom !== null) {
            $date = self::parseDateParam($processedFrom);
            if ($date !== null) {
                $filters['processedFrom'] = $date;
            }
        }

        $processedTo = $request->getQueryParam('processedTo');

        if ($processedTo !== null) {
            $date = self::parseDateParam($processedTo);
            if ($date !== null) {
                $filters['processedTo'] = $date;
            }
        }
    }

    private static function parseColorFilters(Request $request, array &$filters): void
    {
        $colorFamily = $request->getQueryParam('colorFamily');

        if ($colorFamily !== null && trim($colorFamily) !== '') {
            $filters['colorFamily'] = trim($colorFamily);
        }

        $colorTolerance = $request->getQueryParam('colorTolerance');

        if ($colorTolerance !== null && is_numeric($colorTolerance)) {
            $filters['colorTolerance'] = max(0, min(100, (int) $colorTolerance));
        }
    }

    private static function parseBooleanFilters(Request $request, array &$filters): void
    {
        $booleanFilterKeys = [
            'hasDuplicates', 'hasWatermark', 'containsBrandLogo',
            'hasGps', 'hasFocalPoint',
        ];

        foreach ($booleanFilterKeys as $key) {
            $value = $request->getQueryParam($key);

            if ($value !== null && $value !== '') {
                $filters[$key] = filter_var($value, FILTER_VALIDATE_BOOLEAN);
            }
        }
    }

    private static function parseEnumFilters(Request $request, array &$filters): void
    {
        $watermarkType = $request->getQueryParam('watermarkType');

        if ($watermarkType !== null && in_array($watermarkType, ['stock', 'logo', 'text', 'copyright'], true)) {
            $filters['watermarkType'] = $watermarkType;
        }

        $qualityPreset = $request->getQueryParam('qualityPreset');

        if ($qualityPreset !== null && in_array($qualityPreset, ['high', 'medium', 'low'], true)) {
            $filters['qualityPreset'] = $qualityPreset;
        }
    }

    private static function parseQuickFilter(Request $request, array &$filters): void
    {
        $quickFilter = $request->getQueryParam('quickFilter');

        if ($quickFilter !== null && trim($quickFilter) !== '') {
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
            } catch (\Exception) {
                return null;
            }
        }

        if (is_string($param) && trim($param) !== '') {
            try {
                return new \DateTime($param);
            } catch (\Exception) {
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
