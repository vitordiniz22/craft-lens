<?php

declare(strict_types=1);

namespace vitordiniz22\craftlens\providers;

use Psr\Http\Message\ResponseInterface;
use vitordiniz22\craftlens\exceptions\AnalysisException;
use vitordiniz22\craftlens\exceptions\ConfigurationException;
use vitordiniz22\craftlens\helpers\Logger;
use vitordiniz22\craftlens\models\Settings;

/**
 * Claude (Anthropic) API provider for image analysis.
 */
class ClaudeProvider extends BaseAiProvider
{
    private const API_URL = 'https://api.anthropic.com/v1/messages';

    public function getName(): string
    {
        return 'claude';
    }

    public function getDisplayName(): string
    {
        return 'Claude';
    }

    public function validateCredentials(Settings $settings): void
    {
        if (!$settings->hasApiKey('claudeApiKey')) {
            throw ConfigurationException::missingApiKey($this->getName());
        }
    }

    public function testConnection(Settings $settings): void
    {
        $this->validateCredentials($settings);

        $this->executeTestRequest(
            'https://api.anthropic.com/v1/models',
            [
                'x-api-key' => $settings->getClaudeApiKey(),
                'anthropic-version' => '2023-06-01',
            ]
        );
    }

    protected function extractContentText(array $response): string
    {
        return $this->findTextBlock($response) ?? '';
    }

    /**
     * Opus 5.5 always thinks, so the text block is not necessarily first.
     */
    private function findTextBlock(array $response): ?string
    {
        foreach ($response['content'] ?? [] as $block) {
            if (($block['type'] ?? null) === 'text' && isset($block['text'])) {
                return $block['text'];
            }
        }

        return null;
    }

    protected function isResponseTruncated(array $response): bool
    {
        return ($response['stop_reason'] ?? null) === 'max_tokens';
    }

    protected function extractTokenUsage(array $response): array
    {
        $usage = $response['usage'] ?? [];

        return [
            'inputTokens' => (int) ($usage['input_tokens'] ?? 0),
            'outputTokens' => (int) ($usage['output_tokens'] ?? 0),
        ];
    }

    /**
     * @param array{base64: string, mimeType: string} $imageData
     */
    protected function buildHttpRequest(Settings $settings, array $imageData, string $prompt, int $assetId): array
    {
        $payload = [
            'model' => $settings->claudeModel,
            'max_tokens' => 16000,
            'messages' => [
                [
                    'role' => 'user',
                    'content' => [
                        ['type' => 'text', 'text' => $prompt],
                        [
                            'type' => 'image',
                            'source' => [
                                'type' => 'base64',
                                'media_type' => $imageData['mimeType'],
                                'data' => $imageData['base64'],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        // Claude 5 models reject sampling params; Opus 5.5 cannot disable thinking, only lower effort.
        match ($settings->claudeModel) {
            'claude-sonnet-5' => $payload['thinking'] = ['type' => 'disabled'],
            'claude-opus-5-5' => $payload['output_config'] = ['effort' => 'low'],
            default => $payload['temperature'] = 0.1,
        };

        return [
            'method' => 'POST',
            'url' => self::API_URL,
            'options' => [
                'headers' => [
                    'anthropic-version' => '2023-06-01',
                    'x-api-key' => $settings->getClaudeApiKey(),
                    'Content-Type' => 'application/json',
                ],
                'json' => $payload,
            ],
            'parseResponse' => function (ResponseInterface $response, int $startTime) use ($payload, $assetId): array {
                $elapsed = (int) ((hrtime(true) - $startTime) / 1_000_000);
                $body = $this->parseJsonBody($response->getBody()->getContents(), $assetId);

                $this->checkApiError($body, $assetId);

                if ($this->findTextBlock($body) === null) {
                    throw AnalysisException::invalidResponse($this->getDisplayName(), $assetId);
                }

                $usage = $body['usage'] ?? [];
                $logPayload = $payload;
                $imageBytes = strlen($logPayload['messages'][0]['content'][1]['source']['data'] ?? '');
                $logPayload['messages'][0]['content'][1]['source']['data'] = "[base64 image removed — {$imageBytes} bytes]";

                Logger::apiCall(
                    provider: $this->getName(),
                    message: "Image analysis completed for asset {$assetId}",
                    assetId: $assetId,
                    responseTimeMs: $elapsed,
                    httpStatusCode: $response->getStatusCode(),
                    inputTokens: (int) ($usage['input_tokens'] ?? 0),
                    outputTokens: (int) ($usage['output_tokens'] ?? 0),
                    requestPayload: $logPayload,
                    responsePayload: $body,
                    context: $this->buildApiCallContext(1, $response->getStatusCode(), $imageBytes, null),
                );

                return $body;
            },
        ];
    }

    /**
     * Claude API limit: 10MB base64 encoded
     * With 33% encoding overhead: 10MB / 1.33 ≈ 7.5MB original
     * Using 7MB to be safe
     */

    protected function getMaxFileSizeBytes(): int
    {
        return 7 * 1024 * 1024;
    }

    protected function getAcceptedMimeTypes(): array
    {
        return ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
    }
}
