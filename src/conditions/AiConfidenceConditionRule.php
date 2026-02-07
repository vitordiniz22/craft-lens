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
use vitordiniz22\craftlens\migrations\Install;
use vitordiniz22\craftlens\Plugin;

/**
 * Condition rule for filtering assets by AI confidence score.
 */
class AiConfidenceConditionRule extends BaseNumberConditionRule implements ElementConditionRuleInterface
{
    public int|float|null $step = 0.05;

    public function getLabel(): string
    {
        return Craft::t('lens', 'AI Confidence');
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

        $query->lensEnsureJoined();

        switch ($this->operator) {
            case self::OPERATOR_EQ:
                $query->subQuery->andWhere(['lens.altTextConfidence' => $value]);
                break;
            case self::OPERATOR_NE:
                $query->subQuery->andWhere(['not', ['lens.altTextConfidence' => $value]]);
                break;
            case self::OPERATOR_LT:
                $query->subQuery->andWhere(['<', 'lens.altTextConfidence', $value]);
                break;
            case self::OPERATOR_LTE:
                $query->subQuery->andWhere(['<=', 'lens.altTextConfidence', $value]);
                break;
            case self::OPERATOR_GT:
                $query->subQuery->andWhere(['>', 'lens.altTextConfidence', $value]);
                break;
            case self::OPERATOR_GTE:
                $query->subQuery->andWhere(['>=', 'lens.altTextConfidence', $value]);
                break;
            case self::OPERATOR_BETWEEN:
                $query->subQuery->andWhere([
                    'between',
                    'lens.altTextConfidence',
                    $value,
                    $this->maxValue,
                ]);
                break;
            case self::OPERATOR_EMPTY:
                $query->subQuery->andWhere(['lens.altTextConfidence' => null]);
                break;
            case self::OPERATOR_NOT_EMPTY:
                $query->subQuery->andWhere(['not', ['lens.altTextConfidence' => null]]);
                break;
        }
    }

    public function matchElement(ElementInterface $element): bool
    {
        /** @var Asset $element */
        $analysis = Plugin::getInstance()->assetAnalysis->getAnalysis($element->id);
        $confidence = $analysis?->altTextConfidence;

        return $this->matchValue($confidence);
    }
}
