<?php

declare(strict_types=1);

namespace vitordiniz22\craftlens\helpers;

use craft\elements\Asset;
use Imagick;

/**
 * Per-asset image I/O cache shared by every step of the analysis pipeline.
 *
 * Reads the asset file once, decodes it once, and downscales it once to a
 * working size that every consumer (metrics, AI preprocessing, perceptual
 * hash) can reuse. JPEG sources use libjpeg's DCT-block scaling (free) via
 * the `jpeg:size` Imagick option; PNG/WebP/etc. fall back to a full decode
 * followed by a single Lanczos resize. The destructor clears the Imagick
 * handle and removes the local temp copy.
 */
final class AnalysisImageContext
{
    /**
     * Longest-edge cap for the shared decoded buffer. 1568 matches the AI
     * preprocessing target so the same handle can be re-encoded for upload
     * without further resizing. Sharpness's internal 500px cap downscales
     * from this buffer (cheap), not from the raw file.
     */
    public const WORKING_MAX_DIMENSION = 1568;

    private ?string $rawBytes = null;
    private bool $rawBytesLoaded = false;

    private ?string $localPath = null;
    private bool $localPathLoaded = false;

    private ?Imagick $imagick = null;
    private bool $imagickLoaded = false;

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
        $path = $this->asset->getCopyOfFile();
        $this->localPath = ($path !== null && file_exists($path)) ? $path : null;

        return $this->localPath;
    }

    /**
     * Build an Imagick instance from the local file once and return it.
     * Returns null if the Imagick extension is missing or the asset has no
     * usable local copy.
     *
     * The handle is decoded at most `WORKING_MAX_DIMENSION` per edge:
     * - For JPEG: libjpeg's `jpeg:size` hint triggers DCT-block scaling at
     *   read time, so the full-size pixel buffer is never allocated.
     * - For everything else: full decode followed by one Lanczos resize when
     *   the source exceeds the cap.
     */
    public function getImagick(): ?Imagick
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

        try {
            $imagick = new Imagick();
            $imagick->setOption(
                'jpeg:size',
                self::WORKING_MAX_DIMENSION . 'x' . self::WORKING_MAX_DIMENSION,
            );
            $imagick->readImage($path);

            $width = $imagick->getImageWidth();
            $height = $imagick->getImageHeight();

            if (max($width, $height) > self::WORKING_MAX_DIMENSION) {
                $imagick->resizeImage(
                    self::WORKING_MAX_DIMENSION,
                    self::WORKING_MAX_DIMENSION,
                    Imagick::FILTER_LANCZOS,
                    1,
                    true,
                );
            }

            $this->imagick = $imagick;
        } catch (\Throwable) {
            $this->imagick = null;
        }

        return $this->imagick;
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

    public function __destruct()
    {
        if ($this->imagick !== null) {
            try {
                $this->imagick->clear();
            } catch (\Throwable) {
                // best-effort cleanup
            }
            $this->imagick = null;
        }

        if ($this->localPath !== null && file_exists($this->localPath)) {
            @unlink($this->localPath);
        }
    }
}
