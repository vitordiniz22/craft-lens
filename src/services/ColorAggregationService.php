<?php

declare(strict_types=1);

namespace vitordiniz22\craftlens\services;

use vitordiniz22\craftlens\enums\AnalysisStatus;
use vitordiniz22\craftlens\migrations\Install;
use vitordiniz22\craftlens\records\AssetAnalysisRecord;
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
     * @return array<array{hex: string, count: int}>
     */
    public function getColorCounts(int $limit = 20): array
    {
        $exactColorCounts = (new Query())
            ->select(['c.hex', 'COUNT(*) as count'])
            ->from(Install::TABLE_ASSET_COLORS . ' c')
            ->innerJoin(Install::TABLE_ASSET_ANALYSES . ' a', '[[c.analysisId]] = [[a.id]]')
            ->where(['in', 'a.status', AnalysisStatus::analyzedValues()])
            ->groupBy(['c.hex'])
            ->all();

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
     * Group similar colors by rounding RGB values.
     */
    private function groupSimilarColor(string $hex): string
    {
        $hex = ltrim($hex, '#');

        if (strlen($hex) !== 6) {
            return '#000000';
        }

        $r = hexdec(substr($hex, 0, 2));
        $g = hexdec(substr($hex, 2, 2));
        $b = hexdec(substr($hex, 4, 2));

        $step = 32;
        $r = (int)(round($r / $step) * $step);
        $g = (int)(round($g / $step) * $step);
        $b = (int)(round($b / $step) * $step);

        $r = min(255, max(0, $r));
        $g = min(255, max(0, $g));
        $b = min(255, max(0, $b));

        return sprintf('#%02X%02X%02X', $r, $g, $b);
    }
}
