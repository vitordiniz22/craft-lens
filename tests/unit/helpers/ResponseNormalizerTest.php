<?php

declare(strict_types=1);

namespace vitordiniz22\craftlenstests\unit\helpers;

use Codeception\Test\Unit;
use vitordiniz22\craftlens\exceptions\AnalysisException;
use vitordiniz22\craftlens\helpers\ResponseNormalizer;

/**
 * Unit tests for ResponseNormalizer NSFW category validation.
 */
class ResponseNormalizerTest extends Unit
{
    public function testAllValidCategoriesAreAccepted(): void
    {
        $validCategories = ['adult', 'violence', 'hate', 'self-harm', 'drugs'];
        $input = array_map(fn(string $cat) => ['category' => $cat, 'confidence' => 0.5], $validCategories);

        $result = ResponseNormalizer::normalizeNsfwCategories($input, 'test');

        $this->assertCount(5, $result);
        $returnedCategories = array_column($result, 'category');
        $this->assertSame($validCategories, $returnedCategories);
    }

    public function testInvalidCategoryIsRejected(): void
    {
        $input = [
            ['category' => 'invalid-category', 'confidence' => 0.5],
        ];

        $this->expectException(AnalysisException::class);

        ResponseNormalizer::normalizeNsfwCategories($input, 'test');
    }
}
