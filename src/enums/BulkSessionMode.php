<?php

declare(strict_types=1);

namespace vitordiniz22\craftlens\enums;

/**
 * The mode a running bulk processing session represents. Stored on the
 * session payload to distinguish how progress, cost, and cancel handling
 * should behave. `Bulk` and `Retry` are inferred from session shape (volume
 * scope vs scoped asset IDs); `ProCompletion` is set explicitly because its
 * progress and cost tracking diverge from a regular run.
 */
enum BulkSessionMode: string
{
    case Bulk = 'bulk';
    case Retry = 'retry';
    case ProCompletion = 'pro-completion';
}
