<?php

declare(strict_types=1);

namespace vitordiniz22\craftlens\controllers;

use Craft;
use craft\web\Controller;
use vitordiniz22\craftlens\enums\LogCategory;
use vitordiniz22\craftlens\helpers\Logger;
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

        return $this->renderTemplate('lens/_settings/index', [
            'plugin' => $plugin,
            'settings' => $plugin->getSettings(),
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
            'requireReviewBeforeApply',
            'enabledVolumes',
            'enableSemanticSearch',
            'logRetentionDays',
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
            'requireReview' => $settings->requireReviewBeforeApply,
        ]);

        Craft::$app->getSession()->setNotice(Craft::t('lens', 'Settings saved.'));

        return $this->redirectToPostedUrl();
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
