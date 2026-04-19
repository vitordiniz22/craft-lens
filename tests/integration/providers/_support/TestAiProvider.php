<?php

declare(strict_types=1);

namespace vitordiniz22\craftlenstests\integration\providers\_support;

use vitordiniz22\craftlens\models\Settings;
use vitordiniz22\craftlens\providers\BaseAiProvider;

/**
 * Concrete BaseAiProvider implementation for integration tests.
 *
 * Implements only the abstract methods needed to instantiate and drive
 * getBase64ImageData() end-to-end. sendRequest() and the extract methods
 * are never exercised by these tests and throw if accidentally invoked.
 */
final class TestAiProvider extends BaseAiProvider
{
    public function __construct(private readonly int $maxFileSizeBytes)
    {
        parent::__construct();
    }

    public function getName(): string
    {
        return 'test';
    }

    public function getDisplayName(): string
    {
        return 'Test Provider';
    }

    public function validateCredentials(Settings $settings): void
    {
        // no-op
    }

    public function testConnection(Settings $settings): void
    {
        // no-op
    }

    protected function getMaxFileSizeBytes(): int
    {
        return $this->maxFileSizeBytes;
    }

    protected function sendRequest(Settings $settings, array $imageData, string $prompt, int $assetId): array
    {
        throw new \LogicException('TestAiProvider::sendRequest should not be called by these tests');
    }

    protected function extractContentText(array $response): string
    {
        throw new \LogicException('not used');
    }

    protected function extractTokenUsage(array $response): array
    {
        throw new \LogicException('not used');
    }
}
