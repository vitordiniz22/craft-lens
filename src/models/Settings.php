<?php

declare(strict_types=1);

namespace vitordiniz22\craftlens\models;

use Craft;
use craft\base\Model;
use craft\helpers\App;
use vitordiniz22\craftlens\enums\AiProvider;

/**
 * Lens plugin settings.
 */
class Settings extends Model
{
    // API Configuration
    public string $aiProvider = 'openai';
    public string $openaiApiKey = '';
    public string $openaiModel = 'gpt-5-mini';
    public string $geminiApiKey = '';
    public string $geminiModel = 'gemini-2.5-flash';

    // Claude (Anthropic) Configuration
    public string $claudeApiKey = '';
    public string $claudeModel = 'claude-sonnet-4-5-20250929';

    // Processing Options
    public bool $autoProcessOnUpload = true;
    public bool $requireReviewBeforeApply = true;

    // Volume Settings
    public array $enabledVolumes = ['*'];

    // Semantic Search
    public bool $enableSemanticSearch = false;

    // Logging
    public int $logRetentionDays = 30;

    // Batch Processing
    public const BATCH_SIZE = 20;

    // Supported models per provider (single source of truth for validation and defaults)
    public const OPENAI_MODELS = ['gpt-5.2', 'gpt-5-mini', 'gpt-5-nano'];
    public const GEMINI_MODELS = ['gemini-2.5-flash', 'gemini-2.5-flash-lite', 'gemini-2.5-pro'];
    public const CLAUDE_MODELS = ['claude-sonnet-4-5-20250929', 'claude-opus-4-5-20251101', 'claude-haiku-4-5-20251001'];

    public function rules(): array
    {
        return [
            // AI Provider
            [['aiProvider'], 'required'],
            [['aiProvider'], 'in', 'range' => array_column(AiProvider::cases(), 'value')],

            // Provider API keys (conditionally required)
            [['openaiApiKey'], 'required', 'when' => fn() => $this->aiProvider === AiProvider::OpenAi->value],
            [['geminiApiKey'], 'required', 'when' => fn() => $this->aiProvider === AiProvider::Gemini->value],
            [['claudeApiKey'], 'required', 'when' => fn() => $this->aiProvider === AiProvider::Claude->value],
            [['claudeModel'], 'required', 'when' => fn() => $this->aiProvider === AiProvider::Claude->value],
            [['claudeModel'], 'in', 'range' => self::CLAUDE_MODELS],
            [['openaiModel'], 'required', 'when' => fn() => $this->aiProvider === AiProvider::OpenAi->value],
            [['openaiModel'], 'in', 'range' => self::OPENAI_MODELS],
            [['geminiModel'], 'required', 'when' => fn() => $this->aiProvider === AiProvider::Gemini->value],
            [['geminiModel'], 'in', 'range' => self::GEMINI_MODELS],

            // Boolean toggles
            [[
                'autoProcessOnUpload',
                'requireReviewBeforeApply',
                'enableSemanticSearch',
            ], 'boolean'],

            // Arrays
            [['enabledVolumes'], 'safe'],
        ];
    }

    public function attributeLabels(): array
    {
        return [
            'aiProvider' => Craft::t('lens', 'AI Provider'),
            'openaiApiKey' => Craft::t('lens', 'OpenAI API Key'),
            'openaiModel' => Craft::t('lens', 'OpenAI Model'),
            'geminiApiKey' => Craft::t('lens', 'Gemini API Key'),
            'geminiModel' => Craft::t('lens', 'Gemini Model'),
            'claudeApiKey' => Craft::t('lens', 'Claude API Key'),
            'claudeModel' => Craft::t('lens', 'Claude Model'),
            'autoProcessOnUpload' => Craft::t('lens', 'Auto-Process on Upload'),
            'requireReviewBeforeApply' => Craft::t('lens', 'Require Human Review'),
            'enabledVolumes' => Craft::t('lens', 'Enabled Volumes'),
            'enableSemanticSearch' => Craft::t('lens', 'Semantic Asset Search'),
        ];
    }

    /**
     * Returns the AI provider as an enum.
     */
    public function getAiProviderEnum(): AiProvider
    {
        return AiProvider::from($this->aiProvider);
    }

    /**
     * Returns a parsed API key for a given property, supporting environment variables.
     */
    private function getApiKey(string $property): string
    {
        return App::parseEnv($this->{$property});
    }

    /**
     * Returns the parsed OpenAI API key, supporting environment variables.
     */
    public function getOpenaiApiKey(): string
    {
        return $this->getApiKey('openaiApiKey');
    }

    /**
     * Returns the parsed Gemini API key, supporting environment variables.
     */
    public function getGeminiApiKey(): string
    {
        return $this->getApiKey('geminiApiKey');
    }

    /**
     * Returns the parsed Claude API key, supporting environment variables.
     */
    public function getClaudeApiKey(): string
    {
        return $this->getApiKey('claudeApiKey');
    }

    /**
     * Get IDs of volumes enabled for Lens processing, or null if all volumes are enabled.
     *
     * @return int[]|null null means no volume filter (all volumes enabled)
     */
    public function getEnabledVolumeIds(): ?array
    {
        if (empty($this->enabledVolumes) || in_array('*', $this->enabledVolumes, true)) {
            return null;
        }

        $ids = [];

        foreach (Craft::$app->getVolumes()->getAllVolumes() as $volume) {
            if (in_array($volume->uid, $this->enabledVolumes, true)) {
                $ids[] = $volume->id;
            }
        }

        return $ids;
    }
}
