<?php

declare(strict_types=1);

namespace vitordiniz22\craftlens\conditions;

use Craft;
use craft\base\conditions\BaseConditionRule;
use craft\base\ElementInterface;
use craft\elements\Asset;
use craft\elements\conditions\ElementConditionRuleInterface;
use craft\elements\db\AssetQuery;
use craft\elements\db\ElementQueryInterface;
use craft\helpers\Cp;
use craft\helpers\Html;
use vitordiniz22\craftlens\helpers\ColorMatcher;
use vitordiniz22\craftlens\helpers\ColorSupport;
use vitordiniz22\craftlens\Plugin;

class LensColorConditionRule extends BaseConditionRule implements ElementConditionRuleInterface
{
    public string $hex = '';

    /** @var string One of the keys in self::TOLERANCES */
    public string $tolerance = 'close';

    private const TOLERANCES = [
        'exact' => 10,
        'close' => 30,
        'broad' => 55,
        'any' => 80,
    ];

    public function getGroupLabel(): string
    {
        return 'Lens';
    }

    public function getLabel(): string
    {
        return Craft::t('lens', 'Dominant Color');
    }

    public function getExclusiveQueryParams(): array
    {
        return ['lensColor', 'lensColorTolerance'];
    }

    public function getConfig(): array
    {
        return array_merge(parent::getConfig(), [
            'hex' => $this->hex,
            'tolerance' => $this->tolerance,
        ]);
    }

    protected function defineRules(): array
    {
        return array_merge(parent::defineRules(), [
            [['hex', 'tolerance'], 'safe'],
        ]);
    }

    protected function inputHtml(): string
    {
        $toleranceOptions = [
            ['value' => 'exact', 'label' => Craft::t('lens', 'Exact (10)')],
            ['value' => 'close', 'label' => Craft::t('lens', 'Close (30)')],
            ['value' => 'broad', 'label' => Craft::t('lens', 'Broad (55)')],
            ['value' => 'any', 'label' => Craft::t('lens', 'Any similar (80)')],
        ];

        return
            Html::hiddenLabel(Craft::t('lens', 'Hex'), 'hex') .
            Cp::textHtml([
                'type' => 'color',
                'id' => 'hex',
                'name' => 'hex',
                'value' => $this->hex !== '' ? $this->hex : '#808080',
            ]) .
            Html::hiddenLabel(Craft::t('lens', 'Tolerance'), 'tolerance') .
            Cp::selectHtml([
                'id' => 'tolerance',
                'name' => 'tolerance',
                'options' => $toleranceOptions,
                'value' => $this->tolerance,
            ]);
    }

    public function modifyQuery(ElementQueryInterface $query): void
    {
        /** @var AssetQuery $query */
        if (!ColorSupport::isAvailable() || $this->hex === '') {
            return;
        }

        $tolerance = self::TOLERANCES[$this->tolerance] ?? ColorMatcher::DEFAULT_TOLERANCE;

        $query->lensColor($this->hex);
        $query->lensColorTolerance($tolerance);
        $query->lensApplyColorFilter();
    }

    public function matchElement(ElementInterface $element): bool
    {
        /** @var Asset $element */
        if ($this->hex === '') {
            return true;
        }

        $analysis = Plugin::getInstance()->assetAnalysis->getAnalysis($element->id);

        if ($analysis === null) {
            return false;
        }

        $colors = Plugin::getInstance()->colorAggregation->getColorsForAnalysis($analysis->id);
        $tolerance = self::TOLERANCES[$this->tolerance] ?? ColorMatcher::DEFAULT_TOLERANCE;
        $target = ColorMatcher::hexToHsl($this->hex);

        foreach ($colors as $color) {
            if (ColorMatcher::hslMatches((string) $color->hex, $target, $tolerance)) {
                return true;
            }
        }

        return false;
    }
}
