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
        'query', 'tags', 'status', 'containsPeople',
        'faceCountPreset',
        'confidenceMin', 'confidenceMax',
        'nsfwScoreMin', 'nsfwScoreMax',
        'processedFrom', 'processedTo',
        'colorFamily', 'hasDuplicates', 'quickFilter',
        'hasWatermark', 'watermarkType', 'containsBrandLogo',
        'qualityPreset', 'hasGps', 'hasFocalPoint',
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
        $tags = $request->getQueryParam('tags');

        if ($tags !== null) {
            if (is_string($tags)) {
                $tags = array_filter(explode(',', $tags));
            }
            if (!empty($tags)) {
                $filters['tags'] = $tags;
            }
        }

        $tagOperator = $request->getQueryParam('tagOperator');

        if ($tagOperator === 'and' || $tagOperator === 'or') {
            $filters['tagOperator'] = $tagOperator;
        }
    }

    private static function parseStatus(Request $request, array &$filters): void
    {
        $status = $request->getQueryParam('status');

        if ($status !== null) {
            if (is_string($status)) {
                $status = array_filter(explode(',', $status));
            }
            if (!empty($status)) {
                $filters['status'] = $status;
            }
        }
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
        // Confidence
        $confidenceMin = $request->getQueryParam('confidenceMin');

        if ($confidenceMin !== null && is_numeric($confidenceMin)) {
            $filters['confidenceMin'] = (float) $confidenceMin;
        }

        $confidenceMax = $request->getQueryParam('confidenceMax');

        if ($confidenceMax !== null && is_numeric($confidenceMax)) {
            $filters['confidenceMax'] = (float) $confidenceMax;
        }

        // NSFW
        $nsfwScoreMin = $request->getQueryParam('nsfwScoreMin');
        $nsfwScoreMax = $request->getQueryParam('nsfwScoreMax');

        if ($nsfwScoreMin !== null && is_numeric($nsfwScoreMin)) {
            $filters['nsfwScoreMin'] = max(0, min(100, (int) $nsfwScoreMin)) / 100;
        }

        if ($nsfwScoreMax !== null && is_numeric($nsfwScoreMax)) {
            $filters['nsfwScoreMax'] = max(0, min(100, (int) $nsfwScoreMax)) / 100;
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
        $hasDuplicates = $request->getQueryParam('hasDuplicates');

        if ($hasDuplicates !== null && $hasDuplicates !== '') {
            $filters['hasDuplicates'] = filter_var($hasDuplicates, FILTER_VALIDATE_BOOLEAN);
        }

        $hasWatermark = $request->getQueryParam('hasWatermark');

        if ($hasWatermark !== null && $hasWatermark !== '') {
            $filters['hasWatermark'] = filter_var($hasWatermark, FILTER_VALIDATE_BOOLEAN);
        }

        $containsBrandLogo = $request->getQueryParam('containsBrandLogo');

        if ($containsBrandLogo !== null && $containsBrandLogo !== '') {
            $filters['containsBrandLogo'] = filter_var($containsBrandLogo, FILTER_VALIDATE_BOOLEAN);
        }

        $hasGps = $request->getQueryParam('hasGps');

        if ($hasGps !== null && $hasGps !== '') {
            $filters['hasGps'] = filter_var($hasGps, FILTER_VALIDATE_BOOLEAN);
        }

        $hasFocalPoint = $request->getQueryParam('hasFocalPoint');

        if ($hasFocalPoint !== null && $hasFocalPoint !== '') {
            $filters['hasFocalPoint'] = filter_var($hasFocalPoint, FILTER_VALIDATE_BOOLEAN);
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
}
