<?php

declare(strict_types=1);

namespace vitordiniz22\craftlenstests\integration\behaviors;

use Codeception\Test\Unit;
use craft\elements\Asset;
use ReflectionProperty;
use vitordiniz22\craftlens\behaviors\AssetQueryBehavior;
use vitordiniz22\craftlens\conditions\FileTooLargeConditionRule;
use vitordiniz22\craftlens\helpers\ImageMetricsAnalyzer;
use vitordiniz22\craftlens\Plugin;
use vitordiniz22\craftlenstests\_support\Helpers\AnalysisRecordFixtures;

/**
 * Integration tests for quality and technical filters:
 * lensSharpnessBelow, lensExposureIssues, lensHasFocalPoint, lensBlurry,
 * lensTooDark, lensTooBright, lensLowContrast, lensTooLarge, lensHasTextInImage.
 *
 * Every test locks its boundary to the constant in ImageMetricsAnalyzer or
 * FileTooLargeConditionRule, so if a future refactor moves a threshold the
 * test fails loudly rather than silently drifting.
 *
 * Key behaviors under test:
 * - lensBlurry(false) is a NO-OP (unlike lensExposureIssues(false) which flips semantics)
 * - lensTooDark requires exposureScore < 0.22 AND shadowClipRatio > 0.40 (noise is NOT a factor)
 * - lensTooBright requires exposureScore > 0.85 AND highlightClipRatio > 0.40 (noise is NOT a factor)
 * - lensSharpnessBelow explicitly excludes NULL
 * - lensHasTextInImage treats '[]' and NULL as equivalent "no text"
 * - lensTooLarge and lensHasFocalPoint operate on the `assets` table, not `lens`
 */
class AssetQueryQualityTest extends Unit
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

    // ---------- lensSharpnessBelow ----------

    public function testLensSharpnessBelowStrictLessThan(): void
    {
        $below = $this->createAssetFixture('below.jpg', ['sharpnessScore' => 0.29]);
        $boundary = $this->createAssetFixture('eq.jpg', ['sharpnessScore' => 0.30]);
        $above = $this->createAssetFixture('above.jpg', ['sharpnessScore' => 0.31]);

        $ids = Asset::find()->volume('lenstest')->lensSharpnessBelow(0.3)->ids();

        $this->assertContains($below->assetId, $ids);
        $this->assertNotContains($boundary->assetId, $ids);
        $this->assertNotContains($above->assetId, $ids);
    }

    public function testLensSharpnessBelowExplicitlyExcludesNull(): void
    {
        $nullSharp = $this->createAssetFixture('null.jpg', ['sharpnessScore' => null]);
        $below = $this->createAssetFixture('below.jpg', ['sharpnessScore' => 0.1]);

        $ids = Asset::find()->volume('lenstest')->lensSharpnessBelow(0.5)->ids();

        $this->assertNotContains($nullSharp->assetId, $ids, 'NULL sharpness must NOT be treated as "less than" anything');
        $this->assertContains($below->assetId, $ids);
    }

    public function testLensSharpnessBelowNullParameterAppliesNoFilter(): void
    {
        $low = $this->createAssetFixture('low.jpg', ['sharpnessScore' => 0.1]);
        $high = $this->createAssetFixture('high.jpg', ['sharpnessScore' => 0.9]);

        $ids = Asset::find()->volume('lenstest')->lensSharpnessBelow(null)->ids();

        $this->assertContains($low->assetId, $ids);
        $this->assertContains($high->assetId, $ids);
    }

    // ---------- lensBlurry ----------

    public function testLensBlurryTrueUsesSharpnessBlurryConstant(): void
    {
        $this->assertSame(0.3, ImageMetricsAnalyzer::SHARPNESS_BLURRY);

        $blurry = $this->createAssetFixture('blurry.jpg', ['sharpnessScore' => 0.29]);
        $boundary = $this->createAssetFixture('eq.jpg', ['sharpnessScore' => 0.30]);
        $sharp = $this->createAssetFixture('sharp.jpg', ['sharpnessScore' => 0.9]);
        $nullSharp = $this->createAssetFixture('null.jpg', ['sharpnessScore' => null]);

        $ids = Asset::find()->volume('lenstest')->lensBlurry(true)->ids();

        $this->assertContains($blurry->assetId, $ids);
        $this->assertNotContains($boundary->assetId, $ids, '0.30 is not < 0.30');
        $this->assertNotContains($sharp->assetId, $ids);
        $this->assertNotContains($nullSharp->assetId, $ids);
    }

    public function testLensBlurryFalseIsNoOp(): void
    {
        // Per implementation: lensBlurry(false) has no effect — the assertion is
        // that a blurry asset is STILL returned when filter is false.
        $blurry = $this->createAssetFixture('blurry.jpg', ['sharpnessScore' => 0.1]);
        $sharp = $this->createAssetFixture('sharp.jpg', ['sharpnessScore' => 0.9]);

        $ids = Asset::find()->volume('lenstest')->lensBlurry(false)->ids();

        $this->assertContains($blurry->assetId, $ids, 'lensBlurry(false) is a no-op; blurry asset still returned');
        $this->assertContains($sharp->assetId, $ids);
    }

    // ---------- lensExposureIssues ----------

    public function testLensExposureIssuesTrueMatchesOutsideZeroThreeToZeroSevenRange(): void
    {
        $tooDark = $this->createAssetFixture('dark.jpg', ['exposureScore' => 0.29]);
        $tooBright = $this->createAssetFixture('bright.jpg', ['exposureScore' => 0.71]);
        $normal = $this->createAssetFixture('normal.jpg', ['exposureScore' => 0.50]);
        $lowBound = $this->createAssetFixture('lo.jpg', ['exposureScore' => 0.30]);
        $highBound = $this->createAssetFixture('hi.jpg', ['exposureScore' => 0.70]);
        $nullExp = $this->createAssetFixture('null.jpg', ['exposureScore' => null]);

        $ids = Asset::find()->volume('lenstest')->lensExposureIssues(true)->ids();

        $this->assertContains($tooDark->assetId, $ids);
        $this->assertContains($tooBright->assetId, $ids);
        $this->assertNotContains($normal->assetId, $ids);
        $this->assertNotContains($lowBound->assetId, $ids, 'exactly 0.30 is inside the normal range (not < 0.3)');
        $this->assertNotContains($highBound->assetId, $ids, 'exactly 0.70 is inside the normal range (not > 0.7)');
        $this->assertNotContains($nullExp->assetId, $ids, 'NULL must be excluded');
    }

    public function testLensExposureIssuesFalseMatchesNormalRange(): void
    {
        // Unlike lensBlurry(false), lensExposureIssues(false) is NOT a no-op —
        // it filters to the 0.3-0.7 "healthy" range.
        $tooDark = $this->createAssetFixture('dark.jpg', ['exposureScore' => 0.29]);
        $tooBright = $this->createAssetFixture('bright.jpg', ['exposureScore' => 0.71]);
        $normal = $this->createAssetFixture('normal.jpg', ['exposureScore' => 0.50]);
        $lowBound = $this->createAssetFixture('lo.jpg', ['exposureScore' => 0.30]);
        $highBound = $this->createAssetFixture('hi.jpg', ['exposureScore' => 0.70]);

        $ids = Asset::find()->volume('lenstest')->lensExposureIssues(false)->ids();

        $this->assertContains($normal->assetId, $ids);
        $this->assertContains($lowBound->assetId, $ids, '0.30 is inclusive (>= 0.3)');
        $this->assertContains($highBound->assetId, $ids, '0.70 is inclusive (<= 0.7)');
        $this->assertNotContains($tooDark->assetId, $ids);
        $this->assertNotContains($tooBright->assetId, $ids);
    }

    // ---------- lensHasFocalPoint (on assets table) ----------

    public function testLensHasFocalPointTrue(): void
    {
        $withFp = $this->createAssetFixture('fp.jpg', [], ['focalPoint' => '0.5;0.5']);
        $withoutFp = $this->createAssetFixture('nofp.jpg', [], ['focalPoint' => null]);

        $ids = Asset::find()->volume('lenstest')->lensHasFocalPoint(true)->ids();

        $this->assertContains($withFp->assetId, $ids);
        $this->assertNotContains($withoutFp->assetId, $ids);
    }

    public function testLensHasFocalPointFalse(): void
    {
        $withFp = $this->createAssetFixture('fp.jpg', [], ['focalPoint' => '0.25;0.75']);
        $withoutFp = $this->createAssetFixture('nofp.jpg', [], ['focalPoint' => null]);

        $ids = Asset::find()->volume('lenstest')->lensHasFocalPoint(false)->ids();

        $this->assertNotContains($withFp->assetId, $ids);
        $this->assertContains($withoutFp->assetId, $ids);
    }

    // ---------- lensTooDark (conjunction of exposure + shadow clipping) ----------

    public function testLensTooDarkTrueRequiresExposureAndShadowClipping(): void
    {
        $this->assertSame(0.22, ImageMetricsAnalyzer::BRIGHTNESS_DARK_MEDIAN);
        $this->assertSame(0.40, ImageMetricsAnalyzer::SHADOW_CLIP_RATIO);

        $dark = $this->createAssetFixture('dark.jpg', [
            'exposureScore' => 0.15, 'shadowClipRatio' => 0.50,
        ]);
        $failExposure = $this->createAssetFixture('fe.jpg', [
            'exposureScore' => 0.25, 'shadowClipRatio' => 0.50,
        ]);
        $failShadow = $this->createAssetFixture('fs.jpg', [
            'exposureScore' => 0.15, 'shadowClipRatio' => 0.30,
        ]);

        $ids = Asset::find()->volume('lenstest')->lensTooDark(true)->ids();

        $this->assertContains($dark->assetId, $ids);
        $this->assertNotContains($failExposure->assetId, $ids, 'exposure>=0.22 breaks TooDark');
        $this->assertNotContains($failShadow->assetId, $ids, 'shadowClipRatio<=0.40 breaks TooDark');
    }

    public function testLensTooDarkIgnoresNoiseScore(): void
    {
        // Noise drives lensLowContrast, not lensTooDark. A dark, noisy asset
        // must still match lensTooDark so the two filters stay orthogonal.
        $darkAndNoisy = $this->createAssetFixture('dn.jpg', [
            'exposureScore' => 0.15, 'shadowClipRatio' => 0.50, 'noiseScore' => 0.50,
        ]);

        $ids = Asset::find()->volume('lenstest')->lensTooDark(true)->ids();

        $this->assertContains($darkAndNoisy->assetId, $ids);
    }

    public function testLensTooDarkExcludesNullExposure(): void
    {
        $nullExposure = $this->createAssetFixture('nx.jpg', [
            'exposureScore' => null, 'shadowClipRatio' => 0.50,
        ]);

        $ids = Asset::find()->volume('lenstest')->lensTooDark(true)->ids();

        $this->assertNotContains($nullExposure->assetId, $ids, 'NULL exposure must never qualify');
    }

    public function testLensTooDarkFalseIsNoOp(): void
    {
        $dark = $this->createAssetFixture('dark.jpg', [
            'exposureScore' => 0.15, 'shadowClipRatio' => 0.50, 'noiseScore' => 0.20,
        ]);
        $ids = Asset::find()->volume('lenstest')->lensTooDark(false)->ids();
        $this->assertContains($dark->assetId, $ids, 'lensTooDark(false) is a no-op');
    }

    // ---------- lensTooBright (conjunction of exposure + highlight clipping) ----------

    public function testLensTooBrightTrueRequiresExposureAndHighlightClipping(): void
    {
        $this->assertSame(0.85, ImageMetricsAnalyzer::BRIGHTNESS_BRIGHT_MEDIAN);
        $this->assertSame(0.40, ImageMetricsAnalyzer::HIGHLIGHT_CLIP_RATIO);

        $bright = $this->createAssetFixture('bright.jpg', [
            'exposureScore' => 0.95, 'highlightClipRatio' => 0.50,
        ]);
        $failExposure = $this->createAssetFixture('fe.jpg', [
            'exposureScore' => 0.80, 'highlightClipRatio' => 0.50,
        ]);
        $failHighlight = $this->createAssetFixture('fh.jpg', [
            'exposureScore' => 0.95, 'highlightClipRatio' => 0.30,
        ]);

        $ids = Asset::find()->volume('lenstest')->lensTooBright(true)->ids();

        $this->assertContains($bright->assetId, $ids);
        $this->assertNotContains($failExposure->assetId, $ids);
        $this->assertNotContains($failHighlight->assetId, $ids);
    }

    public function testLensTooBrightIgnoresNoiseScore(): void
    {
        // Parallel to TooDark: noise belongs to lensLowContrast, not lensTooBright.
        $brightAndNoisy = $this->createAssetFixture('bn.jpg', [
            'exposureScore' => 0.95, 'highlightClipRatio' => 0.50, 'noiseScore' => 0.50,
        ]);

        $ids = Asset::find()->volume('lenstest')->lensTooBright(true)->ids();

        $this->assertContains($brightAndNoisy->assetId, $ids);
    }

    public function testLensTooBrightExcludesNullExposure(): void
    {
        $nullExposure = $this->createAssetFixture('nx.jpg', [
            'exposureScore' => null, 'highlightClipRatio' => 0.50,
        ]);

        $ids = Asset::find()->volume('lenstest')->lensTooBright(true)->ids();

        $this->assertNotContains($nullExposure->assetId, $ids);
    }

    // ---------- lensLowContrast ----------

    public function testLensLowContrastTrueUsesContrastLowConstant(): void
    {
        $this->assertSame(0.45, ImageMetricsAnalyzer::CONTRAST_LOW);

        $low = $this->createAssetFixture('low.jpg', ['noiseScore' => 0.44]);
        $boundary = $this->createAssetFixture('eq.jpg', ['noiseScore' => 0.45]);
        $high = $this->createAssetFixture('high.jpg', ['noiseScore' => 0.8]);
        $nullRow = $this->createAssetFixture('null.jpg', ['noiseScore' => null]);

        $ids = Asset::find()->volume('lenstest')->lensLowContrast(true)->ids();

        $this->assertContains($low->assetId, $ids);
        $this->assertNotContains($boundary->assetId, $ids);
        $this->assertNotContains($high->assetId, $ids);
        $this->assertNotContains($nullRow->assetId, $ids);
    }

    // ---------- lensTooLarge (assets.size) ----------

    public function testLensTooLargeTrueBoundaryOneMillion(): void
    {
        $this->assertSame(1_000_000, FileTooLargeConditionRule::FILE_SIZE_WARNING);

        $below = $this->createAssetFixture('below.jpg', [], ['size' => 999_999]);
        $boundary = $this->createAssetFixture('eq.jpg', [], ['size' => 1_000_000]);
        $above = $this->createAssetFixture('above.jpg', [], ['size' => 1_500_000]);

        $ids = Asset::find()->volume('lenstest')->lensTooLarge(true)->ids();

        $this->assertNotContains($below->assetId, $ids, '999_999 bytes is below 1MB threshold');
        $this->assertContains($boundary->assetId, $ids, '1MB exactly is >= 1_000_000 (inclusive)');
        $this->assertContains($above->assetId, $ids);
    }

    public function testLensTooLargeFalseIsNoOp(): void
    {
        $large = $this->createAssetFixture('large.jpg', [], ['size' => 5_000_000]);
        $ids = Asset::find()->volume('lenstest')->lensTooLarge(false)->ids();
        $this->assertContains($large->assetId, $ids);
    }

    // ---------- lensHasTextInImage ----------

    public function testLensHasTextInImageTrueExcludesEmptyArrayAndNull(): void
    {
        $withText = $this->createAssetFixture('with.jpg', [
            'extractedTextAi' => ['Hello'],
        ]);
        $emptyArray = $this->createAssetFixture('empty.jpg', [
            'extractedTextAi' => [],
        ]);
        $nullRow = $this->createAssetFixture('null.jpg', [
            'extractedTextAi' => null,
        ]);

        $ids = Asset::find()->volume('lenstest')->lensHasTextInImage(true)->ids();

        $this->assertContains($withText->assetId, $ids);
        $this->assertNotContains($emptyArray->assetId, $ids, '"[]" must be treated as no text');
        $this->assertNotContains($nullRow->assetId, $ids);
    }

    public function testLensHasTextInImageFalseIncludesEmptyArrayAndNull(): void
    {
        $withText = $this->createAssetFixture('with.jpg', [
            'extractedTextAi' => ['Hello'],
        ]);
        $emptyArray = $this->createAssetFixture('empty.jpg', [
            'extractedTextAi' => [],
        ]);
        $nullRow = $this->createAssetFixture('null.jpg', [
            'extractedTextAi' => null,
        ]);

        $ids = Asset::find()->volume('lenstest')->lensHasTextInImage(false)->ids();

        $this->assertNotContains($withText->assetId, $ids);
        $this->assertContains($emptyArray->assetId, $ids);
        $this->assertContains($nullRow->assetId, $ids);
    }

    public function testLensTooBrightFalseIsNoOp(): void
    {
        $bright = $this->createAssetFixture('bright.jpg', [
            'exposureScore' => 0.95, 'highlightClipRatio' => 0.50, 'noiseScore' => 0.20,
        ]);
        $ids = Asset::find()->volume('lenstest')->lensTooBright(false)->ids();
        $this->assertContains($bright->assetId, $ids, 'lensTooBright(false) is a no-op');
    }

    public function testLensLowContrastFalseIsNoOp(): void
    {
        $low = $this->createAssetFixture('low.jpg', ['noiseScore' => 0.1]);
        $high = $this->createAssetFixture('high.jpg', ['noiseScore' => 0.9]);
        $ids = Asset::find()->volume('lenstest')->lensLowContrast(false)->ids();
        $this->assertContains($low->assetId, $ids);
        $this->assertContains($high->assetId, $ids);
    }

    public function testQualityNullParametersApplyNoFilter(): void
    {
        // Consolidated null-parameter contract: every method in this file
        // treats null as "skip the filter". Hitting this contract in one test
        // keeps per-method tests focused on actual filter semantics.
        $low = $this->createAssetFixture('low.jpg', [
            'sharpnessScore' => 0.1,
            'exposureScore' => 0.1,
            'shadowClipRatio' => 0.5,
            'noiseScore' => 0.2,
            'extractedTextAi' => ['t'],
        ], ['focalPoint' => '0.5;0.5', 'size' => 2_000_000]);
        $high = $this->createAssetFixture('high.jpg', [
            'sharpnessScore' => 0.9,
            'exposureScore' => 0.5,
            'extractedTextAi' => null,
        ], ['focalPoint' => null, 'size' => 50_000]);

        foreach ([
            'lensSharpnessBelow' => null,
            'lensExposureIssues' => null,
            'lensHasFocalPoint' => null,
            'lensBlurry' => null,
            'lensTooDark' => null,
            'lensTooBright' => null,
            'lensLowContrast' => null,
            'lensTooLarge' => null,
            'lensHasTextInImage' => null,
        ] as $method => $arg) {
            $ids = Asset::find()->volume('lenstest')->{$method}($arg)->ids();
            $this->assertContains($low->assetId, $ids, "$method(null) must not filter");
            $this->assertContains($high->assetId, $ids, "$method(null) must not filter");
        }
    }
}
