<?php

declare(strict_types=1);

namespace vitordiniz22\craftlens\conditions;

use Craft;
use craft\base\conditions\BaseSelectConditionRule;
use craft\base\ElementInterface;
use craft\elements\Asset;
use craft\elements\conditions\ElementConditionRuleInterface;
use craft\elements\db\AssetQuery;
use craft\elements\db\ElementQueryInterface;
use vitordiniz22\craftlens\Plugin;

class FaceCountConditionRule extends BaseSelectConditionRule implements ElementConditionRuleInterface
{
    public function getGroupLabel(): string
    {
        return 'Lens';
    }

    public function getLabel(): string
    {
        return Craft::t('lens', 'Face Count');
    }

    public function getExclusiveQueryParams(): array
    {
        return ['lensFaceCountPreset'];
    }

    protected function options(): array
    {
        return [
            ['value' => '', 'label' => Craft::t('lens', 'Any')],
            ['value' => '0', 'label' => Craft::t('lens', 'No people')],
            ['value' => '1', 'label' => Craft::t('lens', 'Individual (1)')],
            ['value' => '2-5', 'label' => Craft::t('lens', 'Small group (2-5)')],
            ['value' => '6+', 'label' => Craft::t('lens', 'Large group (6+)')],
        ];
    }

    public function modifyQuery(ElementQueryInterface $query): void
    {
        /** @var AssetQuery $query */
        if ($this->value === '') {
            return;
        }

        $query->lensFaceCountPreset($this->value);
        $query->lensApplyFaceCountPresetFilter();
    }

    public function matchElement(ElementInterface $element): bool
    {
        /** @var Asset $element */
        if ($this->value === '') {
            return true;
        }

        $analysis = Plugin::getInstance()->assetAnalysis->getAnalysis($element->id);

        if ($analysis === null) {
            return false;
        }

        $faces = (int) ($analysis->faceCount ?? 0);
        $containsPeople = (bool) ($analysis->containsPeople ?? false);

        return match ($this->value) {
            '0' => !$containsPeople,
            '1' => $faces === 1,
            '2-5' => $faces >= 2 && $faces <= 5,
            '6+' => $faces >= 6,
            default => false,
        };
    }
}
