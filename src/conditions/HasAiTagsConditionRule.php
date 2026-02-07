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
use vitordiniz22\craftlens\records\AssetTagRecord;

/**
 * Condition rule for filtering assets by AI tag presence.
 */
class HasAiTagsConditionRule extends BaseLightswitchConditionRule implements ElementConditionRuleInterface
{
    public function getLabel(): string
    {
        return Craft::t('lens', 'Has AI Tags');
    }

    public function getExclusiveQueryParams(): array
    {
        return ['lensHasTags'];
    }

    public function modifyQuery(ElementQueryInterface $query): void
    {
        /** @var AssetQuery $query */
        $query->lensHasTags($this->value);
    }

    public function matchElement(ElementInterface $element): bool
    {
        /** @var Asset $element */
        $analysis = Plugin::getInstance()->assetAnalysis->getAnalysis($element->id);
        $hasTags = $analysis !== null && AssetTagRecord::find()->where(['analysisId' => $analysis->id])->exists();

        return $this->matchValue($hasTags);
    }
}
