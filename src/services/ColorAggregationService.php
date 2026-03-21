<?php

declare(strict_types=1);

namespace vitordiniz22\craftlens\services;

use vitordiniz22\craftlens\enums\AnalysisStatus;
use vitordiniz22\craftlens\migrations\Install;
use vitordiniz22\craftlens\records\AssetColorRecord;
use yii\base\Component;
use yii\db\Query;

class ColorAggregationService extends Component
{
    /**
     * Get colors for a specific analysis.
     *
     * @return AssetColorRecord[]
     */
    public function getColorsForAnalysis(int $analysisId): array
    {
        return AssetColorRecord::find()
            ->where(['analysisId' => $analysisId])
            ->all();
    }

    /**
     * Get colors for multiple analyses in a single query.
     *
     * @param int[] $analysisIds
     * @return array<int, AssetColorRecord[]> Map of analysisId => AssetColorRecord[]
     */
    public function getColorsForAnalyses(array $analysisIds): array
    {
        if (empty($analysisIds)) {
            return [];
        }

        $records = AssetColorRecord::find()
            ->where(['analysisId' => $analysisIds])
            ->all();

        $map = [];

        foreach ($records as $record) {
            $map[$record->analysisId][] = $record;
        }

        return $map;
    }

    /**
     * @param int[]|null $volumeIds Restrict to assets in these volume IDs, or null for all volumes.
     * @return array<array{hex: string, count: int}>
     */
    public function getColorCounts(int $limit = 20, ?array $volumeIds = null): array
    {
        if ($volumeIds !== null && empty($volumeIds)) {
            return [];
        }

        $query = (new Query())
            ->select(['c.hex', 'COUNT(*) as count'])
            ->from(Install::TABLE_ASSET_COLORS . ' c')
            ->innerJoin(Install::TABLE_ASSET_ANALYSES . ' a', '[[c.analysisId]] = [[a.id]]')
            ->where(['in', 'a.status', AnalysisStatus::withMetadataValues()]);

        if ($volumeIds !== null) {
            $query->andWhere(['in', '[[a.assetId]]', (new Query())
                ->select('id')
                ->from('{{%assets}}')
                ->where(['in', 'volumeId', $volumeIds])]);
        }

        $exactColorCounts = $query->groupBy(['c.hex'])->all();

        $colorCounts = [];

        foreach ($exactColorCounts as $row) {
            $grouped = $this->groupSimilarColor($row['hex']);
            $colorCounts[$grouped] = ($colorCounts[$grouped] ?? 0) + (int) $row['count'];
        }

        arsort($colorCounts);

        $topColors = array_slice($colorCounts, 0, $limit, true);

        return array_map(
            fn($hex, $count) => ['hex' => $hex, 'count' => $count],
            array_keys($topColors),
            array_values($topColors)
        );
    }

    /**
     * Group similar colors by rounding RGB channels to the nearest step.
     */
    private function groupSimilarColor(string $hex): string
    {
        $hex = ltrim($hex, '#');

        if (strlen($hex) !== 6) {
            return '#000000';
        }

        $step = 32;
        $channels = array_map(
            fn(int $offset) => min(255, max(0, (int)(round(hexdec(substr($hex, $offset, 2)) / $step) * $step))),
            [0, 2, 4]
        );

        return sprintf('#%02X%02X%02X', ...$channels);
    }
}
