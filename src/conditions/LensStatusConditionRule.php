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
use vitordiniz22\craftlens\enums\AnalysisStatus;
use vitordiniz22\craftlens\Plugin;

/**
 * Condition rule for filtering assets by Lens analysis status.
 */
class LensStatusConditionRule extends BaseMultiSelectConditionRule implements ElementConditionRuleInterface
{
    public function getGroupLabel(): string
    {
        return 'Lens';
    }

    public function getLabel(): string
    {
        return Craft::t('lens', 'Status');
    }

    public function getExclusiveQueryParams(): array
    {
        return ['lensStatus'];
    }

    protected function options(): array
    {
        return AnalysisStatus::options();
    }

    public function modifyQuery(ElementQueryInterface $query): void
    {
        /** @var AssetQuery $query */
        $values = $this->paramValue();

        if (empty($values)) {
            return;
        }

        $query->lensStatus($values);
        $query->lensApplyStatusFilter();
    }

    public function matchElement(ElementInterface $element): bool
    {
        /** @var Asset $element */
        $analysis = Plugin::getInstance()->assetAnalysis->getAnalysis($element->id);
        $status = $analysis?->status;

        return $this->matchValue($status);
    }
}
