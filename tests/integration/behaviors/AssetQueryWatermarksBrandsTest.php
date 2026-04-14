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
 * Integration tests for watermarks (lensHasWatermark, lensWatermarkType,
 * lensWatermarkTypes), brand logos (lensContainsBrandLogo, lensDetectedBrand),
 * and stock providers (lensStockProvider).
 *
 * Non-obvious SQL to guard:
 * - lensDetectedBrand does LIKE '%"brand":"Nike"%' on a JSON column.
 *   Partial matches like "Nik" inside "Nikon" must NOT leak.
 * - lensStockProvider wraps the column in LOWER() and lowercases the input,
 *   so fixtures stored mixed-case must still match.
 */
class AssetQueryWatermarksBrandsTest extends Unit
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

    // ---------- lensHasWatermark ----------

    public function testLensHasWatermarkTrue(): void
    {
        $yes = $this->createAssetFixture('y.jpg', ['hasWatermark' => true]);
        $no = $this->createAssetFixture('n.jpg', ['hasWatermark' => false]);

        $ids = Asset::find()->volume('lenstest')->lensHasWatermark(true)->ids();

        $this->assertContains($yes->assetId, $ids);
        $this->assertNotContains($no->assetId, $ids);
    }

    public function testLensHasWatermarkFalse(): void
    {
        $yes = $this->createAssetFixture('y.jpg', ['hasWatermark' => true]);
        $no = $this->createAssetFixture('n.jpg', ['hasWatermark' => false]);

        $ids = Asset::find()->volume('lenstest')->lensHasWatermark(false)->ids();

        $this->assertNotContains($yes->assetId, $ids);
        $this->assertContains($no->assetId, $ids);
    }

    // ---------- lensWatermarkType (single) ----------

    public function testLensWatermarkTypeScalarMatchExact(): void
    {
        $stock = $this->createAssetFixture('stock.jpg', [
            'hasWatermark' => true, 'watermarkType' => 'stock',
        ]);
        $logo = $this->createAssetFixture('logo.jpg', [
            'hasWatermark' => true, 'watermarkType' => 'logo',
        ]);
        $none = $this->createAssetFixture('none.jpg', ['watermarkType' => null]);

        $ids = Asset::find()->volume('lenstest')->lensWatermarkType('stock')->ids();

        $this->assertContains($stock->assetId, $ids);
        $this->assertNotContains($logo->assetId, $ids);
        $this->assertNotContains($none->assetId, $ids);
    }

    // ---------- lensWatermarkTypes (multi) ----------

    public function testLensWatermarkTypesArrayIn(): void
    {
        $stock = $this->createAssetFixture('stock.jpg', ['watermarkType' => 'stock']);
        $logo = $this->createAssetFixture('logo.jpg', ['watermarkType' => 'logo']);
        $text = $this->createAssetFixture('text.jpg', ['watermarkType' => 'text']);
        $ai = $this->createAssetFixture('ai.jpg', ['watermarkType' => 'ai']);

        $ids = Asset::find()->volume('lenstest')->lensWatermarkTypes(['stock', 'logo'])->ids();

        $this->assertContains($stock->assetId, $ids);
        $this->assertContains($logo->assetId, $ids);
        $this->assertNotContains($text->assetId, $ids);
        $this->assertNotContains($ai->assetId, $ids);
    }

    public function testLensWatermarkTypesEmptyArrayIsNoOp(): void
    {
        // Behavior: lensWatermarkTypes applies only when the array is non-empty
        // (see `&& !empty(...)` guard in applyLensFilters).
        $stock = $this->createAssetFixture('stock.jpg', ['watermarkType' => 'stock']);
        $text = $this->createAssetFixture('text.jpg', ['watermarkType' => 'text']);

        $ids = Asset::find()->volume('lenstest')->lensWatermarkTypes([])->ids();

        $this->assertContains($stock->assetId, $ids);
        $this->assertContains($text->assetId, $ids);
    }

    // ---------- lensContainsBrandLogo ----------

    public function testLensContainsBrandLogoTrue(): void
    {
        $yes = $this->createAssetFixture('y.jpg', ['containsBrandLogo' => true]);
        $no = $this->createAssetFixture('n.jpg', ['containsBrandLogo' => false]);

        $ids = Asset::find()->volume('lenstest')->lensContainsBrandLogo(true)->ids();

        $this->assertContains($yes->assetId, $ids);
        $this->assertNotContains($no->assetId, $ids);
    }

    // ---------- lensDetectedBrand (JSON LIKE) ----------

    public function testLensDetectedBrandMatchesExactBrandValue(): void
    {
        $nike = $this->createAssetFixture('nike.jpg', [
            'containsBrandLogo' => true,
            'detectedBrands' => [['brand' => 'Nike', 'confidence' => 0.95]],
        ]);
        $adidas = $this->createAssetFixture('adidas.jpg', [
            'containsBrandLogo' => true,
            'detectedBrands' => [['brand' => 'Adidas', 'confidence' => 0.9]],
        ]);

        $ids = Asset::find()->volume('lenstest')->lensDetectedBrand('Nike')->ids();

        $this->assertContains($nike->assetId, $ids);
        $this->assertNotContains($adidas->assetId, $ids);
    }

    public function testLensDetectedBrandDoesNotLeakSubstrings(): void
    {
        // Critical: "Nik" must NOT match inside "Nikon" because the LIKE
        // pattern closes the brand value with a literal quote: %"brand":"Nik"%.
        $nikon = $this->createAssetFixture('nikon.jpg', [
            'detectedBrands' => [['brand' => 'Nikon']],
        ]);

        $ids = Asset::find()->volume('lenstest')->lensDetectedBrand('Nik')->ids();

        $this->assertNotContains($nikon->assetId, $ids, '"Nik" must not match "Nikon" (closing-quote escapes)');
    }

    public function testLensDetectedBrandMatchesAnyBrandInMultiBrandArray(): void
    {
        $multi = $this->createAssetFixture('multi.jpg', [
            'detectedBrands' => [
                ['brand' => 'Adidas', 'confidence' => 0.9],
                ['brand' => 'Nike', 'confidence' => 0.8],
            ],
        ]);

        $ids = Asset::find()->volume('lenstest')->lensDetectedBrand('Nike')->ids();
        $this->assertContains($multi->assetId, $ids);
    }

    // ---------- lensStockProvider ----------

    public function testLensStockProviderCaseInsensitiveMatch(): void
    {
        $shutterstock = $this->createAssetFixture('ss.jpg', [
            'watermarkType' => 'stock',
            'watermarkDetails' => ['stockProvider' => 'Shutterstock'],
        ]);
        $getty = $this->createAssetFixture('getty.jpg', [
            'watermarkType' => 'stock',
            'watermarkDetails' => ['stockProvider' => 'Getty'],
        ]);

        // Filter lowercases input and wraps column in LOWER(), so the input
        // case must not matter.
        $ids = Asset::find()->volume('lenstest')->lensStockProvider('SHUTTERSTOCK')->ids();

        $this->assertContains($shutterstock->assetId, $ids);
        $this->assertNotContains($getty->assetId, $ids);
    }

    public function testLensStockProviderArrayForm(): void
    {
        $shutterstock = $this->createAssetFixture('ss.jpg', [
            'watermarkDetails' => ['stockProvider' => 'Shutterstock'],
        ]);
        $getty = $this->createAssetFixture('getty.jpg', [
            'watermarkDetails' => ['stockProvider' => 'Getty'],
        ]);
        $istock = $this->createAssetFixture('istock.jpg', [
            'watermarkDetails' => ['stockProvider' => 'iStock'],
        ]);

        $ids = Asset::find()
            ->volume('lenstest')
            ->lensStockProvider(['Shutterstock', 'Getty'])
            ->ids();

        $this->assertContains($shutterstock->assetId, $ids);
        $this->assertContains($getty->assetId, $ids);
        $this->assertNotContains($istock->assetId, $ids);
    }

    public function testLensStockProviderIgnoresUnrelatedJson(): void
    {
        $other = $this->createAssetFixture('other.jpg', [
            'watermarkDetails' => ['provider' => 'Shutterstock'], // wrong key
        ]);

        $ids = Asset::find()->volume('lenstest')->lensStockProvider('Shutterstock')->ids();

        $this->assertNotContains($other->assetId, $ids, 'JSON key must be exactly "stockprovider" (case-normalized)');
    }

    public function testLensContainsBrandLogoFalseExcludesFlaggedAssets(): void
    {
        $yes = $this->createAssetFixture('y.jpg', ['containsBrandLogo' => true]);
        $no = $this->createAssetFixture('n.jpg', ['containsBrandLogo' => false]);

        $ids = Asset::find()->volume('lenstest')->lensContainsBrandLogo(false)->ids();

        $this->assertNotContains($yes->assetId, $ids);
        $this->assertContains($no->assetId, $ids);
    }

    public function testLensWatermarkTypeNonExistentMatchesNothing(): void
    {
        $stock = $this->createAssetFixture('stock.jpg', ['watermarkType' => 'stock']);
        $logo = $this->createAssetFixture('logo.jpg', ['watermarkType' => 'logo']);

        $ids = Asset::find()->volume('lenstest')->lensWatermarkType('nonexistent')->ids();

        $this->assertNotContains($stock->assetId, $ids);
        $this->assertNotContains($logo->assetId, $ids);
    }

    public function testWatermarkAndBrandNullParametersApplyNoFilter(): void
    {
        // Covers null-parameter behavior for every filter in this category in
        // one consolidated test: passing null must skip the filter entirely.
        $a = $this->createAssetFixture('a.jpg', [
            'hasWatermark' => true,
            'watermarkType' => 'stock',
            'containsBrandLogo' => true,
            'detectedBrands' => [['brand' => 'Nike']],
            'watermarkDetails' => ['stockProvider' => 'Getty'],
        ]);
        $b = $this->createAssetFixture('b.jpg', [
            'hasWatermark' => false,
            'watermarkType' => null,
            'containsBrandLogo' => false,
        ]);

        foreach ([
            'lensHasWatermark' => null,
            'lensWatermarkType' => null,
            'lensWatermarkTypes' => null,
            'lensContainsBrandLogo' => null,
            'lensDetectedBrand' => null,
            'lensStockProvider' => null,
        ] as $method => $arg) {
            $ids = Asset::find()->volume('lenstest')->{$method}($arg)->ids();
            $this->assertContains($a->assetId, $ids, "$method(null) should not filter out A");
            $this->assertContains($b->assetId, $ids, "$method(null) should not filter out B");
        }
    }

    public function testLensStockProviderIgnoredOnLite(): void
    {
        // No assets have watermarkDetails set, so the Pro filter would exclude
        // all of them. In Lite, the setter must no-op and both should remain.
        $assetA = $this->createAssetFixture('a.jpg');
        $assetB = $this->createAssetFixture('b.jpg');

        Plugin::getInstance()->edition = Plugin::EDITION_LITE;

        $ids = Asset::find()->volume('lenstest')->lensStockProvider('Shutterstock')->ids();

        $this->assertContains($assetA->assetId, $ids, 'Lite must ignore lensStockProvider');
        $this->assertContains($assetB->assetId, $ids, 'Lite must ignore lensStockProvider');
    }
}
