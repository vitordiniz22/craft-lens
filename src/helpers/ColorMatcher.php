<?php

declare(strict_types=1);

namespace vitordiniz22\craftlens\helpers;

use vitordiniz22\craftlens\enums\AnalysisStatus;
use vitordiniz22\craftlens\migrations\Install;
use yii\db\Query;

/**
 * HSL-space color matching with a tolerance band. Shared between the custom
 * browser's SearchService and the native browser's AssetQueryBehavior so the
 * two surfaces return the same result set for a given hex + tolerance.
 */
class ColorMatcher
{
    public const DEFAULT_TOLERANCE = 30;

    /**
     * Return the asset IDs whose stored dominant colors match the target hex
     * within the given tolerance.
     *
     * @return int[]
     */
    public static function findAssetsMatching(string $hex, int $tolerance = self::DEFAULT_TOLERANCE): array
    {
        $targetHsl = self::hexToHsl($hex);

        $rows = (new Query())
            ->select(['c.hex', 'a.assetId'])
            ->from(Install::TABLE_ASSET_COLORS . ' c')
            ->innerJoin(Install::TABLE_ASSET_ANALYSES . ' a', '[[c.analysisId]] = [[a.id]]')
            ->where(['in', 'a.status', AnalysisStatus::analyzedValues()]);

        $matchingIds = [];

        foreach ($rows->batch(500) as $batch) {
            foreach ($batch as $row) {
                if (self::hslMatches((string) ($row['hex'] ?? ''), $targetHsl, $tolerance)) {
                    $matchingIds[] = (int) $row['assetId'];
                }
            }
        }

        return array_values(array_unique($matchingIds));
    }

    /**
     * True when `$hex` is within `$tolerance` of the target HSL triple.
     *
     * @param array{h: int, s: int, l: int} $targetHsl
     */
    public static function hslMatches(string $hex, array $targetHsl, int $tolerance): bool
    {
        if ($hex === '') {
            return false;
        }

        $hsl = self::hexToHsl($hex);

        $hueDiff = abs($hsl['h'] - $targetHsl['h']);
        $hueDist = min($hueDiff, 360 - $hueDiff);
        $satDist = abs($hsl['s'] - $targetHsl['s']);
        $lightDist = abs($hsl['l'] - $targetHsl['l']);

        return $hueDist <= $tolerance * 1.5
            && $satDist <= $tolerance
            && $lightDist <= $tolerance;
    }

    /**
     * @return array{h: int, s: int, l: int}
     */
    public static function hexToHsl(string $hex): array
    {
        $hex = ltrim($hex, '#');

        if (strlen($hex) === 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }

        $r = hexdec(substr($hex, 0, 2)) / 255;
        $g = hexdec(substr($hex, 2, 2)) / 255;
        $b = hexdec(substr($hex, 4, 2)) / 255;

        $max = max($r, $g, $b);
        $min = min($r, $g, $b);
        $l = ($max + $min) / 2;

        if ($max === $min) {
            $h = 0.0;
            $s = 0.0;
        } else {
            $d = $max - $min;
            $s = $l > 0.5 ? $d / (2 - $max - $min) : $d / ($max + $min);

            $h = match (true) {
                $max === $r => (($g - $b) / $d + ($g < $b ? 6 : 0)) / 6,
                $max === $g => (($b - $r) / $d + 2) / 6,
                default => (($r - $g) / $d + 4) / 6,
            };
        }

        return [
            'h' => (int) round($h * 360),
            's' => (int) round($s * 100),
            'l' => (int) round($l * 100),
        ];
    }
}
