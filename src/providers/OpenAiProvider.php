<?php

declare(strict_types=1);

namespace vitordiniz22\craftlens\providers;

use Psr\Http\Message\ResponseInterface;
use vitordiniz22\craftlens\exceptions\AnalysisException;
use vitordiniz22\craftlens\exceptions\ConfigurationException;
use vitordiniz22\craftlens\helpers\Logger;
use vitordiniz22\craftlens\models\Settings;

/**
 * OpenAI Vision API provider for image analysis.
 */
class OpenAiProvider extends BaseAiProvider
{
    private const API_URL = 'https://api.openai.com/v1/chat/completions';

    public function getName(): string
    {
        return 'openai';
    }

    public function getDisplayName(): string
    {
        return 'OpenAI';
    }

    public function validateCredentials(Settings $settings): void
    {
        if (!$settings->hasApiKey('openaiApiKey')) {
            throw ConfigurationException::missingApiKey($this->getName());
        }
    }

    public function testConnection(Settings $settings): void
    {
        $this->validateCredentials($settings);

        $this->executeTestRequest(
            'https://api.openai.com/v1/models',
            ['Authorization' => 'Bearer ' . $settings->getOpenaiApiKey()]
        );
    }

    protected function extractContentText(array $response): string
    {
        return $response['choices'][0]['message']['content'] ?? '';
    }

    protected function isResponseTruncated(array $response): bool
    {
        return ($response['choices'][0]['finish_reason'] ?? null) === 'length';
    }

    protected function extractTokenUsage(array $response): array
    {
        $usage = $response['usage'] ?? [];

        return [
            'inputTokens' => (int) ($usage['prompt_tokens'] ?? 0),
            'outputTokens' => (int) ($usage['completion_tokens'] ?? 0),
        ];
    }

    /**
     * @param array{base64: string, mimeType: string} $imageData
     */
    protected function buildHttpRequest(Settings $settings, array $imageData, string $prompt, int $assetId): array
    {
        $payload = [
            'model' => $settings->openaiModel,
            'messages' => [
                [
                    'role' => 'user',
                    'content' => [
                        ['type' => 'text', 'text' => $prompt],
                        [
                            'type' => 'image_url',
                            'image_url' => [
                                'url' => "data:{$imageData['mimeType']};base64,{$imageData['base64']}",
                                'detail' => 'high',
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $payload['response_format'] = ['type' => 'json_object'];

        if ($settings->openaiModel === 'gpt-5.4') {
            $payload['temperature'] = 0.1;
            $payload['reasoning_effort'] = 'none';
        } else {
            $payload['max_completion_tokens'] = self::REASONING_MAX_TOKENS;

            if (str_starts_with($settings->openaiModel, 'gpt-5.6-')) {
                $payload['reasoning_effort'] = 'none';
            }
        }

        return [
            'method' => 'POST',
            'url' => self::API_URL,
            'options' => [
                'headers' => [
                    'Authorization' => 'Bearer ' . $settings->getOpenaiApiKey(),
                    'Content-Type' => 'application/json',
                ],
                'json' => $payload,
            ],
            'parseResponse' => function (ResponseInterface $response, int $startTime) use ($payload, $assetId): array {
                $elapsed = (int) ((hrtime(true) - $startTime) / 1_000_000);
                $body = $this->parseJsonBody($response->getBody()->getContents(), $assetId);

                if (!isset($body['choices'][0]['message']['content'])) {
                    throw AnalysisException::invalidResponse($this->getDisplayName(), $assetId);
                }

                $usage = $this->extractTokenUsage($body);

                $logPayload = $payload;
                $dataUrl = $logPayload['messages'][0]['content'][1]['image_url']['url'] ?? '';
                $imageBytes = strlen($dataUrl);
                $logPayload['messages'][0]['content'][1]['image_url']['url'] = "[base64 data URL removed — {$imageBytes} bytes]";

                Logger::apiCall(
                    provider: $this->getName(),
                    message: "Image analysis completed for asset {$assetId}",
                    assetId: $assetId,
                    responseTimeMs: $elapsed,
                    httpStatusCode: $response->getStatusCode(),
                    inputTokens: $usage['inputTokens'],
                    outputTokens: $usage['outputTokens'],
                    requestPayload: $logPayload,
                    responsePayload: $body,
                    context: $this->buildApiCallContext(1, $response->getStatusCode(), $imageBytes, null),
                );

                return $body;
            },
        ];
    }

    private const REASONING_MAX_TOKENS = 16000;

    /**
     * OpenAI limit: ~20MB total payload
     * With 33% encoding overhead: 20MB / 1.33 ≈ 15MB original
     */

    protected function getMaxFileSizeBytes(): int
    {
        return 15 * 1024 * 1024;
    }
}
