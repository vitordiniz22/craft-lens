<?php

declare(strict_types=1);

namespace vitordiniz22\craftlens\services;

use Craft;
use craft\helpers\DateTimeHelper;
use vitordiniz22\craftlens\enums\LogCategory;
use vitordiniz22\craftlens\helpers\Logger;
use vitordiniz22\craftlens\helpers\PerceptualHashHelper;
use vitordiniz22\craftlens\records\AssetAnalysisRecord;
use vitordiniz22\craftlens\records\DuplicateGroupRecord;
use yii\base\Component;

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

        // Only select needed columns to reduce memory usage
        $allRecords = AssetAnalysisRecord::find()
            ->select(['id', 'assetId', 'perceptualHash'])
            ->where(['not', ['perceptualHash' => null]])
            ->andWhere(['!=', 'assetId', $assetId])
            ->all();

        $existingPairs = [];
        $existingPairsQuery = DuplicateGroupRecord::find()
            ->where(['or',
                ['canonicalAssetId' => $assetId],
                ['duplicateAssetId' => $assetId],
            ])
            ->all();

        foreach ($existingPairsQuery as $pair) {
            $key = $pair->canonicalAssetId . '_' . $pair->duplicateAssetId;
            $existingPairs[$key] = $pair;
        }

        $matches = [];
        $transaction = Craft::$app->getDb()->beginTransaction();

        try {
            foreach ($allRecords as $other) {
                $result = $this->matchPair(
                    $assetId, $other->assetId,
                    $sourceHash, $other->perceptualHash,
                    $threshold, $existingPairs,
                );

                if ($result !== null) {
                    $matches[] = $result;
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

        $existingPairs = [];
        $existingPairsQuery = DuplicateGroupRecord::find()
            ->where(['canonicalAssetId' => $assetIds])
            ->andWhere(['duplicateAssetId' => $assetIds])
            ->all();

        foreach ($existingPairsQuery as $pair) {
            $key = $pair->canonicalAssetId . '_' . $pair->duplicateAssetId;
            $existingPairs[$key] = $pair;
        }

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

        $records = AssetAnalysisRecord::find()
            ->select(['id', 'assetId', 'perceptualHash'])
            ->where(['not', ['perceptualHash' => null]])
            ->orderBy(['assetId' => SORT_ASC])
            ->all();

        $existingPairRows = DuplicateGroupRecord::find()
            ->select(['canonicalAssetId', 'duplicateAssetId'])
            ->asArray()
            ->all();
        $existingPairs = [];
        foreach ($existingPairRows as $row) {
            $existingPairs[$row['canonicalAssetId'] . '_' . $row['duplicateAssetId']] = true;
        }

        $newPairs = 0;
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

                    if ($result !== null && $isNew) {
                        $newPairs++;
                    }
                }
            }

            $transaction->commit();
        } catch (\Throwable $e) {
            $transaction->rollBack();
            Logger::error(LogCategory::Duplicate, 'Full duplicate scan failed', exception: $e, context: ['recordsScanned' => count($records)]);
            throw $e;
        }

        Logger::info(LogCategory::Duplicate, "Full duplicate scan completed", context: ['newPairsFound' => $newPairs, 'recordsScanned' => count($records)]);

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
     * Get count of unresolved duplicate pairs.
     */
    public function getUnresolvedDuplicateCount(): int
    {
        return (int) DuplicateGroupRecord::find()
            ->where(['resolution' => null])
            ->count();
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
}
