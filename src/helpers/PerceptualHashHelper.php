<?php

declare(strict_types=1);

namespace vitordiniz22\craftlens\helpers;

use vitordiniz22\craftlens\enums\LogCategory;

/**
 * Perceptual hash helper using average hash (aHash) algorithm.
 *
 * Produces a 256-bit hash (64 hex chars) from a 16x16 grayscale thumbnail.
 * Visually similar images produce similar hashes with small Hamming distances.
 */
class PerceptualHashHelper
{
    private const HASH_SIZE = 16;

    /**
     * Compute a perceptual hash for an image file.
     *
     * @param string $imagePath Path to the image file
     * @return string 64-character hex string (256-bit hash)
     * @throws \RuntimeException If the image cannot be loaded or processed
     */
    public static function compute(string $imagePath): string
    {
        $image = self::loadImage($imagePath);

        // Resize to 16x16
        $resized = imagescale($image, self::HASH_SIZE, self::HASH_SIZE);
        imagedestroy($image);

        if ($resized === false) {
            Logger::warning(LogCategory::AssetProcessing, "Failed to resize image for perceptual hash: {$imagePath}");
            throw new \RuntimeException("Failed to resize image: {$imagePath}");
        }

        // Convert to grayscale
        imagefilter($resized, IMG_FILTER_GRAYSCALE);

        // Collect pixel values and compute average
        $pixels = [];
        $total = 0;

        for ($y = 0; $y < self::HASH_SIZE; $y++) {
            for ($x = 0; $x < self::HASH_SIZE; $x++) {
                $rgb = imagecolorat($resized, $x, $y);
                $gray = $rgb & 0xFF; // Already grayscale, R=G=B
                $pixels[] = $gray;
                $total += $gray;
            }
        }

        imagedestroy($resized);

        $average = $total / count($pixels);

        // Build binary hash: 1 if pixel >= average, 0 otherwise
        $bitArray = [];
        foreach ($pixels as $pixel) {
            $bitArray[] = $pixel >= $average ? '1' : '0';
        }
        $bits = implode('', $bitArray);

        // Convert binary string to hex (256 bits -> 64 hex chars)
        $hexArray = [];
        for ($i = 0; $i < 256; $i += 4) {
            $hexArray[] = dechex(bindec(substr($bits, $i, 4)));
        }

        return implode('', $hexArray);
    }

    /**
     * Calculate Hamming distance between two perceptual hashes.
     *
     * @return int Number of differing bits (0 = identical, 256 = maximally different)
     */
    public static function hammingDistance(string $hash1, string $hash2): int
    {
        if (strlen($hash1) !== strlen($hash2)) {
            throw new \InvalidArgumentException('Hash lengths must match');
        }

        $distance = 0;

        for ($i = 0, $len = strlen($hash1); $i < $len; $i++) {
            $xor = hexdec($hash1[$i]) ^ hexdec($hash2[$i]);
            $distance += substr_count(decbin($xor), '1');
        }

        return $distance;
    }

    /**
     * Calculate similarity between two perceptual hashes.
     *
     * @return float Similarity score (0.0 = completely different, 1.0 = identical)
     */
    public static function similarity(string $hash1, string $hash2): float
    {
        return 1.0 - (self::hammingDistance($hash1, $hash2) / 256);
    }

    /**
     * Load an image file using GD based on its MIME type.
     *
     * @throws \RuntimeException If the image format is unsupported or cannot be loaded
     */
    private static function loadImage(string $path): \GdImage
    {
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mimeType = $finfo->file($path);

        if ($mimeType === false) {
            Logger::warning(LogCategory::AssetProcessing, "Cannot detect MIME type for perceptual hash: {$path}");
            throw new \RuntimeException("Cannot detect MIME type for: {$path}");
        }

        $image = match ($mimeType) {
            'image/jpeg', 'image/jpg' => imagecreatefromjpeg($path),
            'image/png' => imagecreatefrompng($path),
            'image/gif' => imagecreatefromgif($path),
            'image/webp' => imagecreatefromwebp($path),
            'image/bmp', 'image/x-ms-bmp' => imagecreatefrombmp($path),
            default => throw new \RuntimeException("Unsupported image format: {$mimeType}"),
        };

        if ($image === false) {
            Logger::warning(LogCategory::AssetProcessing, "Failed to load image for perceptual hash: {$path}");
            throw new \RuntimeException("Failed to load image: {$path}");
        }

        return $image;
    }
}
