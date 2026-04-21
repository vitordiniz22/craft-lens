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

class NsfwScoreConditionRule extends BaseNumberConditionRule implements ElementConditionRuleInterface
{
    public function __construct($config = [])
    {
        $this->step = 0.05;
        parent::__construct($config);
    }

    public function getGroupLabel(): string
    {
        return 'Lens';
    }

    public function getLabel(): string
    {
        return Craft::t('lens', 'NSFW Score');
    }

    public function getExclusiveQueryParams(): array
    {
        return ['lensNsfwScoreMin', 'lensNsfwScoreMax'];
    }

    public function modifyQuery(ElementQueryInterface $query): void
    {
        /** @var AssetQuery $query */
        $min = null;
        $max = null;

        $value = $this->value === '' ? null : (float) $this->value;
        $maxValue = $this->maxValue === '' ? null : (float) $this->maxValue;

        switch ($this->operator) {
            case self::OPERATOR_EQ:
                if ($value !== null) {
                    $min = $max = $value;
                }
                break;
            case self::OPERATOR_GT:
                if ($value !== null) {
                    $min = $value + 0.000_001;
                }
                break;
            case self::OPERATOR_GTE:
                $min = $value;
                break;
            case self::OPERATOR_LT:
                if ($value !== null) {
                    $max = $value - 0.000_001;
                }
                break;
            case self::OPERATOR_LTE:
                $max = $value;
                break;
            case self::OPERATOR_BETWEEN:
                $min = $value;
                $max = $maxValue;
                break;
            default:
                return;
        }

        if ($min === null && $max === null) {
            return;
        }

        $query->lensNsfwScoreMin($min);
        $query->lensNsfwScoreMax($max);
        $query->lensApplyNsfwScoreRangeFilter();
    }

    public function matchElement(ElementInterface $element): bool
    {
        /** @var Asset $element */
        $analysis = Plugin::getInstance()->assetAnalysis->getAnalysis($element->id);
        $score = $analysis?->nsfwScore;

        return $score !== null && $this->matchValue((string) $score);
    }
}
