<?php

declare(strict_types=1);

namespace vitordiniz22\craftlens\web\assets\lens;

use craft\web\AssetBundle;

class LensSearchAsset extends AssetBundle
{
    public function init(): void
    {
        $this->sourcePath = __DIR__ . '/dist';
        $this->depends = [LensAsset::class];
        $this->js = ['js/lens-search.js'];

        parent::init();
    }
}
