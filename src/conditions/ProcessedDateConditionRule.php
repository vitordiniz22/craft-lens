<?php

declare(strict_types=1);

namespace vitordiniz22\craftlens\conditions;

use Craft;
use craft\base\conditions\BaseDateRangeConditionRule;
use craft\base\ElementInterface;
use craft\elements\Asset;
use craft\elements\conditions\ElementConditionRuleInterface;
use craft\elements\db\AssetQuery;
use craft\elements\db\ElementQueryInterface;
use craft\helpers\DateTimeHelper;
use vitordiniz22\craftlens\Plugin;

class ProcessedDateConditionRule extends BaseDateRangeConditionRule implements ElementConditionRuleInterface
{
    public function getGroupLabel(): string
    {
        return 'Lens';
    }

    public function getLabel(): string
    {
        return Craft::t('lens', 'Processed Date');
    }

    public function getExclusiveQueryParams(): array
    {
        return ['lensProcessedFrom', 'lensProcessedTo'];
    }

    public function modifyQuery(ElementQueryInterface $query): void
    {
        /** @var AssetQuery $query */
        $from = $this->resolveDate('start');
        $to = $this->resolveDate('end');

        if ($from === null && $to === null) {
            return;
        }

        $query->lensProcessedFrom($from);
        $query->lensProcessedTo($to);
        $query->lensApplyProcessedDateFilter();
    }

    public function matchElement(ElementInterface $element): bool
    {
        /** @var Asset $element */
        $analysis = Plugin::getInstance()->assetAnalysis->getAnalysis($element->id);
        $processedAt = $analysis?->processedAt ? DateTimeHelper::toDateTime($analysis->processedAt) : null;

        if ($processedAt === null) {
            return false;
        }

        $from = $this->resolveDate('start');
        $to = $this->resolveDate('end');

        if ($from !== null && $processedAt < $from) {
            return false;
        }

        if ($to !== null && $processedAt > $to) {
            return false;
        }

        return true;
    }

    private function resolveDate(string $which): ?\DateTimeInterface
    {
        $raw = $which === 'start' ? $this->getStartDate() : $this->getEndDate();

        if ($raw === null || $raw === '') {
            return null;
        }

        $dt = DateTimeHelper::toDateTime($raw);

        return $dt === false ? null : $dt;
    }
}
