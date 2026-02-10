<?php

declare(strict_types=1);

namespace vitordiniz22\craftlens\services;

use Craft;
use vitordiniz22\craftlens\enums\AiProvider;
use vitordiniz22\craftlens\enums\SetupSeverity;
use vitordiniz22\craftlens\fieldlayoutelements\LensAnalysisElement;
use vitordiniz22\craftlens\models\Settings;
use vitordiniz22\craftlens\Plugin;
use yii\base\Component;

/**
 * Centralized service for checking plugin setup status and configuration.
 * Used to provide contextual guidance in empty states across the plugin.
 */
class SetupStatusService extends Component
{
    public const CATEGORY_AI_PROVIDER = 'ai_provider';
    public const CATEGORY_VOLUMES = 'volumes';
    public const CATEGORY_FIELD_LAYOUT = 'field_layout';

    /**
     * Get all setup status checks.
     *
     * @return array<array{key: string, category: string, severity: string, message: string, actionLabel: string, actionUrl: string, isResolved: bool}>
     */
    public function getSetupStatus(): array
    {
        $checks = [];

        // AI Provider checks
        $checks[] = $this->checkAiProviderConfigured();

        // Volume checks
        $checks[] = $this->checkVolumesEnabled();

        // Field layout checks
        $checks[] = $this->checkAnalysisPanelConfigured();

        return $checks;
    }

    /**
     * Get only critical issues that block core functionality.
     *
     * @return array<array{key: string, category: string, severity: string, message: string, actionLabel: string, actionUrl: string, isResolved: bool}>
     */
    public function getCriticalIssues(): array
    {
        return array_filter(
            $this->getSetupStatus(),
            fn(array $check) => $check['severity'] === SetupSeverity::Critical->value && !$check['isResolved']
        );
    }

    /**
     * Get warnings (non-blocking issues).
     *
     * @return array<array{key: string, category: string, severity: string, message: string, actionLabel: string, actionUrl: string, isResolved: bool}>
     */
    public function getWarnings(): array
    {
        return array_filter(
            $this->getSetupStatus(),
            fn(array $check) => $check['severity'] === SetupSeverity::Warning->value && !$check['isResolved']
        );
    }

    /**
     * Get all unresolved issues (any severity).
     *
     * @return array<array{key: string, category: string, severity: string, message: string, actionLabel: string, actionUrl: string, isResolved: bool}>
     */
    public function getUnresolvedIssues(): array
    {
        return array_values(array_filter(
            $this->getSetupStatus(),
            fn(array $check) => !$check['isResolved']
        ));
    }

    /**
     * Check if the plugin has any unresolved issues.
     */
    public function hasUnresolvedIssues(): bool
    {
        return count($this->getUnresolvedIssues()) > 0;
    }

    /**
     * Check if the plugin has any critical issues.
     */
    public function hasCriticalIssues(): bool
    {
        return count($this->getCriticalIssues()) > 0;
    }

    /**
     * Check if a specific feature is available based on configuration.
     */
    public function isFeatureAvailable(string $feature): bool
    {
        return match ($feature) {
            'analysis' => $this->isAiProviderConfigured() && $this->hasEnabledVolumes(),
            'duplicates' => $this->isAiProviderConfigured(),
            'tag_extraction' => $this->isAiProviderConfigured(),
            'title_generation' => $this->isAiProviderConfigured(),
            'alt_text_generation' => $this->isAiProviderConfigured(),
            default => true,
        };
    }

    /**
     * Get requirements for a specific feature.
     *
     * @return array<array{key: string, message: string, actionLabel: string, actionUrl: string, isResolved: bool}>
     */
    public function getFeatureRequirements(string $feature): array
    {
        $requirements = [];

        switch ($feature) {
            case 'analysis':
                $aiCheck = $this->checkAiProviderConfigured();
                if (!$aiCheck['isResolved']) {
                    $requirements[] = $aiCheck;
                }
                $volumeCheck = $this->checkVolumesEnabled();
                if (!$volumeCheck['isResolved']) {
                    $requirements[] = $volumeCheck;
                }
                break;

            case 'duplicates':
                $aiCheck = $this->checkAiProviderConfigured();
                if (!$aiCheck['isResolved']) {
                    $requirements[] = $aiCheck;
                }
                break;

        }

        return $requirements;
    }

    /**
     * Check if AI provider is properly configured.
     */
    public function isAiProviderConfigured(): bool
    {
        $settings = $this->getSettings();

        return match ($settings->getAiProviderEnum()) {
            AiProvider::OpenAi => !empty($settings->getOpenaiApiKey()),
            AiProvider::Gemini => !empty($settings->getGeminiApiKey()),
            AiProvider::Claude => !empty($settings->getClaudeApiKey()),
        };
    }

    /**
     * Check if the Analysis Panel is added to at least one enabled volume's field layout.
     */
    public function isAnalysisPanelConfigured(): bool
    {
        $settings = $this->getSettings();
        $enabledVolumeUids = $settings->enabledVolumes ?? [];

        if (empty($enabledVolumeUids)) {
            return false;
        }

        foreach (Craft::$app->getVolumes()->getAllVolumes() as $volume) {
            if (!in_array($volume->uid, $enabledVolumeUids, true)) {
                continue;
            }

            $fieldLayout = $volume->getFieldLayout();

            if ($fieldLayout === null) {
                continue;
            }

            foreach ($fieldLayout->getTabs() as $tab) {
                foreach ($tab->getElements() as $element) {
                    if ($element instanceof LensAnalysisElement) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    /**
     * Check if at least one volume is enabled.
     */
    public function hasEnabledVolumes(): bool
    {
        $settings = $this->getSettings();
        return !empty($settings->enabledVolumes);
    }

    /**
     * Get the display name for the current AI provider.
     */
    public function getAiProviderDisplayName(): string
    {
        return $this->getSettings()->getAiProviderEnum()->label();
    }

    private function checkAiProviderConfigured(): array
    {
        $isResolved = $this->isAiProviderConfigured();
        $providerName = $this->getAiProviderDisplayName();

        return [
            'key' => 'ai_provider_api_key',
            'category' => self::CATEGORY_AI_PROVIDER,
            'severity' => SetupSeverity::Critical->value,
            'message' => $isResolved
                ? Craft::t('lens', '{provider} is configured and ready.', ['provider' => $providerName])
                : Craft::t('lens', 'AI analysis requires a {provider} API key to function.', ['provider' => $providerName]),
            'actionLabel' => Craft::t('lens', 'Configure API Key'),
            'actionUrl' => 'lens/settings#provider',
            'isResolved' => $isResolved,
        ];
    }

    private function checkVolumesEnabled(): array
    {
        $isResolved = $this->hasEnabledVolumes();

        return [
            'key' => 'volumes_enabled',
            'category' => self::CATEGORY_VOLUMES,
            'severity' => SetupSeverity::Critical->value,
            'message' => $isResolved
                ? Craft::t('lens', 'Asset volumes are configured.')
                : Craft::t('lens', 'No asset volumes are enabled for processing. Enable at least one volume.'),
            'actionLabel' => Craft::t('lens', 'Configure Volumes'),
            'actionUrl' => 'lens/settings#volumes',
            'isResolved' => $isResolved,
        ];
    }

    private function checkAnalysisPanelConfigured(): array
    {
        $isResolved = $this->isAnalysisPanelConfigured();

        return [
            'key' => 'analysis_panel_added',
            'category' => self::CATEGORY_FIELD_LAYOUT,
            'severity' => SetupSeverity::Warning->value,
            'message' => $isResolved
                ? Craft::t('lens', 'Analysis Panel is configured.')
                : Craft::t('lens', 'Analysis Panel has not been added to any volume field layout.'),
            'actionLabel' => Craft::t('lens', 'Add to Field Layout'),
            'actionUrl' => 'settings/assets',
            'isResolved' => $isResolved,
        ];
    }

    private function getSettings(): Settings
    {
        return Plugin::getInstance()->getSettings();
    }
}
