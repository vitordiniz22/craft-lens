<?php

declare(strict_types=1);

namespace vitordiniz22\craftlens\twig;

use Twig\Extension\AbstractExtension;
use Twig\Extension\GlobalsInterface;

/**
 * Twig extension that registers the `lens` global variable.
 */
class LensTwigExtension extends AbstractExtension implements GlobalsInterface
{
    public function getGlobals(): array
    {
        return [
            'lens' => new LensTwigGlobal(),
        ];
    }
}
