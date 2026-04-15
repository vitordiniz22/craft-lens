<?php

declare(strict_types=1);

namespace vitordiniz22\craftlenstests\integration\console;

use Codeception\Test\Unit;
use Craft;
use vitordiniz22\craftlens\console\controllers\StatsController;
use vitordiniz22\craftlens\Plugin;
use vitordiniz22\craftlens\services\AssetAnalysisService;
use vitordiniz22\craftlens\services\ColorAggregationService;
use vitordiniz22\craftlens\services\DuplicateDetectionService;
use vitordiniz22\craftlens\services\ReviewService;
use vitordiniz22\craftlens\services\TagAggregationService;
use vitordiniz22\craftlenstests\_support\Helpers\AnalysisRecordFixtures;
use yii\console\ExitCode;

class StatsControllerTest extends Unit
{
    use AnalysisRecordFixtures;

    private CapturingStatsController $controller;

    protected function _before(): void
    {
        parent::_before();
        $this->controller = new CapturingStatsController('stats', Craft::$app);
        $this->controller->interactive = false;
    }

    protected function _after(): void
    {
        $this->cleanupAnalysisRecords();
        parent::_after();
    }

    // ---------------------------------------------------------------------
    // actionIndex
    // ---------------------------------------------------------------------

    public function testIndexPrintsUnprocessedAndPendingCounts(): void
    {
        $assetAnalysis = new class extends AssetAnalysisService {
            public function getUnprocessedCount(): int
            {
                return 7;
            }
        };
        $review = new class extends ReviewService {
            public function getPendingReviewCount(): int
            {
                return 3;
            }
        };

        $originalAsset = Plugin::getInstance()->assetAnalysis;
        $originalReview = Plugin::getInstance()->review;
        Plugin::getInstance()->set('assetAnalysis', $assetAnalysis);
        Plugin::getInstance()->set('review', $review);

        try {
            [$exitCode, $stdout, $stderr] = $this->capture(fn () => $this->controller->actionIndex());
        } finally {
            Plugin::getInstance()->set('assetAnalysis', $originalAsset);
            Plugin::getInstance()->set('review', $originalReview);
        }

        $this->assertSame(ExitCode::OK, $exitCode);
        $this->assertSame('', $stderr);
        $this->assertStringContainsString('Lens Analysis Statistics', $stdout);
        $this->assertStringContainsString('Unprocessed assets:', $stdout);
        $this->assertStringContainsString('7', $stdout);
        $this->assertStringContainsString('Pending review:', $stdout);
        $this->assertStringContainsString('3', $stdout);
    }

    public function testDefaultActionResolvesToIndex(): void
    {
        $this->assertSame('index', $this->controller->defaultAction);
    }

    // ---------------------------------------------------------------------
    // actionTags
    // ---------------------------------------------------------------------

    public function testTagsRespectsLimitAndForwardsSortArg(): void
    {
        $captured = new \stdClass();
        $captured->limit = null;
        $captured->sortBy = null;

        $stub = new class($captured) extends TagAggregationService {
            private \stdClass $captured;

            public function __construct(\stdClass $captured)
            {
                parent::__construct();
                $this->captured = $captured;
            }

            public function getTagCounts(int $limit = 30, string $sortBy = 'count', ?array $volumeIds = null): array
            {
                $this->captured->limit = $limit;
                $this->captured->sortBy = $sortBy;
                return [
                    ['tag' => 'sunset', 'count' => 42],
                    ['tag' => 'beach', 'count' => 17],
                ];
            }
        };

        $original = Plugin::getInstance()->tagAggregation;
        Plugin::getInstance()->set('tagAggregation', $stub);

        try {
            [$exitCode, $stdout, $stderr] = $this->capture(fn () => $this->controller->actionTags(5));
        } finally {
            Plugin::getInstance()->set('tagAggregation', $original);
        }

        $this->assertSame(ExitCode::OK, $exitCode);
        $this->assertSame('', $stderr);
        $this->assertSame(5, $captured->limit);
        $this->assertSame('count', $captured->sortBy);
        $this->assertStringContainsString('Top 5', $stdout);
        $this->assertStringContainsString('sunset', $stdout);
        $this->assertStringContainsString('42', $stdout);
        $this->assertStringContainsString('beach', $stdout);
        $this->assertStringContainsString('17', $stdout);
    }

    public function testTagsEmptyResultPrintsNoTagsFound(): void
    {
        $stub = new class extends TagAggregationService {
            public function getTagCounts(int $limit = 30, string $sortBy = 'count', ?array $volumeIds = null): array
            {
                return [];
            }
        };

        $original = Plugin::getInstance()->tagAggregation;
        Plugin::getInstance()->set('tagAggregation', $stub);

        try {
            [$exitCode, $stdout, $stderr] = $this->capture(fn () => $this->controller->actionTags());
        } finally {
            Plugin::getInstance()->set('tagAggregation', $original);
        }

        $this->assertSame(ExitCode::OK, $exitCode);
        $this->assertSame('', $stderr);
        $this->assertStringContainsString('No tags found.', $stdout);
    }

    // ---------------------------------------------------------------------
    // actionColors
    // ---------------------------------------------------------------------

    public function testColorsRespectsLimit(): void
    {
        $captured = new \stdClass();
        $captured->limit = null;

        $stub = new class($captured) extends ColorAggregationService {
            private \stdClass $captured;

            public function __construct(\stdClass $captured)
            {
                parent::__construct();
                $this->captured = $captured;
            }

            public function getColorCounts(int $limit = 20, ?array $volumeIds = null): array
            {
                $this->captured->limit = $limit;
                return [
                    ['hex' => '#ff0000', 'count' => 9],
                    ['hex' => '#00ff00', 'count' => 4],
                ];
            }
        };

        $original = Plugin::getInstance()->colorAggregation;
        Plugin::getInstance()->set('colorAggregation', $stub);

        try {
            [$exitCode, $stdout, $stderr] = $this->capture(fn () => $this->controller->actionColors(3));
        } finally {
            Plugin::getInstance()->set('colorAggregation', $original);
        }

        $this->assertSame(ExitCode::OK, $exitCode);
        $this->assertSame('', $stderr);
        $this->assertSame(3, $captured->limit);
        $this->assertStringContainsString('Top 3', $stdout);
        $this->assertStringContainsString('#ff0000', $stdout);
        $this->assertStringContainsString('9 assets', $stdout);
        $this->assertStringContainsString('#00ff00', $stdout);
        $this->assertStringContainsString('4 assets', $stdout);
    }

    public function testColorsEmptyResultPrintsNoColorsFound(): void
    {
        $stub = new class extends ColorAggregationService {
            public function getColorCounts(int $limit = 20, ?array $volumeIds = null): array
            {
                return [];
            }
        };

        $original = Plugin::getInstance()->colorAggregation;
        Plugin::getInstance()->set('colorAggregation', $stub);

        try {
            [$exitCode, $stdout, $stderr] = $this->capture(fn () => $this->controller->actionColors());
        } finally {
            Plugin::getInstance()->set('colorAggregation', $original);
        }

        $this->assertSame(ExitCode::OK, $exitCode);
        $this->assertSame('', $stderr);
        $this->assertStringContainsString('No colors found.', $stdout);
    }

    // ---------------------------------------------------------------------
    // actionVolumes
    // ---------------------------------------------------------------------

    public function testVolumesListsAllVolumes(): void
    {
        $this->createTestVolume('lenstest', 'Lens Test');

        [$exitCode, $stdout, $stderr] = $this->capture(fn () => $this->controller->actionVolumes());

        $this->assertSame(ExitCode::OK, $exitCode);
        $this->assertSame('', $stderr);
        $this->assertStringContainsString('Available Volumes', $stdout);
        $this->assertStringContainsString('lenstest', $stdout);
        $this->assertStringContainsString('Lens Test', $stdout);
    }

    // ---------------------------------------------------------------------
    // actionScanDuplicates
    // ---------------------------------------------------------------------

    public function testScanDuplicatesBlockedOnLiteEdition(): void
    {
        $spyCalled = new \stdClass();
        $spyCalled->runFullScan = false;

        $stub = new class($spyCalled) extends DuplicateDetectionService {
            private \stdClass $spy;

            public function __construct(\stdClass $spy)
            {
                parent::__construct();
                $this->spy = $spy;
            }

            public function runFullScan(int $threshold = 10): int
            {
                $this->spy->runFullScan = true;
                return 0;
            }
        };

        $originalEdition = Plugin::getInstance()->edition;
        $originalService = Plugin::getInstance()->duplicateDetection;
        Plugin::getInstance()->edition = Plugin::EDITION_LITE;
        Plugin::getInstance()->set('duplicateDetection', $stub);

        try {
            [$exitCode, $stdout, $stderr] = $this->capture(fn () => $this->controller->actionScanDuplicates());
        } finally {
            Plugin::getInstance()->edition = $originalEdition;
            Plugin::getInstance()->set('duplicateDetection', $originalService);
        }

        $this->assertSame(ExitCode::CONFIG, $exitCode);
        $this->assertStringContainsString('requires the Pro edition', $stderr);
        $this->assertFalse($spyCalled->runFullScan, 'runFullScan must not be called on Lite edition');
    }

    public function testScanDuplicatesRunsFullScanOnPro(): void
    {
        $stub = new class extends DuplicateDetectionService {
            public function runFullScan(int $threshold = 10): int
            {
                return 4;
            }

            public function getUnresolvedDuplicateCount(): int
            {
                return 10;
            }
        };

        $originalEdition = Plugin::getInstance()->edition;
        $originalService = Plugin::getInstance()->duplicateDetection;
        Plugin::getInstance()->edition = Plugin::EDITION_PRO;
        Plugin::getInstance()->set('duplicateDetection', $stub);

        try {
            [$exitCode, $stdout, $stderr] = $this->capture(fn () => $this->controller->actionScanDuplicates());
        } finally {
            Plugin::getInstance()->edition = $originalEdition;
            Plugin::getInstance()->set('duplicateDetection', $originalService);
        }

        $this->assertSame(ExitCode::OK, $exitCode);
        $this->assertSame('', $stderr);
        $this->assertStringContainsString('Running full duplicate scan', $stdout);
        $this->assertStringContainsString('Found 4 new duplicate pair(s)', $stdout);
        $this->assertStringContainsString('Total unresolved duplicates:', $stdout);
        $this->assertStringContainsString('10', $stdout);
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
}

/**
 * Captures stdout/stderr so tests can assert on console output without writing to real streams.
 */
class CapturingStatsController extends StatsController
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
