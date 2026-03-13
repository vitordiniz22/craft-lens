<?php

declare(strict_types=1);

namespace vitordiniz22\craftlens\conditions;

use Craft;
use craft\base\conditions\BaseLightswitchConditionRule;
use craft\base\ElementInterface;
use craft\elements\Asset;
use craft\elements\conditions\ElementConditionRuleInterface;
use craft\elements\db\AssetQuery;
use craft\elements\db\ElementQueryInterface;
use vitordiniz22\craftlens\helpers\ImageMetricsAnalyzer;
use vitordiniz22\craftlens\Plugin;

/**
 * Condition rule for filtering assets with low overall quality scores.
 */
class LowQualityConditionRule extends BaseLightswitchConditionRule implements ElementConditionRuleInterface
{
    public function getGroupLabel(): string
    {
        return 'Lens';
    }

    public function getLabel(): string
    {
        return Craft::t('lens', 'Low Quality');
    }

    public function getExclusiveQueryParams(): array
    {
        return ['lensLowQuality'];
    }

    public function modifyQuery(ElementQueryInterface $query): void
    {
        /** @var AssetQuery $query */
        $query->lensLowQuality($this->value);
        $query->lensApplyLowQualityFilter();
    }

    public function matchElement(ElementInterface $element): bool
    {
        /** @var Asset $element */
        $analysis = Plugin::getInstance()->assetAnalysis->getAnalysis($element->id);

        if ($analysis === null || $analysis->overallQualityScore === null) {
            return !$this->value;
        }

        $isLowQuality = (float) $analysis->overallQualityScore < ImageMetricsAnalyzer::OVERALL_QUALITY_AI_THRESHOLD;

        return $this->matchValue($isLowQuality);
    }
}
