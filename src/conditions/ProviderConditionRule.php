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

class ProviderConditionRule extends BaseMultiSelectConditionRule implements ElementConditionRuleInterface
{
    public function getGroupLabel(): string
    {
        return 'Lens';
    }

    public function getLabel(): string
    {
        return Craft::t('lens', 'AI Provider');
    }

    public function getExclusiveQueryParams(): array
    {
        return ['lensProvider'];
    }

    protected function options(): array
    {
        return Plugin::getInstance()->search->getProviderOptions();
    }

    public function modifyQuery(ElementQueryInterface $query): void
    {
        /** @var AssetQuery $query */
        $values = (array) $this->paramValue();

        if (empty($values)) {
            return;
        }

        $query->lensProvider($values);
        $query->lensApplyProviderFilter();
    }

    public function matchElement(ElementInterface $element): bool
    {
        /** @var Asset $element */
        $analysis = Plugin::getInstance()->assetAnalysis->getAnalysis($element->id);

        return $this->matchValue($analysis?->provider);
    }
}
