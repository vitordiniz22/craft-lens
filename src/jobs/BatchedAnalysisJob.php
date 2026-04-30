<?php

declare(strict_types=1);

namespace vitordiniz22\craftlens\jobs;

use craft\elements\Asset;
use craft\queue\BaseBatchedJob;
use vitordiniz22\craftlens\helpers\Logger;
use vitordiniz22\craftlens\models\Settings;
use vitordiniz22\craftlens\Plugin;

/**
 * Base for queue jobs that analyze assets in batches with concurrent AI dispatch.
 *
 * Subclasses provide the data source (`loadData()`), per-item filtering
 * (`shouldQueueItem()`), and an optional cancel signal (`getCancelSignal()`).
 * The buffer-and-flush mechanism — accumulate eligible items in `processItem`,
 * flush the buffer concurrently in `afterBatch` — lives here.
 */
abstract class BatchedAnalysisJob extends BaseBatchedJob
{
    /**
     * Assets buffered during the current batch, flushed concurrently in afterBatch().
     *
     * @var Asset[]
     */
    private array $batchBuffer = [];

    public function init(): void
    {
        parent::init();
        $this->batchSize = Settings::BATCH_SIZE;
    }

    /**
     * Whether the buffered item should be flushed to the AI dispatch.
     * Return false to skip an item (already processed, cancelled, etc.).
     */
    abstract protected function shouldQueueItem(Asset $item): bool;

    /**
     * Optional per-flush cancel signal passed through to `processAssetsConcurrent`.
     * Return null when the job has no cancellation concept.
     *
     * @return (callable(): bool)|null
     */
    abstract protected function getCancelSignal(): ?callable;

    protected function beforeBatch(): void
    {
        $this->batchBuffer = [];
    }

    protected function processItem(mixed $item): void
    {
        /** @var Asset $item */
        if (!$this->shouldQueueItem($item)) {
            return;
        }

        $this->batchBuffer[] = $item;
    }

    protected function afterBatch(): void
    {
        if (empty($this->batchBuffer)) {
            return;
        }

        $batch = $this->batchBuffer;
        $this->batchBuffer = [];

        $concurrency = Plugin::getInstance()->getSettings()->bulkConcurrency;

        try {
            $this->dispatch($batch, $concurrency, $this->getCancelSignal());
        } catch (\Throwable $e) {
            // The dispatch entry point already routes per-asset failures
            // through its own handler. We only land here on a fatal config
            // error (e.g. invalid credentials). Log once for the batch.
            Logger::jobFailure(
                jobType: static::class,
                message: sprintf('Batch flush failed (%d assets): %s', count($batch), $e->getMessage()),
                exception: $e,
            );
        }
    }

    /**
     * Hand the buffered batch to the appropriate concurrent service entry point.
     *
     * Default: full analysis via `processAssetsConcurrent()`. Subclasses
     * override to route to a different entry (e.g. `processAssetsForProCompletion`).
     *
     * @param Asset[] $batch
     */
    protected function dispatch(array $batch, int $concurrency, ?callable $cancelSignal): void
    {
        Plugin::getInstance()->assetAnalysis->processAssetsConcurrent($batch, $concurrency, $cancelSignal);
    }
}
