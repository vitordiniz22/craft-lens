<?php

declare(strict_types=1);

namespace vitordiniz22\craftlenstests\_support\Helpers;

use Craft;
use craft\helpers\StringHelper;
use vitordiniz22\craftlens\records\AssetAnalysisRecord;

/**
 * Shared fixture helpers for integration tests that need to create
 * AssetAnalysisRecord rows (plus their backing elements/elements_sites rows).
 *
 * Tests using this trait should call cleanupAnalysisRecords() in _after()
 * to remove the elements created during the test.
 */
trait AnalysisRecordFixtures
{
    /** @var int[] IDs of elements created via createAnalysisRecord(). */
    protected array $createdElementIds = [];

    /**
     * Create an AssetAnalysisRecord along with the minimal elements and
     * elements_sites rows required to satisfy FK expectations. FK checks
     * are disabled during insert since we do not create the full asset row.
     *
     * @param array<string, mixed> $overrides Extra fields to set on the record before save.
     */
    protected function createAnalysisRecord(string $status, array $overrides = []): AssetAnalysisRecord
    {
        $db = Craft::$app->getDb();
        $db->createCommand('SET FOREIGN_KEY_CHECKS=0')->execute();

        try {
            $db->createCommand()->insert('{{%elements}}', [
                'type' => 'craft\\elements\\Asset',
                'enabled' => true,
                'dateCreated' => date('Y-m-d H:i:s'),
                'dateUpdated' => date('Y-m-d H:i:s'),
                'uid' => StringHelper::UUID(),
            ])->execute();

            $elementId = (int) $db->getLastInsertID();
            $this->createdElementIds[] = $elementId;

            $primarySite = Craft::$app->getSites()->getPrimarySite();
            $db->createCommand()->insert('{{%elements_sites}}', [
                'elementId' => $elementId,
                'siteId' => $primarySite->id,
                'slug' => 'test-asset-' . $elementId,
                'uri' => null,
                'enabled' => true,
                'dateCreated' => date('Y-m-d H:i:s'),
                'dateUpdated' => date('Y-m-d H:i:s'),
                'uid' => StringHelper::UUID(),
            ])->execute();

            $record = new AssetAnalysisRecord();
            $record->assetId = $elementId;
            $record->status = $status;

            foreach ($overrides as $key => $value) {
                $record->{$key} = $value;
            }

            $record->save(false);

            return $record;
        } finally {
            $db->createCommand('SET FOREIGN_KEY_CHECKS=1')->execute();
        }
    }

    /**
     * Backdate the dateUpdated column on an analysis record so "stuck" queries
     * pick it up. Subtracts one extra second to avoid SQL `<` boundary races.
     */
    protected function backdateAnalysisRecord(AssetAnalysisRecord $record, int $minutesAgo): void
    {
        $backdated = date('Y-m-d H:i:s', time() - ($minutesAgo * 60) - 1);

        Craft::$app->getDb()
            ->createCommand()
            ->update(
                '{{%lens_asset_analyses}}',
                ['dateUpdated' => $backdated],
                ['id' => $record->id],
            )
            ->execute();
    }

    /**
     * Delete all analysis records and their backing elements created through
     * createAnalysisRecord() during this test.
     */
    protected function cleanupAnalysisRecords(): void
    {
        $db = Craft::$app->getDb();

        $db->createCommand('SET FOREIGN_KEY_CHECKS=0')->execute();

        try {
            $db->createCommand()
                ->delete('{{%lens_asset_analyses}}')
                ->execute();

            if (!empty($this->createdElementIds)) {
                $db->createCommand()
                    ->delete('{{%elements_sites}}', ['elementId' => $this->createdElementIds])
                    ->execute();

                $db->createCommand()
                    ->delete('{{%elements}}', ['id' => $this->createdElementIds])
                    ->execute();
            }

            $this->createdElementIds = [];
        } finally {
            $db->createCommand('SET FOREIGN_KEY_CHECKS=1')->execute();
        }
    }
}
