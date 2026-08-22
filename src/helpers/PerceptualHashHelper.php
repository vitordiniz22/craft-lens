<?php

declare(strict_types=1);

namespace vitordiniz22\craftlens\helpers;

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
     * Hash from an already-decoded handle, so the pipeline pays a 16x16 resize
     * instead of a second full decode.
     *
     * @throws \RuntimeException If the pixels cannot be read
     */
    public static function computeFromImagick(\Imagick $image): string
    {
        $thumb = clone $image;

        try {
            $thumb->resizeImage(self::HASH_SIZE, self::HASH_SIZE, \Imagick::FILTER_LANCZOS, 1, false);
            $thumb->transformImageColorspace(\Imagick::COLORSPACE_GRAY);

            $pixels = $thumb->exportImagePixels(
                0,
                0,
                self::HASH_SIZE,
                self::HASH_SIZE,
                'I',
                \Imagick::PIXEL_CHAR,
            );

            if (count($pixels) !== self::HASH_SIZE * self::HASH_SIZE) {
                throw new \RuntimeException('Unexpected pixel count for perceptual hash');
            }

            return self::hashFromPixels($pixels);
        } finally {
            $thumb->clear();
        }
    }

    /**
     * One bit per pixel, set at or above the mean, packed into 64 hex chars.
     *
     * @param int[] $pixels 256 grayscale values, 0-255
     */
    private static function hashFromPixels(array $pixels): string
    {
        $average = array_sum($pixels) / count($pixels);

        $bits = '';
        foreach ($pixels as $pixel) {
            $bits .= $pixel >= $average ? '1' : '0';
        }

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
}
