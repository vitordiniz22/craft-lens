<?php

declare(strict_types=1);

namespace vitordiniz22\craftlens\enums;

/**
 * Identifier for the bulk page tab/view. Used both as the URL `focus` query
 * param and as the active-tab marker passed to templates. Distinct from
 * `BulkSessionMode` because multiple modes (regular bulk, retry) share the
 * same default view.
 */
enum BulkPageView: string
{
    case Default = 'bulk';
    case ProMetadata = 'pro-metadata';
}
