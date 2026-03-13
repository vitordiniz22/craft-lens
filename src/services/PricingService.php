<?php

declare(strict_types=1);

namespace vitordiniz22\craftlens\services;

use vitordiniz22\craftlens\enums\AiProvider;
use vitordiniz22\craftlens\enums\LogCategory;
use vitordiniz22\craftlens\helpers\Logger;
use vitordiniz22\craftlens\Plugin;
use yii\base\Component;

/**
 * Service for calculating actual API costs from usage data.
 */
class PricingService extends Component
{
    /**
     * OpenAI pricing per 1M tokens.
     * @see https://openai.com/pricing
     */
    private const OPENAI_PRICING = [
        'gpt-5.2' => [
            'input' => 1.75,
            'output' => 14.00,
        ],
        'gpt-5-mini' => [
            'input' => 0.25,
            'output' => 2.00,
        ],
        'gpt-5-nano' => [
            'input' => 0.05,
            'output' => 0.40,
        ],
    ];

    /**
     * Gemini pricing per 1M tokens.
     * @see https://ai.google.dev/pricing
     */
    private const GEMINI_PRICING = [
        'gemini-2.5-flash' => [
            'input' => 0.30,
            'output' => 2.50,
        ],
        'gemini-2.5-flash-lite' => [
            'input' => 0.10,
            'output' => 0.40,
        ],
        'gemini-2.5-pro' => [
            'input' => 1.25,
            'output' => 5.00,
        ],
    ];

    /**
     * Claude (Anthropic) pricing per 1M tokens.
     * @see https://www.anthropic.com/pricing
     */
    private const CLAUDE_PRICING = [
        'claude-sonnet-4-5-20250929' => [
            'input' => 3.00,
            'output' => 15.00,
        ],
        'claude-opus-4-5-20251101' => [
            'input' => 5.00,
            'output' => 25.00,
        ],
        'claude-haiku-4-5-20251001' => [
            'input' => 1.00,
            'output' => 5.00,
        ],
    ];

    public function calculateOpenAiCost(string $model, int $inputTokens, int $outputTokens): float
    {
        return $this->calculateCost(self::OPENAI_PRICING, 'OpenAI', $model, $inputTokens, $outputTokens);
    }

    public function calculateGeminiCost(string $model, int $inputTokens, int $outputTokens): float
    {
        return $this->calculateCost(self::GEMINI_PRICING, 'Gemini', $model, $inputTokens, $outputTokens);
    }

    public function calculateClaudeCost(string $model, int $inputTokens, int $outputTokens): float
    {
        return $this->calculateCost(self::CLAUDE_PRICING, 'Claude', $model, $inputTokens, $outputTokens);
    }

    private function calculateCost(array $pricingTable, string $providerLabel, string $model, int $inputTokens, int $outputTokens): float
    {
        if (!isset($pricingTable[$model])) {
            Logger::warning(LogCategory::Configuration, "Unknown {$providerLabel} model for pricing: {$model}");
            throw new \RuntimeException("Unknown {$providerLabel} model for pricing: {$model}");
        }

        $pricing = $pricingTable[$model];

        return ($inputTokens / 1_000_000) * $pricing['input']
             + ($outputTokens / 1_000_000) * $pricing['output'];
    }

    /**
     * Calculate cost for the currently configured AI provider.
     */
    public function calculateCostForCurrentProvider(int $inputTokens, int $outputTokens): float
    {
        $settings = Plugin::getInstance()->getSettings();

        return match ($settings->getAiProviderEnum()) {
            AiProvider::OpenAi => $this->calculateOpenAiCost($settings->openaiModel, $inputTokens, $outputTokens),
            AiProvider::Gemini => $this->calculateGeminiCost($settings->geminiModel, $inputTokens, $outputTokens),
            AiProvider::Claude => $this->calculateClaudeCost($settings->claudeModel, $inputTokens, $outputTokens),
        };
    }

    /**
     * Get supported model names for a provider.
     *
     * @return string[]
     */
    public function getSupportedModels(AiProvider $provider): array
    {
        return match ($provider) {
            AiProvider::OpenAi => array_keys(self::OPENAI_PRICING),
            AiProvider::Gemini => array_keys(self::GEMINI_PRICING),
            AiProvider::Claude => array_keys(self::CLAUDE_PRICING),
        };
    }

    /**
     * Extract token usage from OpenAI response.
     *
     * @return array{inputTokens: int, outputTokens: int}
     */
    public function extractOpenAiUsage(array $response): array
    {
        $usage = $response['usage'] ?? [];

        return [
            'inputTokens' => (int)($usage['prompt_tokens'] ?? 0),
            'outputTokens' => (int)($usage['completion_tokens'] ?? 0),
        ];
    }
}
