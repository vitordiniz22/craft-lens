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
     * Verified against https://openai.com/api/pricing/ on 2026-04-15 (standard tier).
     */
    private const OPENAI_PRICING = [
        'gpt-5.4' => [
            'input' => 2.50,
            'output' => 15.00,
        ],
        'gpt-5.4-mini' => [
            'input' => 0.75,
            'output' => 4.50,
        ],
        'gpt-5.4-nano' => [
            'input' => 0.20,
            'output' => 1.25,
        ],
    ];

    /**
     * Gemini pricing per 1M tokens.
     * Verified against https://ai.google.dev/pricing on 2026-04-15 (paid tier).
     *
     * Note: gemini-2.5-pro uses the ≤200k-token tier rates. Vendor also charges
     * $2.50 input / $15.00 output for prompts >200k tokens, but Lens image-analysis
     * prompts are far below that threshold, so the single-rate approximation is
     * used to keep the pricing table flat.
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
            'output' => 10.00,
        ],
    ];

    /**
     * Claude (Anthropic) pricing per 1M tokens.
     * @see https://www.anthropic.com/pricing
     */
    private const CLAUDE_PRICING = [
        'claude-sonnet-4-6' => [
            'input' => 3.00,
            'output' => 15.00,
        ],
        'claude-opus-4-6' => [
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

}
