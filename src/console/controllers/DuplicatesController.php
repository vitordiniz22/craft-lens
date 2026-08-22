<?php

declare(strict_types=1);

namespace vitordiniz22\craftlens\console\controllers;

use Craft;
use craft\console\Controller;
use craft\db\Query;
use craft\elements\Asset;
use craft\helpers\Console;
use vitordiniz22\craftlens\helpers\AnalysisImageContext;
use vitordiniz22\craftlens\helpers\DuplicateSupport;
use vitordiniz22\craftlens\helpers\PerceptualHashHelper;
use vitordiniz22\craftlens\Plugin;
use vitordiniz22\craftlens\records\AssetAnalysisRecord;
use vitordiniz22\craftlens\records\DuplicateGroupRecord;
use yii\console\ExitCode;

/**
 * Maintains the perceptual hashes duplicate detection compares.
 */
class DuplicatesController extends Controller
{
    public $defaultAction = 'rebuild-hashes';

    /**
     * Recomputes every stored perceptual hash and rescans for duplicates.
     *
     * Hashes only compare when produced the same way, so an install carrying
     * hashes from an older release needs one pass. No AI calls, safe to
     * repeat, and resolved pairs survive unless their hash moved.
     *
     * Example: php craft lens/duplicates/rebuild-hashes
     */
    public function actionRebuildHashes(): int
    {
        if (!DuplicateSupport::isAvailable()) {
            $this->stderr("Duplicate detection needs the Imagick extension.\n", Console::FG_RED);

            return ExitCode::UNAVAILABLE;
        }

        $rows = (new Query())
            ->select(['assetId', 'perceptualHash'])
            ->from(AssetAnalysisRecord::tableName())
            ->orderBy(['assetId' => SORT_ASC]);

        $total = (int) $rows->count();

        if ($total === 0) {
            $this->stdout("No analyses to rehash.\n");

            return ExitCode::OK;
        }

        $this->stdout("Rehashing {$total} assets...\n");
        Console::startProgress(0, $total);

        $processed = 0;
        $changed = 0;
        $skipped = 0;

        foreach ($rows->batch(100) as $batch) {
            foreach ($batch as $row) {
                $processed++;
                Console::updateProgress($processed, $total);

                $hash = $this->hashAsset((int) $row['assetId']);

                if ($hash === null) {
                    $skipped++;
                    continue;
                }

                if ($hash === $row['perceptualHash']) {
                    continue;
                }

                $this->storeHash((int) $row['assetId'], $hash);
                $changed++;
            }
        }

        Console::clearLine();
        $this->stdout("Rehashed {$processed} assets: {$changed} changed, {$skipped} skipped.\n");

        if ($changed === 0) {
            return ExitCode::OK;
        }

        $this->stdout("Rescanning for duplicates...\n");
        $pairs = Plugin::getInstance()->duplicateDetection->runFullScan();

        $this->stdout("Done! ", Console::FG_GREEN);
        $this->stdout("{$pairs} new duplicate pairs.\n");

        return ExitCode::OK;
    }

    private function hashAsset(int $assetId): ?string
    {
        $asset = Asset::find()->id($assetId)->one();

        if ($asset === null || $asset->kind !== Asset::KIND_IMAGE) {
            return null;
        }

        try {
            $context = new AnalysisImageContext($asset);
            $imagick = $context->getWorkingImagick();

            if ($imagick !== null) {
                return PerceptualHashHelper::computeFromImagick($imagick);
            }

            return null;
        } catch (\Throwable) {
            return null;
        }
    }

    /** Drops this asset pairs too, since they came from the old hash. */
    private function storeHash(int $assetId, string $hash): void
    {
        Craft::$app->getDb()->createCommand()
            ->update(
                AssetAnalysisRecord::tableName(),
                ['perceptualHash' => $hash],
                ['assetId' => $assetId],
            )
            ->execute();

        DuplicateGroupRecord::deleteAll([
            'or',
            ['canonicalAssetId' => $assetId],
            ['duplicateAssetId' => $assetId],
        ]);
    }
}
