<?php

declare(strict_types=1);

namespace vitordiniz22\craftlens\services;

use craft\elements\Asset;
use DateTime;
use vitordiniz22\craftlens\dto\ExifData;
use vitordiniz22\craftlens\enums\LogCategory;
use vitordiniz22\craftlens\helpers\Logger;
use yii\base\Component;

/**
 * Service for extracting EXIF metadata from image files.
 *
 * Uses PHP's native exif_read_data() function for robust EXIF parsing.
 */
class ExifExtractionService extends Component
{
    /**
     * Supported file extensions for EXIF extraction.
     */
    private const SUPPORTED_EXTENSIONS = ['jpg', 'jpeg', 'tiff', 'tif'];

    /**
     * Extract EXIF data from a Craft Asset.
     *
     * @throws \RuntimeException If extraction fails
     */
    public function extractFromAsset(Asset $asset): ?ExifData
    {
        if (!$this->hasExifSupport($asset)) {
            return null;
        }

        $tempPath = $asset->getCopyOfFile();

        try {
            return $this->extractFromFile($tempPath);
        } finally {
            // Clean up temp file
            if (file_exists($tempPath)) {
                unlink($tempPath);
            }
        }
    }

    /**
     * Extract EXIF data from a file path.
     *
     * @throws \RuntimeException If file doesn't exist or EXIF extension unavailable
     */
    public function extractFromFile(string $filePath): ExifData
    {
        if (!file_exists($filePath)) {
            throw new \RuntimeException("File does not exist: {$filePath}");
        }

        if (!function_exists('exif_read_data')) {
            throw new \RuntimeException('PHP EXIF extension is not available');
        }

        $exif = exif_read_data($filePath, 'ANY_TAG', true);

        if ($exif === false) {
            // File has no EXIF data - this is valid, not an error
            return ExifData::empty();
        }

        return $this->parseExifData($exif);
    }

    /**
     * Check if an asset supports EXIF extraction.
     */
    public function hasExifSupport(Asset $asset): bool
    {
        if ($asset->kind !== Asset::KIND_IMAGE) {
            return false;
        }

        $extension = strtolower($asset->getExtension());

        return in_array($extension, self::SUPPORTED_EXTENSIONS, true);
    }

    /**
     * Parse raw EXIF data into an ExifData DTO.
     */
    private function parseExifData(array $exif): ExifData
    {
        // Flatten the EXIF array for easier access
        $flat = $this->flattenExifArray($exif);

        // Parse GPS coordinates
        $latitude = $this->parseGpsCoordinate(
            $flat['GPSLatitude'] ?? null,
            $flat['GPSLatitudeRef'] ?? null
        );
        $longitude = $this->parseGpsCoordinate(
            $flat['GPSLongitude'] ?? null,
            $flat['GPSLongitudeRef'] ?? null
        );
        $altitude = $this->parseAltitude(
            $flat['GPSAltitude'] ?? null,
            $flat['GPSAltitudeRef'] ?? null
        );

        return new ExifData(
            cameraMake: $this->cleanString($flat['Make'] ?? null),
            cameraModel: $this->cleanString($flat['Model'] ?? null),
            lens: $this->parseLens($flat),
            focalLength: $this->parseFocalLength($flat['FocalLength'] ?? null),
            aperture: $this->parseAperture($flat['FNumber'] ?? $flat['COMPUTED']['ApertureFNumber'] ?? null),
            shutterSpeed: $this->parseShutterSpeed($flat['ExposureTime'] ?? null),
            iso: $this->parseIso($flat['ISOSpeedRatings'] ?? null),
            exposureMode: $this->parseExposureMode($flat['ExposureMode'] ?? null),
            dateTaken: $this->parseDateTime($flat['DateTimeOriginal'] ?? $flat['DateTime'] ?? null),
            orientation: $this->parseOrientation($flat['Orientation'] ?? null),
            width: $this->parseInt($flat['COMPUTED']['Width'] ?? $flat['ExifImageWidth'] ?? null),
            height: $this->parseInt($flat['COMPUTED']['Height'] ?? $flat['ExifImageLength'] ?? null),
            latitude: $latitude,
            longitude: $longitude,
            altitude: $altitude,
            rawExif: $exif,
        );
    }

    /**
     * Flatten nested EXIF array while preserving COMPUTED section.
     */
    private function flattenExifArray(array $exif): array
    {
        $flat = [];

        foreach ($exif as $section => $data) {
            if (is_array($data)) {
                if ($section === 'COMPUTED') {
                    $flat['COMPUTED'] = $data;
                } else {
                    foreach ($data as $key => $value) {
                        $flat[$key] = $value;
                    }
                }
            } else {
                $flat[$section] = $data;
            }
        }

        return $flat;
    }

    /**
     * Parse GPS coordinate from DMS (degrees/minutes/seconds) to decimal.
     */
    private function parseGpsCoordinate(?array $coordinate, ?string $ref): ?float
    {
        if ($coordinate === null || $ref === null) {
            return null;
        }

        if (count($coordinate) < 3) {
            return null;
        }

        $degrees = $this->parseGpsComponent($coordinate[0]);
        $minutes = $this->parseGpsComponent($coordinate[1]);
        $seconds = $this->parseGpsComponent($coordinate[2]);

        if ($degrees === null || $minutes === null || $seconds === null) {
            return null;
        }

        $decimal = $degrees + ($minutes / 60) + ($seconds / 3600);

        // Apply reference (N/S for latitude, E/W for longitude)
        if ($ref === 'S' || $ref === 'W') {
            $decimal = -$decimal;
        }

        return round($decimal, 8);
    }

    /**
     * Parse a single GPS component (degrees, minutes, or seconds).
     *
     * GPS components can be strings like "40/1" or "30/100".
     */
    private function parseGpsComponent(mixed $value): ?float
    {
        if (is_numeric($value)) {
            return (float) $value;
        }

        if (is_string($value) && str_contains($value, '/')) {
            $parts = explode('/', $value);
            if (count($parts) === 2 && is_numeric($parts[0]) && is_numeric($parts[1]) && (float) $parts[1] !== 0.0) {
                return (float) $parts[0] / (float) $parts[1];
            }
        }

        return null;
    }

    /**
     * Parse GPS altitude.
     */
    private function parseAltitude(mixed $altitude, mixed $ref): ?float
    {
        if ($altitude === null) {
            return null;
        }

        $value = $this->parseGpsComponent($altitude);

        if ($value === null) {
            return null;
        }

        // AltitudeRef: 0 = above sea level, 1 = below sea level
        if ($ref === '1' || $ref === 1) {
            $value = -$value;
        }

        return round($value, 2);
    }

    /**
     * Parse EXIF date/time string to DateTime.
     */
    private function parseDateTime(?string $dateTime): ?DateTime
    {
        if ($dateTime === null || trim($dateTime) === '') {
            return null;
        }

        // EXIF date format: "YYYY:MM:DD HH:MM:SS"
        try {
            $parsed = DateTime::createFromFormat('Y:m:d H:i:s', $dateTime);

            if ($parsed !== false) {
                return $parsed;
            }

            // Try alternative format
            $parsed = DateTime::createFromFormat('Y-m-d H:i:s', $dateTime);

            if ($parsed !== false) {
                return $parsed;
            }
        } catch (\Throwable $e) {
            Logger::warning(LogCategory::AssetProcessing, "Failed to parse EXIF date: {$e->getMessage()}");
        }

        return null;
    }

    /**
     * Parse aperture value to display string.
     */
    private function parseAperture(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        // Already formatted (e.g., from COMPUTED section)
        if (is_string($value) && str_starts_with($value, 'f/')) {
            return $value;
        }

        $numeric = $this->parseGpsComponent($value);

        if ($numeric === null) {
            return null;
        }

        // Format as f/X.X (check if whole number)
        if ($numeric === floor($numeric)) {
            return sprintf('f/%.0f', $numeric);
        }

        return sprintf('f/%.1f', $numeric);
    }

    /**
     * Parse shutter speed to display string.
     */
    private function parseShutterSpeed(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        // Already a string like "1/125"
        if (is_string($value)) {
            if (str_contains($value, '/')) {
                return $value . 's';
            }

            $numeric = (float) $value;
        } else {
            $numeric = $this->parseGpsComponent($value);
        }

        if ($numeric === null) {
            return null;
        }

        // Format shutter speed
        if ($numeric >= 1) {
            return sprintf('%.0fs', $numeric);
        }

        // Express as fraction (e.g., 1/125s)
        $denominator = round(1 / $numeric);

        return sprintf('1/%ds', (int) $denominator);
    }

    /**
     * Parse focal length to display string.
     */
    private function parseFocalLength(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $numeric = $this->parseGpsComponent($value);

        if ($numeric === null) {
            return null;
        }

        if ($numeric === floor($numeric)) {
            return sprintf('%.0fmm', $numeric);
        }

        return sprintf('%.1fmm', $numeric);
    }

    /**
     * Parse ISO value.
     */
    private function parseIso(mixed $value): ?int
    {
        if ($value === null) {
            return null;
        }

        if (is_array($value)) {
            $value = $value[0] ?? null;
        }

        return $this->parseInt($value);
    }

    /**
     * Parse exposure mode.
     */
    private function parseExposureMode(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $mode = (int) $value;

        return match ($mode) {
            0 => 'Auto',
            1 => 'Manual',
            2 => 'Auto bracket',
            default => null,
        };
    }

    /**
     * Parse orientation value.
     */
    private function parseOrientation(mixed $value): ?int
    {
        if ($value === null) {
            return null;
        }

        $orientation = (int) $value;

        // Valid EXIF orientation values are 1-8
        if ($orientation < 1 || $orientation > 8) {
            return null;
        }

        return $orientation;
    }

    /**
     * Parse lens information from various EXIF fields.
     */
    private function parseLens(array $flat): ?string
    {
        // Try various lens fields
        $lens = $flat['LensModel'] ?? $flat['Lens'] ?? $flat['LensInfo'] ?? null;

        return $this->cleanString($lens);
    }

    /**
     * Parse a value to integer.
     */
    private function parseInt(mixed $value): ?int
    {
        if ($value === null) {
            return null;
        }

        if (is_numeric($value)) {
            return (int) $value;
        }

        return null;
    }

    /**
     * Clean and trim a string value.
     */
    private function cleanString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (!is_string($value)) {
            return null;
        }

        $cleaned = trim($value);

        if ($cleaned === '') {
            return null;
        }

        // Remove null bytes and other control characters
        $cleaned = preg_replace('/[\x00-\x1F\x7F]/', '', $cleaned);

        return $cleaned === '' ? null : $cleaned;
    }
}
