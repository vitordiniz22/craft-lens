<?php

declare(strict_types=1);

namespace vitordiniz22\craftlens\web\assets\lens;

use craft\web\AssetBundle;
use craft\web\assets\cp\CpAsset;

/**
 * Loaded on Craft's native assets index page. Honors `?source=lens:all`
 * + `?lensFilter=<key>` + `?search=<text>` deep links from Lens notices
 * and tag chips.
 */
class LensAssetIndexAsset extends AssetBundle
{
    public function init(): void
    {
        $this->sourcePath = __DIR__ . '/dist';
        $this->depends = [CpAsset::class];
        $this->css = [
            'css/components/asset-index-filter-chip.css',
        ];
        $this->js = [
            'js/components/lens-asset-index.js',
        ];

        parent::init();
    }
}
