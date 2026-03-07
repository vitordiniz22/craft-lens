<?php

declare(strict_types=1);

namespace vitordiniz22\craftlens\services;

use Craft;
use craft\helpers\DateTimeHelper;
use vitordiniz22\craftlens\enums\LogCategory;
use vitordiniz22\craftlens\helpers\Logger;
use vitordiniz22\craftlens\helpers\PerceptualHashHelper;
use vitordiniz22\craftlens\Plugin;
use vitordiniz22\craftlens\records\AssetAnalysisRecord;
use vitordiniz22\craftlens\records\DuplicateGroupRecord;
use yii\base\Component;
use yii\db\Query;

/**
 * Service for detecting and managing duplicate assets via perceptual hashing.
 */
class DuplicateDetectionService extends Component
{
    /**
     * Find duplicates for a specific asset by comparing perceptual hashes.
     *
     * @return DuplicateGroupRecord[]
     */
    public function findDuplicatesForAsset(int $assetId, int $threshold = 10): array
    {
        $record = AssetAnalysisRecord::findOne(['assetId' => $assetId]);

        if ($record === null || empty($record->perceptualHash)) {
            return [];
        }

        $sourceHash = $record->perceptualHash;

        $existingPairs = $this->loadExistingPairs([$assetId]);

        $matches = [];
        $transaction = Craft::$app->getDb()->beginTransaction();

        try {
            $query = AssetAnalysisRecord::find()
                ->select(['id', 'assetId', 'perceptualHash'])
                ->where(['not', ['perceptualHash' => null]])
                ->andWhere(['!=', 'assetId', $assetId]);

            foreach ($query->batch(1000) as $batch) {
                foreach ($batch as $other) {
                    $result = $this->matchPair(
                        $assetId, $other->assetId,
                        $sourceHash, $other->perceptualHash,
                        $threshold, $existingPairs,
                    );

                    if ($result !== null) {
                        $matches[] = $result;
                    }
                }
            }

            $transaction->commit();
        } catch (\Throwable $e) {
            $transaction->rollBack();
            Logger::error(LogCategory::Duplicate, 'Duplicate detection failed for asset', assetId: $assetId, exception: $e);
            throw $e;
        }

        usort($matches, fn($a, $b) => $a->hammingDistance <=> $b->hammingDistance);

        if (!empty($matches)) {
            Logger::info(LogCategory::Duplicate, "Found duplicates for asset {$assetId}", assetId: $assetId, context: ['pairsFound' => count($matches)]);
        }

        return $matches;
    }

    /**
     * Find duplicates among a set of assets (batch operation).
     *
     * @param int[] $assetIds
     * @return int Number of duplicate pairs found
     */
    public function findDuplicatesForAssets(array $assetIds, int $threshold = 10): int
    {
        if (!Plugin::getInstance()->getIsPro()) {
            return 0;
        }

        if (count($assetIds) < 2) {
            return 0;
        }

        $records = AssetAnalysisRecord::find()
            ->select(['id', 'assetId', 'perceptualHash'])
            ->where(['assetId' => $assetIds])
            ->andWhere(['not', ['perceptualHash' => null]])
            ->orderBy(['assetId' => SORT_ASC])
            ->all();

        if (count($records) < 2) {
            return 0;
        }

        $existingPairs = $this->loadExistingPairs($assetIds);

        $pairsFound = 0;
        $transaction = Craft::$app->getDb()->beginTransaction();

        try {
            for ($i = 0, $count = count($records); $i < $count; $i++) {
                for ($j = $i + 1; $j < $count; $j++) {
                    $result = $this->matchPair(
                        $records[$i]->assetId, $records[$j]->assetId,
                        $records[$i]->perceptualHash, $records[$j]->perceptualHash,
                        $threshold, $existingPairs,
                    );

                    if ($result !== null) {
                        $pairsFound++;
                    }
                }
            }

            $transaction->commit();
        } catch (\Throwable $e) {
            $transaction->rollBack();
            Logger::error(LogCategory::Duplicate, 'Batch duplicate detection failed', exception: $e, context: ['assetCount' => count($assetIds)]);
            throw $e;
        }

        return $pairsFound;
    }

    /**
     * Run a full pairwise scan of all analyzed assets to detect duplicates.
     *
     * @return int Number of new duplicate pairs found
     */
    public function runFullScan(int $threshold = 10): int
    {
        Logger::info(LogCategory::Duplicate, 'Starting full duplicate scan');

        $hashes = (new Query())
            ->select(['id', 'assetId', 'perceptualHash'])
            ->from(AssetAnalysisRecord::tableName())
            ->where(['not', ['perceptualHash' => null]])
            ->orderBy(['assetId' => SORT_ASC])
            ->all();

        $existingPairs = $this->loadExistingPairs();
        $count = count($hashes);
        $newPairs = 0;
        $chunkSize = 500;

        // Process in chunks to keep transactions short and memory bounded.
        for ($chunkStart = 0; $chunkStart < $count; $chunkStart += $chunkSize) {
            $chunkEnd = min($chunkStart + $chunkSize, $count);
            $transaction = Craft::$app->getDb()->beginTransaction();

            try {
                for ($i = $chunkStart; $i < $chunkEnd; $i++) {
                    for ($j = $i + 1; $j < $count; $j++) {
                        $isNew = false;
                        $result = $this->matchPair(
                            (int)$hashes[$i]['assetId'], (int)$hashes[$j]['assetId'],
                            $hashes[$i]['perceptualHash'], $hashes[$j]['perceptualHash'],
                            $threshold, $existingPairs, $isNew,
                        );

                        if ($result !== null && $isNew) {
                            $newPairs++;
                        }
                    }
                }

                $transaction->commit();
            } catch (\Throwable $e) {
                $transaction->rollBack();
                Logger::error(LogCategory::Duplicate, 'Full duplicate scan failed', exception: $e, context: ['recordsScanned' => $count]);
                throw $e;
            }
        }

        Logger::info(LogCategory::Duplicate, 'Full duplicate scan completed', context: ['newPairsFound' => $newPairs, 'recordsScanned' => $count]);

        return $newPairs;
    }

    /**
     * Get unresolved duplicate pairs with pagination.
     *
     * @return DuplicateGroupRecord[]
     */
    public function getUnresolvedDuplicates(int $limit = 20, int $offset = 0): array
    {
        return DuplicateGroupRecord::find()
            ->where(['resolution' => null])
            ->orderBy(['hammingDistance' => SORT_ASC])
            ->limit($limit)
            ->offset($offset)
            ->all();
    }

    /**
     * Get count of unique assets involved in unresolved duplicate pairs.
     */
    public function getUnresolvedDuplicateCount(): int
    {
        $table = DuplicateGroupRecord::tableName();

        return (int) (new Query())
            ->from([
                'dup_union' => (new Query())
                    ->select(['canonicalAssetId AS asset_id'])
                    ->from($table)
                    ->where(['resolution' => null])
                    ->union(
                        (new Query())
                            ->select(['duplicateAssetId AS asset_id'])
                            ->from($table)
                            ->where(['resolution' => null]),
                    ),
            ])
            ->count('DISTINCT [[asset_id]]');
    }

    /**
     * Get count of unresolved duplicate pairs for a specific asset.
     */
    public function getUnresolvedDuplicateCountForAsset(int $assetId): int
    {
        return (int) DuplicateGroupRecord::find()
            ->where(['resolution' => null])
            ->andWhere([
                'or',
                ['canonicalAssetId' => $assetId],
                ['duplicateAssetId' => $assetId],
            ])
            ->count();
    }

    /**
     * Get unresolved duplicate counts for multiple assets in a single query.
     *
     * @param int[] $assetIds
     * @return array<int, int> Map of assetId => count
     */
    public function getUnresolvedDuplicateCountsForAssets(array $assetIds): array
    {
        if (empty($assetIds)) {
            return [];
        }

        $counts = [];

        $canonicalRows = DuplicateGroupRecord::find()
            ->select(['canonicalAssetId', 'COUNT(*) AS cnt'])
            ->where(['resolution' => null, 'canonicalAssetId' => $assetIds])
            ->groupBy(['canonicalAssetId'])
            ->asArray()
            ->all();

        foreach ($canonicalRows as $row) {
            $id = (int) $row['canonicalAssetId'];
            $counts[$id] = ($counts[$id] ?? 0) + (int) $row['cnt'];
        }

        $duplicateRows = DuplicateGroupRecord::find()
            ->select(['duplicateAssetId', 'COUNT(*) AS cnt'])
            ->where(['resolution' => null, 'duplicateAssetId' => $assetIds])
            ->groupBy(['duplicateAssetId'])
            ->asArray()
            ->all();

        foreach ($duplicateRows as $row) {
            $id = (int) $row['duplicateAssetId'];
            $counts[$id] = ($counts[$id] ?? 0) + (int) $row['cnt'];
        }

        return $counts;
    }

    /**
     * Compare two assets and return the matching pair record if similar.
     *
     * Creates a new DuplicateGroupRecord if the pair doesn't already exist.
     * Adds newly created pairs to $existingPairs to prevent duplicate inserts.
     */
    private function matchPair(
        int $assetId1,
        int $assetId2,
        string $hash1,
        string $hash2,
        int $threshold,
        array &$existingPairs,
        bool &$isNew = false,
    ): ?DuplicateGroupRecord {
        $distance = PerceptualHashHelper::hammingDistance($hash1, $hash2);

        if ($distance > $threshold) {
            return null;
        }

        $canonicalId = min($assetId1, $assetId2);
        $duplicateId = max($assetId1, $assetId2);
        $pairKey = $canonicalId . '_' . $duplicateId;

        if (isset($existingPairs[$pairKey])) {
            $existing = $existingPairs[$pairKey];
            return $existing instanceof DuplicateGroupRecord ? $existing : null;
        }

        $record = new DuplicateGroupRecord();
        $record->canonicalAssetId = $canonicalId;
        $record->duplicateAssetId = $duplicateId;
        $record->hammingDistance = $distance;
        $record->similarity = 1.0 - $distance / 256;

        if (!$record->save()) {
            Logger::warning(LogCategory::Duplicate, 'Failed to save duplicate pair record', context: [
                'canonicalAssetId' => $canonicalId,
                'duplicateAssetId' => $duplicateId,
                'errors' => $record->getErrorSummary(true),
            ]);
            return null;
        }

        $existingPairs[$pairKey] = $record;
        $isNew = true;

        return $record;
    }

    /**
     * Get similar assets for display in the analysis panel.
     *
     * @return array<array{assetId: int, filename: string, thumbnailUrl: string, editUrl: string, similarity: float}>
     */
    public function getSimilarAssetsForDisplay(int $assetId, int $limit = 3): array
    {
        $duplicates = DuplicateGroupRecord::find()
            ->where(['and',
                ['resolvedAt' => null],
                ['or',
                    ['canonicalAssetId' => $assetId],
                    ['duplicateAssetId' => $assetId],
                ],
            ])
            ->orderBy(['similarity' => SORT_DESC])
            ->limit($limit)
            ->all();

        if (empty($duplicates)) {
            return [];
        }

        $otherAssetIds = array_map(function($dup) use ($assetId) {
            return (int) $dup->canonicalAssetId === $assetId
                ? (int) $dup->duplicateAssetId
                : (int) $dup->canonicalAssetId;
        }, $duplicates);

        $otherAssets = \craft\elements\Asset::find()
            ->id($otherAssetIds)
            ->indexBy('id')
            ->all();

        $similarImages = [];

        foreach ($duplicates as $dup) {
            $otherAssetId = (int) $dup->canonicalAssetId === $assetId
                ? (int) $dup->duplicateAssetId
                : (int) $dup->canonicalAssetId;

            $otherAsset = $otherAssets[$otherAssetId] ?? null;

            if ($otherAsset !== null) {
                $thumbnailUrl = Craft::$app->getAssets()->getThumbUrl($otherAsset, 120, 120);

                if ($thumbnailUrl !== null) {
                    $similarImages[] = [
                        'assetId' => $otherAssetId,
                        'filename' => $otherAsset->filename,
                        'thumbnailUrl' => $thumbnailUrl,
                        'editUrl' => $otherAsset->getCpEditUrl(),
                        'similarity' => $dup->similarity,
                    ];
                }
            }
        }

        return $similarImages;
    }

    /**
     * Get IDs of assets similar to the given asset, ordered by similarity (highest first).
     * Only includes unresolved duplicate pairs.
     *
     * @return int[]
     */
    public function getSimilarAssetIds(int $assetId): array
    {
        $duplicates = DuplicateGroupRecord::find()
            ->select(['canonicalAssetId', 'duplicateAssetId'])
            ->where(['and',
                ['resolvedAt' => null],
                ['or',
                    ['canonicalAssetId' => $assetId],
                    ['duplicateAssetId' => $assetId],
                ],
            ])
            ->orderBy(['similarity' => SORT_DESC])
            ->asArray()
            ->all();

        if (empty($duplicates)) {
            return [];
        }

        $ids = array_map(function (array $dup) use ($assetId) {
            return (int) $dup['canonicalAssetId'] === $assetId
                ? (int) $dup['duplicateAssetId']
                : (int) $dup['canonicalAssetId'];
        }, $duplicates);

        return array_values(array_unique($ids));
    }

    /**
     * Get a map of asset ID => similarity score for all similar assets.
     *
     * @return array<int, float> [assetId => similarity]
     */
    public function getSimilarityMapForAsset(int $assetId): array
    {
        $duplicates = DuplicateGroupRecord::find()
            ->select(['canonicalAssetId', 'duplicateAssetId', 'similarity'])
            ->where(['and',
                ['resolvedAt' => null],
                ['or',
                    ['canonicalAssetId' => $assetId],
                    ['duplicateAssetId' => $assetId],
                ],
            ])
            ->asArray()
            ->all();

        $map = [];

        foreach ($duplicates as $dup) {
            $otherId = (int) $dup['canonicalAssetId'] === $assetId
                ? (int) $dup['duplicateAssetId']
                : (int) $dup['canonicalAssetId'];

            if (!isset($map[$otherId])) {
                $map[$otherId] = (float) $dup['similarity'];
            }
        }

        return $map;
    }

    /**
     * Resolve a duplicate pair.
     */
    public function resolve(int $groupId, string $resolution, ?int $userId): bool
    {
        $record = DuplicateGroupRecord::findOne($groupId);

        if ($record === null) {
            return false;
        }

        $record->resolution = $resolution;
        $record->resolvedAt = DateTimeHelper::now();
        $record->resolvedBy = $userId;

        $saved = $record->save();

        if ($saved) {
            Logger::info(LogCategory::Duplicate, "Duplicate pair resolved", context: ['groupId' => $groupId, 'resolution' => $resolution]);
        }

        return $saved;
    }

    /**
     * Load existing duplicate pairs for given asset IDs.
     * If no asset IDs provided, loads all pairs.
     *
     * @param array $assetIds Asset IDs to filter by (empty = load all)
     * @return array Map of "canonicalId_duplicateId" => DuplicateGroupRecord|true
     */
    private function loadExistingPairs(array $assetIds = []): array
    {
        $query = DuplicateGroupRecord::find();

        if (!empty($assetIds)) {
            $query->where(['or',
                ['canonicalAssetId' => $assetIds],
                ['duplicateAssetId' => $assetIds],
            ]);
        }

        $pairs = [];

        foreach ($query->all() as $pair) {
            $key = $pair->canonicalAssetId . '_' . $pair->duplicateAssetId;
            $pairs[$key] = $pair;
        }

        return $pairs;
    }
}
