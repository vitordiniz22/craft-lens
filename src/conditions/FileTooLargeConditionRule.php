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

class FileTooLargeConditionRule extends BaseLightswitchConditionRule implements ElementConditionRuleInterface
{
    public const FILE_SIZE_WARNING = 1_000_000;

    public function getGroupLabel(): string
    {
        return 'Lens';
    }

    public function getLabel(): string
    {
        return Craft::t('lens', 'File Too Large (>1MB)');
    }

    public function getExclusiveQueryParams(): array
    {
        return ['lensTooLarge'];
    }

    public function modifyQuery(ElementQueryInterface $query): void
    {
        /** @var AssetQuery $query */
        $query->lensTooLarge($this->value);
        $query->lensApplyTooLargeFilter();
    }

    public function matchElement(ElementInterface $element): bool
    {
        /** @var Asset $element */
        $isTooLarge = ($element->size ?? 0) >= self::FILE_SIZE_WARNING;

        return $this->matchValue($isTooLarge);
    }
}
