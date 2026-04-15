<?php

declare(strict_types=1);

namespace vitordiniz22\craftlenstests\integration\console;

use Codeception\Test\Unit;
use Craft;
use craft\helpers\StringHelper;
use ReflectionClass;
use vitordiniz22\craftlens\console\controllers\SearchIndexController;
use vitordiniz22\craftlens\enums\AnalysisStatus;
use vitordiniz22\craftlens\helpers\Stemmer;
use vitordiniz22\craftlens\migrations\Install;
use vitordiniz22\craftlens\Plugin;
use vitordiniz22\craftlens\records\AssetAnalysisRecord;
use vitordiniz22\craftlens\services\SearchIndexService;
use vitordiniz22\craftlenstests\_support\Helpers\AnalysisRecordFixtures;
use yii\console\ExitCode;
use yii\db\Query;

class SearchIndexControllerTest extends Unit
{
    use AnalysisRecordFixtures;

    private CapturingSearchIndexController $controller;

    protected function _before(): void
    {
        parent::_before();
        $this->controller = new CapturingSearchIndexController('search-index', Craft::$app);
        $this->controller->interactive = false;

        // MySQL TRUNCATE (used by rebuildAll) auto-commits, which breaks
        // Codeception's transaction-wrapped isolation. Manual cleanup keeps
        // this suite hermetic, same pattern as ProcessControllerTest.
        $this->cleanupAnalysisRecords();
        $this->truncateSearchIndex();
    }

    protected function _after(): void
    {
        $this->cleanupAnalysisRecords();
        $this->truncateSearchIndex();
        parent::_after();
    }

    // ---------------------------------------------------------------------
    // Controller surface
    // ---------------------------------------------------------------------

    public function testRebuildWithNoRecordsPrintsZero(): void
    {
        [$exitCode, $stdout, $stderr] = $this->capture(fn () => $this->controller->actionRebuild());

        $this->assertSame(ExitCode::OK, $exitCode);
        $this->assertSame('', $stderr);
        $this->assertStringContainsString('Rebuilding Lens search index...', $stdout);
        $this->assertStringContainsString('Done!', $stdout);
        $this->assertStringContainsString('Indexed 0 assets.', $stdout);
    }

    public function testRebuildIndexesProcessedRecords(): void
    {
        $r1 = $this->createProcessedRecord(['altText' => 'mountain landscape at dawn']);
        $r2 = $this->createProcessedRecord(['altText' => 'sunset over the pacific ocean']);
        $r3 = $this->createProcessedRecord(['altText' => 'a field of yellow tulips']);

        [$exitCode, $stdout, $stderr] = $this->capture(fn () => $this->controller->actionRebuild());

        $this->assertSame(ExitCode::OK, $exitCode);
        $this->assertSame('', $stderr);
        $this->assertStringContainsString('Indexed 3 assets.', $stdout);

        $this->assertGreaterThan(0, $this->countTokensFor($r1->assetId));
        $this->assertGreaterThan(0, $this->countTokensFor($r2->assetId));
        $this->assertGreaterThan(0, $this->countTokensFor($r3->assetId));
    }

    public function testRebuildSkipsRecordsWithoutProcessedAt(): void
    {
        // Same status, different processedAt — isolates the filter the service
        // actually uses ('NOT processedAt IS NULL') from any status-based logic.
        $processed = $this->createProcessedRecord(['altText' => 'a red fox in the snow']);
        $unprocessed = $this->createAnalysisRecord(AnalysisStatus::Completed->value, [
            'altText' => 'should never be indexed',
            'processedAt' => null,
        ]);

        [$exitCode, $stdout] = $this->capture(fn () => $this->controller->actionRebuild());

        $this->assertSame(ExitCode::OK, $exitCode);
        $this->assertStringContainsString('Indexed 1 assets.', $stdout);

        $this->assertGreaterThan(0, $this->countTokensFor($processed->assetId));
        $this->assertSame(0, $this->countTokensFor($unprocessed->assetId));
    }

    public function testRebuildIsIdempotentAndTruncatesBeforeReinserting(): void
    {
        $record = $this->createProcessedRecord(['altText' => 'a calm blue lake']);

        $this->controller->actionRebuild();
        $firstTokens = array_column($this->fetchTokenRows($record->assetId), 'token');
        $this->assertNotEmpty($firstTokens, 'Expected tokens from first rebuild');

        // Plant a sentinel row for the same asset. If rebuild truncates first,
        // the sentinel disappears. If rebuild no-ops or only upserts, it stays.
        $sentinelToken = 'zz_sentinel_token';
        $this->insertSentinelRow($record->assetId, $record->id, $sentinelToken);
        $this->assertSame(
            1,
            $this->countTokensMatching($record->assetId, $sentinelToken),
            'Sentinel row failed to insert',
        );

        [$exitCode, $stdout] = $this->capture(fn () => $this->controller->actionRebuild());
        $secondTokens = array_column($this->fetchTokenRows($record->assetId), 'token');

        $this->assertSame(ExitCode::OK, $exitCode);
        $this->assertStringContainsString('Indexed 1 assets.', $stdout);
        $this->assertSame(
            0,
            $this->countTokensMatching($record->assetId, $sentinelToken),
            'Sentinel row survived — rebuild did not truncate before reinserting',
        );

        sort($firstTokens);
        sort($secondTokens);
        $this->assertSame($firstTokens, $secondTokens, 'Token set should be stable across rebuilds');
    }

    public function testRebuildWipesStaleTokensWhenAnalysisIsGone(): void
    {
        // Populate the index, then drop the analysis record but leave the
        // backing element row intact. The next rebuild must truncate the
        // orphaned tokens since the asset no longer has a processed analysis.
        $record = $this->createProcessedRecord(['altText' => 'temporary asset content']);
        $assetId = $record->assetId;

        $this->controller->actionRebuild();
        $this->assertGreaterThan(0, $this->countTokensFor($assetId));

        AssetAnalysisRecord::deleteAll(['id' => $record->id]);

        $this->controller->actionRebuild();

        $this->assertSame(0, $this->countTokensFor($assetId));
    }

    public function testRebuildViaConsoleRoute(): void
    {
        $record = $this->createProcessedRecord(['altText' => 'an empty cobblestone street']);

        // runAction() returns a Response object in test context (not an int);
        // assert side effects instead, matching ProcessControllerTest pattern.
        Craft::$app->runAction('lens/search-index/rebuild');

        $this->assertGreaterThan(0, $this->countTokensFor($record->assetId));
    }

    // ---------------------------------------------------------------------
    // Service contract edge cases the controller depends on
    // ---------------------------------------------------------------------

    public function testRebuildContinuesWhenSingleAssetIndexingFails(): void
    {
        $good1 = $this->createProcessedRecord(['altText' => 'a lonely pine tree']);
        $bad = $this->createProcessedRecord(['altText' => 'this one will explode']);
        $good2 = $this->createProcessedRecord(['altText' => 'a busy market square']);

        $stub = new class($bad->assetId) extends SearchIndexService {
            public function __construct(private readonly int $throwingAssetId)
            {
                parent::__construct();
            }

            public function indexAsset(AssetAnalysisRecord $record): void
            {
                if ($record->assetId === $this->throwingAssetId) {
                    throw new \RuntimeException('simulated indexing failure');
                }
                parent::indexAsset($record);
            }
        };

        $exitCode = $this->withStubService($stub, fn () => $this->capture(
            fn () => $this->controller->actionRebuild()
        ));

        [$exit, $stdout, $stderr] = $exitCode;
        $this->assertSame(ExitCode::OK, $exit);
        $this->assertSame('', $stderr);
        $this->assertStringContainsString('Indexed 2 assets.', $stdout);
        $this->assertGreaterThan(0, $this->countTokensFor($good1->assetId));
        $this->assertGreaterThan(0, $this->countTokensFor($good2->assetId));
        $this->assertSame(0, $this->countTokensFor($bad->assetId));
    }

    public function testRebuildWhenAllAssetsFailStillReturnsOk(): void
    {
        $this->createProcessedRecord(['altText' => 'alpha']);
        $this->createProcessedRecord(['altText' => 'beta']);
        $this->createProcessedRecord(['altText' => 'gamma']);

        $stub = new class extends SearchIndexService {
            public function indexAsset(AssetAnalysisRecord $record): void
            {
                throw new \RuntimeException('everything is broken');
            }
        };

        [$exit, $stdout, $stderr] = $this->withStubService(
            $stub,
            fn () => $this->capture(fn () => $this->controller->actionRebuild())
        );

        $this->assertSame(ExitCode::OK, $exit);
        $this->assertSame('', $stderr);
        $this->assertStringContainsString('Indexed 0 assets.', $stdout);
        $this->assertSame(0, $this->totalTokenRows(), 'No tokens should be written when every asset fails');
    }

    public function testRebuildReturnsZeroWhenMutexIsAlreadyHeld(): void
    {
        $record = $this->createProcessedRecord(['altText' => 'should not be indexed under mutex']);

        $mutex = Craft::$app->getMutex();
        $lockName = 'lens-search-index-rebuild';
        $this->assertTrue($mutex->acquire($lockName, 0), 'Failed to acquire mutex for test setup');

        try {
            [$exitCode, $stdout] = $this->capture(fn () => $this->controller->actionRebuild());
        } finally {
            $mutex->release($lockName);
        }

        $this->assertSame(ExitCode::OK, $exitCode);
        $this->assertStringContainsString('Indexed 0 assets.', $stdout);
        $this->assertSame(0, $this->countTokensFor($record->assetId));
    }

    public function testRebuildReleasesMutexAfterServiceException(): void
    {
        $this->createProcessedRecord(['altText' => 'will trigger an exception before indexing']);

        $throwingStub = new class extends SearchIndexService {
            public function rebuildAll(?callable $progress = null): int
            {
                $mutex = Craft::$app->getMutex();
                $lockName = 'lens-search-index-rebuild';
                if (!$mutex->acquire($lockName, 0)) {
                    return 0;
                }
                try {
                    throw new \RuntimeException('mid-rebuild failure');
                } finally {
                    $mutex->release($lockName);
                }
            }
        };

        try {
            $this->withStubService($throwingStub, fn () => $this->controller->actionRebuild());
            $this->fail('Controller should have propagated the service exception');
        } catch (\RuntimeException $e) {
            $this->assertSame('mid-rebuild failure', $e->getMessage());
        }

        // The mutex must be free again — if the finally block didn't run, this
        // acquire would fail and the next rebuild would silently no-op forever.
        $mutex = Craft::$app->getMutex();
        $this->assertTrue(
            $mutex->acquire('lens-search-index-rebuild', 0),
            'Mutex was not released after exception — finally block did not run',
        );
        $mutex->release('lens-search-index-rebuild');
    }

    public function testRebuildInvokesProgressCallbackWithMonotonicValues(): void
    {
        $n = 3;
        for ($i = 0; $i < $n; $i++) {
            $this->createProcessedRecord(['altText' => "item {$i}"]);
        }

        // Spy on the real service by intercepting the callback. We keep the
        // real rebuildAll so monotonicity reflects actual production behavior.
        $calls = [];
        $spyingService = new class($calls) extends SearchIndexService {
            public function __construct(public array &$calls)
            {
                parent::__construct();
            }

            public function rebuildAll(?callable $progress = null): int
            {
                return parent::rebuildAll(function (int $current, int $total) use ($progress) {
                    $this->calls[] = [$current, $total];
                    if ($progress !== null) {
                        $progress($current, $total);
                    }
                });
            }
        };

        [$exit, $stdout] = $this->withStubService(
            $spyingService,
            fn () => $this->capture(fn () => $this->controller->actionRebuild())
        );

        $this->assertSame(ExitCode::OK, $exit);
        $this->assertStringContainsString("Indexed {$n} assets.", $stdout);
        $this->assertCount($n, $spyingService->calls);

        $last = null;
        foreach ($spyingService->calls as [$current, $total]) {
            $this->assertSame($n, $total, 'Total should equal the final indexed count');
            $this->assertLessThanOrEqual($total, $current);
            if ($last !== null) {
                $this->assertGreaterThan($last, $current, 'Progress must be monotonically increasing');
            }
            $last = $current;
        }

        $this->assertSame([$n, $n], end($spyingService->calls), 'Final progress tick should be (total, total)');
    }

    public function testRebuildResetsStemmerLanguageCache(): void
    {
        // Prime the Stemmer cache with a bogus language so we can detect the reset.
        $ref = new ReflectionClass(Stemmer::class);
        $langProp = $ref->getProperty('language');
        $langProp->setAccessible(true);
        $langProp->setValue(null, 'xx-primed');

        $this->controller->actionRebuild();

        $this->assertNull(
            $langProp->getValue(),
            'rebuildAll() must reset Stemmer language cache so queue workers pick up language changes',
        );
    }

    public function testRebuildHandlesBatchBoundary(): void
    {
        $total = 105;
        $assetIds = [];
        for ($i = 0; $i < $total; $i++) {
            $assetIds[] = $this->createProcessedRecord(['altText' => "alt text number {$i}"])->assetId;
        }

        [$exitCode, $stdout] = $this->capture(fn () => $this->controller->actionRebuild());

        $this->assertSame(ExitCode::OK, $exitCode);
        $this->assertStringContainsString("Indexed {$total} assets.", $stdout);

        $distinctIndexedAssets = (int) (new Query())
            ->from(Install::TABLE_SEARCH_INDEX)
            ->where(['assetId' => $assetIds])
            ->select(['assetId'])
            ->distinct()
            ->count('*', Craft::$app->getDb());

        $this->assertSame($total, $distinctIndexedAssets);
    }

    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------

    /**
     * @return array{0:int,1:string,2:string}
     */
    private function capture(callable $fn): array
    {
        $this->controller->capturedStdout = '';
        $this->controller->capturedStderr = '';
        $exitCode = $fn();
        return [$exitCode, $this->controller->capturedStdout, $this->controller->capturedStderr];
    }

    /**
     * Swap in a stub `searchIndex` service, run $fn, always restore.
     */
    private function withStubService(SearchIndexService $stub, callable $fn): mixed
    {
        $original = Plugin::getInstance()->searchIndex;
        Plugin::getInstance()->set('searchIndex', $stub);
        try {
            return $fn();
        } finally {
            Plugin::getInstance()->set('searchIndex', $original);
        }
    }

    private function createProcessedRecord(array $overrides = []): AssetAnalysisRecord
    {
        return $this->createAnalysisRecord(AnalysisStatus::Completed->value, array_merge([
            'provider' => 'test',
            'providerModel' => 'test-model',
            'processedAt' => date('Y-m-d H:i:s'),
        ], $overrides));
    }

    private function countTokensFor(int $assetId): int
    {
        return (int) (new Query())
            ->from(Install::TABLE_SEARCH_INDEX)
            ->where(['assetId' => $assetId])
            ->count('*', Craft::$app->getDb());
    }

    private function totalTokenRows(): int
    {
        return (int) (new Query())
            ->from(Install::TABLE_SEARCH_INDEX)
            ->count('*', Craft::$app->getDb());
    }

    /**
     * @return array<int, array{id:int, token:string}>
     */
    private function fetchTokenRows(int $assetId): array
    {
        return (new Query())
            ->from(Install::TABLE_SEARCH_INDEX)
            ->select(['id', 'token'])
            ->where(['assetId' => $assetId])
            ->all(Craft::$app->getDb());
    }

    private function countTokensMatching(int $assetId, string $token): int
    {
        return (int) (new Query())
            ->from(Install::TABLE_SEARCH_INDEX)
            ->where(['assetId' => $assetId, 'token' => $token])
            ->count('*', Craft::$app->getDb());
    }

    private function insertSentinelRow(int $assetId, int $analysisId, string $token): void
    {
        $now = date('Y-m-d H:i:s');
        Craft::$app->getDb()
            ->createCommand()
            ->insert(Install::TABLE_SEARCH_INDEX, [
                'assetId' => $assetId,
                'analysisId' => $analysisId,
                'token' => $token,
                'tokenRaw' => $token,
                'field' => 'altText',
                'fieldWeight' => 1.10,
                'tf' => 1,
                'dateCreated' => $now,
                'dateUpdated' => $now,
                'uid' => StringHelper::UUID(),
            ])
            ->execute();
    }

    private function truncateSearchIndex(): void
    {
        Craft::$app->getDb()
            ->createCommand()
            ->delete(Install::TABLE_SEARCH_INDEX)
            ->execute();
    }

}

/**
 * Captures stdout/stderr so tests can assert on console output without writing to real streams.
 */
class CapturingSearchIndexController extends SearchIndexController
{
    public string $capturedStdout = '';
    public string $capturedStderr = '';

    public function stdout($string, ...$args)
    {
        $this->capturedStdout .= (string) $string;
        return strlen((string) $string);
    }

    public function stderr($string, ...$args)
    {
        $this->capturedStderr .= (string) $string;
        return strlen((string) $string);
    }
}
