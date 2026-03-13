<?php

declare(strict_types=1);

namespace vitordiniz22\craftlens\controllers;

use Craft;
use craft\elements\Asset;
use craft\web\Controller;
use vitordiniz22\craftlens\enums\LogCategory;
use vitordiniz22\craftlens\helpers\Logger;
use vitordiniz22\craftlens\Plugin;
use yii\web\BadRequestHttpException;
use yii\web\Response;

/**
 * AJAX endpoint for the Lens semantic asset selector field.
 * Searches assets using Lens analysis metadata (alt text, descriptions, tags, OCR text).
 */
class SemanticSearchController extends Controller
{
    protected array|int|bool $allowAnonymous = false;

    /**
     * Search assets using Lens metadata.
     *
     * POST lens/semantic-search/search
     * Body: { query: string, limit?: int }
     * Returns: { assetIds: int[], total: int }
     */
    public function actionSearch(): Response
    {
        $this->requireCpRequest();
        $this->requirePostRequest();
        $this->requireAcceptsJson();
        $this->requirePermission('accessPlugin-lens');
        Plugin::getInstance()->requireProEdition();

        if (!Plugin::getInstance()->getSettings()->enableSemanticSearch) {
            throw new BadRequestHttpException('Semantic search is disabled.');
        }

        $request = Craft::$app->getRequest();
        $query = trim((string) $request->getBodyParam('query', ''));
        $limit = min(100, max(1, (int) $request->getBodyParam('limit', 50)));

        if ($query === '') {
            return $this->asJson([
                'assetIds' => [],
                'total' => 0,
            ]);
        }

        $plugin = Plugin::getInstance();

        try {
            $results = $plugin->search->search([
                'query' => $query,
                'limit' => $limit,
            ]);
        } catch (\Throwable $e) {
            Logger::error(LogCategory::AssetProcessing, 'Semantic search failed: ' . $e->getMessage());

            return $this->asJson([
                'assetIds' => [],
                'total' => 0,
            ]);
        }

        $assetIds = array_map(fn(Asset $a) => $a->id, $results['assets']);

        return $this->asJson([
            'assetIds' => $assetIds,
            'total' => $results['total'],
        ]);
    }
}
