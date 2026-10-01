<?php

declare(strict_types=1);

namespace vitordiniz22\craftlenstests\unit\helpers;

use Codeception\Test\Unit;
use vitordiniz22\craftlens\helpers\MemoryBudget;

/**
 * Unit tests for MemoryBudget.
 *
 * Covers calculate(), the pure arithmetic. The cgroup readers are exercised
 * against whatever the test host reports, which is a container limit under
 * DDEV and nothing on an uncontained machine, so those assertions only check
 * the shape of the answer.
 */
class MemoryBudgetTest extends Unit
{
    private const MB = 1024 * 1024;

    private const FALLBACK = 384 * self::MB;

    protected function _after(): void
    {
        MemoryBudget::resetCache();
        parent::_after();
    }

    // -- calculate() --

    public function testFallbackWhenNoContainerLimit(): void
    {
        $this->assertSame(
            self::FALLBACK,
            MemoryBudget::calculate(null, 200 * self::MB, self::FALLBACK),
        );
    }

    public function testFallbackWhenUsageUnavailable(): void
    {
        $this->assertSame(
            self::FALLBACK,
            MemoryBudget::calculate(512 * self::MB, null, self::FALLBACK),
        );
    }

    public function testHalfOfHeadroomOnAnIdleWorker(): void
    {
        // 512MB limit, 112MB in use -> 400MB headroom -> 200MB budget
        $this->assertSame(
            200 * self::MB,
            MemoryBudget::calculate(512 * self::MB, 112 * self::MB, self::FALLBACK),
        );
    }

    public function testHalfOfHeadroomOnABusyInstance(): void
    {
        // Servd shape: 800MB instance, 500MB already used by sibling workers and cache
        $this->assertSame(
            150 * self::MB,
            MemoryBudget::calculate(800 * self::MB, 500 * self::MB, self::FALLBACK),
        );
    }

    public function testClampsToCeilingOnARoomyHost(): void
    {
        $this->assertSame(
            384 * self::MB,
            MemoryBudget::calculate(4096 * self::MB, 256 * self::MB, self::FALLBACK),
        );
    }

    public function testUncontainedHostGetsHalfOfAvailableMemory(): void
    {
        // forImageProcessing() passes MemAvailable as the limit with zero usage
        $this->assertSame(
            150 * self::MB,
            MemoryBudget::calculate(300 * self::MB, 0, self::FALLBACK),
        );
    }

    // -- parseMemAvailable() --

    public function testParsesMemAvailableFromMeminfo(): void
    {
        $meminfo = "MemTotal:        8000000 kB\nMemFree:          100000 kB\nMemAvailable:    5874320 kB\n";

        $this->assertSame(5874320 * 1024, MemoryBudget::parseMemAvailable($meminfo));
    }

    public function testMissingMemAvailableReadsAsNull(): void
    {
        $this->assertNull(MemoryBudget::parseMemAvailable(''));
        $this->assertNull(MemoryBudget::parseMemAvailable("MemTotal: 8000000 kB\n"));
    }

    public function testThinHeadroomIsReportedHonestly(): void
    {
        // 40MB left -> 20MB, small but real. Handing Imagick more than the
        // container has left is how an OOM kill happens instead of a
        // catchable failure, so the number is not padded upwards.
        $this->assertSame(
            20 * self::MB,
            MemoryBudget::calculate(512 * self::MB, 472 * self::MB, self::FALLBACK),
        );
    }

    public function testDoesNotExceedRealHeadroomOnAFullContainer(): void
    {
        // 4MB left computes to 2MB; padding it would risk a kernel kill.
        $this->assertSame(
            2 * self::MB,
            MemoryBudget::calculate(512 * self::MB, 508 * self::MB, self::FALLBACK),
        );
    }

    public function testReturnsZeroWhenUsageExceedsLimit(): void
    {
        // Possible mid-reclaim; a negative headroom must not become a negative budget
        $this->assertSame(
            0,
            MemoryBudget::calculate(512 * self::MB, 600 * self::MB, self::FALLBACK),
        );
    }

    public function testFallbackBelowHardCeilingIsUsedVerbatim(): void
    {
        // The fallback is the caller's decision, so it passes through untouched
        $this->assertSame(
            32 * self::MB,
            MemoryBudget::calculate(null, null, 32 * self::MB),
        );
    }

    // -- host readers --

    public function testContainerLimitIsPositiveOrNull(): void
    {
        MemoryBudget::resetCache();
        $limit = MemoryBudget::containerLimit();

        if ($limit === null) {
            $this->assertNull($limit);

            return;
        }

        $this->assertGreaterThan(0, $limit);
    }

    public function testUsageIsOnlyReportedAlongsideALimit(): void
    {
        MemoryBudget::resetCache();

        if (MemoryBudget::containerLimit() === null) {
            $this->assertNull(MemoryBudget::containerUsage());

            return;
        }

        $this->assertGreaterThan(0, MemoryBudget::containerUsage());
    }

    public function testForImageProcessingStaysWithinBounds(): void
    {
        MemoryBudget::resetCache();
        $budget = MemoryBudget::forImageProcessing(self::FALLBACK);

        $this->assertGreaterThanOrEqual(0, $budget);
        $this->assertLessThanOrEqual(384 * self::MB, $budget);
    }

    public function testForcedBudgetCannotBypassHardCeiling(): void
    {
        $detected = MemoryBudget::forImageProcessing(self::FALLBACK);
        MemoryBudget::forceForTesting(512 * self::MB);
        $forced = MemoryBudget::forImageProcessing(self::FALLBACK);

        $this->assertLessThanOrEqual(384 * self::MB, $forced);
        $this->assertLessThanOrEqual($detected, $forced);
    }

    public function testUploadEstimateIncludesEncodingAndRequestCopies(): void
    {
        $this->assertSame(
            self::MB + 15,
            MemoryBudget::estimateUploadBytes(3),
        );
    }

    public function testUploadMustFitInsideHalfOfHeadroom(): void
    {
        $this->assertTrue(MemoryBudget::fitsHeadroom(20 * self::MB, 100 * self::MB, 50 * self::MB));
        $this->assertFalse(MemoryBudget::fitsHeadroom(30 * self::MB, 100 * self::MB, 50 * self::MB));
    }
}
