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

/**
 * Condition rule for filtering assets by a specific detected brand.
 */
class DetectedBrandConditionRule extends BaseSelectConditionRule implements ElementConditionRuleInterface
{
    public function getGroupLabel(): string
    {
        return 'Lens';
    }

    public function getLabel(): string
    {
        return Craft::t('lens', 'Detected Brand');
    }

    public function getExclusiveQueryParams(): array
    {
        return ['lensDetectedBrand'];
    }

    protected function options(): array
    {
        return Plugin::getInstance()->assetAnalysis->getDetectedBrands();
    }

    public function modifyQuery(ElementQueryInterface $query): void
    {
        /** @var AssetQuery $query */
        $value = $this->paramValue();

        if ($value === null || $value === '') {
            return;
        }

        $query->lensDetectedBrand($value);
        $query->lensApplyDetectedBrandFilter();
    }

    public function matchElement(ElementInterface $element): bool
    {
        /** @var Asset $element */
        $analysis = Plugin::getInstance()->assetAnalysis->getAnalysis($element->id);

        if ($analysis === null) {
            return false;
        }

        $brands = $analysis->detectedBrands;

        if (is_string($brands)) {
            $brands = json_decode($brands, true) ?: [];
        }

        if (!is_array($brands) || empty($brands)) {
            return false;
        }

        $target = strtolower((string) $this->paramValue());

        foreach ($brands as $entry) {
            $name = is_array($entry) ? ($entry['brand'] ?? null) : null;

            if (is_string($name) && strtolower($name) === $target) {
                return true;
            }
        }

        return false;
    }
}
