<?php

declare(strict_types=1);

namespace vitordiniz22\craftlens\web\assets\lens;

use craft\web\AssetBundle;

class LensAssetActionsAsset extends AssetBundle
{
    public function init(): void
    {
        $this->sourcePath = __DIR__ . '/dist';
        $this->depends = [LensAsset::class];
        $this->js = [
            'js/components/lens-inline-editor.js',
            'js/components/lens-tag-editor.js',
            'js/pages/lens-analysis-panel.js',
        ];

        parent::init();
    }
}
