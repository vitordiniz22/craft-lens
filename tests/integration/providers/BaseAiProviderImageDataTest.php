<?php

declare(strict_types=1);

namespace vitordiniz22\craftlenstests\integration\providers;

use Codeception\Test\Unit;
use craft\elements\Asset;
use ReflectionMethod;
use vitordiniz22\craftlens\exceptions\AnalysisException;
use vitordiniz22\craftlens\helpers\ImagePreprocessor;
use vitordiniz22\craftlens\providers\BaseAiProvider;
use vitordiniz22\craftlenstests\integration\providers\_support\TestAiProvider;

/**
 * Integration tests for BaseAiProvider::getBase64ImageData().
 *
 * These tests lock in the preprocessing contract:
 *   - When preprocessing succeeds, the post-size check uses the processed bytes.
 *   - When preprocessing fails, we fall back to the original bytes WITHOUT
 *     throwing fileTooLarge, even if the original exceeds the provider cap.
 *     This is the load-bearing guarantee: preprocessing failures never break
 *     analysis.
 *   - The decode-cost guard rejects pathological originals before any attempt
 *     at decode.
 */
class BaseAiProviderImageDataTest extends Unit
{
    private string $tempDir;
    /** @var string[] */
    private array $tempFiles = [];

    protected function _before(): void
    {
        parent::_before();
        ImagePreprocessor::resetStaticState();

        $this->tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'lens-preproc-test-' . bin2hex(random_bytes(6));
        if (!is_dir($this->tempDir)) {
            mkdir($this->tempDir, 0777, true);
        }
    }

    protected function _after(): void
    {
        foreach ($this->tempFiles as $path) {
            if (file_exists($path)) {
                @unlink($path);
            }
        }
        if (is_dir($this->tempDir)) {
            // Best-effort cleanup; leave anything we missed to the OS.
            foreach (glob($this->tempDir . '/*') ?: [] as $path) {
                @unlink($path);
            }
            @rmdir($this->tempDir);
        }

        parent::_after();
    }

    // -- Load-bearing: failure fallback must NOT throw fileTooLarge --------

    public function testFailedPreprocessingDoesNotThrowFileTooLargeOnFallback(): void
    {
        // 600 KB of random bytes labelled as .jpg — loadImage() will throw,
        // the helper catches, and we fall back to the raw 600 KB bytes.
        $rawBytes = random_bytes(600_000);
        $path = $this->writeTempFile('corrupt.jpg', $rawBytes);

        $asset = $this->stubAsset(
            path: $path,
            mimeType: 'image/jpeg',
            size: strlen($rawBytes),
            width: 6000,
            height: 4000,
            extension: 'jpg',
        );

        // Provider cap is 500 KB; raw is 600 KB and would fail the cap if
        // the post-check ran on fallback bytes. It must NOT.
        $provider = new TestAiProvider(maxFileSizeBytes: 500_000);

        $result = $this->invokeGetBase64ImageData($provider, $asset);

        $this->assertSame(
            base64_encode($rawBytes),
            $result['base64'],
            'Failed preprocessing must fall through to raw bytes unchanged, even when raw exceeds the provider cap'
        );
    }

    // -- Post-check applies when preprocessing succeeded -------------------

    public function testOverCapAfterSuccessfulPreprocessingThrowsFileTooLarge(): void
    {
        // Real JPEG that preprocesses cleanly. We set the cap absurdly low
        // (100 bytes) so any processed output will exceed it.
        $path = $this->writeTempFile('photo.jpg', $this->realLargeJpeg());

        $asset = $this->stubAsset(
            path: $path,
            mimeType: 'image/jpeg',
            size: filesize($path),
            width: 2000,
            height: 1500,
            extension: 'jpg',
        );

        $provider = new TestAiProvider(maxFileSizeBytes: 100);

        $this->expectException(AnalysisException::class);
        $this->invokeGetBase64ImageData($provider, $asset);
    }

    // -- Decode-cost guard -------------------------------------------------

    public function testDecodeCostGuardRejectsPathologicalOriginal(): void
    {
        // Asset.size of 2 GB. We don't actually write 2 GB — the guard fires
        // based on reported $asset->size before any decode attempt. If the
        // guard failed to fire, the test would hang/blow up trying to decode.
        $asset = $this->stubAsset(
            path: null,
            mimeType: 'image/jpeg',
            size: 2 * 1024 * 1024 * 1024,
            width: 0,
            height: 0,
            extension: 'jpg',
        );

        // Cap = 3 MB; guard fires at max(cap*10, 100 MB) = 100 MB. 2 GB blows past it.
        $provider = new TestAiProvider(maxFileSizeBytes: 3 * 1024 * 1024);

        $this->expectException(AnalysisException::class);
        $this->invokeGetBase64ImageData($provider, $asset);
    }

    // -- Happy path through real Craft Images service ---------------------

    public function testSuccessfulPreprocessingProducesSmallerBytes(): void
    {
        $path = $this->writeTempFile('photo.jpg', $this->realLargeJpeg());
        $originalSize = filesize($path);

        $asset = $this->stubAsset(
            path: $path,
            mimeType: 'image/jpeg',
            size: $originalSize,
            width: 2000,
            height: 1500,
            extension: 'jpg',
        );

        // Cap well above expected processed size
        $provider = new TestAiProvider(maxFileSizeBytes: 10 * 1024 * 1024);

        $result = $this->invokeGetBase64ImageData($provider, $asset);

        $decoded = base64_decode($result['base64'], true);
        $this->assertNotFalse($decoded);
        $this->assertLessThan($originalSize, strlen($decoded), 'Processed bytes should be smaller than original for a large photo');
        $this->assertSame('image/jpeg', $result['mimeType']);
    }

    // -- Helpers ----------------------------------------------------------

    private function invokeGetBase64ImageData(BaseAiProvider $provider, Asset $asset): array
    {
        $method = new ReflectionMethod(BaseAiProvider::class, 'getBase64ImageData');
        $method->setAccessible(true);

        return $method->invoke($provider, $asset);
    }

    /**
     * Build an Asset stub that reads from / copies a real temp file on disk.
     */
    private function stubAsset(
        ?string $path,
        string $mimeType,
        int $size,
        int $width,
        int $height,
        string $extension,
    ): Asset {
        $asset = $this->createStub(Asset::class);
        $asset->id = 999;
        $asset->kind = Asset::KIND_IMAGE;
        $asset->size = $size;

        $asset->method('getMimeType')->willReturn($mimeType);
        $asset->method('getExtension')->willReturn($extension);
        $asset->method('getWidth')->willReturn($width);
        $asset->method('getHeight')->willReturn($height);

        if ($path !== null) {
            $asset->method('getStream')->willReturnCallback(
                static fn() => fopen($path, 'rb') ?: null
            );
            $asset->method('getCopyOfFile')->willReturnCallback(
                function () use ($path): string {
                    $copy = $this->tempDir . DIRECTORY_SEPARATOR . 'copy-' . bin2hex(random_bytes(6)) . '.bin';
                    copy($path, $copy);
                    $this->tempFiles[] = $copy;
                    return $copy;
                }
            );
        } else {
            // Decode-cost guard test: stream/copy should never be called.
            $asset->method('getStream')->willReturn(null);
        }

        return $asset;
    }

    private function writeTempFile(string $name, string $contents): string
    {
        $path = $this->tempDir . DIRECTORY_SEPARATOR . $name;
        file_put_contents($path, $contents);
        $this->tempFiles[] = $path;
        return $path;
    }

    /**
     * Create a real 2000x1500 JPEG of random-noise pixels via GD.
     * Noise defeats JPEG's compression, producing a file large enough to
     * exercise the resize path (~300 KB+ at quality 90).
     */
    private function realLargeJpeg(): string
    {
        $image = imagecreatetruecolor(2000, 1500);
        for ($x = 0; $x < 2000; $x += 2) {
            for ($y = 0; $y < 1500; $y += 2) {
                $color = imagecolorallocate($image, random_int(0, 255), random_int(0, 255), random_int(0, 255));
                imagesetpixel($image, $x, $y, $color);
                imagesetpixel($image, $x + 1, $y, $color);
                imagesetpixel($image, $x, $y + 1, $color);
                imagesetpixel($image, $x + 1, $y + 1, $color);
            }
        }

        ob_start();
        imagejpeg($image, null, 90);
        $data = (string) ob_get_clean();
        imagedestroy($image);

        return $data;
    }

}
