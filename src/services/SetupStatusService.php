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

    private ?array $cachedStatus = null;

    /**
     * Get all setup status checks.
     *
     * @return array<array{key: string, category: string, severity: string, message: string, actionLabel: string, actionUrl: string, isResolved: bool}>
     */
    public function getSetupStatus(): array
    {
        if ($this->cachedStatus !== null) {
            return $this->cachedStatus;
        }

        $this->cachedStatus = [
            $this->checkAiProviderConfigured(),
            $this->checkVolumesEnabled(),
            $this->checkAnalysisPanelConfigured(),
        ];

        if (Plugin::getInstance()->getIsPro()) {
            $this->cachedStatus[] = $this->checkSemanticSearchEnabled();
        }

        if ($this->isAiProviderConfigured() && $this->hasEnabledVolumes()) {
            $this->cachedStatus[] = $this->checkFirstAnalysis();
        }

        return $this->cachedStatus;
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
            case 'tag_extraction':
            case 'title_generation':
            case 'alt_text_generation':
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
     * Check if Lens Analysis is added to at least one enabled volume's field layout.
     */
    public function isAnalysisPanelConfigured(): bool
    {
        $settings = $this->getSettings();
        $enabledVolumeUids = $settings->enabledVolumes ?? [];

        if (empty($enabledVolumeUids)) {
            return false;
        }

        $allVolumesEnabled = in_array('*', $enabledVolumeUids, true);

        foreach (Craft::$app->getVolumes()->getAllVolumes() as $volume) {
            if (!$allVolumesEnabled && !in_array($volume->uid, $enabledVolumeUids, true)) {
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

        return [
            'key' => 'ai_provider_api_key',
            'category' => self::CATEGORY_AI_PROVIDER,
            'severity' => SetupSeverity::Critical->value,
            'message' => Craft::t('lens', 'Add your AI provider API key. Lens uses it to analyze images and generate metadata.'),
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
            'message' => Craft::t('lens', 'Enable at least one asset volume so Lens knows which images to process.'),
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
            'message' => Craft::t('lens', 'Add the Lens Analysis element to a volume\'s field layout to see AI results directly on asset pages.'),
            'actionLabel' => Craft::t('lens', 'Add to Field Layout'),
            'actionUrl' => 'settings/assets',
            'isResolved' => $isResolved,
        ];
    }

    private function checkSemanticSearchEnabled(): array
    {
        $isResolved = $this->getSettings()->enableSemanticSearch;

        return [
            'key' => 'semantic_search_enabled',
            'category' => self::CATEGORY_VOLUMES,
            'severity' => SetupSeverity::Info->value,
            'message' => Craft::t('lens', 'Turn on semantic search to replace the native asset selector search with Lens, so queries match against AI descriptions, tags, and extracted text.'),
            'actionLabel' => Craft::t('lens', 'Go to Settings'),
            'actionUrl' => 'lens/settings',
            'isResolved' => $isResolved,
        ];
    }

    private function checkFirstAnalysis(): array
    {
        $plugin = Plugin::getInstance();
        $stats = $plugin->bulkProcessingStatus->getStats();
        $isResolved = $stats['analyzed'] > 0;
        $isPro = $plugin->getIsPro();

        return [
            'key' => 'first_analysis_complete',
            'category' => self::CATEGORY_VOLUMES,
            'severity' => SetupSeverity::Info->value,
            'message' => Craft::t('lens', 'Analyze your first image to see Lens in action.'),
            'actionLabel' => $isPro
                ? Craft::t('lens', 'Bulk Process')
                : Craft::t('lens', 'Go to Assets'),
            'actionUrl' => $isPro ? 'lens/bulk' : 'assets',
            'isResolved' => $isResolved,
        ];
    }

    private function getSettings(): Settings
    {
        return Plugin::getInstance()->getSettings();
    }
}
