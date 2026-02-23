<?php

declare(strict_types=1);

namespace vitordiniz22\craftlens\dto;

use DateTime;

/**
 * Immutable data transfer object for EXIF metadata extraction results.
 *
 * This represents the extracted EXIF data from an image file.
 */
readonly class ExifData
{
    /**
     * @param string|null $cameraMake Camera manufacturer
     * @param string|null $cameraModel Camera model
     * @param string|null $lens Lens model
     * @param string|null $focalLength Focal length (e.g., "50mm")
     * @param string|null $aperture Aperture (e.g., "f/2.8")
     * @param string|null $shutterSpeed Shutter speed (e.g., "1/125s")
     * @param int|null $iso ISO sensitivity
     * @param string|null $exposureMode Exposure mode (Auto, Manual, etc.)
     * @param DateTime|null $dateTaken Date/time the photo was taken
     * @param int|null $orientation EXIF orientation (1-8)
     * @param int|null $width Original image width
     * @param int|null $height Original image height
     * @param float|null $latitude GPS latitude in decimal degrees
     * @param float|null $longitude GPS longitude in decimal degrees
     * @param float|null $altitude GPS altitude in meters
     * @param array $rawExif Full raw EXIF data for debugging
     */
    public function __construct(
        public ?string $cameraMake = null,
        public ?string $cameraModel = null,
        public ?string $lens = null,
        public ?string $focalLength = null,
        public ?string $aperture = null,
        public ?string $shutterSpeed = null,
        public ?int $iso = null,
        public ?string $exposureMode = null,
        public ?DateTime $dateTaken = null,
        public ?int $orientation = null,
        public ?int $width = null,
        public ?int $height = null,
        public ?float $latitude = null,
        public ?float $longitude = null,
        public ?float $altitude = null,
        public array $rawExif = [],
    ) {
    }

    /**
     * Check if GPS coordinates are available.
     */
    public function hasGpsCoordinates(): bool
    {
        return $this->latitude !== null && $this->longitude !== null;
    }

    /**
     * Check if camera information is available.
     */
    public function hasCamera(): bool
    {
        return $this->cameraMake !== null || $this->cameraModel !== null;
    }

    /**
     * Check if any EXIF data was extracted.
     */
    public function hasAnyData(): bool
    {
        return $this->hasCamera()
            || $this->hasGpsCoordinates()
            || $this->dateTaken !== null
            || $this->iso !== null
            || $this->aperture !== null
            || $this->shutterSpeed !== null
            || $this->focalLength !== null;
    }

    /**
     * Create an empty result when no EXIF data is found.
     */
    public static function empty(): self
    {
        return new self();
    }
}
