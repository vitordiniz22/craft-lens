<?php

declare(strict_types=1);

namespace vitordiniz22\craftlens\conditions;

use Craft;
use craft\base\conditions\BaseNumberConditionRule;
use craft\base\ElementInterface;
use craft\elements\Asset;
use craft\elements\conditions\ElementConditionRuleInterface;
use craft\elements\db\AssetQuery;
use craft\elements\db\ElementQueryInterface;
use vitordiniz22\craftlens\Plugin;

/**
 * Condition rule for filtering assets by AI confidence score.
 */
class AiConfidenceConditionRule extends BaseNumberConditionRule implements ElementConditionRuleInterface
{
    public int|float|null $step = 0.05;

    public function getLabel(): string
    {
        return Craft::t('lens', 'Lens - AI Confidence');
    }

    public function getExclusiveQueryParams(): array
    {
        return ['lensConfidenceAbove', 'lensConfidenceBelow'];
    }

    public function modifyQuery(ElementQueryInterface $query): void
    {
        /** @var AssetQuery $query */
        $value = $this->paramValue();

        if ($value === null) {
            return;
        }

        $where = match ($this->operator) {
            self::OPERATOR_EQ => ['lens.altTextConfidence' => $value],
            self::OPERATOR_NE => ['not', ['lens.altTextConfidence' => $value]],
            self::OPERATOR_LT => ['<', 'lens.altTextConfidence', $value],
            self::OPERATOR_LTE => ['<=', 'lens.altTextConfidence', $value],
            self::OPERATOR_GT => ['>', 'lens.altTextConfidence', $value],
            self::OPERATOR_GTE => ['>=', 'lens.altTextConfidence', $value],
            self::OPERATOR_BETWEEN => ['between', 'lens.altTextConfidence', $value, $this->maxValue],
            self::OPERATOR_EMPTY => ['lens.altTextConfidence' => null],
            self::OPERATOR_NOT_EMPTY => ['not', ['lens.altTextConfidence' => null]],
            default => null,
        };

        if ($where === null) {
            return;
        }

        $query->lensRawWhereConditions[] = $where;
        $query->lensApplyRawWhereConditions();
    }

    public function matchElement(ElementInterface $element): bool
    {
        /** @var Asset $element */
        $analysis = Plugin::getInstance()->assetAnalysis->getAnalysis($element->id);
        $confidence = $analysis?->altTextConfidence;

        return $this->matchValue($confidence);
    }
}
