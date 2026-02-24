<?php

declare(strict_types=1);

namespace vitordiniz22\craftlens\providers;

use craft\elements\Asset;
use vitordiniz22\craftlens\dto\AnalysisResult;
use vitordiniz22\craftlens\exceptions\AnalysisException;
use vitordiniz22\craftlens\exceptions\ConfigurationException;
use vitordiniz22\craftlens\helpers\Logger;
use vitordiniz22\craftlens\models\Settings;

/**
 * Google Gemini API provider for image analysis.
 */
class GeminiProvider extends BaseAiProvider
{
    private const API_BASE_URL = 'https://generativelanguage.googleapis.com/v1beta/models/';

    public function getName(): string
    {
        return 'gemini';
    }

    public function getDisplayName(): string
    {
        return 'Google Gemini';
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
        $apiKey = $settings->getGeminiApiKey();

        if (empty($apiKey)) {
            throw ConfigurationException::missingApiKey($this->getName());
        }
    }

    public function testConnection(Settings $settings): void
    {
        $this->validateCredentials($settings);

        $this->executeTestRequest(
            'https://generativelanguage.googleapis.com/v1beta/models?key=' . $settings->getGeminiApiKey(),
            ['Content-Type' => 'application/json']
        );
    }

    protected function extractContentText(array $response): string
    {
        return $response['candidates'][0]['content']['parts'][0]['text'] ?? '';
    }

    protected function extractTokenUsage(array $response): array
    {
        $usage = $response['usageMetadata'] ?? [];

        return [
            'inputTokens' => (int) ($usage['promptTokenCount'] ?? 0),
            'outputTokens' => (int) ($usage['candidatesTokenCount'] ?? 0),
        ];
    }

    /**
     * @param array{base64: string, mimeType: string} $imageData
     */
    private function sendRequest(Settings $settings, array $imageData, string $prompt, int $assetId): array
    {
        $model = $settings->geminiModel;
        $apiKey = $settings->getGeminiApiKey();
        $url = self::API_BASE_URL . $model . ':generateContent';

        $payload = [
            'contents' => [
                [
                    'parts' => [
                        ['text' => $prompt],
                        [
                            'inline_data' => [
                                'mime_type' => $imageData['mimeType'],
                                'data' => $imageData['base64'],
                            ],
                        ],
                    ],
                ],
            ],
            'generationConfig' => [
                'temperature' => 0.1,
                'responseMimeType' => 'application/json',
            ],
        ];

        return $this->executeApiRequest(function(int $startTime) use ($url, $apiKey, $payload, $assetId) {
            $response = $this->client->post($url, [
                'headers' => [
                    'Content-Type' => 'application/json',
                    'x-goog-api-key' => $apiKey,
                ],
                'json' => $payload,
            ]);

            $elapsed = (int) ((hrtime(true) - $startTime) / 1_000_000);
            $body = $this->parseJsonBody($response->getBody()->getContents(), $assetId);

            $this->checkApiError($body, $assetId);

            if (!isset($body['candidates'][0]['content']['parts'][0]['text'])) {
                throw AnalysisException::invalidResponse($this->getName(), $assetId);
            }

            $usage = $body['usageMetadata'] ?? [];
            Logger::apiCall(
                provider: $this->getName(),
                message: "Image analysis completed for asset {$assetId}",
                assetId: $assetId,
                responseTimeMs: $elapsed,
                httpStatusCode: $response->getStatusCode(),
                inputTokens: (int) ($usage['promptTokenCount'] ?? 0),
                outputTokens: (int) ($usage['candidatesTokenCount'] ?? 0),
                requestPayload: $payload,
                responsePayload: $body,
            );

            return $body;
        }, $assetId);
    }

    /**
     * Gemini limit: varies by model, use conservative limit
     * Using same as OpenAI
     */

    protected function getMaxFileSizeBytes(): int
    {
        return 15 * 1024 * 1024;
    }
}
