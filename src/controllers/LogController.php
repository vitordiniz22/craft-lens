<?php

declare(strict_types=1);

namespace vitordiniz22\craftlens\controllers;

use Craft;
use craft\web\Controller;
use craft\web\twig\variables\Paginate;
use vitordiniz22\craftlens\enums\LogCategory;
use vitordiniz22\craftlens\enums\LogLevel;
use vitordiniz22\craftlens\Plugin;
use yii\web\ForbiddenHttpException;
use yii\web\Response;

/**
 * Controller for the Logs CP page.
 */
class LogController extends Controller
{
    private const PER_PAGE = 50;

    protected array|int|bool $allowAnonymous = false;

    public function actionIndex(): Response
    {
        if (!Plugin::isDevInstall()) {
            throw new ForbiddenHttpException('Logs are only available in development mode.');
        }

        $this->requireCpRequest();
        $this->requirePermission('accessPlugin-lens');

        $request = Craft::$app->getRequest();
        $logService = Plugin::getInstance()->log;

        $filters = [
            'level' => $request->getQueryParam('level') ?: null,
            'category' => $request->getQueryParam('category') ?: null,
            'assetId' => $request->getQueryParam('assetId') ?: null,
        ];

        $result = $logService->getLogs(
            level: $filters['level'],
            category: $filters['category'],
            assetId: $filters['assetId'] ? (int) $filters['assetId'] : null,
            page: $request->getPageNum(),
            perPage: self::PER_PAGE,
        );

        $pageOffset = ($result['page'] - 1) * self::PER_PAGE;
        $pageInfo = new Paginate([
            'first' => $result['total'] > 0 ? $pageOffset + 1 : 0,
            'last' => min($result['total'], $pageOffset + count($result['logs'])),
            'total' => $result['total'],
            'currentPage' => $result['page'],
            'totalPages' => $result['totalPages'],
        ]);
        $pageInfo->setBasePath($request->getPathInfo());

        return $this->renderTemplate('lens/_logs/index', [
            'logs' => $result['logs'],
            'total' => $result['total'],
            'pageInfo' => $pageInfo,
            'isDevMode' => Plugin::isDevInstall(),
            'filters' => $filters,
            'levels' => array_column(LogLevel::cases(), 'value'),
            'categories' => array_column(LogCategory::cases(), 'value'),
        ]);
    }

    public function actionRetry(): Response
    {
        if (!Plugin::isDevInstall()) {
            throw new ForbiddenHttpException('Logs are only available in development mode.');
        }

        $this->requireCpRequest();
        $this->requirePermission('accessPlugin-lens');
        $this->requirePostRequest();

        $logId = (int) Craft::$app->getRequest()->getRequiredBodyParam('logId');
        $success = Plugin::getInstance()->log->retryFromLog($logId);

        if ($success) {
            Craft::$app->getSession()->setNotice(Craft::t('lens', 'Job re-queued successfully.'));
        } else {
            Craft::$app->getSession()->setError(Craft::t('lens', 'Unable to retry this job.'));
        }

        return $this->redirect('lens/logs');
    }

    public function actionDeleteAll(): Response
    {
        if (!Plugin::isDevInstall()) {
            throw new ForbiddenHttpException('Logs are only available in development mode.');
        }

        $this->requireCpRequest();
        $this->requirePermission('accessPlugin-lens');
        $this->requirePostRequest();

        $deleted = Plugin::getInstance()->log->deleteAll();
        Craft::$app->getSession()->setNotice(
            Craft::t('lens', '{count} log entries deleted.', ['count' => $deleted])
        );

        return $this->redirect('lens/logs');
    }
}
