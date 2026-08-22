<?php

declare(strict_types=1);

namespace vitordiniz22\craftlenstests\unit\helpers;

use Codeception\Test\Unit;
use craft\elements\Asset;
use ReflectionMethod;
use vitordiniz22\craftlens\helpers\AnalysisImageContext;

/**
 * Unit tests for AnalysisImageContext.
 *
 * Decoding needs real files and a memory budget, so it lives in the stress
 * probes. What is covered here is the decode hint, which is pure arithmetic
 * and where the subtle bugs were: libjpeg treats the hint as a minimum, so a
 * square one upsamples the short axis and gets extreme aspect ratios refused.
 */
class AnalysisImageContextTest extends Unit
{
    public function testHintKeepsLandscapeAspectRatio(): void
    {
        $this->assertSame('2000x1333', $this->invokeHintFor(6000, 4000, 2000));
    }

    public function testHintKeepsPortraitAspectRatio(): void
    {
        $this->assertSame('1500x2000', $this->invokeHintFor(3120, 4160, 2000));
    }

    public function testHintSurvivesExtremeAspectRatio(): void
    {
        // A square hint here would ask libjpeg to scale the long axis to 40
        // million pixels, and the read gets refused.
        $this->assertSame('2000x1', $this->invokeHintFor(20000, 1, 2000));
    }

    public function testHintNeverReportsAZeroAxis(): void
    {
        $this->assertSame('1x2000', $this->invokeHintFor(2, 12000, 2000));
    }

    public function testHintFallsBackToSquareWhenSizeIsUnknown(): void
    {
        $this->assertSame('2000x2000', $this->invokeHintFor(PHP_INT_MAX, PHP_INT_MAX, 2000));
        $this->assertSame('2000x2000', $this->invokeHintFor(0, 0, 2000));
    }

    public function testHintScalesWithTheRequestedTarget(): void
    {
        $this->assertSame('700x467', $this->invokeHintFor(6000, 4000, 700));
    }

    private function invokeHintFor(int $width, int $height, int $maxDimension): string
    {
        $context = new AnalysisImageContext($this->createStub(Asset::class));

        $method = new ReflectionMethod(AnalysisImageContext::class, 'hintFor');
        $method->setAccessible(true);

        return $method->invoke($context, $width, $height, $maxDimension);
    }
}
