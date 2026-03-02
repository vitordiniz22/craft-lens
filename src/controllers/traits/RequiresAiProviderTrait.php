<?php

declare(strict_types=1);

namespace vitordiniz22\craftlens\controllers\traits;

use Craft;
use craft\helpers\UrlHelper;
use vitordiniz22\craftlens\Plugin;

/**
 * Trait for controllers that require a configured AI provider.
 * Redirects to settings with an error flash if not configured.
 */
trait RequiresAiProviderTrait
{
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        if (!Plugin::getInstance()->setupStatus->isAiProviderConfigured()) {
            Craft::$app->getSession()->setError(
                Craft::t('lens', 'Please configure an AI provider API key before using this feature.')
            );
            $this->redirect(UrlHelper::cpUrl('lens/settings'));
            return false;
        }

        return true;
    }
}
