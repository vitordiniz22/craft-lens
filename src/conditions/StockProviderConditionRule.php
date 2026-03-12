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
use vitordiniz22\craftlens\Plugin;

/**
 * Condition rule for filtering assets by detected stock photo provider.
 */
class StockProviderConditionRule extends BaseMultiSelectConditionRule implements ElementConditionRuleInterface
{
    public function getGroupLabel(): string
    {
        return 'Lens';
    }

    public function getLabel(): string
    {
        return Craft::t('lens', 'Stock Provider');
    }

    public function getExclusiveQueryParams(): array
    {
        return ['lensStockProvider'];
    }

    protected function options(): array
    {
        return Plugin::getInstance()->assetAnalysis->getDetectedStockProviders();
    }

    public function modifyQuery(ElementQueryInterface $query): void
    {
        /** @var AssetQuery $query */
        $values = $this->paramValue();

        if (empty($values)) {
            return;
        }

        $query->lensStockProvider($values);
        $query->lensApplyStockProviderFilter();
    }

    public function matchElement(ElementInterface $element): bool
    {
        /** @var Asset $element */
        $analysis = Plugin::getInstance()->assetAnalysis->getAnalysis($element->id);

        if ($analysis === null || !$analysis->hasWatermark) {
            return false;
        }

        $stockProvider = $analysis->getStockProvider();

        if ($stockProvider === null) {
            return false;
        }

        $stockProviderLower = strtolower($stockProvider);
        $valuesLower = array_map('strtolower', $this->paramValue());

        return in_array($stockProviderLower, $valuesLower, true);
    }
}
