<?php

declare(strict_types=1);

namespace vitordiniz22\craftlens\controllers;

use Craft;
use craft\web\Controller;
use vitordiniz22\craftlens\enums\AssetBrowserLayout;
use vitordiniz22\craftlens\Plugin;
use yii\web\BadRequestHttpException;
use yii\web\Response;

/**
 * Controller for per-user preferences stored in lens_user_settings.
 */
class UserSettingsController extends Controller
{
    protected array|int|bool $allowAnonymous = false;

    public function actionSetAssetBrowserLayout(): Response
    {
        $this->requireCpRequest();
        $this->requirePostRequest();
        $this->requireAcceptsJson();
        $this->requirePermission('accessPlugin-lens');

        $value = (string) $this->request->getRequiredBodyParam('layout');
        $layout = AssetBrowserLayout::tryFrom($value);

        if ($layout === null) {
            throw new BadRequestHttpException('Invalid layout value');
        }

        Plugin::getInstance()->userSettings->setAssetBrowserLayout($layout);

        return $this->asJson(['success' => true, 'layout' => $layout->value]);
    }
}
