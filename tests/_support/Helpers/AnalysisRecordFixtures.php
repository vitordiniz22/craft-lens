<?php

declare(strict_types=1);

namespace vitordiniz22\craftlenstests\_support\Helpers;

use Craft;
use craft\fs\Local;
use craft\helpers\StringHelper;
use craft\models\FieldLayout;
use craft\models\FieldLayoutTab;
use craft\models\Volume;
use vitordiniz22\craftlens\fieldlayoutelements\LensAnalysisElement;
use vitordiniz22\craftlens\jobs\BulkAnalyzeAssetsJob;
use vitordiniz22\craftlens\migrations\Install;
use vitordiniz22\craftlens\records\AssetAnalysisRecord;
use yii\db\Query;

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

    /** @var int[] IDs of volumes created via createTestVolume(). */
    protected array $createdVolumeIds = [];

    /** @var array{volumeId:int, folderId:int}|null Cached behavior-test volume and root folder. */
    protected ?array $behaviorTestVolume = null;

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
            // Truncate lens child tables first so leftover rows from prior runs
            // never interfere with EXISTS-based filters in other tests.
            $db->createCommand()->delete(Install::TABLE_ASSET_TAGS)->execute();
            $db->createCommand()->delete(Install::TABLE_DUPLICATE_GROUPS)->execute();
            $db->createCommand()->delete(Install::TABLE_ASSET_ANALYSES)->execute();

            if (!empty($this->createdElementIds)) {
                $db->createCommand()
                    ->delete('{{%assets}}', ['id' => $this->createdElementIds])
                    ->execute();

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

        if (!empty($this->createdVolumeIds)) {
            $volumesService = Craft::$app->getVolumes();
            foreach ($this->createdVolumeIds as $volumeId) {
                $volumesService->deleteVolumeById($volumeId);
            }
            $this->createdVolumeIds = [];
        }

        $this->behaviorTestVolume = null;
    }

    /**
     * Lazy-create a volume for AssetQueryBehavior integration tests and return
     * its id plus its root-folder id. Uses raw SQL inserts so the rows live
     * entirely inside the test's DB transaction (rolled back at end of test)
     * and never touch project config. Going through Craft's Volumes service
     * accumulates stale volume entries in project config across tests which
     * eventually breaks Craft's test bootstrap.
     *
     * @return array{volumeId:int, folderId:int}
     */
    protected function ensureTestAssetVolume(): array
    {
        if ($this->behaviorTestVolume !== null) {
            return $this->behaviorTestVolume;
        }

        $db = Craft::$app->getDb();
        $now = date('Y-m-d H:i:s');

        $db->createCommand()->insert('{{%volumes}}', [
            'name' => 'Lens Test',
            'handle' => 'lenstest',
            'fs' => 'lenstestfs',
            'titleTranslationMethod' => 'site',
            'altTranslationMethod' => 'site',
            'sortOrder' => 9999,
            'dateCreated' => $now,
            'dateUpdated' => $now,
            'uid' => StringHelper::UUID(),
        ])->execute();
        $volumeId = (int) $db->getLastInsertID();

        $db->createCommand()->insert('{{%volumefolders}}', [
            'parentId' => null,
            'volumeId' => $volumeId,
            'name' => 'Lens Test',
            'path' => '',
            'dateCreated' => $now,
            'dateUpdated' => $now,
            'uid' => StringHelper::UUID(),
        ])->execute();
        $folderId = (int) $db->getLastInsertID();

        $this->behaviorTestVolume = ['volumeId' => $volumeId, 'folderId' => $folderId];
        return $this->behaviorTestVolume;
    }

    /**
     * Create a full asset fixture: elements + elements_sites + assets + lens_asset_analyses.
     * Returns the analysis record (which carries assetId equal to the element id).
     *
     * FK checks are disabled during insert because we bypass Craft's element
     * persistence to keep fixtures fast and deterministic.
     *
     * @param array<string, mixed> $analysisOverrides Extra fields for the AssetAnalysisRecord.
     * @param array<string, mixed> $assetOverrides Extra fields for the assets row (size, focalPoint, width, height, kind).
     */
    protected function createAssetFixture(
        string $filename,
        array $analysisOverrides = [],
        array $assetOverrides = [],
        string $analysisStatus = 'completed',
    ): AssetAnalysisRecord {
        $volume = $this->ensureTestAssetVolume();
        $db = Craft::$app->getDb();
        $now = date('Y-m-d H:i:s');

        $db->createCommand('SET FOREIGN_KEY_CHECKS=0')->execute();

        try {
            $db->createCommand()->insert('{{%elements}}', [
                'type' => 'craft\\elements\\Asset',
                'enabled' => true,
                'dateCreated' => $now,
                'dateUpdated' => $now,
                'uid' => StringHelper::UUID(),
            ])->execute();

            $elementId = (int) $db->getLastInsertID();
            $this->createdElementIds[] = $elementId;

            $primarySite = Craft::$app->getSites()->getPrimarySite();
            $db->createCommand()->insert('{{%elements_sites}}', [
                'elementId' => $elementId,
                'siteId' => $primarySite->id,
                'slug' => 'lens-fixture-' . $elementId,
                'uri' => null,
                'enabled' => true,
                'dateCreated' => $now,
                'dateUpdated' => $now,
                'uid' => StringHelper::UUID(),
            ])->execute();

            $assetRow = array_merge([
                'id' => $elementId,
                'volumeId' => $volume['volumeId'],
                'folderId' => $volume['folderId'],
                'uploaderId' => null,
                'filename' => $filename,
                'mimeType' => 'image/jpeg',
                'kind' => 'image',
                'alt' => null,
                'width' => 640,
                'height' => 480,
                'size' => 50_000,
                'focalPoint' => null,
                'deletedWithVolume' => false,
                'dateModified' => $now,
                'dateCreated' => $now,
                'dateUpdated' => $now,
            ], $assetOverrides);

            $db->createCommand()->insert('{{%assets}}', $assetRow)->execute();

            // Extract JSON-column overrides and apply them via raw UPDATE
            // after save(). ActiveRecord's JSON auto-serialization doesn't
            // produce the text form the behavior's LIKE patterns expect, so
            // we write the literal JSON bytes ourselves.
            $jsonColumns = ['detectedBrands', 'watermarkDetails', 'nsfwCategories', 'extractedTextAi'];
            $jsonOverrides = [];
            foreach ($jsonColumns as $col) {
                if (array_key_exists($col, $analysisOverrides)) {
                    $jsonOverrides[$col] = $analysisOverrides[$col];
                    unset($analysisOverrides[$col]);
                }
            }

            $record = new AssetAnalysisRecord();
            $record->assetId = $elementId;
            $record->status = $analysisStatus;

            foreach ($analysisOverrides as $key => $value) {
                $record->{$key} = $value;
            }

            $record->save(false);

            if (!empty($jsonOverrides)) {
                // Raw parameterized UPDATE. Bind the pre-encoded JSON string as
                // a plain PDO string param so Yii doesn't re-serialize the value
                // for the `json` column type (which silently double-encodes).
                $table = Install::TABLE_ASSET_ANALYSES;
                foreach ($jsonOverrides as $col => $value) {
                    $json = $value === null ? null : json_encode($value);
                    $sql = 'UPDATE ' . $db->quoteTableName($table)
                        . ' SET ' . $db->quoteColumnName($col) . ' = :val'
                        . ' WHERE ' . $db->quoteColumnName('id') . ' = :id';
                    $db->createCommand($sql, [':val' => $json, ':id' => $record->id])->execute();
                }

                $record->refresh();
            }

            return $record;
        } finally {
            $db->createCommand('SET FOREIGN_KEY_CHECKS=1')->execute();
        }
    }

    /**
     * Create an asset fixture without any analysis record. Used to exercise
     * lensStatus('untagged') which relies on LEFT JOIN and matches assets
     * with no lens row at all.
     *
     * @param array<string, mixed> $assetOverrides
     */
    protected function createAssetWithoutAnalysis(string $filename, array $assetOverrides = []): int
    {
        $volume = $this->ensureTestAssetVolume();
        $db = Craft::$app->getDb();
        $now = date('Y-m-d H:i:s');

        $db->createCommand('SET FOREIGN_KEY_CHECKS=0')->execute();

        try {
            $db->createCommand()->insert('{{%elements}}', [
                'type' => 'craft\\elements\\Asset',
                'enabled' => true,
                'dateCreated' => $now,
                'dateUpdated' => $now,
                'uid' => StringHelper::UUID(),
            ])->execute();

            $elementId = (int) $db->getLastInsertID();
            $this->createdElementIds[] = $elementId;

            $primarySite = Craft::$app->getSites()->getPrimarySite();
            $db->createCommand()->insert('{{%elements_sites}}', [
                'elementId' => $elementId,
                'siteId' => $primarySite->id,
                'slug' => 'lens-fixture-' . $elementId,
                'uri' => null,
                'enabled' => true,
                'dateCreated' => $now,
                'dateUpdated' => $now,
                'uid' => StringHelper::UUID(),
            ])->execute();

            $assetRow = array_merge([
                'id' => $elementId,
                'volumeId' => $volume['volumeId'],
                'folderId' => $volume['folderId'],
                'uploaderId' => null,
                'filename' => $filename,
                'mimeType' => 'image/jpeg',
                'kind' => 'image',
                'alt' => null,
                'width' => 640,
                'height' => 480,
                'size' => 50_000,
                'focalPoint' => null,
                'deletedWithVolume' => false,
                'dateModified' => $now,
                'dateCreated' => $now,
                'dateUpdated' => $now,
            ], $assetOverrides);

            $db->createCommand()->insert('{{%assets}}', $assetRow)->execute();

            return $elementId;
        } finally {
            $db->createCommand('SET FOREIGN_KEY_CHECKS=1')->execute();
        }
    }

    /**
     * Insert a lens_asset_tags row.
     */
    protected function createTagRow(
        int $analysisId,
        int $assetId,
        string $tag,
        float $confidence = 0.9,
        bool $isAi = true,
    ): int {
        $now = date('Y-m-d H:i:s');
        Craft::$app->getDb()->createCommand()->insert(Install::TABLE_ASSET_TAGS, [
            'assetId' => $assetId,
            'analysisId' => $analysisId,
            'tag' => $tag,
            'tagNormalized' => strtolower($tag),
            'confidence' => $confidence,
            'isAi' => $isAi,
            'dateCreated' => $now,
            'dateUpdated' => $now,
            'uid' => StringHelper::UUID(),
        ])->execute();

        return (int) Craft::$app->getDb()->getLastInsertID();
    }

    /**
     * Insert a lens_duplicate_groups row. resolution=null means unresolved
     * (the only state that makes lensHasDuplicates(true) match).
     */
    protected function createDuplicateGroup(
        int $canonicalAssetId,
        int $duplicateAssetId,
        ?string $resolution = null,
        int $hammingDistance = 0,
        float $similarity = 0.99,
    ): int {
        $now = date('Y-m-d H:i:s');
        Craft::$app->getDb()->createCommand()->insert(Install::TABLE_DUPLICATE_GROUPS, [
            'canonicalAssetId' => $canonicalAssetId,
            'duplicateAssetId' => $duplicateAssetId,
            'hammingDistance' => $hammingDistance,
            'similarity' => $similarity,
            'resolution' => $resolution,
            'resolvedAt' => $resolution === null ? null : $now,
            'resolvedBy' => null,
            'dateCreated' => $now,
            'dateUpdated' => $now,
            'uid' => StringHelper::UUID(),
        ])->execute();

        return (int) Craft::$app->getDb()->getLastInsertID();
    }

    /**
     * Create a test volume backed by a local filesystem rooted in the system
     * temp directory. Idempotent: the backing filesystem is reused across calls.
     * Returns the created volume's ID.
     */
    protected function createTestVolume(string $handle = 'lenstest', string $name = 'Lens Test'): int
    {
        $fsService = Craft::$app->getFs();
        $fsHandle = 'lenstestfs';

        if ($fsService->getFilesystemByHandle($fsHandle) === null) {
            $fs = $fsService->createFilesystem([
                'type' => Local::class,
                'name' => 'Lens Test FS',
                'handle' => $fsHandle,
                'settings' => ['path' => sys_get_temp_dir() . '/lens-test-volume'],
            ]);
            $fsService->saveFilesystem($fs);
        }

        $volume = new Volume([
            'name' => $name,
            'handle' => $handle,
            'fs' => $fsHandle,
        ]);

        Craft::$app->getVolumes()->saveVolume($volume);

        $this->createdVolumeIds[] = $volume->id;
        return $volume->id;
    }

    /**
     * Attach a LensAnalysisElement to the given volume's field layout so
     * SetupStatusService::isAnalysisPanelConfigured() returns true for it.
     */
    protected function attachLensAnalysisElementToVolume(int $volumeId): void
    {
        $volume = Craft::$app->getVolumes()->getVolumeById($volumeId);

        if ($volume === null) {
            throw new \RuntimeException("Volume {$volumeId} not found");
        }

        $fieldLayout = $volume->getFieldLayout() ?? new FieldLayout(['type' => Volume::class]);

        $tab = new FieldLayoutTab(['name' => 'Lens', 'layout' => $fieldLayout]);
        $tab->setElements([new LensAnalysisElement()]);

        $fieldLayout->setTabs(array_merge($fieldLayout->getTabs(), [$tab]));

        Craft::$app->getFields()->saveLayout($fieldLayout);

        $volume->setFieldLayout($fieldLayout);
        Craft::$app->getVolumes()->saveVolume($volume);
    }

    /**
     * Read the most-recently-queued BulkAnalyzeAssetsJob and return its public
     * properties. Returns null if no matching job is queued.
     *
     * @return array{volumeId: ?int, reprocess: bool}|null
     */
    protected function readLatestBulkAnalyzeJobPayload(): ?array
    {
        $row = (new Query())
            ->select(['job'])
            ->from('{{%queue}}')
            ->where(['like', 'job', BulkAnalyzeAssetsJob::class])
            ->orderBy(['id' => SORT_DESC])
            ->one(Craft::$app->getDb());

        if ($row === null) {
            return null;
        }

        $job = Craft::$app->getQueue()->serializer->unserialize($row['job']);
        return ['volumeId' => $job->volumeId, 'reprocess' => $job->reprocess];
    }

    /**
     * Read all queued BulkAnalyzeAssetsJob payloads in insertion order (oldest first).
     *
     * @return list<array{volumeId: ?int, reprocess: bool}>
     */
    protected function readAllBulkAnalyzeJobPayloads(): array
    {
        $rows = (new Query())
            ->select(['job'])
            ->from('{{%queue}}')
            ->where(['like', 'job', BulkAnalyzeAssetsJob::class])
            ->orderBy(['id' => SORT_ASC])
            ->all(Craft::$app->getDb());

        $serializer = Craft::$app->getQueue()->serializer;
        return array_map(static function (array $row) use ($serializer): array {
            $job = $serializer->unserialize($row['job']);
            return ['volumeId' => $job->volumeId, 'reprocess' => $job->reprocess];
        }, $rows);
    }
}
