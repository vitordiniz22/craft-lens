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
use vitordiniz22\craftlens\helpers\ImageQualityChecker;
use vitordiniz22\craftlens\Plugin;

/**
 * Condition rule for filtering assets by web readiness issues.
 * Only matches analyzed assets.
 */
class WebReadinessConditionRule extends BaseMultiSelectConditionRule implements ElementConditionRuleInterface
{
    public function getGroupLabel(): string
    {
        return 'Lens';
    }

    public function getLabel(): string
    {
        return Craft::t('lens', 'Web Readiness Issues');
    }

    public function getExclusiveQueryParams(): array
    {
        return ['lensWebReadinessIssues'];
    }

    protected function options(): array
    {
        return [
            'fileTooLarge' => Craft::t('lens', 'File Too Large (>1MB)'),
            'resolutionTooSmall' => Craft::t('lens', 'Resolution Too Small'),
            'unsupportedFormat' => Craft::t('lens', 'Unsupported Format'),
        ];
    }

    public function modifyQuery(ElementQueryInterface $query): void
    {
        /** @var AssetQuery $query */
        $values = $this->paramValue();

        if (empty($values)) {
            return;
        }

        $query->lensWebReadinessIssues($values);
        $query->lensApplyWebReadinessFilter();
    }

    public function matchElement(ElementInterface $element): bool
    {
        /** @var Asset $element */
        $analysis = Plugin::getInstance()->assetAnalysis->getAnalysis($element->id);

        if ($analysis === null) {
            return false;
        }

        $values = $this->paramValue();

        if (empty($values)) {
            return true;
        }

        foreach ($values as $value) {
            $matches = match ($value) {
                'fileTooLarge' => ($element->size ?? 0) >= ImageQualityChecker::FILE_SIZE_WARNING,
                'resolutionTooSmall' => ($element->width ?? 0) > 0 && ($element->width ?? 0) < ImageQualityChecker::MIN_WIDTH_RECOMMENDED,
                'unsupportedFormat' => in_array(strtolower($element->getExtension()), ['tif', 'tiff'], true),
                default => false,
            };

            if ($matches) {
                return true;
            }
        }

        return false;
    }
}
