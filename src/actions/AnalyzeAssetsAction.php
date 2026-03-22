<?php

declare(strict_types=1);

namespace vitordiniz22\craftlens\actions;

use Craft;
use craft\base\ElementAction;
use craft\elements\Asset;
use craft\elements\db\ElementQueryInterface;
use craft\helpers\Queue;
use vitordiniz22\craftlens\jobs\AnalyzeSelectedAssetsJob;
use vitordiniz22\craftlens\Plugin;

/**
 * Element action to queue selected assets for AI analysis.
 */
class AnalyzeAssetsAction extends ElementAction
{
    public static function displayName(): string
    {
        return Craft::t('lens', 'Analyze with Lens');
    }

    public function performAction(ElementQueryInterface $query): bool
    {
        $plugin = Plugin::getInstance();

        if (!$plugin->setupStatus->isAiProviderConfigured()) {
            $this->setMessage(
                Craft::t('lens', 'Please configure an AI provider before analyzing assets.')
            );
            return false;
        }

        $service = $plugin->assetAnalysis;
        $eligibleIds = [];
        $skipped = 0;

        /** @var Asset $asset */
        foreach ($query->all() as $asset) {
            if ($service->shouldProcessForReplace($asset)) {
                $eligibleIds[] = $asset->id;
            } else {
                $skipped++;
            }
        }

        if (empty($eligibleIds)) {
            $this->setMessage(
                Craft::t('lens', 'No eligible assets to analyze.')
            );
            return true;
        }

        Queue::push(new AnalyzeSelectedAssetsJob([
            'assetIds' => $eligibleIds,
        ]));

        $queued = count($eligibleIds);

        if ($skipped > 0) {
            $this->setMessage(
                Craft::t('lens', '{queued} assets queued for analysis. {skipped} skipped (not an image or volume not enabled).', [
                    'queued' => $queued,
                    'skipped' => $skipped,
                ])
            );
        } else {
            $this->setMessage(
                Craft::t('lens', '{count} assets queued for analysis.', ['count' => $queued])
            );
        }

        return true;
    }
}
