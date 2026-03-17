<?php

declare(strict_types=1);

namespace vitordiniz22\craftlens\controllers;

use craft\web\Controller;
use vitordiniz22\craftlens\enums\LogCategory;
use vitordiniz22\craftlens\helpers\Logger;
use vitordiniz22\craftlens\Plugin;
use yii\web\Response;

/**
 * Controller for the Lens Dashboard.
 */
class DashboardController extends Controller
{
    protected array|int|bool $allowAnonymous = false;

    /**
     * Lightweight AJAX endpoint for dashboard processing status polling.
     */
    public function actionProcessingStatus(): Response
    {
        $this->requireCpRequest();
        $this->requirePermission('accessPlugin-lens');
        $this->requireAcceptsJson();

        $bulkStatus = Plugin::getInstance()->bulkProcessingStatus;
        $stats = $bulkStatus->getStats();

        $total = $stats['totalImages'];
        $unprocessed = $stats['unprocessed'];
        $processed = max(0, $total - $unprocessed);
        $percentComplete = $total > 0 ? min(100, round(($processed / $total) * 100)) : 0;

        return $this->asJson([
            'success' => true,
            'processing' => $stats['processing'] ?? 0,
            'total' => number_format($total),
            'completed' => number_format($processed),
            'percentComplete' => $percentComplete,
        ]);
    }

    public function actionIndex(): Response
    {
        $this->requireCpRequest();
        $this->requirePermission('accessPlugin-lens');

        $plugin = Plugin::getInstance();

        try {
            $stats = $plugin->statistics;
            $overviewStats = $stats->getOverviewStats();

            // Get processing status for Section 2
            $bulkStatus = $plugin->bulkProcessingStatus;
            $processingStatus = $bulkStatus->getStatus();
            $processingStats = $bulkStatus->getStats();

            return $this->renderTemplate('lens/_dashboard/index', [
                // Empty state context
                'isAllVolumesMode' => in_array('*', $plugin->getSettings()->enabledVolumes, true),

                // Setup status
                'setupStatus' => $plugin->setupStatus->getSetupStatus(),
                'criticalIssues' => $plugin->setupStatus->getCriticalIssues(),
                'setupWarnings' => $plugin->setupStatus->getWarnings(),
                'unresolvedIssues' => $plugin->setupStatus->getUnresolvedIssues(),
                'hasCriticalIssues' => $plugin->setupStatus->hasCriticalIssues(),
                'hasUnresolvedIssues' => $plugin->setupStatus->hasUnresolvedIssues(),

                // Section 1: Needs Attention
                'attentionItems' => $stats->getAttentionItems($overviewStats),

                // Section 2: Processing Status
                'processingState' => $processingStatus['state'],
                'processingStats' => $processingStats,
                'processingProgress' => $processingStatus['progress'] ?? null,

                // Section 3: Metadata Coverage
                'altTextCoverage' => $stats->getAltTextCoverage(),
                'taggedPercentage' => $stats->getTaggedPercentage(),
                'focalPointCoverage' => $stats->getFocalPointCoverage(),

                // Section 4: Quick Insights
                'topTags' => $stats->getTopTags(10),
                'dominantColors' => $stats->getDominantColors(5),

                // Section 5: Recent Activity
                'recentActivity' => $stats->getRecentActivity(10),

                // Section 6: Usage
                'monthlyUsage' => $stats->getMonthlyUsageSummary(),
                'lastMonthUsage' => $stats->getLastMonthUsageSummary(),
                'monthlyHistory' => $stats->getMonthlyUsageHistory(3),
                'allTimeUsage' => $stats->getAllTimeUsage(),
                'costProjection' => $stats->getCostProjection(
                    $overviewStats['unprocessed'],
                    $overviewStats['avgCostPerAsset'],
                ),
                'tokenUsage' => $stats->getTokenUsage(),
                'providerBreakdown' => $stats->getProviderBreakdown(),
                'currentModel' => $plugin->getSettings()->getCurrentModel(),
            ]);
        } catch (\Throwable $e) {
            Logger::error(LogCategory::AssetProcessing, 'Dashboard data load failed', exception: $e);
            throw $e;
        }
    }
}
