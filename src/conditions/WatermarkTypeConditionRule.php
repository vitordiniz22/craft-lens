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
use vitordiniz22\craftlens\enums\WatermarkType;
use vitordiniz22\craftlens\Plugin;

/**
 * Condition rule for filtering assets by watermark type.
 */
class WatermarkTypeConditionRule extends BaseMultiSelectConditionRule implements ElementConditionRuleInterface
{
    public function getLabel(): string
    {
        return Craft::t('lens', 'Watermark Type');
    }

    public function getExclusiveQueryParams(): array
    {
        return ['lensWatermarkType', 'lensWatermarkTypes'];
    }

    protected function options(): array
    {
        return WatermarkType::options();
    }

    public function modifyQuery(ElementQueryInterface $query): void
    {
        /** @var AssetQuery $query */
        $values = $this->paramValue();

        if (empty($values)) {
            return;
        }

        $query->lensWatermarkTypes($values);
    }

    public function matchElement(ElementInterface $element): bool
    {
        /** @var Asset $element */
        $analysis = Plugin::getInstance()->assetAnalysis->getAnalysis($element->id);

        if ($analysis === null || !$analysis->hasWatermark) {
            return false;
        }

        return $this->matchValue($analysis->watermarkType);
    }
}
