<?php

declare(strict_types=1);

namespace vitordiniz22\craftlens\helpers;

use Craft;

/**
 * Derives quality verdicts from existing AI scores.
 * Returns template-ready data — thresholds live here, not in Twig.
 */
class QualityAdvice
{
    public const SHARPNESS_THRESHOLD = 0.4;
    public const EXPOSURE_DARK_THRESHOLD = 0.3;
    public const EXPOSURE_BRIGHT_THRESHOLD = 0.85;
    public const NOISE_THRESHOLD = 0.4;
    public const OVERALL_QUALITY_THRESHOLD = 0.4;

    /**
     * Returns per-metric assessment data for the quality section template.
     *
     * @return array<string, array{label: string, score: float, verdict: string|null, status: 'pass'|'warning'}>
     */
    public static function assess(
        ?float $sharpnessScore,
        ?float $exposureScore,
        ?float $noiseScore,
    ): array {
        $metrics = [];

        if ($sharpnessScore !== null) {
            $isIssue = $sharpnessScore < self::SHARPNESS_THRESHOLD;
            $metrics['sharpness'] = [
                'label' => Craft::t('lens', 'Sharpness'),
                'score' => $sharpnessScore,
                'status' => $isIssue ? 'warning' : 'pass',
                'verdict' => $isIssue
                    ? Craft::t('lens', 'Blurry')
                    : ($sharpnessScore >= 0.7 ? Craft::t('lens', 'Sharp') : Craft::t('lens', 'Acceptable')),
            ];
        }

        if ($exposureScore !== null) {
            $isTooDark = $exposureScore < self::EXPOSURE_DARK_THRESHOLD;
            $isOverexposed = $exposureScore > self::EXPOSURE_BRIGHT_THRESHOLD;
            $isIssue = $isTooDark || $isOverexposed;
            $metrics['exposure'] = [
                'label' => Craft::t('lens', 'Exposure'),
                'score' => $exposureScore,
                'status' => $isIssue ? 'warning' : 'pass',
                'verdict' => $isTooDark
                    ? Craft::t('lens', 'Too dark')
                    : ($isOverexposed ? Craft::t('lens', 'Overexposed') : Craft::t('lens', 'Good')),
            ];
        }

        if ($noiseScore !== null) {
            $isIssue = $noiseScore < self::NOISE_THRESHOLD;
            $metrics['noise'] = [
                'label' => Craft::t('lens', 'Clarity'),
                'score' => $noiseScore,
                'status' => $isIssue ? 'warning' : 'pass',
                'verdict' => $isIssue
                    ? Craft::t('lens', 'Noisy')
                    : ($noiseScore >= 0.7 ? Craft::t('lens', 'Clean') : Craft::t('lens', 'Acceptable')),
            ];
        }

        return $metrics;
    }
}
