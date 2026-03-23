<?php

declare(strict_types=1);

namespace vitordiniz22\craftlens\providers;

use vitordiniz22\craftlens\exceptions\AnalysisException;
use vitordiniz22\craftlens\exceptions\ConfigurationException;
use vitordiniz22\craftlens\helpers\Logger;
use vitordiniz22\craftlens\models\Settings;
use vitordiniz22\craftlens\Plugin;

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
        $apiKey = $settings->getOpenaiApiKey();

        if (empty($apiKey)) {
            throw ConfigurationException::missingApiKey($this->getName());
        }

        if (!str_starts_with($apiKey, 'sk-')) {
            throw ConfigurationException::invalidApiKey($this->getName());
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
        return Plugin::getInstance()->pricing->extractOpenAiUsage($response);
    }

    /**
     * @param array{base64: string, mimeType: string} $imageData
     */
    protected function sendRequest(Settings $settings, array $imageData, string $prompt, int $assetId): array
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
                                'detail' => 'low',
                            ],
                        ],
                    ],
                ],
            ],
        ];

        if ($this->isReasoningModel($settings->openaiModel)) {
            $payload['max_completion_tokens'] = self::REASONING_MAX_TOKENS;
        } else {
            $payload['temperature'] = 0.1;
            $payload['reasoning_effort'] = 'none';
        }

        return $this->executeApiRequest(function(int $startTime) use ($settings, $payload, $assetId) {
            $response = $this->client->post(self::API_URL, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $settings->getOpenaiApiKey(),
                    'Content-Type' => 'application/json',
                ],
                'json' => $payload,
            ]);

            $elapsed = (int) ((hrtime(true) - $startTime) / 1_000_000);
            $body = $this->parseJsonBody($response->getBody()->getContents(), $assetId);

            if (!isset($body['choices'][0]['message']['content'])) {
                throw AnalysisException::invalidResponse($this->getName(), $assetId);
            }

            $usage = Plugin::getInstance()->pricing->extractOpenAiUsage($body);

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
            );

            return $body;
        }, $assetId);
    }

    /**
     * Models that require internal reasoning tokens.
     */
    private function isReasoningModel(string $model): bool
    {
        return in_array($model, ['gpt-5-mini', 'gpt-5-nano'], true);
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
