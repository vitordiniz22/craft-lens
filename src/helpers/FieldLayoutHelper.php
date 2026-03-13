<?php

declare(strict_types=1);

namespace vitordiniz22\craftlens\helpers;

use craft\elements\Asset;
use craft\fieldlayoutelements\assets\AltField;
use vitordiniz22\craftlens\fieldlayoutelements\LensAnalysisElement;

/**
 * Helpers for inspecting an asset's field layout.
 */
final class FieldLayoutHelper
{
    public static function hasAnalysisElement(Asset $asset): bool
    {
        return self::contains($asset, LensAnalysisElement::class);
    }

    public static function hasAltField(Asset $asset): bool
    {
        return self::contains($asset, AltField::class);
    }

    private static function contains(Asset $asset, string $className): bool
    {
        $fieldLayout = $asset->getFieldLayout();

        if ($fieldLayout === null) {
            return false;
        }

        foreach ($fieldLayout->getTabs() as $tab) {
            foreach ($tab->getElements() as $element) {
                if ($element instanceof $className) {
                    return true;
                }
            }
        }

        return false;
    }
}
