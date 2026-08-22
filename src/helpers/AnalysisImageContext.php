<?php

declare(strict_types=1);

namespace vitordiniz22\craftlens\helpers;

use Craft;
use craft\elements\Asset;
use Imagick;
use vitordiniz22\craftlens\enums\LogCategory;

/**
 * Per-asset image I/O cache shared by every step of the analysis pipeline.
 *
 * Reads the asset file once and decodes it once, bounded to
 * WORKING_MAX_DIMENSION. Consumers (metrics, AI preprocessing, perceptual
 * hash) clone that handle and downscale further as needed, so no step ever
 * pays for native-resolution pixels. Image resources can be released as soon
 * as upload bytes exist; the destructor removes the local temp copy.
 */
final class AnalysisImageContext
{
    /** Above every consumer working resolution, below the cost of a native decode. */
    public const WORKING_MAX_DIMENSION = 1568;

    /** Used when the host reports no container limit. */
    private const PIXEL_CACHE_FALLBACK_BYTES = 64 * 1024 * 1024;

    private const IMAGE_LOCK_NAME = 'lens:image-processing';

    private const IMAGE_LOCK_TIMEOUT_SECONDS = 30;

    /** @var array<int, int>|null */
    private ?array $previousResourceLimits = null;

    private ?string $rawBytes = null;
    private bool $rawBytesLoaded = false;

    private ?string $localPath = null;
    private bool $localPathLoaded = false;

    private ?Imagick $imagick = null;
    private bool $imagickLoaded = false;

    private ?string $lastDecodeError = null;

    private bool $imageLockAttempted = false;

    private bool $imageLockAcquired = false;

    private ?string $fileContentHash = null;

    public function __construct(public readonly Asset $asset)
    {
    }

    /**
     * Read the asset's bytes into memory once and return them.
     *
     * Reads from the same local file copy that `getLocalPath()` materializes,
     * so a single asset on a remote volume (S3, Bunny, etc.) only round-trips
     * to the volume once regardless of which methods are called.
     */
    public function getRawBytes(): ?string
    {
        if ($this->rawBytesLoaded) {
            return $this->rawBytes;
        }

        $this->rawBytesLoaded = true;
        $path = $this->getLocalPath();

        if ($path === null) {
            return $this->rawBytes = null;
        }

        $contents = @file_get_contents($path);
        $this->rawBytes = $contents === false ? null : $contents;

        return $this->rawBytes;
    }

    /**
     * Materialize the asset to a local temp file once and return the path.
     * The file is removed when this context is destroyed.
     */
    public function getLocalPath(): ?string
    {
        if ($this->localPathLoaded) {
            return $this->localPath;
        }

        $this->localPathLoaded = true;

        try {
            // Remote filesystems throw when a file is missing; null means
            // "no image" to callers, which report it properly.
            $path = $this->asset->getCopyOfFile();
        } catch (\Throwable $e) {
            Logger::warning(
                LogCategory::AssetProcessing,
                'Could not fetch asset file: ' . $e->getMessage(),
                $this->asset->id,
            );

            return $this->localPath = null;
        }

        $this->localPath = ($path !== null && file_exists($path)) ? $path : null;

        return $this->localPath;
    }

    /**
     * Build the shared Imagick handle from the local file once and return it.
     * Returns null if the Imagick extension is missing or the asset has no
     * usable local copy.
     *
     * Bounded to WORKING_MAX_DIMENSION: metrics work at 1568 and 500,
     * preprocessing at 1568, the hash at 16x16, so a native decode would cost
     * ~140MB of pixel cache on a 24MP photo and every clone would repeat it.
     */
    public function getWorkingImagick(): ?Imagick
    {
        if ($this->imagickLoaded) {
            return $this->imagick;
        }

        $this->imagickLoaded = true;

        if (!extension_loaded('imagick')) {
            return $this->imagick = null;
        }

        $path = $this->getLocalPath();

        if ($path === null) {
            return $this->imagick = null;
        }

        $this->imagick = $this->decodeBounded($path, self::WORKING_MAX_DIMENSION);

        if ($this->imagick === null) {
            // Without this a starved host looks like one without Imagick.
            Logger::warning(
                LogCategory::AssetProcessing,
                'Image normalization decode failed: ' . $this->lastDecodeError,
                $this->asset->id,
                context: [
                    'assetWidth' => $this->asset->width,
                    'assetHeight' => $this->asset->height,
                    'assetSize' => $this->asset->size,
                    'imagickMemoryLimit' => Imagick::getResourceLimit(Imagick::RESOURCETYPE_MEMORY),
                    'containerMemoryLimit' => MemoryBudget::containerLimit(),
                    'containerMemoryUsage' => MemoryBudget::containerUsage(),
                ],
            );
        }

        return $this->imagick;
    }

    /**
     * Read the file bounded to the given longest edge, or null if it does not
     * fit the budget. JPEG scales during decode; other formats are read whole
     * and resized.
     */
    private function decodeBounded(string $path, int $maxDimension): ?Imagick
    {
        if (!$this->acquireImageLock() || !$this->applyResourceLimits()) {
            return null;
        }

        [$sourceWidth, $sourceHeight] = $this->sourceDimensions($path);
        $oversized = max($sourceWidth, $sourceHeight) > $maxDimension;
        $imagick = null;

        try {
            $imagick = new Imagick();

            if ($oversized && str_starts_with($this->asset->getMimeType() ?? '', 'image/jpeg')) {
                // Minimum, not maximum: a square hint upscales the short axis.
                $imagick->setOption('jpeg:size', $this->hintFor($sourceWidth, $sourceHeight, $maxDimension));
            }

            $imagick->readImage($path);

            $width = $imagick->getImageWidth();
            $height = $imagick->getImageHeight();

            if (max($width, $height) > $maxDimension) {
                // bestfit rounds an extreme panorama short side to zero.
                $scale = $maxDimension / max($width, $height);

                $imagick->resizeImage(
                    max(1, (int) round($width * $scale)),
                    max(1, (int) round($height * $scale)),
                    Imagick::FILTER_LANCZOS,
                    1,
                    false,
                );
            }

            $this->lastDecodeError = null;

            return $imagick;
        } catch (\Throwable $e) {
            $this->lastDecodeError = $e->getMessage();

            if ($imagick !== null) {
                try {
                    $imagick->clear();
                } catch (\Throwable) {
                    // best-effort cleanup
                }
            }

            return null;
        }
    }

    /**
     * Source dimensions, from Craft or a header ping. Decides whether the
     * decode hint applies at all, since hinting a small file upsamples it.
     *
     * @return array{0: int, 1: int}
     */
    private function sourceDimensions(string $path): array
    {
        $width = (int) ($this->asset->getWidth() ?? 0);
        $height = (int) ($this->asset->getHeight() ?? 0);

        if ($width > 0 && $height > 0) {
            return [$width, $height];
        }

        $ping = null;

        try {
            $ping = new Imagick();
            $ping->pingImage($path);
            return [$ping->getImageWidth(), $ping->getImageHeight()];
        } catch (\Throwable) {
            return [PHP_INT_MAX, PHP_INT_MAX];
        } finally {
            if ($ping !== null) {
                try {
                    $ping->clear();
                } catch (\Throwable) {
                    // best-effort cleanup
                }
            }
        }
    }

    /**
     * Decode hint sized to fit the target box while keeping the aspect ratio.
     */
    private function hintFor(int $width, int $height, int $maxDimension): string
    {
        $longest = max($width, $height);

        if ($longest <= 0 || $longest === PHP_INT_MAX) {
            return $maxDimension . 'x' . $maxDimension;
        }

        $scale = $maxDimension / $longest;

        return max(1, (int) round($width * $scale)) . 'x' . max(1, (int) round($height * $scale));
    }

    /**
     * Bound the pixel cache for the lifetime of this context. The defaults
     * (1GB memory, 2GB map, unlimited disk) outlive most queue workers: an
     * oversized decode either dies uncatchably or stalls on the disk cache.
     * Limits are process-wide, so previous values are restored on release.
     */
    private function applyResourceLimits(): bool
    {
        if ($this->previousResourceLimits !== null) {
            return true;
        }

        $budget = MemoryBudget::forImageProcessing(self::PIXEL_CACHE_FALLBACK_BYTES);

        if ($budget <= 0) {
            $this->lastDecodeError = 'No safe container memory headroom is available';

            return false;
        }

        $types = [Imagick::RESOURCETYPE_MEMORY, Imagick::RESOURCETYPE_MAP, Imagick::RESOURCETYPE_DISK];
        $previous = [];

        foreach ($types as $type) {
            $previous[$type] = Imagick::getResourceLimit($type);
        }

        $this->previousResourceLimits = $previous;

        try {
            Imagick::setResourceLimit(Imagick::RESOURCETYPE_MEMORY, $budget);
            Imagick::setResourceLimit(Imagick::RESOURCETYPE_MAP, 0);
            Imagick::setResourceLimit(Imagick::RESOURCETYPE_DISK, 0);
        } catch (\Throwable $e) {
            $this->lastDecodeError = 'Could not apply image memory limits: ' . $e->getMessage();
            $this->restoreResourceLimits();

            return false;
        }

        return true;
    }

    private function acquireImageLock(): bool
    {
        if ($this->imageLockAttempted) {
            return $this->imageLockAcquired;
        }

        $this->imageLockAttempted = true;

        try {
            $this->imageLockAcquired = Craft::$app->getMutex()->acquire(
                self::IMAGE_LOCK_NAME,
                self::IMAGE_LOCK_TIMEOUT_SECONDS,
            );
        } catch (\Throwable $e) {
            $this->lastDecodeError = 'Could not acquire image-processing lock: ' . $e->getMessage();

            return false;
        }

        if (!$this->imageLockAcquired) {
            $this->lastDecodeError = 'Timed out waiting for the image-processing lock';
        }

        return $this->imageLockAcquired;
    }

    private function restoreResourceLimits(): void
    {
        foreach ($this->previousResourceLimits ?? [] as $type => $limit) {
            try {
                Imagick::setResourceLimit($type, $limit);
            } catch (\Throwable) {
                // best-effort cleanup
            }
        }

        $this->previousResourceLimits = null;
    }

    private function releaseImageLock(): void
    {
        if (!$this->imageLockAcquired) {
            return;
        }

        try {
            Craft::$app->getMutex()->release(self::IMAGE_LOCK_NAME);
        } catch (\Throwable) {
            // best-effort cleanup
        }

        $this->imageLockAcquired = false;
    }

    /**
     * SHA-256 of the asset's bytes. Computed once. Hashes from in-memory bytes
     * when they're already loaded, otherwise streams the local file from disk.
     */
    public function getFileContentHash(): ?string
    {
        if ($this->fileContentHash !== null) {
            return $this->fileContentHash;
        }

        if ($this->rawBytesLoaded && $this->rawBytes !== null) {
            return $this->fileContentHash = hash('sha256', $this->rawBytes);
        }

        $path = $this->getLocalPath();

        if ($path === null) {
            return null;
        }

        $hash = @hash_file('sha256', $path);

        return $this->fileContentHash = ($hash === false ? null : $hash);
    }

    public function releaseImageResources(): void
    {
        if ($this->imagick !== null) {
            try {
                $this->imagick->clear();
            } catch (\Throwable) {
                // best-effort cleanup
            }
            $this->imagick = null;
        }

        $this->restoreResourceLimits();
        $this->releaseImageLock();
    }

    public function releaseRawBytes(): void
    {
        $this->rawBytes = null;
        $this->rawBytesLoaded = false;
    }

    public function __destruct()
    {
        $this->releaseImageResources();

        if ($this->localPath !== null && file_exists($this->localPath)) {
            @unlink($this->localPath);
        }
    }
}
