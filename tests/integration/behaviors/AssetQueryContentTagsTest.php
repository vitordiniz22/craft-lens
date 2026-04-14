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
 * Integration tests for lensTag(), lensColor(), lensTextSearch().
 *
 * These three filters read from child tables (lens_asset_tags, lens_asset_colors)
 * or a JSON column (extractedTextAi) via EXISTS subquery or LIKE respectively.
 */
class AssetQueryContentTagsTest extends Unit
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

    // ---------- lensTag ----------

    public function testLensTagMatchesOnlyAssetsWithThatTag(): void
    {
        $withSunset = $this->createAssetFixture('sunset.jpg');
        $this->createTagRow($withSunset->id, $withSunset->assetId, 'sunset');
        $this->createTagRow($withSunset->id, $withSunset->assetId, 'beach');

        $withOther = $this->createAssetFixture('other.jpg');
        $this->createTagRow($withOther->id, $withOther->assetId, 'mountain');

        $noTags = $this->createAssetFixture('notags.jpg');

        $ids = Asset::find()->volume('lenstest')->lensTag('sunset')->ids();

        $this->assertContains($withSunset->assetId, $ids);
        $this->assertNotContains($withOther->assetId, $ids);
        $this->assertNotContains($noTags->assetId, $ids);
    }

    public function testLensTagExactMatchNotSubstring(): void
    {
        $sunset = $this->createAssetFixture('sunset.jpg');
        $this->createTagRow($sunset->id, $sunset->assetId, 'sunset');

        $sunsetBeach = $this->createAssetFixture('sunsetbeach.jpg');
        $this->createTagRow($sunsetBeach->id, $sunsetBeach->assetId, 'sunset-beach');

        $ids = Asset::find()->volume('lenstest')->lensTag('sunset')->ids();

        $this->assertContains($sunset->assetId, $ids);
        $this->assertNotContains($sunsetBeach->assetId, $ids, 'substring "sunset" in "sunset-beach" must not match');
    }

    public function testLensTagNullAppliesNoFilter(): void
    {
        $tagged = $this->createAssetFixture('a.jpg');
        $this->createTagRow($tagged->id, $tagged->assetId, 'tag1');
        $untagged = $this->createAssetFixture('b.jpg');

        $ids = Asset::find()->volume('lenstest')->lensTag(null)->ids();

        $this->assertContains($tagged->assetId, $ids);
        $this->assertContains($untagged->assetId, $ids);
    }

    // ---------- lensColor ----------

    public function testLensColorMatchesAnyStoredColor(): void
    {
        $red = $this->createAssetFixture('red.jpg');
        $this->createColorRow($red->id, $red->assetId, '#FF0000', 0.6);
        $this->createColorRow($red->id, $red->assetId, '#000000', 0.4);

        $blue = $this->createAssetFixture('blue.jpg');
        $this->createColorRow($blue->id, $blue->assetId, '#0000FF', 1.0);

        $noColors = $this->createAssetFixture('nocolors.jpg');

        $ids = Asset::find()->volume('lenstest')->lensColor('#FF0000')->ids();

        $this->assertContains($red->assetId, $ids, 'asset with multiple colors including red matches');
        $this->assertNotContains($blue->assetId, $ids);
        $this->assertNotContains($noColors->assetId, $ids);
    }

    public function testLensColorIsExactHexMatch(): void
    {
        $upper = $this->createAssetFixture('upper.jpg');
        $this->createColorRow($upper->id, $upper->assetId, '#FF0000');

        $lower = $this->createAssetFixture('lower.jpg');
        $this->createColorRow($lower->id, $lower->assetId, '#ff0000');

        // Matching by exact string. MySQL collation on varchar(7) determines
        // whether #FF0000 and #ff0000 are equal; this test documents actual behavior.
        $idsUpper = Asset::find()->volume('lenstest')->lensColor('#FF0000')->ids();

        $this->assertContains($upper->assetId, $idsUpper);
        // The lower-case record either matches (if CI collation) or not (if CS).
        // Either way, the OPPOSITE direction must behave consistently:
        $idsLower = Asset::find()->volume('lenstest')->lensColor('#ff0000')->ids();
        $this->assertSame(
            in_array($upper->assetId, $idsUpper, true),
            in_array($lower->assetId, $idsLower, true),
            'hex matching must be symmetrical across case (CI) or consistently case-sensitive',
        );
    }

    public function testLensColorNullAppliesNoFilter(): void
    {
        $withColor = $this->createAssetFixture('a.jpg');
        $this->createColorRow($withColor->id, $withColor->assetId, '#FF0000');
        $noColor = $this->createAssetFixture('b.jpg');

        $ids = Asset::find()->volume('lenstest')->lensColor(null)->ids();

        $this->assertContains($withColor->assetId, $ids);
        $this->assertContains($noColor->assetId, $ids);
    }

    // ---------- lensTextSearch ----------

    public function testLensTextSearchMatchesSubstringInJsonArray(): void
    {
        $withInvoice = $this->createAssetFixture('invoice.jpg', [
            'extractedTextAi' => ['Invoice #123', 'Total: $50'],
        ]);
        $withReceipt = $this->createAssetFixture('receipt.jpg', [
            'extractedTextAi' => ['Receipt for lunch'],
        ]);
        $noText = $this->createAssetFixture('notext.jpg', [
            'extractedTextAi' => null,
        ]);

        $ids = Asset::find()->volume('lenstest')->lensTextSearch('Invoice')->ids();

        $this->assertContains($withInvoice->assetId, $ids);
        $this->assertNotContains($withReceipt->assetId, $ids);
        $this->assertNotContains($noText->assetId, $ids);
    }

    public function testLensTextSearchEmptyStringAppliesNoFilter(): void
    {
        // Behavior: lensTextSearch('') skips the filter (empty string guard in applyTextSearchFilter).
        $withText = $this->createAssetFixture('a.jpg', [
            'extractedTextAi' => ['hello'],
        ]);
        $noText = $this->createAssetFixture('b.jpg', [
            'extractedTextAi' => null,
        ]);

        $ids = Asset::find()->volume('lenstest')->lensTextSearch('')->ids();

        $this->assertContains($withText->assetId, $ids);
        $this->assertContains($noText->assetId, $ids);
    }

    public function testLensTextSearchNullAppliesNoFilter(): void
    {
        $withText = $this->createAssetFixture('a.jpg', [
            'extractedTextAi' => ['hello'],
        ]);
        $noText = $this->createAssetFixture('b.jpg', [
            'extractedTextAi' => null,
        ]);

        $ids = Asset::find()->volume('lenstest')->lensTextSearch(null)->ids();

        $this->assertContains($withText->assetId, $ids);
        $this->assertContains($noText->assetId, $ids);
    }

    public function testLensTextSearchWithNoMatchesReturnsEmpty(): void
    {
        $hello = $this->createAssetFixture('hello.jpg', [
            'extractedTextAi' => ['hello world'],
        ]);
        $goodbye = $this->createAssetFixture('bye.jpg', [
            'extractedTextAi' => ['goodbye'],
        ]);

        $ids = Asset::find()->volume('lenstest')->lensTextSearch('nonexistentstring')->ids();

        $this->assertNotContains($hello->assetId, $ids);
        $this->assertNotContains($goodbye->assetId, $ids);
    }

    public function testLensTagIgnoredOnLite(): void
    {
        $withSunset = $this->createAssetFixture('sunset.jpg');
        $this->createTagRow($withSunset->id, $withSunset->assetId, 'sunset');

        $withOther = $this->createAssetFixture('mountain.jpg');
        $this->createTagRow($withOther->id, $withOther->assetId, 'mountain');

        Plugin::getInstance()->edition = Plugin::EDITION_LITE;

        $ids = Asset::find()->volume('lenstest')->lensTag('sunset')->ids();

        $this->assertContains($withSunset->assetId, $ids);
        $this->assertContains($withOther->assetId, $ids, 'Lite must ignore lensTag');
    }

    public function testLensColorIgnoredOnLite(): void
    {
        $red = $this->createAssetFixture('red.jpg');
        $this->createColorRow($red->id, $red->assetId, '#FF0000');

        $blue = $this->createAssetFixture('blue.jpg');
        $this->createColorRow($blue->id, $blue->assetId, '#0000FF');

        Plugin::getInstance()->edition = Plugin::EDITION_LITE;

        $ids = Asset::find()->volume('lenstest')->lensColor('#FF0000')->ids();

        $this->assertContains($red->assetId, $ids);
        $this->assertContains($blue->assetId, $ids, 'Lite must ignore lensColor');
    }

    public function testLensTextSearchIgnoredOnLite(): void
    {
        $withInvoice = $this->createAssetFixture('invoice.jpg', [
            'extractedTextAi' => json_encode(['Invoice #123']),
        ]);
        $withReceipt = $this->createAssetFixture('receipt.jpg', [
            'extractedTextAi' => json_encode(['Receipt for lunch']),
        ]);

        Plugin::getInstance()->edition = Plugin::EDITION_LITE;

        $ids = Asset::find()->volume('lenstest')->lensTextSearch('Invoice')->ids();

        $this->assertContains($withInvoice->assetId, $ids);
        $this->assertContains($withReceipt->assetId, $ids, 'Lite must ignore lensTextSearch');
    }

    public function testLensHasTextInImageIgnoredOnLite(): void
    {
        $withText = $this->createAssetFixture('with.jpg', [
            'extractedTextAi' => json_encode(['Hello']),
        ]);
        $noText = $this->createAssetFixture('notext.jpg', [
            'extractedTextAi' => null,
        ]);

        Plugin::getInstance()->edition = Plugin::EDITION_LITE;

        $ids = Asset::find()->volume('lenstest')->lensHasTextInImage(true)->ids();

        $this->assertContains($withText->assetId, $ids);
        $this->assertContains($noText->assetId, $ids, 'Lite must ignore lensHasTextInImage');
    }
}
