<?php

declare(strict_types=1);

namespace vitordiniz22\craftlens\services;

use vitordiniz22\craftlens\enums\AnalysisStatus;
use vitordiniz22\craftlens\migrations\Install;
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
     * Get tags for multiple analyses in a single query.
     *
     * @param int[] $analysisIds
     * @return array<int, AssetTagRecord[]> Map of analysisId => AssetTagRecord[]
     */
    public function getTagsForAnalyses(array $analysisIds): array
    {
        if (empty($analysisIds)) {
            return [];
        }

        $records = AssetTagRecord::find()
            ->where(['analysisId' => $analysisIds])
            ->orderBy(['confidence' => SORT_DESC])
            ->all();

        $map = [];

        foreach ($records as $record) {
            $map[$record->analysisId][] = $record;
        }

        return $map;
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
     * @param int[]|null $volumeIds Restrict to assets in these volume IDs, or null for all volumes.
     * @return array<array{tag: string, count: int}>
     */
    public function getTagCounts(int $limit = 30, string $sortBy = 'count', ?array $volumeIds = null): array
    {
        if ($volumeIds !== null && empty($volumeIds)) {
            return [];
        }

        $query = (new Query())
            ->select(['tags.tag', 'COUNT(*) as count'])
            ->from(Install::TABLE_ASSET_TAGS . ' tags')
            ->innerJoin(Install::TABLE_ASSET_ANALYSES . ' lens', '[[tags.analysisId]] = [[lens.id]]')
            ->where(['in', 'lens.status', AnalysisStatus::withMetadataValues()]);

        if ($volumeIds !== null) {
            $query->andWhere(['in', '[[lens.assetId]]', (new Query())
                ->select('id')
                ->from('{{%assets}}')
                ->where(['in', 'volumeId', $volumeIds]), ]);
        }

        $query->groupBy(['tags.tagNormalized', 'tags.tag']);

        if ($sortBy === 'alphabetical') {
            $query->orderBy(['tags.tag' => SORT_ASC]);
        } else {
            $query->orderBy(['count' => SORT_DESC]);
        }

        $query->limit($limit);

        $results = $query->all();

        return array_map(function($row) {
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
