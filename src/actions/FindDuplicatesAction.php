<?php

declare(strict_types=1);

namespace vitordiniz22\craftlens\actions;

use Craft;
use craft\base\ElementAction;
use craft\elements\db\ElementQueryInterface;
use vitordiniz22\craftlens\Plugin;

/**
 * Element action to find duplicates for selected assets.
 */
class FindDuplicatesAction extends ElementAction
{
    public static function displayName(): string
    {
        return Craft::t('lens', 'Find Duplicates');
    }

    public function performAction(ElementQueryInterface $query): bool
    {
        $service = Plugin::getInstance()->duplicateDetection;
        $assetIds = $query->ids();
        $count = $service->findDuplicatesForAssets($assetIds);

        $this->setMessage(
            Craft::t('lens', '{count} duplicate pairs found.', ['count' => $count])
        );

        return true;
    }
}
