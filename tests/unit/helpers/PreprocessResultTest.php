<?php

declare(strict_types=1);

namespace vitordiniz22\craftlenstests\unit\helpers;

use Codeception\Test\Unit;
use vitordiniz22\craftlens\helpers\PreprocessResult;

/**
 * Unit tests for the PreprocessResult DTO.
 *
 * PreprocessResult is a readonly value object produced by ImagePreprocessor.
 * Its two factories encode the two possible outcomes of preprocessing: a
 * successful resize (processed) or a passthrough of the original bytes
 * (passthrough). Anything reading the result in BaseAiProvider relies on
 * wasProcessed + reason to decide whether to log success, skip, or failure.
 */
class PreprocessResultTest extends Unit
{
    public function testPassthroughCarriesOriginalBytesAndReason(): void
    {
        $result = PreprocessResult::passthrough('raw-bytes', 'image/jpeg', 'no_driver');

        $this->assertSame('raw-bytes', $result->bytes);
        $this->assertSame('image/jpeg', $result->mimeType);
        $this->assertFalse($result->wasProcessed);
        $this->assertSame('no_driver', $result->reason);
        $this->assertNull($result->originalBytes);
        $this->assertNull($result->processedBytes);
        $this->assertNull($result->originalWidth);
        $this->assertNull($result->originalHeight);
        $this->assertNull($result->processedWidth);
        $this->assertNull($result->processedHeight);
    }

    public function testPassthroughReasonIsOptional(): void
    {
        $result = PreprocessResult::passthrough('abc', 'image/png');

        $this->assertNull($result->reason);
        $this->assertFalse($result->wasProcessed);
    }

    public function testProcessedPopulatesAllDimensionAndByteFields(): void
    {
        $result = PreprocessResult::processed(
            bytes: 'new-bytes',
            mimeType: 'image/jpeg',
            originalBytes: 6_000_000,
            processedBytes: 300_000,
            originalWidth: 6000,
            originalHeight: 4000,
            processedWidth: 1568,
            processedHeight: 1045,
        );

        $this->assertSame('new-bytes', $result->bytes);
        $this->assertSame('image/jpeg', $result->mimeType);
        $this->assertTrue($result->wasProcessed);
        $this->assertNull($result->reason);
        $this->assertSame(6_000_000, $result->originalBytes);
        $this->assertSame(300_000, $result->processedBytes);
        $this->assertSame(6000, $result->originalWidth);
        $this->assertSame(4000, $result->originalHeight);
        $this->assertSame(1568, $result->processedWidth);
        $this->assertSame(1045, $result->processedHeight);
    }

    public function testReadonlyPropertyCannotBeReassigned(): void
    {
        $result = PreprocessResult::passthrough('x', 'image/jpeg');

        $this->expectException(\Error::class);
        // @phpstan-ignore-next-line — intentional write to readonly property
        $result->bytes = 'mutated';
    }
}
