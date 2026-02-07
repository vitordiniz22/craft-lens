<?php

declare(strict_types=1);

namespace vitordiniz22\craftlens\services;

use craft\elements\Asset;
use vitordiniz22\craftlens\dto\AnalysisResult;
use vitordiniz22\craftlens\enums\LogCategory;
use vitordiniz22\craftlens\exceptions\ConfigurationException;
use vitordiniz22\craftlens\helpers\Logger;
use vitordiniz22\craftlens\models\Settings;
use vitordiniz22\craftlens\Plugin;
use vitordiniz22\craftlens\providers\AiProviderInterface;
use vitordiniz22\craftlens\providers\ClaudeProvider;
use vitordiniz22\craftlens\providers\GeminiProvider;
use vitordiniz22\craftlens\providers\OpenAiProvider;
use yii\base\Component;

/**
 * Service for managing AI providers and orchestrating image analysis.
 */
class AiProviderService extends Component
{
    /** @var array<string, AiProviderInterface> */
    private array $providers = [];

    public function init(): void
    {
        parent::init();
        $this->registerDefaultProviders();
    }

    /**
     * Analyzes an asset using the configured AI provider.
     */
    public function analyzeAsset(Asset $asset): AnalysisResult
    {
        $settings = $this->getSettings();
        $provider = $this->getDefaultProvider();

        return $provider->analyze($asset, $settings);
    }

    /**
     * Returns the default AI provider based on settings.
     */
    public function getDefaultProvider(): AiProviderInterface
    {
        $settings = $this->getSettings();
        return $this->getProvider($settings->aiProvider);
    }

    /**
     * Returns a specific provider by name.
     *
     * @throws ConfigurationException if provider not found
     */
    public function getProvider(string $name): AiProviderInterface
    {
        if (!isset($this->providers[$name])) {
            throw new ConfigurationException("AI provider '{$name}' is not registered");
        }

        return $this->providers[$name];
    }

    /**
     * Registers a new AI provider.
     */
    public function registerProvider(AiProviderInterface $provider): void
    {
        $name = $provider->getName();

        if (empty($name)) {
            Logger::warning(LogCategory::Configuration, 'Attempted to register AI provider with empty name');
            return;
        }

        if (isset($this->providers[$name])) {
            Logger::warning(LogCategory::Configuration, "AI provider '{$name}' is already registered, overwriting");
        }

        $this->providers[$name] = $provider;
    }

    /**
     * Returns all registered providers.
     *
     * @return array<string, AiProviderInterface>
     */
    public function getAllProviders(): array
    {
        return $this->providers;
    }

    /**
     * Validates credentials for the default provider.
     *
     * @throws ConfigurationException
     */
    public function validateCredentials(): void
    {
        $settings = $this->getSettings();
        $provider = $this->getDefaultProvider();
        $provider->validateCredentials($settings);
    }

    private function registerDefaultProviders(): void
    {
        $this->registerProvider(new OpenAiProvider());
        $this->registerProvider(new GeminiProvider());
        $this->registerProvider(new ClaudeProvider());
    }

    private function getSettings(): Settings
    {
        return Plugin::getInstance()->getSettings();
    }
}
