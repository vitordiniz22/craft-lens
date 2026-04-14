<?php

declare(strict_types=1);

namespace vitordiniz22\craftlenstests\integration\behaviors;

use Codeception\Test\Unit;
use craft\elements\Asset;
use ReflectionProperty;
use vitordiniz22\craftlens\behaviors\AssetQueryBehavior;
use vitordiniz22\craftlens\Plugin;
use vitordiniz22\craftlenstests\_support\Helpers\AnalysisRecordFixtures;

/**
 * Integration tests for lensContainsPeople() and lensNsfwFlagged().
 *
 * Critical edge case under test: lensNsfwFlagged(false) INCLUDES rows with
 * NULL nsfwScore (per applyFilterByNsfwFlagged OR condition), unlike the
 * simple boolean columns which just flip true/false.
 */
class AssetQueryPeopleSafetyTest extends Unit
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

    // ---------- lensContainsPeople ----------

    public function testLensContainsPeopleTrueMatchesOnlyFlaggedAssets(): void
    {
        $withPeople = $this->createAssetFixture('people.jpg', ['containsPeople' => true]);
        $noPeople = $this->createAssetFixture('nopeople.jpg', ['containsPeople' => false]);

        $ids = Asset::find()->volume('lenstest')->lensContainsPeople(true)->ids();

        $this->assertContains($withPeople->assetId, $ids);
        $this->assertNotContains($noPeople->assetId, $ids);
    }

    public function testLensContainsPeopleFalseMatchesOnlyUnflaggedAssets(): void
    {
        $withPeople = $this->createAssetFixture('people.jpg', ['containsPeople' => true]);
        $noPeople = $this->createAssetFixture('nopeople.jpg', ['containsPeople' => false]);

        $ids = Asset::find()->volume('lenstest')->lensContainsPeople(false)->ids();

        $this->assertNotContains($withPeople->assetId, $ids);
        $this->assertContains($noPeople->assetId, $ids);
    }

    public function testLensContainsPeopleNullAppliesNoFilter(): void
    {
        $withPeople = $this->createAssetFixture('a.jpg', ['containsPeople' => true]);
        $noPeople = $this->createAssetFixture('b.jpg', ['containsPeople' => false]);

        $ids = Asset::find()->volume('lenstest')->lensContainsPeople(null)->ids();

        $this->assertContains($withPeople->assetId, $ids);
        $this->assertContains($noPeople->assetId, $ids);
    }

    // ---------- lensNsfwFlagged(true) — threshold 0.5 inclusive ----------

    public function testLensNsfwFlaggedTrueUsesHalfInclusive(): void
    {
        $below = $this->createAssetFixture('below.jpg', ['nsfwScore' => 0.49]);
        $boundary = $this->createAssetFixture('eq.jpg', ['nsfwScore' => 0.50]);
        $above = $this->createAssetFixture('above.jpg', ['nsfwScore' => 0.51]);

        $ids = Asset::find()->volume('lenstest')->lensNsfwFlagged(true)->ids();

        $this->assertNotContains($below->assetId, $ids, '0.49 < 0.50 threshold');
        $this->assertContains($boundary->assetId, $ids, '0.50 is inclusive (>= 0.5)');
        $this->assertContains($above->assetId, $ids, '0.51 matches');
    }

    public function testLensNsfwFlaggedTrueExcludesNull(): void
    {
        $nullScore = $this->createAssetFixture('null.jpg', ['nsfwScore' => null]);
        $high = $this->createAssetFixture('high.jpg', ['nsfwScore' => 0.9]);

        $ids = Asset::find()->volume('lenstest')->lensNsfwFlagged(true)->ids();

        $this->assertNotContains($nullScore->assetId, $ids, 'NULL must NOT match NSFW-flagged');
        $this->assertContains($high->assetId, $ids);
    }

    // ---------- lensNsfwFlagged(false) — NULL-inclusive inverse ----------

    public function testLensNsfwFlaggedFalseIncludesNull(): void
    {
        $nullScore = $this->createAssetFixture('null.jpg', ['nsfwScore' => null]);
        $below = $this->createAssetFixture('below.jpg', ['nsfwScore' => 0.3]);
        $boundary = $this->createAssetFixture('eq.jpg', ['nsfwScore' => 0.5]);
        $above = $this->createAssetFixture('above.jpg', ['nsfwScore' => 0.8]);

        $ids = Asset::find()->volume('lenstest')->lensNsfwFlagged(false)->ids();

        $this->assertContains($nullScore->assetId, $ids, 'NULL nsfwScore must be treated as not-flagged');
        $this->assertContains($below->assetId, $ids, '0.3 < 0.5 is not-flagged');
        $this->assertNotContains($boundary->assetId, $ids, '0.5 is flagged (inclusive) so must NOT appear here');
        $this->assertNotContains($above->assetId, $ids);
    }

    public function testLensNsfwFlaggedNullAppliesNoFilter(): void
    {
        $low = $this->createAssetFixture('low.jpg', ['nsfwScore' => 0.1]);
        $high = $this->createAssetFixture('high.jpg', ['nsfwScore' => 0.9]);
        $nullRow = $this->createAssetFixture('null.jpg', ['nsfwScore' => null]);

        $ids = Asset::find()->volume('lenstest')->lensNsfwFlagged(null)->ids();

        $this->assertContains($low->assetId, $ids);
        $this->assertContains($high->assetId, $ids);
        $this->assertContains($nullRow->assetId, $ids);
    }

    public function testLensNsfwTrueAndFalseReturnDisjointSets(): void
    {
        $this->createAssetFixture('a.jpg', ['nsfwScore' => 0.1]);
        $this->createAssetFixture('b.jpg', ['nsfwScore' => 0.7]);
        $this->createAssetFixture('c.jpg', ['nsfwScore' => null]);

        $flagged = Asset::find()->volume('lenstest')->lensNsfwFlagged(true)->ids();
        $notFlagged = Asset::find()->volume('lenstest')->lensNsfwFlagged(false)->ids();

        $this->assertEmpty(array_intersect($flagged, $notFlagged), 'true and false sets must not overlap');
        // And their union must be the complete volume.
        $all = Asset::find()->volume('lenstest')->ids();
        $this->assertEqualsCanonicalizing($all, array_unique(array_merge($flagged, $notFlagged)));
    }
}
