<?php

declare(strict_types=1);

namespace vitordiniz22\craftlens\providers;

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
        $apiKey = $settings->getClaudeApiKey();

        if (empty($apiKey)) {
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
        return $response['content'][0]['text'] ?? '';
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
    protected function sendRequest(Settings $settings, array $imageData, string $prompt, int $assetId): array
    {
        $payload = [
            'model' => $settings->claudeModel,
            'max_tokens' => 4096,
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
            'temperature' => 0.1,
        ];

        return $this->executeApiRequest(function(int $startTime) use ($settings, $payload, $assetId) {
            $response = $this->client->post(self::API_URL, [
                'headers' => [
                    'anthropic-version' => '2023-06-01',
                    'x-api-key' => $settings->getClaudeApiKey(),
                    'Content-Type' => 'application/json',
                ],
                'json' => $payload,
            ]);

            $elapsed = (int) ((hrtime(true) - $startTime) / 1_000_000);
            $body = $this->parseJsonBody($response->getBody()->getContents(), $assetId);

            $this->checkApiError($body, $assetId);

            if (!isset($body['content'][0]['text'])) {
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
            );

            return $body;
        }, $assetId);
    }

    /**
     * Claude API limit: 5MB base64 encoded
     * With 33% encoding overhead: 5MB / 1.33 ≈ 3.75MB original
     * Using 3MB to be safe
     */

    protected function getMaxFileSizeBytes(): int
    {
        return 3 * 1024 * 1024;
    }
}
