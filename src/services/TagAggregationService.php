<?php

declare(strict_types=1);

namespace vitordiniz22\craftlens\services;

use vitordiniz22\craftlens\enums\AnalysisStatus;
use vitordiniz22\craftlens\migrations\Install;
use vitordiniz22\craftlens\records\AssetAnalysisRecord;
use vitordiniz22\craftlens\records\AssetTagRecord;
use yii\base\Component;
use yii\db\Query;

class TagAggregationService extends Component
{
    /**
     * Get tags for a specific analysis.
     *
     * @return AssetTagRecord[]
     */
    public function getTagsForAnalysis(int $analysisId): array
    {
        return AssetTagRecord::find()
            ->where(['analysisId' => $analysisId])
            ->orderBy(['confidence' => SORT_DESC])
            ->all();
    }

    /**
     * Search tags by partial name for autocomplete.
     *
     * @return array<array{tag: string, count: int}>
     */
    public function searchTags(string $query, int $limit = 10): array
    {
        $query = $this->normalizeQuery($query);

        if ($query === '') {
            return [];
        }

        $results = (new Query())
            ->select(['tag', 'COUNT(*) as count'])
            ->from(Install::TABLE_ASSET_TAGS)
            ->where(['like', 'tagNormalized', $query])
            ->groupBy(['tagNormalized', 'tag'])
            ->orderBy(['count' => SORT_DESC])
            ->limit($limit)
            ->all();

        return array_map(fn($row) => [
            'tag' => $row['tag'],
            'count' => (int)$row['count'],
        ], $results);
    }

    /**
     * Get tag counts using the indexed tags table.
     *
     * @return array<array{tag: string, count: int}>
     */
    public function getTagCounts(int $limit = 30, string $sortBy = 'count'): array
    {
        $query = (new Query())
            ->select(['tags.tag', 'COUNT(*) as count'])
            ->from(Install::TABLE_ASSET_TAGS . ' tags')
            ->innerJoin(Install::TABLE_ASSET_ANALYSES . ' lens', '[[tags.analysisId]] = [[lens.id]]')
            ->where(['in', 'lens.status', AnalysisStatus::analyzedValues()])
            ->groupBy(['tags.tagNormalized', 'tags.tag']);

        if ($sortBy === 'alphabetical') {
            $query->orderBy(['tags.tag' => SORT_ASC]);
        } else {
            $query->orderBy(['count' => SORT_DESC]);
        }

        $query->limit($limit);

        $results = $query->all();

        return array_map(function ($row) {
            return ['tag' => $row['tag'], 'count' => (int) $row['count']];
        }, $results);
    }

    /**
     * Normalize a tag query string.
     */
    private function normalizeQuery(string $query): string
    {
        return mb_strtolower(trim($query));
    }
}
