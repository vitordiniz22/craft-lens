<?php

declare(strict_types=1);

namespace vitordiniz22\craftlens\console\controllers;

use Craft;
use craft\console\Controller;
use craft\db\Query;
use craft\elements\Asset;
use craft\helpers\Console;
use vitordiniz22\craftlens\helpers\AnalysisImageContext;
use vitordiniz22\craftlens\helpers\ImageMetricsAnalyzer;
use vitordiniz22\craftlens\migrations\Install;
use yii\console\ExitCode;

/**
 * Diagnostic helper for sharpness-metric calibration. Runs the
 * Imagick-only metrics on every image asset in a volume (no AI calls,
 * no DB writes to the analysis record) and emits one JSON line per
 * asset carrying every intermediate value that drives the sharpness
 * verdict, sourced from the structured log emission inside
 * `ImageMetricsAnalyzer::measureSharpness()`.
 *
 * Usage: `php craft lens/sharpness/profile <volumeHandle>`
 */
class SharpnessController extends Controller
{
    public $defaultAction = 'profile';

    /**
     * Profile every image asset in a volume. Output is JSON Lines on stdout
     * with the assetId, filename, dimensions, raw metric inputs, and
     * post-sigmoid sharpness score.
     */
    public function actionProfile(string $volumeHandle): int
    {
        $volume = Craft::$app->getVolumes()->getVolumeByHandle($volumeHandle);

        if ($volume === null) {
            $this->stderr("Volume '{$volumeHandle}' not found.\n", Console::FG_RED);
            return ExitCode::USAGE;
        }

        $assets = Asset::find()
            ->volume($volume)
            ->kind(Asset::KIND_IMAGE)
            ->limit(null)
            ->all();

        if (empty($assets)) {
            $this->stderr("No image assets found in volume '{$volumeHandle}'.\n", Console::FG_YELLOW);
            return ExitCode::OK;
        }

        $startUtc = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');

        $assetIds = [];
        $errors = [];
        foreach ($assets as $asset) {
            try {
                $context = new AnalysisImageContext($asset);
                ImageMetricsAnalyzer::analyze($context);
                unset($context);
                $assetIds[] = $asset->id;
            } catch (\Throwable $e) {
                $errors[$asset->id] = $e->getMessage();
            }
        }

        $logs = (new Query())
            ->select(['assetId', 'message', 'context', 'dateCreated'])
            ->from(Install::TABLE_LOGS)
            ->where([
                'category' => 'asset_processing',
                'assetId' => $assetIds,
            ])
            ->andWhere(['like', 'message', 'Sharpness'])
            ->andWhere(['>=', 'dateCreated', $startUtc])
            ->orderBy(['dateCreated' => SORT_DESC])
            ->all();

        $latestPerAsset = [];
        foreach ($logs as $row) {
            $aid = (int) $row['assetId'];
            if (!isset($latestPerAsset[$aid])) {
                $latestPerAsset[$aid] = $row;
            }
        }

        foreach ($assets as $asset) {
            $row = $latestPerAsset[$asset->id] ?? null;
            $context = is_string($row['context'] ?? null)
                ? json_decode($row['context'], true)
                : ($row['context'] ?? null);

            $payload = [
                'volume' => $volumeHandle,
                'assetId' => $asset->id,
                'filename' => $asset->filename,
                'kind' => $asset->kind,
                'extension' => $asset->getExtension(),
                'logMessage' => $row['message'] ?? null,
                'metrics' => $context,
                'error' => $errors[$asset->id] ?? null,
            ];

            $this->stdout(json_encode($payload, JSON_UNESCAPED_SLASHES) . "\n");
        }

        return ExitCode::OK;
    }
}
