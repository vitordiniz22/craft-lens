<?php

declare(strict_types=1);

namespace vitordiniz22\craftlens\helpers;

/**
 * How much memory image processing may take on this host.
 *
 * Imagick's pixel cache is outside PHP's memory_limit, so without a cap it
 * allocates until the container's limit is hit and the kernel kills the
 * worker, which throws nothing and logs nothing. Capping it below the
 * container ceiling makes Imagick fail first, catchably.
 */
final class MemoryBudget
{
    private const HEADROOM_SHARE = 0.5;

    /** Raw bytes, base64, JSON, and the HTTP request body can coexist briefly. */
    private const UPLOAD_BASE64_COPIES = 3;

    private const UPLOAD_FIXED_OVERHEAD = 1024 * 1024;

    /** One bounded 1568px pipeline fits below this; larger limits add risk, not quality. */
    private const MAX_BYTES = 64 * 1024 * 1024;

    /** cgroup v1's "no limit" sentinel. */
    private const V1_UNLIMITED = 9223372036854771712;

    private const CGROUP_PATHS = [
        ['limit' => '/sys/fs/cgroup/memory.max', 'usage' => '/sys/fs/cgroup/memory.current'],
        [
            'limit' => '/sys/fs/cgroup/memory/memory.limit_in_bytes',
            'usage' => '/sys/fs/cgroup/memory/memory.usage_in_bytes',
        ],
    ];

    private static ?int $cachedLimit = null;
    private static bool $limitResolved = false;
    private static ?int $forcedBudget = null;
    private static ?bool $forcedUploadHeadroom = null;

    /**
     * @param int $fallback Budget for hosts that report no container limit
     */
    public static function forImageProcessing(int $fallback): int
    {
        $detectedBudget = self::calculate(self::containerLimit(), self::containerUsage(), $fallback);

        if (self::$forcedBudget !== null) {
            return max(0, min($detectedBudget, self::$forcedBudget));
        }

        return $detectedBudget;
    }

    /**
     * Half the remaining headroom, clamped. The result may land below what a
     * decode needs; on a full container that is the correct answer.
     */
    public static function calculate(?int $limit, ?int $usage, int $fallback): int
    {
        if ($limit === null || $usage === null) {
            return max(0, min(self::MAX_BYTES, $fallback));
        }

        $headroom = (int) (($limit - $usage) * self::HEADROOM_SHARE);

        return max(0, min(self::MAX_BYTES, $headroom));
    }

    public static function estimateUploadBytes(int $rawBytes, bool $payloadLoaded = false): int
    {
        $rawBytes = max(0, $rawBytes);
        $base64Bytes = 4 * (int) ceil($rawBytes / 3);

        return ($payloadLoaded ? 0 : $rawBytes)
            + ($base64Bytes * self::UPLOAD_BASE64_COPIES)
            + self::UPLOAD_FIXED_OVERHEAD;
    }

    /**
     * Require the estimated request allocation to fit inside half of every
     * memory ceiling the host exposes. Unknown ceilings are not invented.
     */
    public static function hasUploadHeadroom(int $rawBytes, bool $payloadLoaded = false): bool
    {
        if (self::$forcedUploadHeadroom !== null) {
            return self::$forcedUploadHeadroom;
        }

        $required = self::estimateUploadBytes($rawBytes, $payloadLoaded);
        $containerLimit = self::containerLimit();
        $containerUsage = self::containerUsage();

        if ($containerLimit !== null && $containerUsage !== null
            && !self::fitsHeadroom($required, $containerLimit, $containerUsage)
        ) {
            return false;
        }

        $phpLimit = self::phpMemoryLimitBytes();

        return $phpLimit === null
            || self::fitsHeadroom($required, $phpLimit, memory_get_usage(true));
    }

    public static function fitsHeadroom(int $required, int $limit, int $usage): bool
    {
        $safeHeadroom = (int) (max(0, $limit - $usage) * self::HEADROOM_SHARE);

        return $required <= $safeHeadroom;
    }

    public static function containerLimit(): ?int
    {
        if (self::$limitResolved) {
            return self::$cachedLimit;
        }

        self::$limitResolved = true;

        foreach (self::CGROUP_PATHS as $paths) {
            $value = self::readBytes($paths['limit']);

            if ($value !== null && $value < self::V1_UNLIMITED) {
                return self::$cachedLimit = $value;
            }
        }

        return self::$cachedLimit = null;
    }

    /** Current cgroup usage; conservative by design because the hard limit includes it all. */
    public static function containerUsage(): ?int
    {
        if (self::containerLimit() === null) {
            return null;
        }

        foreach (self::CGROUP_PATHS as $paths) {
            $value = self::readBytes($paths['usage']);

            if ($value !== null) {
                return $value;
            }
        }

        return null;
    }

    /** Test seam: pin the budget, or null to resume detection. */
    public static function forceForTesting(?int $bytes): void
    {
        self::$forcedBudget = $bytes;
    }

    public static function forceUploadHeadroomForTesting(?bool $available): void
    {
        self::$forcedUploadHeadroom = $available;
    }

    public static function resetCache(): void
    {
        self::$cachedLimit = null;
        self::$limitResolved = false;
        self::$forcedBudget = null;
        self::$forcedUploadHeadroom = null;
    }

    /** Reads a single-number cgroup file. `max` and anything unparseable read as null. */
    private static function readBytes(string $path): ?int
    {
        if (!is_readable($path)) {
            return null;
        }

        $contents = trim((string) @file_get_contents($path));

        return ctype_digit($contents) ? (int) $contents : null;
    }

    private static function phpMemoryLimitBytes(): ?int
    {
        $value = trim((string) ini_get('memory_limit'));

        if ($value === '' || $value === '-1') {
            return null;
        }

        if (ctype_digit($value)) {
            return (int) $value;
        }

        $number = (float) $value;
        $unit = strtolower(substr($value, -1));
        $multiplier = match ($unit) {
            'g' => 1024 ** 3,
            'm' => 1024 ** 2,
            'k' => 1024,
            default => 1,
        };

        return (int) ($number * $multiplier);
    }
}
