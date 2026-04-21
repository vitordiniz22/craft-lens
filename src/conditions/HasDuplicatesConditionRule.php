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
use vitordiniz22\craftlens\Plugin;

class HasDuplicatesConditionRule extends BaseLightswitchConditionRule implements ElementConditionRuleInterface
{
    public function getGroupLabel(): string
    {
        return 'Lens';
    }

    public function getLabel(): string
    {
        return Craft::t('lens', 'Has Duplicates');
    }

    public function getExclusiveQueryParams(): array
    {
        return ['lensHasDuplicates'];
    }

    public function modifyQuery(ElementQueryInterface $query): void
    {
        /** @var AssetQuery $query */
        $query->lensHasDuplicates($this->value);
        $query->lensApplyHasDuplicatesFilter();
    }

    public function matchElement(ElementInterface $element): bool
    {
        /** @var Asset $element */
        $count = Plugin::getInstance()->duplicateDetection->getUnresolvedDuplicateCountsForAssets([$element->id]);

        return $this->matchValue(($count[$element->id] ?? 0) > 0);
    }
}
