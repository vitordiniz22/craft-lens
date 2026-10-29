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
 *
 * Each pricing row defines:
 *  - input / output:         USD per 1M tokens (vendor rates, verified manually)
 *  - estInputTokens:         Average input tokens per single-image analysis request,
 *                            measured from past runs. Used for pre-run cost estimates.
 *  - estOutputTokens:        Average output tokens per request, measured from past runs.
 *
 * Whenever a model is added, removed, or re-tuned, update every field in its row at
 * the same time. This table is the single source of truth for both actual cost
 * calculation and pre-run estimates.
 */
class PricingService extends Component
{
    /**
     * OpenAI pricing and token estimates.
     * Prices per https://developers.openai.com/api/docs/pricing.
     *
     * Rates are the short-context tier. OpenAI charges 2x input and 1.5x output on
     * requests above 272k input tokens, but Lens image-analysis prompts are a few
     * thousand tokens, so the single-rate approximation keeps the table flat.
     */
    private const OPENAI_PRICING = [
        'gpt-6.1-sol' => [
            'input' => 2.00,
            'output' => 10.00,
            'estInputTokens' => 4362,
            'estOutputTokens' => 1252,
        ],
        'gpt-6.1-terra' => [
            'input' => 1.00,
            'output' => 5.00,
            'estInputTokens' => 4362,
            'estOutputTokens' => 1214,
        ],
        'gpt-6-luna' => [
            'input' => 0.10,
            'output' => 0.50,
            'estInputTokens' => 4362,
            'estOutputTokens' => 1137,
        ],
        'gpt-5.6-sol' => [
            'input' => 4.00,
            'output' => 20.00,
            'estInputTokens' => 3284,
            'estOutputTokens' => 1075,
        ],
        'gpt-5.6-terra' => [
            'input' => 2.00,
            'output' => 12.00,
            'estInputTokens' => 3603,
            'estOutputTokens' => 992,
        ],
        'gpt-5.6-luna' => [
            'input' => 0.20,
            'output' => 1.20,
            'estInputTokens' => 3888,
            'estOutputTokens' => 1027,
        ],
        'gpt-5.4' => [
            'input' => 2.50,
            'output' => 15.00,
            'estInputTokens' => 3284,
            'estOutputTokens' => 1075,
        ],
        'gpt-5.4-mini' => [
            'input' => 0.75,
            'output' => 4.50,
            'estInputTokens' => 3603,
            'estOutputTokens' => 992,
        ],
        'gpt-5.4-nano' => [
            'input' => 0.20,
            'output' => 1.25,
            'estInputTokens' => 3888,
            'estOutputTokens' => 1027,
        ],
    ];

    /**
     * Gemini pricing and token estimates.
     * Prices per https://ai.google.dev/pricing (paid tier).
     *
     * Note: the Pro models use the ≤200k-token tier rates. Vendor charges more for
     * prompts >200k tokens (3.1 Pro: $4.00 / $18.00, 2.5 Pro: $2.50 / $15.00), but
     * Lens image-analysis prompts are far below that threshold, so the single-rate
     * approximation is used to keep the pricing table flat.
     *
     * gemini-3.8-flash is on introductory pricing until 2026-12-31; from 2027-01-01
     * it is $1.50 input / $7.50 output.
     *
     * Output rates include thinking tokens, which Gemini 3 models always spend.
     */
    private const GEMINI_PRICING = [
        'gemini-3.8-flash' => [
            'input' => 0.75,
            'output' => 3.75,
            'estInputTokens' => 3292,
            'estOutputTokens' => 1241,
        ],
        'gemini-3.1-pro-preview' => [
            'input' => 2.00,
            'output' => 12.00,
            'estInputTokens' => 3292,
            'estOutputTokens' => 1246,
        ],
        'gemini-3.5-flash-lite' => [
            'input' => 0.30,
            'output' => 2.50,
            'estInputTokens' => 3292,
            'estOutputTokens' => 1425,
        ],
        'gemini-2.5-flash' => [
            'input' => 0.30,
            'output' => 2.50,
            'estInputTokens' => 2564,
            'estOutputTokens' => 1233,
        ],
        'gemini-2.5-flash-lite' => [
            'input' => 0.10,
            'output' => 0.40,
            'estInputTokens' => 2564,
            'estOutputTokens' => 1181,
        ],
        'gemini-2.5-pro' => [
            'input' => 1.25,
            'output' => 10.00,
            'estInputTokens' => 2564,
            'estOutputTokens' => 1165,
        ],
    ];

    /**
     * Claude (Anthropic) pricing and token estimates.
     * Prices per https://www.anthropic.com/pricing.
     */
    private const CLAUDE_PRICING = [
        'claude-sonnet-5' => [
            'input' => 2.00,
            'output' => 10.00,
            'estInputTokens' => 3732,
            'estOutputTokens' => 1802,
        ],
        'claude-opus-5-5' => [
            'input' => 4.00,
            'output' => 20.00,
            'estInputTokens' => 3734,
            'estOutputTokens' => 1497,
        ],
        'claude-sonnet-4-6' => [
            'input' => 3.00,
            'output' => 15.00,
            'estInputTokens' => 3183,
            'estOutputTokens' => 1156,
        ],
        'claude-opus-4-6' => [
            'input' => 5.00,
            'output' => 25.00,
            'estInputTokens' => 2965,
            'estOutputTokens' => 1057,
        ],
        'claude-haiku-4-5-20251001' => [
            'input' => 1.00,
            'output' => 5.00,
            'estInputTokens' => 3025,
            'estOutputTokens' => 1211,
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
     * Estimate per-asset cost for the currently configured provider/model using
     * the token estimates stored in the pricing table.
     */
    public function estimateCostPerAssetForCurrentProvider(): float
    {
        $settings = Plugin::getInstance()->getSettings();

        [$table, $label, $model] = match ($settings->getAiProviderEnum()) {
            AiProvider::OpenAi => [self::OPENAI_PRICING, 'OpenAI', $settings->openaiModel],
            AiProvider::Gemini => [self::GEMINI_PRICING, 'Gemini', $settings->geminiModel],
            AiProvider::Claude => [self::CLAUDE_PRICING, 'Claude', $settings->claudeModel],
        };

        if (!isset($table[$model])) {
            Logger::warning(LogCategory::Configuration, "Unknown {$label} model for estimate: {$model}");
            return 0.0;
        }

        $row = $table[$model];

        return $this->calculateCost($table, $label, $model, $row['estInputTokens'], $row['estOutputTokens']);
    }
}
