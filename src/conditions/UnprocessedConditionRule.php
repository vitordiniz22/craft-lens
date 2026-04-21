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
use vitordiniz22\craftlens\enums\AnalysisStatus;
use vitordiniz22\craftlens\Plugin;

class UnprocessedConditionRule extends BaseLightswitchConditionRule implements ElementConditionRuleInterface
{
    public function getGroupLabel(): string
    {
        return 'Lens';
    }

    public function getLabel(): string
    {
        return Craft::t('lens', 'Unprocessed');
    }

    public function getExclusiveQueryParams(): array
    {
        return ['lensUnprocessed'];
    }

    public function modifyQuery(ElementQueryInterface $query): void
    {
        /** @var AssetQuery $query */
        $query->lensUnprocessed($this->value);
        $query->lensApplyUnprocessedFilter();
    }

    public function matchElement(ElementInterface $element): bool
    {
        /** @var Asset $element */
        $analysis = Plugin::getInstance()->assetAnalysis->getAnalysis($element->id);

        $isUnprocessed = $analysis === null
            || in_array($analysis->status, AnalysisStatus::unprocessedStatuses(), true);

        return $this->matchValue($isUnprocessed);
    }
}
