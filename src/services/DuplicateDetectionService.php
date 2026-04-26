<?php

declare(strict_types=1);

namespace vitordiniz22\craftlens\services;

use Craft;
use craft\helpers\DateTimeHelper;
use vitordiniz22\craftlens\enums\LogCategory;
use vitordiniz22\craftlens\helpers\DuplicateSupport;
use vitordiniz22\craftlens\helpers\Logger;
use vitordiniz22\craftlens\helpers\PerceptualHashHelper;
use vitordiniz22\craftlens\migrations\Install;
use vitordiniz22\craftlens\records\AssetAnalysisRecord;
use vitordiniz22\craftlens\records\DuplicateGroupRecord;
use yii\base\Component;
use yii\db\Query;

/**
 * Service for detecting and managing duplicate assets via perceptual hashing.
 */
class DuplicateDetectionService extends Component
{
    private const DEFAULT_HAMMING_THRESHOLD = 10;
    private const COMPARISON_CHUNK_SIZE = 500;
    private const MAX_HAMMING_DISTANCE = 256;

    /**
     * Find duplicates for a specific asset by comparing perceptual hashes.
     *
     * @return DuplicateGroupRecord[]
     */
    public function findDuplicatesForAsset(int $assetId, int $threshold = self::DEFAULT_HAMMING_THRESHOLD): array
    {
        $record = AssetAnalysisRecord::findOne(['assetId' => $assetId]);

        if ($record === null || empty($record->perceptualHash)) {
            return [];
        }

        $sourceHash = $record->perceptualHash;

        $existingPairs = $this->loadExistingPairs([$assetId]);

        $matches = [];
        $newPairAssetIds = [];
        $transaction = Craft::$app->getDb()->beginTransaction();

        try {
            $query = AssetAnalysisRecord::find()
                ->select(['id', 'assetId', 'perceptualHash'])
                ->where(['not', ['perceptualHash' => null]])
                ->andWhere(['!=', 'assetId', $assetId]);

            foreach ($query->batch(1000) as $batch) {
                foreach ($batch as $other) {
                    $isNew = false;
                    $result = $this->matchPair(
                        $assetId, $other->assetId,
                        $sourceHash, $other->perceptualHash,
                        $threshold, $existingPairs, $isNew,
                    );

                    if ($result !== null) {
                        $matches[] = $result;
                        if ($isNew) {
                            $newPairAssetIds[$assetId] = true;
                            $newPairAssetIds[(int) $other->assetId] = true;
                        }
                    }
                }
            }

            if (!empty($newPairAssetIds)) {
                $this->recomputeClusterKeys(array_keys($newPairAssetIds));
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
    public function findDuplicatesForAssets(array $assetIds, int $threshold = self::DEFAULT_HAMMING_THRESHOLD): int
    {
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
        $newPairAssetIds = [];
        $transaction = Craft::$app->getDb()->beginTransaction();

        try {
            for ($i = 0, $count = count($records); $i < $count; $i++) {
                for ($j = $i + 1; $j < $count; $j++) {
                    $isNew = false;
                    $result = $this->matchPair(
                        $records[$i]->assetId, $records[$j]->assetId,
                        $records[$i]->perceptualHash, $records[$j]->perceptualHash,
                        $threshold, $existingPairs, $isNew,
                    );

                    if ($result !== null) {
                        $pairsFound++;
                        if ($isNew) {
                            $newPairAssetIds[(int) $records[$i]->assetId] = true;
                            $newPairAssetIds[(int) $records[$j]->assetId] = true;
                        }
                    }
                }
            }

            if (!empty($newPairAssetIds)) {
                $this->recomputeClusterKeys(array_keys($newPairAssetIds));
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
    public function runFullScan(int $threshold = self::DEFAULT_HAMMING_THRESHOLD): int
    {
        Logger::info(LogCategory::Duplicate, 'Starting full duplicate scan');

        $hashes = [];

        $hashQuery = (new Query())
            ->select(['id', 'assetId', 'perceptualHash'])
            ->from(AssetAnalysisRecord::tableName())
            ->where(['not', ['perceptualHash' => null]])
            ->orderBy(['assetId' => SORT_ASC]);

        foreach ($hashQuery->batch(1000) as $batch) {
            foreach ($batch as $row) {
                $hashes[] = $row;
            }
        }

        $existingPairs = $this->loadExistingPairs();
        $count = count($hashes);
        $newPairs = 0;
        $anyNewPair = false;

        // Process in chunks to keep transactions short and memory bounded.
        for ($chunkStart = 0; $chunkStart < $count; $chunkStart += self::COMPARISON_CHUNK_SIZE) {
            $chunkEnd = min($chunkStart + self::COMPARISON_CHUNK_SIZE, $count);
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
                            $anyNewPair = true;
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

        if ($anyNewPair) {
            $this->recomputeAllClusterKeys();
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
        if (!DuplicateSupport::isAvailable()) {
            return 0;
        }

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
        if (!DuplicateSupport::isAvailable()) {
            return 0;
        }

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
     * Get duplicate cluster keys for a set of assets.
     *
     * Each asset is assigned a "group key" — the smallest asset ID in the
     * connected component it belongs to (via unresolved duplicate pairs).
     * Transitive relationships are resolved using Union-Find, so if A~B
     * and B~C, all connected assets share the same group key.
     *
     * @param int[] $assetIds
     * @return array<int, int> Map of assetId => groupKey
     */
    public function getClusterKeysForAssets(array $assetIds): array
    {
        if (empty($assetIds)) {
            return [];
        }

        $pairs = (new Query())
            ->select(['canonicalAssetId', 'duplicateAssetId'])
            ->from(Install::TABLE_DUPLICATE_GROUPS)
            ->where(['resolution' => null])
            ->andWhere([
                'or',
                ['canonicalAssetId' => $assetIds],
                ['duplicateAssetId' => $assetIds],
            ])
            ->all();

        if (empty($pairs)) {
            return [];
        }

        $parent = [];
        $rank = [];

        $find = function(int $x) use (&$parent, &$find): int {
            if ($parent[$x] !== $x) {
                $parent[$x] = $find($parent[$x]);
            }

            return $parent[$x];
        };

        $union = function(int $a, int $b) use (&$parent, &$rank, $find): void {
            $rootA = $find($a);
            $rootB = $find($b);

            if ($rootA === $rootB) {
                return;
            }

            if ($rank[$rootA] < $rank[$rootB]) {
                $parent[$rootA] = $rootB;
            } elseif ($rank[$rootA] > $rank[$rootB]) {
                $parent[$rootB] = $rootA;
            } else {
                $parent[$rootB] = $rootA;
                $rank[$rootA]++;
            }
        };

        foreach ($pairs as $pair) {
            $canonical = (int) $pair['canonicalAssetId'];
            $duplicate = (int) $pair['duplicateAssetId'];

            foreach ([$canonical, $duplicate] as $id) {
                if (!isset($parent[$id])) {
                    $parent[$id] = $id;
                    $rank[$id] = 0;
                }
            }

            $union($canonical, $duplicate);
        }

        // Find the minimum asset ID in each connected component.
        $componentMin = [];
        foreach ($parent as $id => $__) {
            $root = $find($id);

            if (!isset($componentMin[$root]) || $id < $componentMin[$root]) {
                $componentMin[$root] = $id;
            }
        }

        // Build final map filtered to requested asset IDs.
        $assetIdSet = array_flip($assetIds);
        $map = [];
        foreach ($parent as $id => $__) {
            if (isset($assetIdSet[$id])) {
                $map[$id] = $componentMin[$find($id)];
            }
        }

        return $map;
    }

    /**
     * Get unresolved duplicate counts for multiple assets in a single query.
     *
     * @param int[] $assetIds
     * @return array<int, int> Map of assetId => count
     */
    public function getUnresolvedDuplicateCountsForAssets(array $assetIds): array
    {
        if (!DuplicateSupport::isAvailable()) {
            return [];
        }

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
        $record->similarity = 1.0 - $distance / self::MAX_HAMMING_DISTANCE;

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
        if (!DuplicateSupport::isAvailable()) {
            return [];
        }

        $duplicates = DuplicateGroupRecord::find()
            ->where(['and',
                ['resolution' => null],
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
        if (!DuplicateSupport::isAvailable()) {
            return [];
        }

        $duplicates = DuplicateGroupRecord::find()
            ->select(['canonicalAssetId', 'duplicateAssetId'])
            ->where(['and',
                ['resolution' => null],
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

        $ids = array_map(function(array $dup) use ($assetId) {
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
                ['resolution' => null],
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

        $canonicalId = (int) $record->canonicalAssetId;
        $duplicateId = (int) $record->duplicateAssetId;

        $record->resolution = $resolution;
        $record->resolvedAt = DateTimeHelper::now();
        $record->resolvedBy = $userId;

        $saved = $record->save();

        if ($saved) {
            // Resolving a pair may split a cluster (e.g. A-B-C becomes A-B and C
            // once the bridging pair is resolved). Re-walk the remaining unresolved
            // pairs touching either endpoint so surviving rows carry the correct key.
            $this->recomputeClusterKeys([$canonicalId, $duplicateId]);
            Logger::info(LogCategory::Duplicate, "Duplicate pair resolved", context: ['groupId' => $groupId, 'resolution' => $resolution]);
        }

        return $saved;
    }

    /**
     * Drop pair rows touching a deleted asset and re-cluster surviving siblings.
     *
     * Hard delete relies on the FK CASCADE to remove pair rows but never
     * recomputes `clusterKey` on the rows that survive. Soft delete leaves the
     * elements row in place, so the cascade never fires and the pair rows live
     * on as orphaned references. Both cases need explicit cleanup.
     */
    public function cleanupForDeletedAsset(int $assetId): void
    {
        if (!DuplicateSupport::isAvailable()) {
            return;
        }

        $rows = (new Query())
            ->select(['canonicalAssetId', 'duplicateAssetId'])
            ->from(Install::TABLE_DUPLICATE_GROUPS)
            ->where(['or',
                ['canonicalAssetId' => $assetId],
                ['duplicateAssetId' => $assetId],
            ])
            ->all();

        if (empty($rows)) {
            return;
        }

        $siblingIds = [];

        foreach ($rows as $row) {
            $canonical = (int) $row['canonicalAssetId'];
            $duplicate = (int) $row['duplicateAssetId'];

            if ($canonical !== $assetId) {
                $siblingIds[$canonical] = true;
            }

            if ($duplicate !== $assetId) {
                $siblingIds[$duplicate] = true;
            }
        }

        DuplicateGroupRecord::deleteAll(['or',
            ['canonicalAssetId' => $assetId],
            ['duplicateAssetId' => $assetId],
        ]);

        if (!empty($siblingIds)) {
            $this->recomputeClusterKeys(array_keys($siblingIds));
        }
    }

    /**
     * Rebuild `clusterKey` on every unresolved pair whose connected component
     * contains at least one of the given seed assets. The seed set is BFS-expanded
     * through unresolved pairs so the full cluster is covered, including transitive
     * members missed by a one-hop lookup. Each affected row is stamped with its
     * cluster's minimum asset ID, which is the same value for every row in the
     * cluster and is what the "Has Duplicates" sort key groups on.
     *
     * @param int[] $seedAssetIds
     */
    private function recomputeClusterKeys(array $seedAssetIds): void
    {
        if (empty($seedAssetIds)) {
            return;
        }

        $seedAssetIds = array_values(array_unique(array_map('intval', $seedAssetIds)));
        $visited = array_fill_keys($seedAssetIds, true);
        $frontier = $seedAssetIds;
        $allPairs = [];

        while (!empty($frontier)) {
            $rows = (new Query())
                ->select(['id', 'canonicalAssetId', 'duplicateAssetId'])
                ->from(Install::TABLE_DUPLICATE_GROUPS)
                ->where(['resolution' => null])
                ->andWhere(['or',
                    ['canonicalAssetId' => $frontier],
                    ['duplicateAssetId' => $frontier],
                ])
                ->all();

            $nextFrontier = [];

            foreach ($rows as $row) {
                $pairId = (int) $row['id'];

                if (isset($allPairs[$pairId])) {
                    continue;
                }

                $canonical = (int) $row['canonicalAssetId'];
                $duplicate = (int) $row['duplicateAssetId'];
                $allPairs[$pairId] = [$canonical, $duplicate];

                foreach ([$canonical, $duplicate] as $id) {
                    if (!isset($visited[$id])) {
                        $visited[$id] = true;
                        $nextFrontier[] = $id;
                    }
                }
            }

            $frontier = $nextFrontier;
        }

        $this->applyClusterKeys($allPairs);
    }

    /**
     * Rebuild `clusterKey` on every unresolved pair in the table. Used after a
     * full scan, where touching every cluster individually would duplicate work.
     */
    private function recomputeAllClusterKeys(): void
    {
        $rows = (new Query())
            ->select(['id', 'canonicalAssetId', 'duplicateAssetId'])
            ->from(Install::TABLE_DUPLICATE_GROUPS)
            ->where(['resolution' => null])
            ->all();

        $allPairs = [];

        foreach ($rows as $row) {
            $allPairs[(int) $row['id']] = [(int) $row['canonicalAssetId'], (int) $row['duplicateAssetId']];
        }

        $this->applyClusterKeys($allPairs);
    }

    /**
     * Union-find over a set of pair rows, then stamp every row with its
     * component's minimum asset ID. Rows are grouped by target key so the
     * update is one statement per distinct cluster.
     *
     * @param array<int, array{0:int,1:int}> $allPairs pairId => [canonical, duplicate]
     */
    private function applyClusterKeys(array $allPairs): void
    {
        if (empty($allPairs)) {
            return;
        }

        $parent = [];
        $rank = [];

        $find = function(int $x) use (&$parent, &$find): int {
            if ($parent[$x] !== $x) {
                $parent[$x] = $find($parent[$x]);
            }

            return $parent[$x];
        };

        $union = function(int $a, int $b) use (&$parent, &$rank, $find): void {
            $rootA = $find($a);
            $rootB = $find($b);

            if ($rootA === $rootB) {
                return;
            }

            if ($rank[$rootA] < $rank[$rootB]) {
                $parent[$rootA] = $rootB;
            } elseif ($rank[$rootA] > $rank[$rootB]) {
                $parent[$rootB] = $rootA;
            } else {
                $parent[$rootB] = $rootA;
                $rank[$rootA]++;
            }
        };

        foreach ($allPairs as [$canonical, $duplicate]) {
            foreach ([$canonical, $duplicate] as $id) {
                if (!isset($parent[$id])) {
                    $parent[$id] = $id;
                    $rank[$id] = 0;
                }
            }

            $union($canonical, $duplicate);
        }

        $componentMin = [];

        foreach ($parent as $id => $__) {
            $root = $find($id);

            if (!isset($componentMin[$root]) || $id < $componentMin[$root]) {
                $componentMin[$root] = $id;
            }
        }

        $updatesByKey = [];

        foreach ($allPairs as $pairId => [$canonical, $__]) {
            $key = $componentMin[$find($canonical)];
            $updatesByKey[$key][] = $pairId;
        }

        $db = Craft::$app->getDb();

        foreach ($updatesByKey as $clusterKey => $pairIds) {
            $db->createCommand()
                ->update(
                    Install::TABLE_DUPLICATE_GROUPS,
                    ['clusterKey' => $clusterKey],
                    ['id' => $pairIds],
                )
                ->execute();
        }
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

        foreach ($query->batch(1000) as $batch) {
            foreach ($batch as $pair) {
                $key = $pair->canonicalAssetId . '_' . $pair->duplicateAssetId;
                $pairs[$key] = $pair;
            }
        }

        return $pairs;
    }
}
