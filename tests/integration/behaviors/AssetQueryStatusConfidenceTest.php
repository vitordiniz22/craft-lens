<?php

declare(strict_types=1);

namespace vitordiniz22\craftlenstests\integration\behaviors;

use Codeception\Test\Unit;
use craft\elements\Asset;
use ReflectionProperty;
use vitordiniz22\craftlens\behaviors\AssetQueryBehavior;
use vitordiniz22\craftlens\enums\AnalysisStatus;
use vitordiniz22\craftlens\Plugin;
use vitordiniz22\craftlenstests\_support\Helpers\AnalysisRecordFixtures;

/**
 * Integration tests for lensStatus(), lensConfidenceBelow(), lensConfidenceAbove().
 *
 * Pattern: every assertion pins specific asset IDs via assertContains /
 * assertNotContains so the test fails if the filter SQL is replaced with a
 * no-op. No assertNotEmpty / assertGreaterThan(0)-style assertions.
 */
class AssetQueryStatusConfidenceTest extends Unit
{
    use AnalysisRecordFixtures;

    protected function _before(): void
    {
        parent::_before();
        // lens*() filters are Pro-only — force Pro on the plugin instance so
        // beforePrepare() doesn't short-circuit before applying filters.
        Plugin::getInstance()->edition = Plugin::EDITION_PRO;
        // Reset static state on the behavior so a failed earlier test can't
        // leave schemaValid=false and silently nuke every filter in this suite.
        (new ReflectionProperty(AssetQueryBehavior::class, 'schemaValid'))->setValue(null, null);
        (new ReflectionProperty(AssetQueryBehavior::class, 'flashShown'))->setValue(null, false);
    }

    protected function _after(): void
    {
        $this->cleanupAnalysisRecords();
        parent::_after();
    }

    private function volumeIds(): array
    {
        return Asset::find()->volume('lenstest')->ids();
    }

    // ---------- lensStatus (scalar) ----------

    public function testLensStatusCompletedMatchesOnlyCompleted(): void
    {
        $completed = $this->createAssetFixture('completed.jpg', [], [], 'completed');
        $pending = $this->createAssetFixture('pending.jpg', [], [], 'pending');
        $failed = $this->createAssetFixture('failed.jpg', [], [], 'failed');

        $ids = Asset::find()->volume('lenstest')->lensStatus('completed')->ids();

        $this->assertContains($completed->assetId, $ids);
        $this->assertNotContains($pending->assetId, $ids);
        $this->assertNotContains($failed->assetId, $ids);
    }

    public function testLensStatusPendingMatchesOnlyPending(): void
    {
        $pending = $this->createAssetFixture('pending.jpg', [], [], 'pending');
        $completed = $this->createAssetFixture('completed.jpg', [], [], 'completed');

        $ids = Asset::find()->volume('lenstest')->lensStatus('pending')->ids();

        $this->assertContains($pending->assetId, $ids);
        $this->assertNotContains($completed->assetId, $ids);
    }

    public function testLensStatusNullAppliesNoFilter(): void
    {
        $completed = $this->createAssetFixture('a.jpg', [], [], 'completed');
        $pending = $this->createAssetFixture('b.jpg', [], [], 'pending');

        $ids = Asset::find()->volume('lenstest')->lensStatus(null)->ids();

        $this->assertContains($completed->assetId, $ids);
        $this->assertContains($pending->assetId, $ids);
    }

    // ---------- lensStatus('untagged') ----------

    public function testLensStatusUntaggedIncludesAssetsWithoutAnalysis(): void
    {
        $noAnalysisId = $this->createAssetWithoutAnalysis('no-analysis.jpg');
        $pending = $this->createAssetFixture('pending.jpg', [], [], 'pending');
        $failed = $this->createAssetFixture('failed.jpg', [], [], 'failed');
        $completed = $this->createAssetFixture('completed.jpg', [], [], 'completed');

        $ids = Asset::find()->volume('lenstest')->lensStatus('untagged')->ids();

        $this->assertContains($noAnalysisId, $ids, 'asset with no lens row must match (LEFT JOIN IS NULL)');
        $this->assertContains($pending->assetId, $ids, 'pending status must match');
        $this->assertContains($failed->assetId, $ids, 'failed status must match');
        $this->assertNotContains($completed->assetId, $ids, 'completed must NOT match untagged');
    }

    public function testLensStatusUntaggedExcludesProcessingAndReviewed(): void
    {
        $processing = $this->createAssetFixture('processing.jpg', [], [], 'processing');
        $approved = $this->createAssetFixture('approved.jpg', [], [], 'approved');
        $rejected = $this->createAssetFixture('rejected.jpg', [], [], 'rejected');
        $pendingReview = $this->createAssetFixture('pr.jpg', [], [], 'pending_review');
        $pending = $this->createAssetFixture('pending.jpg', [], [], 'pending');

        $ids = Asset::find()->volume('lenstest')->lensStatus('untagged')->ids();

        $this->assertContains($pending->assetId, $ids);
        $this->assertNotContains($processing->assetId, $ids);
        $this->assertNotContains($approved->assetId, $ids);
        $this->assertNotContains($rejected->assetId, $ids);
        $this->assertNotContains($pendingReview->assetId, $ids);
    }

    // ---------- lensStatus('analyzed') ----------

    public function testLensStatusAnalyzedMatchesExactlyCompletedApprovedPendingReview(): void
    {
        // Sanity check: the enum helper returns the exact set we're testing.
        $this->assertSame(
            ['completed', 'pending_review', 'approved'],
            AnalysisStatus::analyzedValues(),
        );

        $completed = $this->createAssetFixture('c.jpg', [], [], 'completed');
        $approved = $this->createAssetFixture('a.jpg', [], [], 'approved');
        $pendingReview = $this->createAssetFixture('pr.jpg', [], [], 'pending_review');
        $rejected = $this->createAssetFixture('r.jpg', [], [], 'rejected');
        $pending = $this->createAssetFixture('p.jpg', [], [], 'pending');
        $processing = $this->createAssetFixture('pro.jpg', [], [], 'processing');
        $failed = $this->createAssetFixture('f.jpg', [], [], 'failed');

        $ids = Asset::find()->volume('lenstest')->lensStatus('analyzed')->ids();

        $this->assertContains($completed->assetId, $ids);
        $this->assertContains($approved->assetId, $ids);
        $this->assertContains($pendingReview->assetId, $ids);
        $this->assertNotContains($rejected->assetId, $ids);
        $this->assertNotContains($pending->assetId, $ids);
        $this->assertNotContains($processing->assetId, $ids);
        $this->assertNotContains($failed->assetId, $ids);
    }

    // ---------- lensStatus(array) ----------

    public function testLensStatusArrayMatchesIn(): void
    {
        $pending = $this->createAssetFixture('p.jpg', [], [], 'pending');
        $failed = $this->createAssetFixture('f.jpg', [], [], 'failed');
        $completed = $this->createAssetFixture('c.jpg', [], [], 'completed');

        $ids = Asset::find()->volume('lenstest')->lensStatus(['pending', 'failed'])->ids();

        $this->assertContains($pending->assetId, $ids);
        $this->assertContains($failed->assetId, $ids);
        $this->assertNotContains($completed->assetId, $ids);
    }

    public function testLensStatusEmptyArrayMatchesNothing(): void
    {
        $completed = $this->createAssetFixture('c.jpg', [], [], 'completed');

        $ids = Asset::find()->volume('lenstest')->lensStatus([])->ids();

        // Empty IN() in Yii resolves to a no-match condition.
        $this->assertNotContains($completed->assetId, $ids);
    }

    // ---------- lensConfidenceBelow ----------

    public function testLensConfidenceBelowStrictLessThan(): void
    {
        $below = $this->createAssetFixture('below.jpg', ['altTextConfidence' => 0.49]);
        $boundary = $this->createAssetFixture('eq.jpg', ['altTextConfidence' => 0.50]);
        $above = $this->createAssetFixture('above.jpg', ['altTextConfidence' => 0.51]);

        $ids = Asset::find()->volume('lenstest')->lensConfidenceBelow(0.5)->ids();

        $this->assertContains($below->assetId, $ids, '0.49 < 0.50 should match');
        $this->assertNotContains($boundary->assetId, $ids, '0.50 is not strictly less than 0.50');
        $this->assertNotContains($above->assetId, $ids, '0.51 must not match');
    }

    public function testLensConfidenceBelowExcludesNull(): void
    {
        $nullConf = $this->createAssetFixture('null.jpg', ['altTextConfidence' => null]);
        $withConf = $this->createAssetFixture('with.jpg', ['altTextConfidence' => 0.30]);

        $ids = Asset::find()->volume('lenstest')->lensConfidenceBelow(0.5)->ids();

        $this->assertNotContains($nullConf->assetId, $ids, 'NULL confidence must not match < threshold');
        $this->assertContains($withConf->assetId, $ids);
    }

    public function testLensConfidenceBelowNullParameterAppliesNoFilter(): void
    {
        $low = $this->createAssetFixture('low.jpg', ['altTextConfidence' => 0.10]);
        $high = $this->createAssetFixture('high.jpg', ['altTextConfidence' => 0.90]);

        $ids = Asset::find()->volume('lenstest')->lensConfidenceBelow(null)->ids();

        $this->assertContains($low->assetId, $ids);
        $this->assertContains($high->assetId, $ids);
    }

    // ---------- lensConfidenceAbove ----------

    public function testLensConfidenceAboveInclusiveGreaterEqual(): void
    {
        $below = $this->createAssetFixture('below.jpg', ['altTextConfidence' => 0.49]);
        $boundary = $this->createAssetFixture('eq.jpg', ['altTextConfidence' => 0.50]);
        $above = $this->createAssetFixture('above.jpg', ['altTextConfidence' => 0.51]);

        $ids = Asset::find()->volume('lenstest')->lensConfidenceAbove(0.5)->ids();

        $this->assertNotContains($below->assetId, $ids, '0.49 must not match >= 0.50');
        $this->assertContains($boundary->assetId, $ids, '0.50 matches >= 0.50 (inclusive)');
        $this->assertContains($above->assetId, $ids, '0.51 must match');
    }

    public function testLensConfidenceAboveExcludesNull(): void
    {
        $nullConf = $this->createAssetFixture('null.jpg', ['altTextConfidence' => null]);
        $withConf = $this->createAssetFixture('with.jpg', ['altTextConfidence' => 0.99]);

        $ids = Asset::find()->volume('lenstest')->lensConfidenceAbove(0.5)->ids();

        $this->assertNotContains($nullConf->assetId, $ids);
        $this->assertContains($withConf->assetId, $ids);
    }

    public function testLensConfidenceAboveNullParameterAppliesNoFilter(): void
    {
        $low = $this->createAssetFixture('low.jpg', ['altTextConfidence' => 0.10]);
        $high = $this->createAssetFixture('high.jpg', ['altTextConfidence' => 0.90]);

        $ids = Asset::find()->volume('lenstest')->lensConfidenceAbove(null)->ids();

        $this->assertContains($low->assetId, $ids);
        $this->assertContains($high->assetId, $ids);
    }

    public function testConfidenceBelowAndAboveTogetherFormRange(): void
    {
        $low = $this->createAssetFixture('low.jpg', ['altTextConfidence' => 0.20]);
        $mid = $this->createAssetFixture('mid.jpg', ['altTextConfidence' => 0.60]);
        $high = $this->createAssetFixture('high.jpg', ['altTextConfidence' => 0.95]);

        $ids = Asset::find()
            ->volume('lenstest')
            ->lensConfidenceAbove(0.5)
            ->lensConfidenceBelow(0.9)
            ->ids();

        $this->assertNotContains($low->assetId, $ids);
        $this->assertContains($mid->assetId, $ids);
        $this->assertNotContains($high->assetId, $ids);
    }

    public function testLensStatusIgnoredOnLite(): void
    {
        $completed = $this->createAssetFixture('completed.jpg', [], [], 'completed');
        $pending = $this->createAssetFixture('pending.jpg', [], [], 'pending');

        Plugin::getInstance()->edition = Plugin::EDITION_LITE;

        $ids = Asset::find()->volume('lenstest')->lensStatus('completed')->ids();

        $this->assertContains($completed->assetId, $ids);
        $this->assertContains($pending->assetId, $ids, 'Lite must ignore lensStatus and return pending too');
    }
}
