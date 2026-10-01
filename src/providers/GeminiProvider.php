<?php

declare(strict_types=1);

namespace vitordiniz22\craftlens\providers;

use Psr\Http\Message\ResponseInterface;
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
        return 'Gemini';
    }

    public function validateCredentials(Settings $settings): void
    {
        if (!$settings->hasApiKey('geminiApiKey')) {
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

    protected function isResponseTruncated(array $response): bool
    {
        return ($response['candidates'][0]['finishReason'] ?? null) === 'MAX_TOKENS';
    }

    protected function extractTokenUsage(array $response): array
    {
        $usage = $response['usageMetadata'] ?? [];

        return [
            'inputTokens' => (int) ($usage['promptTokenCount'] ?? 0),
            'outputTokens' => $this->countOutputTokens($usage),
        ];
    }

    /**
     * Thinking tokens are billed as output but reported separately from candidates.
     */
    private function countOutputTokens(array $usage): int
    {
        return (int) ($usage['candidatesTokenCount'] ?? 0) + (int) ($usage['thoughtsTokenCount'] ?? 0);
    }

    /**
     * @param array{base64: string, mimeType: string} $imageData
     */
    protected function buildHttpRequest(Settings $settings, array $imageData, string $prompt, int $assetId): array
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
                'responseMimeType' => 'application/json',
                'mediaResolution' => 'MEDIA_RESOLUTION_HIGH',
            ],
        ];

        // Gemini 3 always thinks and Google advises keeping its default temperature.
        if (str_starts_with($model, 'gemini-3')) {
            $payload['generationConfig']['thinkingConfig'] = [
                'thinkingLevel' => str_contains($model, '-flash-lite') ? 'minimal' : 'low',
            ];
        } else {
            $payload['generationConfig']['temperature'] = 0.1;
        }

        return [
            'method' => 'POST',
            'url' => $url,
            'options' => [
                'headers' => [
                    'Content-Type' => 'application/json',
                    'x-goog-api-key' => $apiKey,
                ],
                'json' => $payload,
            ],
            'parseResponse' => function (ResponseInterface $response, int $startTime) use ($payload, $assetId): array {
                $elapsed = (int) ((hrtime(true) - $startTime) / 1_000_000);
                $body = $this->parseJsonBody($response->getBody()->getContents(), $assetId);

                $this->checkApiError($body, $assetId);

                if (!isset($body['candidates'][0]['content']['parts'][0]['text'])) {
                    throw AnalysisException::invalidResponse($this->getDisplayName(), $assetId);
                }

                $usage = $body['usageMetadata'] ?? [];
                $logPayload = $payload;
                $imageBytes = strlen($logPayload['contents'][0]['parts'][1]['inline_data']['data'] ?? '');
                $logPayload['contents'][0]['parts'][1]['inline_data']['data'] = "[base64 image removed — {$imageBytes} bytes]";

                Logger::apiCall(
                    provider: $this->getName(),
                    message: "Image analysis completed for asset {$assetId}",
                    assetId: $assetId,
                    responseTimeMs: $elapsed,
                    httpStatusCode: $response->getStatusCode(),
                    inputTokens: (int) ($usage['promptTokenCount'] ?? 0),
                    outputTokens: $this->countOutputTokens($usage),
                    requestPayload: $logPayload,
                    responsePayload: $body,
                    context: $this->buildApiCallContext(1, $response->getStatusCode(), $imageBytes, null),
                );

                return $body;
            },
        ];
    }

    /**
     * Gemini limit: varies by model, use conservative limit
     * Using same as OpenAI
     */

    protected function getMaxFileSizeBytes(): int
    {
        return 15 * 1024 * 1024;
    }

    /** GIF is absent from Gemini's documented list but accepted by the API. */
    protected function getAcceptedMimeTypes(): array
    {
        return ['image/jpeg', 'image/png', 'image/webp', 'image/heic', 'image/heif', 'image/gif'];
    }
}
