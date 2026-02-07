<?php

declare(strict_types=1);

namespace vitordiniz22\craftlens\providers;

use craft\elements\Asset;
use vitordiniz22\craftlens\dto\AnalysisResult;
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
        return 'OpenAI Vision';
    }

    public function analyze(Asset $asset, Settings $settings): AnalysisResult
    {
        $this->validateCredentials($settings);

        $imageData = $this->getBase64ImageData($asset);
        $prompt = $this->buildPrompt();
        $response = $this->sendRequest($settings, $imageData, $prompt, $asset->id);

        return $this->parseResponse($response);
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

    protected function extractContentText(array $response): string
    {
        return $response['choices'][0]['message']['content'] ?? '';
    }

    protected function extractTokenUsage(array $response): array
    {
        return Plugin::getInstance()->pricing->extractOpenAiUsage($response);
    }

    /**
     * @param array{base64: string, mimeType: string} $imageData
     */
    private function sendRequest(Settings $settings, array $imageData, string $prompt, int $assetId): array
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
            'max_completion_tokens' => $this->getMaxCompletionTokens($settings->openaiModel),
        ];

        if ($this->supportsTemperature($settings->openaiModel)) {
            $payload['temperature'] = 0.1;
            $payload['reasoning_effort'] = 'none';
        }

        return $this->executeApiRequest(function (int $startTime) use ($settings, $payload, $assetId) {
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

            Logger::apiCall(
                provider: $this->getName(),
                message: "Image analysis completed for asset {$assetId}",
                assetId: $assetId,
                responseTimeMs: $elapsed,
                httpStatusCode: $response->getStatusCode(),
                inputTokens: $usage['inputTokens'],
                outputTokens: $usage['outputTokens'],
                requestPayload: $payload,
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

    /**
     * Temperature is only supported on non-reasoning models.
     */
    private function supportsTemperature(string $model): bool
    {
        return !$this->isReasoningModel($model);
    }

    private function getMaxCompletionTokens(string $model): int
    {
        return $this->isReasoningModel($model) ? 16000 : 1000;
    }

    /**
     * OpenAI limit: ~20MB total payload
     * With 33% encoding overhead: 20MB / 1.33 ≈ 15MB original
     */

    protected function getMaxFileSizeBytes(): int
    {
        return 15 * 1024 * 1024;
    }
}
