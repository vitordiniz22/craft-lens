<?php

declare(strict_types=1);

namespace vitordiniz22\craftlens\conditions;

use Craft;
use craft\base\conditions\BaseElementSelectConditionRule;
use craft\base\ElementInterface;
use craft\elements\Asset;
use craft\elements\conditions\ElementConditionRuleInterface;
use craft\elements\db\AssetQuery;
use craft\elements\db\ElementQueryInterface;
use vitordiniz22\craftlens\helpers\DuplicateSupport;
use vitordiniz22\craftlens\Plugin;

class SimilarToConditionRule extends BaseElementSelectConditionRule implements ElementConditionRuleInterface
{
    public function getGroupLabel(): string
    {
        return 'Lens';
    }

    public function getLabel(): string
    {
        return Craft::t('lens', 'Similar To');
    }

    public function getExclusiveQueryParams(): array
    {
        return ['lensSimilarTo'];
    }

    protected function elementType(): string
    {
        return Asset::class;
    }

    protected function criteria(): ?array
    {
        return ['kind' => 'image'];
    }

    public function modifyQuery(ElementQueryInterface $query): void
    {
        /** @var AssetQuery $query */
        if (!DuplicateSupport::isAvailable()) {
            return;
        }

        $anchorId = $this->getElementId();

        if (!is_int($anchorId) || $anchorId <= 0) {
            return;
        }

        $query->lensSimilarTo($anchorId);
        $query->lensApplySimilarToFilter();
    }

    public function matchElement(ElementInterface $element): bool
    {
        /** @var Asset $element */
        $anchorId = $this->getElementId();

        if (!is_int($anchorId) || $anchorId <= 0) {
            return true;
        }

        if ($element->id === $anchorId) {
            return true;
        }

        $clusterMap = Plugin::getInstance()->duplicateDetection->getClusterKeysForAssets([$anchorId, $element->id]);

        return isset($clusterMap[$anchorId], $clusterMap[$element->id])
            && $clusterMap[$anchorId] === $clusterMap[$element->id];
    }
}
