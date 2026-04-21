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
use vitordiniz22\craftlens\Plugin;

class LensTagsAnyConditionRule extends BaseMultiSelectConditionRule implements ElementConditionRuleInterface
{
    private const TAG_OPTION_LIMIT = 200;

    public function getGroupLabel(): string
    {
        return 'Lens';
    }

    public function getLabel(): string
    {
        return Craft::t('lens', 'Tags (any of)');
    }

    public function getExclusiveQueryParams(): array
    {
        return ['lensTag'];
    }

    protected function options(): array
    {
        $rows = Plugin::getInstance()->tagAggregation->getTagCounts(self::TAG_OPTION_LIMIT, 'alphabetical');

        return array_map(
            fn(array $row) => ['value' => $row['tag'], 'label' => $row['tag']],
            $rows,
        );
    }

    public function modifyQuery(ElementQueryInterface $query): void
    {
        /** @var AssetQuery $query */
        $values = (array) $this->paramValue();

        if (empty($values)) {
            return;
        }

        $query->lensTag($values);
        $query->lensApplyTagFilter();
    }

    public function matchElement(ElementInterface $element): bool
    {
        /** @var Asset $element */
        $values = (array) $this->paramValue();

        if (empty($values)) {
            return true;
        }

        $tags = $this->getElementTags($element);
        $normalizedSelected = array_map('mb_strtolower', $values);

        foreach ($tags as $tag) {
            if (in_array(mb_strtolower($tag), $normalizedSelected, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return string[]
     */
    private function getElementTags(Asset $asset): array
    {
        $analysis = Plugin::getInstance()->assetAnalysis->getAnalysis($asset->id);

        if ($analysis === null) {
            return [];
        }

        $records = Plugin::getInstance()->tagAggregation->getTagsForAnalysis($analysis->id);

        return array_map(fn($r) => (string) $r->tag, $records);
    }
}
