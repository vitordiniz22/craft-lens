<?php

declare(strict_types=1);

namespace vitordiniz22\craftlens\controllers;

use Craft;
use craft\helpers\App;
use craft\web\Controller;
use vitordiniz22\craftlens\enums\LogCategory;
use vitordiniz22\craftlens\helpers\Logger;
use vitordiniz22\craftlens\models\Settings;
use vitordiniz22\craftlens\Plugin;
use yii\web\Response;

/**
 * Controller for plugin settings within the Lens CP section.
 */
class SettingsController extends Controller
{
    protected array|int|bool $allowAnonymous = false;

    public function actionIndex(): Response
    {
        $this->requireCpRequest();
        $this->requirePermission('accessPlugin-lens');

        $plugin = Plugin::getInstance();
        $volumeOptions = $this->getVolumeOptions();

        $settings = $plugin->getSettings();
        $this->validateUnresolvedApiKey($settings);

        return $this->renderTemplate('lens/_settings/index', [
            'plugin' => $plugin,
            'settings' => $settings,
            'volumeOptions' => $volumeOptions,
        ]);
    }

    public function actionSave(): Response
    {
        $this->requireCpRequest();
        $this->requirePostRequest();
        $this->requirePermission('accessPlugin-lens');

        $plugin = Plugin::getInstance();
        $settings = $plugin->getSettings();

        $settingsData = $this->request->getBodyParam('settings', []);

        $allowedKeys = [
            'aiProvider',
            'openaiApiKey',
            'openaiModel',
            'geminiApiKey',
            'geminiModel',
            'claudeApiKey',
            'claudeModel',
            'autoProcessOnUpload',
            'enabledVolumes',
            'enableSemanticSearch',
        ];

        $settings->setAttributes(array_intersect_key((array) $settingsData, array_flip($allowedKeys)));

        if (!$settings->validate()) {
            Logger::warning(LogCategory::Configuration, 'Settings validation failed', context: ['errors' => $settings->getErrors()]);
            Craft::$app->getSession()->setError(Craft::t('lens', 'Could not save settings.'));

            return $this->renderTemplate('lens/_settings/index', [
                'plugin' => $plugin,
                'settings' => $settings,
                'volumeOptions' => $this->getVolumeOptions(),
            ]);
        }

        if (!Craft::$app->getPlugins()->savePluginSettings($plugin, $settings->toArray())) {
            Craft::$app->getSession()->setError(Craft::t('lens', 'Could not save settings.'));

            return $this->renderTemplate('lens/_settings/index', [
                'plugin' => $plugin,
                'settings' => $settings,
                'volumeOptions' => $this->getVolumeOptions(),
            ]);
        }

        Logger::info(LogCategory::Configuration, 'Plugin settings saved', context: [
            'provider' => $settings->aiProvider,
            'autoProcess' => $settings->autoProcessOnUpload,
        ]);

        Craft::$app->getSession()->setNotice(Craft::t('lens', 'Settings saved.'));

        return $this->redirectToPostedUrl();
    }

    /**
     * Adds a field error when the API key field has a value (e.g. an env var reference)
     * that doesn't resolve. Skips empty fields (fresh install).
     */
    private function validateUnresolvedApiKey(Settings $settings): void
    {
        $property = match ($settings->aiProvider) {
            'openai' => 'openaiApiKey',
            'gemini' => 'geminiApiKey',
            'claude' => 'claudeApiKey',
            default => null,
        };

        $raw = $property ? $settings->{$property} : '';

        if ($raw !== '' && App::parseEnv($raw) === null) {
            $settings->addError($property, Craft::t('lens', 'The configured environment variable could not be resolved. Check that it is set in your .env file.'));
        }
    }

    private function getVolumeOptions(): array
    {
        $options = [];

        foreach (Craft::$app->getVolumes()->getAllVolumes() as $volume) {
            $options[] = [
                'label' => $volume->name,
                'value' => $volume->uid,
            ];
        }

        return $options;
    }
}
