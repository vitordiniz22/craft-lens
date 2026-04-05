<?php

declare(strict_types=1);

namespace vitordiniz22\craftlens\helpers;

use vitordiniz22\craftlens\enums\LogCategory;

/**
 * Extracts dominant colors from an image using the median-cut algorithm.
 *
 * Produces deterministic, pixel-accurate color palettes — no AI guessing.
 * Downsamples to a small thumbnail first for performance, then partitions
 * the RGB color space into clusters by repeatedly splitting along the
 * widest channel at the median.
 */
class DominantColorExtractor
{
    /** Downsample target — 150×150 = 22 500 pixels, enough for accurate proportions. */
    private const SAMPLE_SIZE = 150;

    /**
     * Extract dominant colors from an image file.
     *
     * @param string $imagePath Absolute path to the image file
     * @param int $count Number of colors to extract (default 6)
     * @return array<array{hex: string, percentage: float}> Sorted by percentage descending
     * @throws \RuntimeException If the image cannot be loaded or processed
     */
    public static function extract(string $imagePath, int $count = 6): array
    {
        if (!file_exists($imagePath) || !is_readable($imagePath)) {
            throw new \RuntimeException("Image file not found or not readable: {$imagePath}");
        }

        $source = self::loadImage($imagePath);
        $canvas = null;

        try {
            $srcW = imagesx($source);
            $srcH = imagesy($source);

            // Create a white-filled canvas and composite the source onto it.
            // This simultaneously downsamples and flattens transparency to white.
            $canvas = imagecreatetruecolor(self::SAMPLE_SIZE, self::SAMPLE_SIZE);

            if ($canvas === false) {
                throw new \RuntimeException('Failed to create GD canvas');
            }

            $white = imagecolorallocate($canvas, 255, 255, 255);
            imagefill($canvas, 0, 0, $white);
            imagecopyresampled($canvas, $source, 0, 0, 0, 0, self::SAMPLE_SIZE, self::SAMPLE_SIZE, $srcW, $srcH);

            // Collect all pixel RGB values
            $pixels = [];

            for ($y = 0; $y < self::SAMPLE_SIZE; $y++) {
                for ($x = 0; $x < self::SAMPLE_SIZE; $x++) {
                    $rgb = imagecolorat($canvas, $x, $y);
                    $pixels[] = [
                        ($rgb >> 16) & 0xFF, // R
                        ($rgb >> 8) & 0xFF,  // G
                        $rgb & 0xFF,         // B
                    ];
                }
            }

            $clusters = self::medianCut($pixels, $count);
            $totalPixels = count($pixels);
            $colors = [];

            foreach ($clusters as $cluster) {
                $avg = self::averageColor($cluster);
                $colors[] = [
                    'hex' => self::rgbToHex($avg[0], $avg[1], $avg[2]),
                    'percentage' => round(count($cluster) / $totalPixels, 4),
                ];
            }

            usort($colors, fn(array $a, array $b) => $b['percentage'] <=> $a['percentage']);

            return $colors;
        } finally {
            imagedestroy($source);

            if ($canvas !== null) {
                imagedestroy($canvas);
            }
        }
    }

    /**
     * Partition pixels into clusters using the median-cut algorithm.
     *
     * @param array<array{0: int, 1: int, 2: int}> $pixels
     * @return array<array<array{0: int, 1: int, 2: int}>> Array of clusters
     */
    private static function medianCut(array $pixels, int $targetCount): array
    {
        if (empty($pixels) || $targetCount < 1) {
            return [];
        }

        $boxes = [$pixels];

        while (count($boxes) < $targetCount) {
            // Find the box with the largest range on its widest channel
            $bestIndex = 0;
            $bestRange = -1;

            foreach ($boxes as $i => $box) {
                if (count($box) < 2) {
                    continue;
                }

                [$channel, $range] = self::widestChannel($box);

                if ($range > $bestRange) {
                    $bestRange = $range;
                    $bestIndex = $i;
                }
            }

            // Nothing left to split
            if ($bestRange <= 0) {
                break;
            }

            $boxToSplit = $boxes[$bestIndex];
            [$channel] = self::widestChannel($boxToSplit);

            // Sort pixels by the widest channel and split at the median
            usort($boxToSplit, fn(array $a, array $b) => $a[$channel] <=> $b[$channel]);

            $median = (int) floor(count($boxToSplit) / 2);
            $left = array_slice($boxToSplit, 0, $median);
            $right = array_slice($boxToSplit, $median);

            // Replace the original box with the two halves
            array_splice($boxes, $bestIndex, 1, [$left, $right]);
        }

        return $boxes;
    }

    /**
     * Find which RGB channel has the widest range in a set of pixels.
     *
     * @param array<array{0: int, 1: int, 2: int}> $pixels
     * @return array{0: int, 1: int} [channelIndex, range]
     */
    private static function widestChannel(array $pixels): array
    {
        $min = [255, 255, 255];
        $max = [0, 0, 0];

        foreach ($pixels as $p) {
            for ($c = 0; $c < 3; $c++) {
                if ($p[$c] < $min[$c]) {
                    $min[$c] = $p[$c];
                }
                if ($p[$c] > $max[$c]) {
                    $max[$c] = $p[$c];
                }
            }
        }

        $bestChannel = 0;
        $bestRange = $max[0] - $min[0];

        for ($c = 1; $c < 3; $c++) {
            $range = $max[$c] - $min[$c];
            if ($range > $bestRange) {
                $bestRange = $range;
                $bestChannel = $c;
            }
        }

        return [$bestChannel, $bestRange];
    }

    /**
     * Compute the average RGB color of a pixel cluster.
     *
     * @param array<array{0: int, 1: int, 2: int}> $pixels
     * @return array{0: int, 1: int, 2: int}
     */
    private static function averageColor(array $pixels): array
    {
        $count = count($pixels);

        if ($count === 0) {
            return [0, 0, 0];
        }

        $rSum = 0;
        $gSum = 0;
        $bSum = 0;

        foreach ($pixels as $p) {
            $rSum += $p[0];
            $gSum += $p[1];
            $bSum += $p[2];
        }

        return [
            (int) round($rSum / $count),
            (int) round($gSum / $count),
            (int) round($bSum / $count),
        ];
    }

    /**
     * Format RGB values as a #RRGGBB hex string.
     */
    private static function rgbToHex(int $r, int $g, int $b): string
    {
        return sprintf('#%02X%02X%02X', $r, $g, $b);
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
            throw new \RuntimeException("Cannot detect MIME type for: {$path}");
        }

        $image = match ($mimeType) {
            'image/jpeg', 'image/jpg' => imagecreatefromjpeg($path),
            'image/png' => imagecreatefrompng($path),
            'image/gif' => imagecreatefromgif($path),
            'image/webp' => imagecreatefromwebp($path),
            'image/bmp', 'image/x-ms-bmp' => imagecreatefrombmp($path),
            default => throw new \RuntimeException("Unsupported image format for color extraction: {$mimeType}"),
        };

        if ($image === false) {
            throw new \RuntimeException("Failed to load image: {$path}");
        }

        return $image;
    }
}
