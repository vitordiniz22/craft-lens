<?php

declare(strict_types=1);

namespace vitordiniz22\craftlens\web\assets\lens;

use craft\web\AssetBundle;
use craft\web\assets\cp\CpAsset;

/**
 * Base asset bundle for the Lens plugin.
 * Loads shared utilities, dismissible notices, and all CSS.
 */
class LensAsset extends AssetBundle
{
    public function init(): void
    {
        $this->sourcePath = __DIR__ . '/dist';
        $this->depends = [CpAsset::class];
        $this->css = [
            'css/lens.css',
            'css/components/badge.css',
            'css/components/swatch.css',
            'css/components/card.css',
            'css/components/progress-bar.css',
            'css/components/stat-card.css',
            'css/components/alert-section.css',
            'css/components/inline-notice.css',
            'css/components/empty-state.css',
            'css/components/setup-banner.css',
            'css/components/stats-grid.css',
            'css/lens-analysis.css',
            'css/lens-statistics.css',
            'css/lens-widgets.css',
            'css/lens-search.css',
            'css/lens-bulk.css',
            'css/lens-review.css',
            'css/lens-dashboard.css',
        ];
        $this->js = [
            'js/lens-base.js',
            'js/lens-config.js',
            'js/core/lens-utils.js',
            'js/core/lens-dom.js',
            'js/core/lens-button-state.js',
            'js/core/lens-api.js',
            'js/services/lens-people-detection-service.js',
            'js/services/lens-taxonomy-service.js',
            'js/services/lens-asset-processing-service.js',
            'js/components/lens-dismissible-notices.js',
        ];

        parent::init();
    }
}
