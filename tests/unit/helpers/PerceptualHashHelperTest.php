<?php

declare(strict_types=1);

namespace vitordiniz22\craftlenstests\unit\helpers;

use Codeception\Test\Unit;
use vitordiniz22\craftlens\helpers\PerceptualHashHelper;

/**
 * Unit tests for PerceptualHashHelper.
 *
 * Tests the pure-math methods (hammingDistance, similarity) which have
 * zero external dependencies. The compute() method requires GD + real
 * images and is better suited for integration tests.
 */
class PerceptualHashHelperTest extends Unit
{
    // -- hammingDistance() --

    public function testHammingDistanceIdenticalHashes(): void
    {
        $hash = str_repeat('a', 64);

        $this->assertSame(0, PerceptualHashHelper::hammingDistance($hash, $hash));
    }

    public function testHammingDistanceSingleBitDifference(): void
    {
        // Hex chars: '0' = 0000, '1' = 0001 -> XOR = 0001 -> 1 bit differs
        $hash1 = str_repeat('0', 64);
        $hash2 = str_repeat('0', 63) . '1';

        $this->assertSame(1, PerceptualHashHelper::hammingDistance($hash1, $hash2));
    }

    public function testHammingDistanceMaximalDifference(): void
    {
        // '0' = 0000, 'f' = 1111 -> XOR = 1111 -> 4 bits per char * 64 chars = 256
        $hash1 = str_repeat('0', 64);
        $hash2 = str_repeat('f', 64);

        $this->assertSame(256, PerceptualHashHelper::hammingDistance($hash1, $hash2));
    }

    public function testHammingDistanceKnownValues(): void
    {
        // '3' = 0011, '5' = 0101 -> XOR = 0110 -> 2 bits differ
        $hash1 = str_repeat('0', 63) . '3';
        $hash2 = str_repeat('0', 63) . '5';

        $this->assertSame(2, PerceptualHashHelper::hammingDistance($hash1, $hash2));
    }

    public function testHammingDistanceMismatchedLengthsThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        PerceptualHashHelper::hammingDistance('aaa', 'aa');
    }

    // -- similarity() --

    public function testSimilarityIdentical(): void
    {
        $hash = str_repeat('a', 64);

        $this->assertEqualsWithDelta(1.0, PerceptualHashHelper::similarity($hash, $hash), 0.001);
    }

    public function testSimilarityMaximalDifference(): void
    {
        $hash1 = str_repeat('0', 64);
        $hash2 = str_repeat('f', 64);

        $this->assertEqualsWithDelta(0.0, PerceptualHashHelper::similarity($hash1, $hash2), 0.001);
    }

    public function testSimilarityMatchesFormula(): void
    {
        $hash1 = str_repeat('0', 64);
        $hash2 = str_repeat('0', 63) . 'f'; // 4 bits differ

        $distance = PerceptualHashHelper::hammingDistance($hash1, $hash2);
        $expected = 1.0 - ($distance / 256);

        $this->assertSame(4, $distance);
        $this->assertEqualsWithDelta($expected, PerceptualHashHelper::similarity($hash1, $hash2), 0.001);
    }
}
