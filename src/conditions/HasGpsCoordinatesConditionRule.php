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
 * Condition rule for filtering assets by GPS coordinates presence.
 */
class HasGpsCoordinatesConditionRule extends BaseLightswitchConditionRule implements ElementConditionRuleInterface
{
    public function getGroupLabel(): string
    {
        return 'Lens';
    }

    public function getLabel(): string
    {
        return Craft::t('lens', 'Has GPS Coordinates');
    }

    public function getExclusiveQueryParams(): array
    {
        return ['lensHasGpsCoordinates'];
    }

    public function modifyQuery(ElementQueryInterface $query): void
    {
        /** @var AssetQuery $query */
        $query->lensHasGpsCoordinates($this->value);
        $query->lensApplyHasGpsCoordinatesFilter();
    }

    public function matchElement(ElementInterface $element): bool
    {
        /** @var Asset $element */
        $hasGps = Plugin::getInstance()->exifMetadata->hasGpsCoordinates($element->id);

        return $this->matchValue($hasGps);
    }
}
