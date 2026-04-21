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

class LensTagsAllConditionRule extends BaseMultiSelectConditionRule implements ElementConditionRuleInterface
{
    private const TAG_OPTION_LIMIT = 200;

    public function getGroupLabel(): string
    {
        return 'Lens';
    }

    public function getLabel(): string
    {
        return Craft::t('lens', 'Tags (all of)');
    }

    public function getExclusiveQueryParams(): array
    {
        return ['lensTagsAll'];
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

        $query->lensTagsAll($values);
        $query->lensApplyTagsAllFilter();
    }

    public function matchElement(ElementInterface $element): bool
    {
        /** @var Asset $element */
        $values = (array) $this->paramValue();

        if (empty($values)) {
            return true;
        }

        $analysis = Plugin::getInstance()->assetAnalysis->getAnalysis($element->id);

        if ($analysis === null) {
            return false;
        }

        $records = Plugin::getInstance()->tagAggregation->getTagsForAnalysis($analysis->id);
        $assetTags = array_map(fn($r) => mb_strtolower((string) $r->tag), $records);

        foreach ($values as $selected) {
            if (!in_array(mb_strtolower($selected), $assetTags, true)) {
                return false;
            }
        }

        return true;
    }
}
