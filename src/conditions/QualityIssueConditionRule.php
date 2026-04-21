<?php

declare(strict_types=1);

namespace vitordiniz22\craftlens\conditions;

use Craft;
use craft\base\conditions\BaseMultiSelectConditionRule;
use craft\base\ElementInterface;
use craft\elements\Asset;
use craft\elements\conditions\ElementConditionRuleInterface;
use craft\elements\db\AssetQuery;
use craft\elements\db\ElementQueryInterface;
use vitordiniz22\craftlens\helpers\ImageMetricsAnalyzer;
use vitordiniz22\craftlens\helpers\QualitySupport;
use vitordiniz22\craftlens\Plugin;

class QualityIssueConditionRule extends BaseMultiSelectConditionRule implements ElementConditionRuleInterface
{
    public function getGroupLabel(): string
    {
        return 'Lens';
    }

    public function getLabel(): string
    {
        return Craft::t('lens', 'Quality Issue');
    }

    public function getExclusiveQueryParams(): array
    {
        return ['lensBlurry', 'lensTooDark', 'lensTooBright', 'lensLowContrast'];
    }

    protected function options(): array
    {
        return [
            ['value' => 'blurry', 'label' => Craft::t('lens', 'Blurry')],
            ['value' => 'tooDark', 'label' => Craft::t('lens', 'Too dark')],
            ['value' => 'tooBright', 'label' => Craft::t('lens', 'Too bright')],
            ['value' => 'lowContrast', 'label' => Craft::t('lens', 'Low contrast')],
        ];
    }

    public function modifyQuery(ElementQueryInterface $query): void
    {
        /** @var AssetQuery $query */
        if (!QualitySupport::isAvailable()) {
            return;
        }

        $issues = (array) $this->paramValue();

        if (empty($issues)) {
            return;
        }

        // Stacking multiple per-issue apply methods would AND them and
        // return the intersection (near-empty). Assemble one OR expression
        // via lensAddRawWhere so the rule matches any selected issue,
        // like the custom browser's multi-select.
        $or = ['or'];

        foreach ($issues as $issue) {
            $or[] = $this->buildConditionForIssue($issue);
        }

        $query->lensAddRawWhere($or);
        $query->lensApplyRawWhereConditions();
    }

    /**
     * @return array<mixed>
     */
    private function buildConditionForIssue(string $issue): array
    {
        return match ($issue) {
            'blurry' => [
                'and',
                ['<', 'lens.sharpnessScore', ImageMetricsAnalyzer::SHARPNESS_BLURRY],
                ['not', ['lens.sharpnessScore' => null]],
            ],
            'tooDark' => [
                'and',
                ['<', 'lens.exposureScore', ImageMetricsAnalyzer::BRIGHTNESS_DARK_MEDIAN],
                ['>', 'lens.shadowClipRatio', ImageMetricsAnalyzer::SHADOW_CLIP_RATIO],
                ['<', 'lens.noiseScore', ImageMetricsAnalyzer::CONTRAST_LOW],
                ['not', ['lens.exposureScore' => null]],
                ['not', ['lens.noiseScore' => null]],
            ],
            'tooBright' => [
                'and',
                ['>', 'lens.exposureScore', ImageMetricsAnalyzer::BRIGHTNESS_BRIGHT_MEDIAN],
                ['>', 'lens.highlightClipRatio', ImageMetricsAnalyzer::HIGHLIGHT_CLIP_RATIO],
                ['<', 'lens.noiseScore', ImageMetricsAnalyzer::CONTRAST_LOW],
                ['not', ['lens.exposureScore' => null]],
                ['not', ['lens.noiseScore' => null]],
            ],
            'lowContrast' => [
                'and',
                ['<', 'lens.noiseScore', ImageMetricsAnalyzer::CONTRAST_LOW],
                ['not', ['lens.noiseScore' => null]],
            ],
            default => ['1' => '0'],
        };
    }

    public function matchElement(ElementInterface $element): bool
    {
        /** @var Asset $element */
        $issues = (array) $this->paramValue();

        if (empty($issues)) {
            return true;
        }

        $analysis = Plugin::getInstance()->assetAnalysis->getAnalysis($element->id);

        if ($analysis === null) {
            return false;
        }

        foreach ($issues as $issue) {
            if ($this->analysisMatchesIssue($analysis, $issue)) {
                return true;
            }
        }

        return false;
    }

    private function analysisMatchesIssue(object $analysis, string $issue): bool
    {
        $sharpness = $analysis->sharpnessScore ?? null;
        $exposure = $analysis->exposureScore ?? null;
        $shadowClip = $analysis->shadowClipRatio ?? null;
        $highlightClip = $analysis->highlightClipRatio ?? null;
        $noise = $analysis->noiseScore ?? null;

        return match ($issue) {
            'blurry' => $sharpness !== null && $sharpness < ImageMetricsAnalyzer::SHARPNESS_BLURRY,
            'tooDark' => $exposure !== null && $noise !== null
                && $exposure < ImageMetricsAnalyzer::BRIGHTNESS_DARK_MEDIAN
                && (float) $shadowClip > ImageMetricsAnalyzer::SHADOW_CLIP_RATIO
                && $noise < ImageMetricsAnalyzer::CONTRAST_LOW,
            'tooBright' => $exposure !== null && $noise !== null
                && $exposure > ImageMetricsAnalyzer::BRIGHTNESS_BRIGHT_MEDIAN
                && (float) $highlightClip > ImageMetricsAnalyzer::HIGHLIGHT_CLIP_RATIO
                && $noise < ImageMetricsAnalyzer::CONTRAST_LOW,
            'lowContrast' => $noise !== null && $noise < ImageMetricsAnalyzer::CONTRAST_LOW,
            default => false,
        };
    }
}
