<?php

declare(strict_types=1);

namespace vitordiniz22\craftlenstests\unit\helpers;

use Codeception\Test\Unit;
use craft\elements\Asset;
use ReflectionClass;
use ReflectionMethod;
use vitordiniz22\craftlens\helpers\ImagePreprocessor;

/**
 * Unit tests for ImagePreprocessor.
 *
 * End-to-end resize behavior requires Craft's Images service and real image
 * files on disk; those assertions live in the integration suite. These unit
 * tests cover the pure-logic branches: the skip matrix (shouldSkip) and
 * static driver state reset. The skip matrix is the bulk of the helper's
 * decision surface and is where regressions are most likely to appear.
 */
class ImagePreprocessorTest extends Unit
{
    protected function _before(): void
    {
        parent::_before();
        ImagePreprocessor::resetStaticState();
    }

    // -- resetStaticState() -----------------------------------------------

    public function testResetStaticStateClearsBothFlags(): void
    {
        $class = new ReflectionClass(ImagePreprocessor::class);

        $driverCache = $class->getProperty('driverCache');
        $driverCache->setAccessible(true);
        $driverCache->setValue(null, false);

        $warning = $class->getProperty('driverWarningEmitted');
        $warning->setAccessible(true);
        $warning->setValue(null, true);

        ImagePreprocessor::resetStaticState();

        $this->assertNull($driverCache->getValue());
        $this->assertFalse($warning->getValue());
    }

    // -- shouldSkip() via reflection --------------------------------------

    public function testShouldSkipReturnsNotImageForNonImageKind(): void
    {
        $asset = $this->stubAsset(kind: Asset::KIND_VIDEO);

        $this->assertSame('not_image', $this->invokeShouldSkip($asset, 'video/mp4', 1_000_000, 1568));
    }

    public function testShouldSkipReturnsMimeUnsupportedForSvg(): void
    {
        $asset = $this->stubAsset(kind: Asset::KIND_IMAGE, extension: 'svg');

        $this->assertSame('mime_unsupported', $this->invokeShouldSkip($asset, 'image/svg+xml', 5_000, 1568));
    }

    public function testShouldSkipReturnsMimeUnsupportedForAnimatedGif(): void
    {
        $asset = $this->stubAsset(kind: Asset::KIND_IMAGE, extension: 'gif');

        $this->assertSame('mime_unsupported', $this->invokeShouldSkip($asset, 'image/gif', 10_000, 1568));
    }

    public function testShouldSkipReturnsMimeUnsupportedForPdf(): void
    {
        $asset = $this->stubAsset(kind: Asset::KIND_IMAGE, extension: 'pdf');

        $this->assertSame('mime_unsupported', $this->invokeShouldSkip($asset, 'application/pdf', 50_000, 1568));
    }

    public function testShouldSkipReturnsRawFormatUnsupportedForCr2(): void
    {
        $asset = $this->stubAsset(kind: Asset::KIND_IMAGE, extension: 'cr2');

        $this->assertSame('raw_format_unsupported', $this->invokeShouldSkip($asset, 'image/x-canon-cr2', 20_000_000, 1568));
    }

    public function testShouldSkipIsCaseInsensitiveForExtension(): void
    {
        $asset = $this->stubAsset(kind: Asset::KIND_IMAGE, extension: 'NEF');

        $this->assertSame('raw_format_unsupported', $this->invokeShouldSkip($asset, 'image/x-nikon-nef', 20_000_000, 1568));
    }

    public function testShouldSkipReturnsEmptyFileForZeroBytes(): void
    {
        $asset = $this->stubAsset(kind: Asset::KIND_IMAGE, extension: 'jpg', size: 0);

        $this->assertSame('empty_file', $this->invokeShouldSkip($asset, 'image/jpeg', 0, 1568));
    }

    public function testShouldSkipReturnsAlreadySmallWhenUnderBothThresholds(): void
    {
        $asset = $this->stubAsset(
            kind: Asset::KIND_IMAGE,
            extension: 'jpg',
            size: 200_000,
            width: 1200,
            height: 800,
        );

        $this->assertSame('already_small', $this->invokeShouldSkip($asset, 'image/jpeg', 200_000, 1568));
    }

    public function testShouldSkipDoesNotSkipWhenOverBytesButUnderDimensions(): void
    {
        $asset = $this->stubAsset(
            kind: Asset::KIND_IMAGE,
            extension: 'png',
            size: 2_000_000,
            width: 1500,
            height: 1500,
        );

        // 1500x1500 PNG at 2 MB: dimensions under 1568 but bytes over 500K threshold → should process.
        $this->assertNull($this->invokeShouldSkip($asset, 'image/png', 2_000_000, 1568));
    }

    public function testShouldSkipDoesNotSkipLargePhoto(): void
    {
        $asset = $this->stubAsset(
            kind: Asset::KIND_IMAGE,
            extension: 'jpg',
            size: 6_000_000,
            width: 6000,
            height: 4000,
        );

        $this->assertNull($this->invokeShouldSkip($asset, 'image/jpeg', 6_000_000, 1568));
    }

    public function testShouldSkipDoesNotSkipWhenDimensionsMissing(): void
    {
        // Assets without cached width/height (width=0) should not be skipped
        // as "already_small" — we fall through and let preprocessing decide.
        $asset = $this->stubAsset(
            kind: Asset::KIND_IMAGE,
            extension: 'jpg',
            size: 100_000,
            width: 0,
            height: 0,
        );

        $this->assertNull($this->invokeShouldSkip($asset, 'image/jpeg', 100_000, 1568));
    }

    // -- Helpers ----------------------------------------------------------

    /**
     * Build an Asset stub that mocks only the methods/properties shouldSkip reads.
     */
    private function stubAsset(
        string $kind = Asset::KIND_IMAGE,
        string $extension = 'jpg',
        ?int $size = 1_000_000,
        int $width = 3000,
        int $height = 2000,
    ): Asset {
        $asset = $this->createStub(Asset::class);
        $asset->kind = $kind;
        $asset->size = $size;
        $asset->method('getExtension')->willReturn($extension);
        $asset->method('getWidth')->willReturn($width);
        $asset->method('getHeight')->willReturn($height);

        return $asset;
    }

    private function invokeShouldSkip(Asset $asset, string $mimeType, int $byteLength, int $maxDimension): ?string
    {
        $method = new ReflectionMethod(ImagePreprocessor::class, 'shouldSkip');
        $method->setAccessible(true);

        return $method->invoke(null, $asset, $mimeType, $byteLength, $maxDimension);
    }
}
