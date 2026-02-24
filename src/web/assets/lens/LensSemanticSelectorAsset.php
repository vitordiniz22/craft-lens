<?php

declare(strict_types=1);

namespace vitordiniz22\craftlens\web\assets\lens;

use craft\web\AssetBundle;
use craft\web\assets\cp\CpAsset;

/**
 * Asset bundle for the Lens semantic asset search.
 * Patches Craft.AssetSelectInput.prototype to replace native search
 * with Lens semantic search in all asset selector modals.
 *
 * Registered globally on all CP pages when the setting is enabled.
 * Depends on CpAsset (not LensAsset) since it loads outside Lens CP pages.
 */
class LensSemanticSelectorAsset extends AssetBundle
{
    public function init(): void
    {
        $this->sourcePath = __DIR__ . '/dist';
        $this->depends = [CpAsset::class];
        $this->css = [
            'css/components/semantic-search.css',
        ];
        $this->js = [
            'js/components/lens-semantic-selector.js',
        ];

        parent::init();
    }
}
