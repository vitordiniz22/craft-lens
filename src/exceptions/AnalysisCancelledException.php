<?php

declare(strict_types=1);

namespace vitordiniz22\craftlens\exceptions;

/**
 * Thrown when a running analysis job detects that it has been cancelled.
 *
 * Distinguishes cancellation from actual failures so the job is not
 * marked as failed (FR-011).
 */
class AnalysisCancelledException extends \RuntimeException
{
}
