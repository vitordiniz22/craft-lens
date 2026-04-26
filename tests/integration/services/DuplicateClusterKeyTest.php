<?php

declare(strict_types=1);

namespace vitordiniz22\craftlenstests\integration\services;

use Codeception\Test\Unit;
use ReflectionMethod;
use vitordiniz22\craftlens\enums\DuplicateResolution;
use vitordiniz22\craftlens\helpers\AssetTableAttributes;
use vitordiniz22\craftlens\migrations\Install;
use vitordiniz22\craftlens\Plugin;
use vitordiniz22\craftlens\services\DuplicateDetectionService;
use vitordiniz22\craftlenstests\_support\Helpers\AnalysisRecordFixtures;
use yii\db\Query;

/**
 * Integration coverage for clusterKey maintenance on lens_duplicate_groups.
 *
 * Each test maps to one of the documented cases for the "Has Duplicates" sort
 * order: simple pairs, transitive clusters, cross-cluster merges, and the two
 * flavors of resolution (keep-intact vs. split).
 */
class DuplicateClusterKeyTest extends Unit
{
    use AnalysisRecordFixtures;

    private DuplicateDetectionService $service;

    protected function _before(): void
    {
        parent::_before();
        $this->service = Plugin::getInstance()->duplicateDetection;
    }

    protected function _after(): void
    {
        $this->cleanupAnalysisRecords();
        parent::_after();
    }

    public function testSimpleMatchCreatesPairWithClusterKey(): void
    {
        $hash = str_repeat('0', 64);
        $a = $this->createAnalysisRecord('completed', ['perceptualHash' => $hash]);
        $b = $this->createAnalysisRecord('completed', ['perceptualHash' => $hash]);

        $this->service->findDuplicatesForAssets([$a->assetId, $b->assetId]);

        $rows = $this->fetchAllUnresolvedPairs();

        $this->assertCount(1, $rows);
        $this->assertEquals(min($a->assetId, $b->assetId), (int) $rows[0]['clusterKey']);
    }

    public function testTransitiveClusterSharesClusterKey(): void
    {
        // A-B distance 8, B-C distance 8, A-C distance 16. Threshold is 10,
        // so only the bridge pairs match directly; C is only reachable via B.
        $hashA = str_repeat('0', 64);
        $hashB = str_repeat('0', 62) . 'ff';
        $hashC = str_repeat('0', 60) . 'ffff';

        $a = $this->createAnalysisRecord('completed', ['perceptualHash' => $hashA]);
        $b = $this->createAnalysisRecord('completed', ['perceptualHash' => $hashB]);
        $c = $this->createAnalysisRecord('completed', ['perceptualHash' => $hashC]);

        $this->service->findDuplicatesForAssets([$a->assetId, $b->assetId, $c->assetId]);

        $rows = $this->fetchAllUnresolvedPairs();

        $this->assertCount(2, $rows);

        $expected = min($a->assetId, $b->assetId, $c->assetId);
        foreach ($rows as $row) {
            $this->assertEquals($expected, (int) $row['clusterKey']);
        }
    }

    public function testMergeAcrossPreviouslySeparateClusters(): void
    {
        $a = $this->createAnalysisRecord('completed');
        $b = $this->createAnalysisRecord('completed');
        $c = $this->createAnalysisRecord('completed');
        $d = $this->createAnalysisRecord('completed');

        // Two independent clusters, stamped so each carries its own key.
        $this->seedPair($a->assetId, $b->assetId);
        $this->seedPair($c->assetId, $d->assetId);
        $this->invokePrivate('recomputeClusterKeys', [[$a->assetId, $c->assetId]]);

        // Bridge (B, C) merges the two clusters into one.
        $this->seedPair($b->assetId, $c->assetId);
        $this->invokePrivate('recomputeClusterKeys', [[$b->assetId, $c->assetId]]);

        $rows = $this->fetchAllUnresolvedPairs();
        $this->assertCount(3, $rows);

        $expected = min($a->assetId, $b->assetId, $c->assetId, $d->assetId);
        foreach ($rows as $row) {
            $this->assertEquals($expected, (int) $row['clusterKey']);
        }
    }

    public function testResolveKeepsIntactClusterRestampsKey(): void
    {
        $a = $this->createAnalysisRecord('completed');
        $b = $this->createAnalysisRecord('completed');
        $c = $this->createAnalysisRecord('completed');

        $abId = $this->seedPair($a->assetId, $b->assetId);
        $bcId = $this->seedPair($b->assetId, $c->assetId);
        $this->invokePrivate('recomputeClusterKeys', [[$a->assetId, $b->assetId, $c->assetId]]);

        $this->service->resolve($abId, DuplicateResolution::Kept->value, null);

        $bcRow = $this->fetchPairById($bcId);
        $this->assertEquals(min($b->assetId, $c->assetId), (int) $bcRow['clusterKey']);
    }

    public function testResolveBridgingPairSplitsClusterKeys(): void
    {
        $a = $this->createAnalysisRecord('completed');
        $b = $this->createAnalysisRecord('completed');
        $c = $this->createAnalysisRecord('completed');
        $d = $this->createAnalysisRecord('completed');

        $abId = $this->seedPair($a->assetId, $b->assetId);
        $bcId = $this->seedPair($b->assetId, $c->assetId);
        $cdId = $this->seedPair($c->assetId, $d->assetId);
        $this->invokePrivate('recomputeClusterKeys', [
            [$a->assetId, $b->assetId, $c->assetId, $d->assetId],
        ]);

        $this->service->resolve($bcId, DuplicateResolution::Kept->value, null);

        $abRow = $this->fetchPairById($abId);
        $cdRow = $this->fetchPairById($cdId);

        $this->assertEquals(min($a->assetId, $b->assetId), (int) $abRow['clusterKey']);
        $this->assertEquals(min($c->assetId, $d->assetId), (int) $cdRow['clusterKey']);
        $this->assertNotEquals((int) $abRow['clusterKey'], (int) $cdRow['clusterKey']);
    }

    public function testRecomputeAllStampsEveryUnresolvedPair(): void
    {
        $a = $this->createAnalysisRecord('completed');
        $b = $this->createAnalysisRecord('completed');
        $c = $this->createAnalysisRecord('completed');
        $d = $this->createAnalysisRecord('completed');

        // Two disjoint clusters seeded without any clusterKey set.
        $this->seedPair($a->assetId, $b->assetId);
        $this->seedPair($c->assetId, $d->assetId);

        $this->invokePrivate('recomputeAllClusterKeys', []);

        $rows = $this->fetchAllUnresolvedPairs();
        $this->assertCount(2, $rows);

        foreach ($rows as $row) {
            $canonical = (int) $row['canonicalAssetId'];
            $duplicate = (int) $row['duplicateAssetId'];
            $this->assertEquals(min($canonical, $duplicate), (int) $row['clusterKey']);
        }
    }

    public function testClusterSortOptionIsRegistered(): void
    {
        $options = AssetTableAttributes::sortOptions();

        $cluster = null;
        foreach ($options as $option) {
            if (($option['attribute'] ?? null) === AssetTableAttributes::ATTR_DUPLICATE_CLUSTER) {
                $cluster = $option;
                break;
            }
        }

        $this->assertNotNull($cluster, 'Duplicate cluster sort option should be registered');
        $this->assertStringContainsString('clusterKey', $cluster['orderBy']);
    }

    public function testCleanupRemovesPairRowsForDeletedAsset(): void
    {
        $a = $this->createAnalysisRecord('completed');
        $b = $this->createAnalysisRecord('completed');
        $c = $this->createAnalysisRecord('completed');

        $this->seedPair($a->assetId, $b->assetId);
        $this->seedPair($a->assetId, $c->assetId);
        $bcId = $this->seedPair($b->assetId, $c->assetId);

        $this->service->cleanupForDeletedAsset($a->assetId);

        $rows = $this->fetchAllUnresolvedPairs();
        $this->assertCount(1, $rows, 'Only the (B, C) pair should survive');
        $this->assertEquals($bcId, (int) $rows[0]['id']);
    }

    public function testCleanupRestampsClusterKeyWhenAnchorAssetDeleted(): void
    {
        // A is the cluster MIN; before deletion every pair's clusterKey == A.
        // After A is deleted, surviving pairs must be re-stamped with min(B, C).
        $a = $this->createAnalysisRecord('completed');
        $b = $this->createAnalysisRecord('completed');
        $c = $this->createAnalysisRecord('completed');

        $this->seedPair($a->assetId, $b->assetId);
        $this->seedPair($a->assetId, $c->assetId);
        $bcId = $this->seedPair($b->assetId, $c->assetId);
        $this->invokePrivate('recomputeClusterKeys', [[$a->assetId, $b->assetId, $c->assetId]]);

        $bcRowBefore = $this->fetchPairById($bcId);
        $this->assertEquals($a->assetId, (int) $bcRowBefore['clusterKey'], 'precondition: A is the cluster anchor');

        $this->service->cleanupForDeletedAsset($a->assetId);

        $bcRowAfter = $this->fetchPairById($bcId);
        $this->assertEquals(min($b->assetId, $c->assetId), (int) $bcRowAfter['clusterKey']);
    }

    public function testCleanupDropsEntireClusterWhenHubAssetDeleted(): void
    {
        // Pairs are A-B and B-C only. B is the only bridge; deleting B leaves
        // no remaining pairs at all (no direct A-C pair was ever created).
        $a = $this->createAnalysisRecord('completed');
        $b = $this->createAnalysisRecord('completed');
        $c = $this->createAnalysisRecord('completed');

        $this->seedPair($a->assetId, $b->assetId);
        $this->seedPair($b->assetId, $c->assetId);
        $this->invokePrivate('recomputeClusterKeys', [[$a->assetId, $b->assetId, $c->assetId]]);

        $this->service->cleanupForDeletedAsset($b->assetId);

        $this->assertCount(0, $this->fetchAllUnresolvedPairs());
    }

    public function testCleanupForAssetWithNoDuplicatesIsNoOp(): void
    {
        $a = $this->createAnalysisRecord('completed');
        $b = $this->createAnalysisRecord('completed');
        $lonely = $this->createAnalysisRecord('completed');

        $this->seedPair($a->assetId, $b->assetId);
        $this->invokePrivate('recomputeClusterKeys', [[$a->assetId, $b->assetId]]);

        $this->service->cleanupForDeletedAsset($lonely->assetId);

        $rows = $this->fetchAllUnresolvedPairs();
        $this->assertCount(1, $rows, 'Existing pair must be untouched');
        $this->assertEquals(min($a->assetId, $b->assetId), (int) $rows[0]['clusterKey']);
    }

    private function seedPair(int $assetA, int $assetB): int
    {
        return $this->createDuplicateGroup(min($assetA, $assetB), max($assetA, $assetB));
    }

    /** @return list<array<string, mixed>> */
    private function fetchAllUnresolvedPairs(): array
    {
        return (new Query())
            ->from(Install::TABLE_DUPLICATE_GROUPS)
            ->where(['resolution' => null])
            ->orderBy(['id' => SORT_ASC])
            ->all();
    }

    /** @return array<string, mixed> */
    private function fetchPairById(int $id): array
    {
        return (new Query())
            ->from(Install::TABLE_DUPLICATE_GROUPS)
            ->where(['id' => $id])
            ->one();
    }

    private function invokePrivate(string $method, array $args): mixed
    {
        $ref = new ReflectionMethod($this->service, $method);
        $ref->setAccessible(true);
        return $ref->invokeArgs($this->service, $args);
    }
}
