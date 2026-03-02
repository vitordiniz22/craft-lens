<?php

declare(strict_types=1);

namespace vitordiniz22\craftlens\web\assets\lens;

use craft\web\AssetBundle;

class LensLogsAsset extends AssetBundle
{
    public function init(): void
    {
        $this->sourcePath = __DIR__ . '/dist';
        $this->depends = [LensAsset::class];
        $this->css = ['css/pages/lens-logs.css'];
        $this->js = ['js/pages/lens-logs.js'];

        parent::init();
    }
}
