<?php

declare(strict_types=1);

namespace vitordiniz22\craftlenstests\integration\behaviors;

use Codeception\Test\Unit;
use craft\elements\Asset;
use ReflectionProperty;
use vitordiniz22\craftlens\behaviors\AssetQueryBehavior;
use vitordiniz22\craftlens\helpers\AssetTableAttributes;
use vitordiniz22\craftlens\Plugin;
use vitordiniz22\craftlenstests\_support\Helpers\AnalysisRecordFixtures;
use yii\db\Expression;

/**
 * Integration tests for lensHasDuplicates() plus filter composition.
 *
 * Chaining scenarios verify:
 * - Multiple filters AND together (intersection semantics)
 * - Two assets-table filters coexist (regression guard: the JOIN to lens
 *   should not interfere with WHERE on the assets table)
 * - Multiple EXISTS subqueries on separate child tables compose correctly
 * - Repeated calls to the same setter overwrite rather than accumulate
 */
class AssetQueryDuplicatesChainingTest extends Unit
{
    use AnalysisRecordFixtures;

    protected function _before(): void
    {
        parent::_before();
        Plugin::getInstance()->edition = Plugin::EDITION_PRO;
        (new ReflectionProperty(AssetQueryBehavior::class, 'schemaValid'))->setValue(null, null);
        (new ReflectionProperty(AssetQueryBehavior::class, 'flashShown'))->setValue(null, false);
    }

    protected function _after(): void
    {
        $this->cleanupAnalysisRecords();
        parent::_after();
    }

    // ---------- lensHasDuplicates ----------

    public function testLensHasDuplicatesTrueMatchesBothSidesOfUnresolvedGroup(): void
    {
        $canonical = $this->createAssetFixture('canonical.jpg');
        $dup = $this->createAssetFixture('dup.jpg');
        $alone = $this->createAssetFixture('alone.jpg');

        $this->createDuplicateGroup($canonical->assetId, $dup->assetId, resolution: null);

        $ids = Asset::find()->volume('lenstest')->lensHasDuplicates(true)->ids();

        $this->assertContains($canonical->assetId, $ids, 'canonical side must match (OR condition)');
        $this->assertContains($dup->assetId, $ids, 'duplicate side must match (OR condition)');
        $this->assertNotContains($alone->assetId, $ids);
    }

    public function testLensHasDuplicatesTrueIgnoresResolvedGroups(): void
    {
        $canonical = $this->createAssetFixture('canonical.jpg');
        $dup = $this->createAssetFixture('dup.jpg');

        // resolution='kept' means a human already reviewed this; lensHasDuplicates(true) must skip it.
        $this->createDuplicateGroup($canonical->assetId, $dup->assetId, resolution: 'kept');

        $ids = Asset::find()->volume('lenstest')->lensHasDuplicates(true)->ids();

        $this->assertNotContains($canonical->assetId, $ids);
        $this->assertNotContains($dup->assetId, $ids);
    }

    public function testLensHasDuplicatesFalseMatchesAssetsWithoutUnresolvedGroups(): void
    {
        $canonical = $this->createAssetFixture('canonical.jpg');
        $dup = $this->createAssetFixture('dup.jpg');
        $alone = $this->createAssetFixture('alone.jpg');
        $resolvedCanonical = $this->createAssetFixture('rc.jpg');
        $resolvedDup = $this->createAssetFixture('rd.jpg');

        $this->createDuplicateGroup($canonical->assetId, $dup->assetId, resolution: null);
        $this->createDuplicateGroup($resolvedCanonical->assetId, $resolvedDup->assetId, resolution: 'kept');

        $ids = Asset::find()->volume('lenstest')->lensHasDuplicates(false)->ids();

        $this->assertContains($alone->assetId, $ids, 'asset with no group is a non-duplicate');
        $this->assertContains($resolvedCanonical->assetId, $ids, 'resolved-group asset is also a non-duplicate');
        $this->assertContains($resolvedDup->assetId, $ids);
        $this->assertNotContains($canonical->assetId, $ids);
        $this->assertNotContains($dup->assetId, $ids);
    }

    public function testLensHasDuplicatesNullAppliesNoFilter(): void
    {
        $canonical = $this->createAssetFixture('canonical.jpg');
        $dup = $this->createAssetFixture('dup.jpg');
        $alone = $this->createAssetFixture('alone.jpg');

        $this->createDuplicateGroup($canonical->assetId, $dup->assetId, resolution: null);

        $ids = Asset::find()->volume('lenstest')->lensHasDuplicates(null)->ids();

        $this->assertContains($canonical->assetId, $ids);
        $this->assertContains($dup->assetId, $ids);
        $this->assertContains($alone->assetId, $ids);
    }

    // ---------- Chaining ----------

    public function testChainingPeopleAndNsfwUsesAndSemantics(): void
    {
        $peopleSafe = $this->createAssetFixture('ps.jpg', [
            'containsPeople' => true, 'nsfwScore' => 0.1,
        ]);
        $peopleNsfw = $this->createAssetFixture('pn.jpg', [
            'containsPeople' => true, 'nsfwScore' => 0.9,
        ]);
        $noPeopleSafe = $this->createAssetFixture('ns.jpg', [
            'containsPeople' => false, 'nsfwScore' => 0.1,
        ]);

        $ids = Asset::find()
            ->volume('lenstest')
            ->lensContainsPeople(true)
            ->lensNsfwFlagged(false)
            ->ids();

        $this->assertContains($peopleSafe->assetId, $ids);
        $this->assertNotContains($peopleNsfw->assetId, $ids, 'fails nsfw filter');
        $this->assertNotContains($noPeopleSafe->assetId, $ids, 'fails people filter');
    }

    public function testChainingStatusAndConfidence(): void
    {
        $goodCompleted = $this->createAssetFixture('gc.jpg', ['altTextConfidence' => 0.9], [], 'completed');
        $lowCompleted = $this->createAssetFixture('lc.jpg', ['altTextConfidence' => 0.4], [], 'completed');
        $goodPending = $this->createAssetFixture('gp.jpg', ['altTextConfidence' => 0.9], [], 'pending');

        $ids = Asset::find()
            ->volume('lenstest')
            ->lensStatus('completed')
            ->lensConfidenceAbove(0.7)
            ->ids();

        $this->assertContains($goodCompleted->assetId, $ids);
        $this->assertNotContains($lowCompleted->assetId, $ids);
        $this->assertNotContains($goodPending->assetId, $ids);
    }

    public function testChainingTwoAssetsTableFiltersCoexist(): void
    {
        // Regression guard: lensTooLarge and lensHasFocalPoint both operate on
        // the assets table. Earlier versions of the behavior used an explicit
        // JOIN; if that regresses, both constraints should still AND together.
        $largeWithFp = $this->createAssetFixture('lg.jpg', [], [
            'size' => 2_000_000, 'focalPoint' => '0.5;0.5',
        ]);
        $largeNoFp = $this->createAssetFixture('ln.jpg', [], [
            'size' => 2_000_000, 'focalPoint' => null,
        ]);
        $smallWithFp = $this->createAssetFixture('sf.jpg', [], [
            'size' => 100_000, 'focalPoint' => '0.5;0.5',
        ]);

        $ids = Asset::find()
            ->volume('lenstest')
            ->lensTooLarge(true)
            ->lensHasFocalPoint(true)
            ->ids();

        $this->assertContains($largeWithFp->assetId, $ids);
        $this->assertNotContains($largeNoFp->assetId, $ids);
        $this->assertNotContains($smallWithFp->assetId, $ids);
    }

    public function testRepeatedSetterCallsOverwriteRatherThanAccumulate(): void
    {
        $withPeople = $this->createAssetFixture('wp.jpg', ['containsPeople' => true]);
        $noPeople = $this->createAssetFixture('np.jpg', ['containsPeople' => false]);

        // Call lensContainsPeople twice; the second value must win.
        $ids = Asset::find()
            ->volume('lenstest')
            ->lensContainsPeople(true)
            ->lensContainsPeople(false)
            ->ids();

        $this->assertNotContains($withPeople->assetId, $ids);
        $this->assertContains($noPeople->assetId, $ids);
    }

    public function testChainingFourFiltersIntersectCorrectly(): void
    {
        $allPass = $this->createAssetFixture('pass.jpg', [
            'containsPeople' => true,
            'nsfwScore' => 0.1,
            'altTextConfidence' => 0.9,
            'hasWatermark' => false,
        ]);
        $failPeople = $this->createAssetFixture('fp.jpg', [
            'containsPeople' => false,
            'nsfwScore' => 0.1,
            'altTextConfidence' => 0.9,
            'hasWatermark' => false,
        ]);
        $failWatermark = $this->createAssetFixture('fw.jpg', [
            'containsPeople' => true,
            'nsfwScore' => 0.1,
            'altTextConfidence' => 0.9,
            'hasWatermark' => true,
        ]);

        $ids = Asset::find()
            ->volume('lenstest')
            ->lensContainsPeople(true)
            ->lensNsfwFlagged(false)
            ->lensConfidenceAbove(0.7)
            ->lensHasWatermark(false)
            ->ids();

        $this->assertContains($allPass->assetId, $ids);
        $this->assertNotContains($failPeople->assetId, $ids);
        $this->assertNotContains($failWatermark->assetId, $ids);
    }

    public function testLensHasDuplicatesIgnoredOnLite(): void
    {
        $canonical = $this->createAssetFixture('canonical.jpg');
        $duplicate = $this->createAssetFixture('duplicate.jpg');
        $this->createDuplicateGroup($canonical->assetId, $duplicate->assetId);

        $noDup = $this->createAssetFixture('nodup.jpg');

        Plugin::getInstance()->edition = Plugin::EDITION_LITE;

        $ids = Asset::find()->volume('lenstest')->lensHasDuplicates(true)->ids();

        $this->assertContains($canonical->assetId, $ids);
        $this->assertContains($duplicate->assetId, $ids);
        $this->assertContains($noDup->assetId, $ids, 'Lite must ignore lensHasDuplicates and return asset with no duplicate group');
    }

    // ---------- Cluster sort grouping ----------

    public function testClusterSortGroupsSiblingsAdjacent(): void
    {
        // Build two clusters with INTERLEAVED IDs so the cluster grouping
        // can't be a coincidence of ascending insertion order. The sort must
        // pull sibling pairs back together regardless of when they were created.
        //   Cluster X: a1, a3
        //   Cluster Y: a2, a4
        $a1 = $this->createAssetFixture('a1.jpg');
        $a2 = $this->createAssetFixture('a2.jpg');
        $a3 = $this->createAssetFixture('a3.jpg');
        $a4 = $this->createAssetFixture('a4.jpg');

        $this->createDuplicateGroup(min($a1->assetId, $a3->assetId), max($a1->assetId, $a3->assetId));
        $this->createDuplicateGroup(min($a2->assetId, $a4->assetId), max($a2->assetId, $a4->assetId));

        // Stamp clusterKey via the same path the production code uses.
        $service = Plugin::getInstance()->duplicateDetection;
        (new \ReflectionMethod($service, 'recomputeAllClusterKeys'))
            ->invoke($service);

        $orderBy = $this->resolveClusterSortOrderBy();

        $ids = Asset::find()
            ->volume('lenstest')
            ->lensHasDuplicates(true)
            ->orderBy(new Expression($orderBy))
            ->ids();

        $this->assertCount(4, $ids);

        // Find each asset's position. Siblings must be adjacent (positions differ by 1).
        $position = array_flip($ids);

        $clusterX = [$a1->assetId, $a3->assetId];
        $clusterY = [$a2->assetId, $a4->assetId];

        foreach ([$clusterX, $clusterY] as $cluster) {
            $positions = [$position[$cluster[0]], $position[$cluster[1]]];
            sort($positions);
            $this->assertSame(
                1,
                $positions[1] - $positions[0],
                "Cluster siblings must be adjacent in the sort order; got positions " . json_encode($positions),
            );
        }
    }

    // ---------- Deletion-driven filter behavior ----------

    public function testFilterDropsAssetWhenItsOnlyDuplicatePartnerIsCleanedUp(): void
    {
        // A-B is the only pair. After A is deleted (cleanup runs), B has no
        // unresolved partners left, so the source must stop showing B.
        $a = $this->createAssetFixture('a.jpg');
        $b = $this->createAssetFixture('b.jpg');

        $this->createDuplicateGroup($a->assetId, $b->assetId);

        $idsBefore = Asset::find()->volume('lenstest')->lensHasDuplicates(true)->ids();
        $this->assertContains($a->assetId, $idsBefore);
        $this->assertContains($b->assetId, $idsBefore);

        Plugin::getInstance()->duplicateDetection->cleanupForDeletedAsset($a->assetId);

        $idsAfter = Asset::find()->volume('lenstest')->lensHasDuplicates(true)->ids();
        $this->assertNotContains($b->assetId, $idsAfter, 'B has no remaining duplicate pair, so it must drop from the source');
    }

    private function resolveClusterSortOrderBy(): string
    {
        foreach (AssetTableAttributes::sortOptions() as $option) {
            if (($option['attribute'] ?? null) === AssetTableAttributes::ATTR_DUPLICATE_CLUSTER) {
                return $option['orderBy'];
            }
        }

        $this->fail('Cluster sort option not registered');
    }
}
