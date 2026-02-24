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

/**
 * Condition rule for filtering assets by NSFW flag.
 */
class NsfwFlaggedConditionRule extends BaseLightswitchConditionRule implements ElementConditionRuleInterface
{
    public function getLabel(): string
    {
        return Craft::t('lens', 'Lens - NSFW Flagged');
    }

    public function getExclusiveQueryParams(): array
    {
        return ['lensNsfwFlagged'];
    }

    public function modifyQuery(ElementQueryInterface $query): void
    {
        /** @var AssetQuery $query */
        $query->lensNsfwFlagged($this->value);
        $query->lensApplyNsfwFlaggedFilter();
    }

    public function matchElement(ElementInterface $element): bool
    {
        /** @var Asset $element */
        $analysis = Plugin::getInstance()->assetAnalysis->getAnalysis($element->id);
        $isFlagged = $analysis?->isFlaggedNsfw ?? false;

        return $this->matchValue($isFlagged);
    }
}
