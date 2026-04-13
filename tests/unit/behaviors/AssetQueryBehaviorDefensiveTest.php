<?php

declare(strict_types=1);

namespace vitordiniz22\craftlenstests\unit\behaviors;

use Codeception\Test\Unit;
use vitordiniz22\craftlens\behaviors\AssetQueryBehavior;
use yii\db\Query;

/**
 * Tests the defensive error handling in AssetQueryBehavior.
 *
 * Verifies that broken Lens filters never crash Craft's asset index:
 * - Schema validation blocks filters when the lens table is missing
 * - SubQuery introspection prevents duplicate/missing JOINs
 * - Snapshot + try/catch restores a clean subQuery on PHP errors
 * - safeApplyFilter wraps each Flow B method individually
 */
class AssetQueryBehaviorDefensiveTest extends Unit
{
    protected function _before(): void
    {
        parent::_before();

        (new \ReflectionProperty(AssetQueryBehavior::class, 'flashShown'))
            ->setValue(null, false);
        (new \ReflectionProperty(AssetQueryBehavior::class, 'schemaValid'))
            ->setValue(null, null);
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function bypassSchemaValidation(): void
    {
        (new \ReflectionProperty(AssetQueryBehavior::class, 'schemaValid'))
            ->setValue(null, true);
    }

    /**
     * Create a behavior whose subQuery throws on all query-building methods.
     */
    private function createBehaviorWithThrowingSubQuery(): AssetQueryBehavior
    {
        $exception = new \RuntimeException('Simulated filter error');

        $subQuery = $this->createMock(Query::class);
        $subQuery->method('andWhere')->willThrowException($exception);
        $subQuery->method('innerJoin')->willThrowException($exception);
        $subQuery->method('leftJoin')->willThrowException($exception);

        $owner = new \stdClass();
        $owner->subQuery = $subQuery;

        $behavior = new AssetQueryBehavior();
        $behavior->owner = $owner;

        return $behavior;
    }

    /**
     * Create a behavior whose subQuery accepts all calls normally.
     */
    private function createBehaviorWithWorkingSubQuery(): AssetQueryBehavior
    {
        $subQuery = $this->createMock(Query::class);
        $subQuery->method('andWhere')->willReturnSelf();
        $subQuery->method('innerJoin')->willReturnSelf();
        $subQuery->method('leftJoin')->willReturnSelf();

        $owner = new \stdClass();
        $owner->subQuery = $subQuery;

        $behavior = new AssetQueryBehavior();
        $behavior->owner = $owner;

        return $behavior;
    }

    // ==================================================================
    // SubQuery introspection (isLensTableJoined)
    // ==================================================================

    public function testEnsureJoinedAddsJoinWhenLensNotPresent(): void
    {
        $subQuery = $this->createMock(Query::class);
        $subQuery->join = []; // No lens table joined
        $subQuery->method('andWhere')->willReturnSelf();
        $subQuery->expects($this->once())->method('innerJoin')->willReturnSelf();

        $owner = new \stdClass();
        $owner->subQuery = $subQuery;

        $behavior = new AssetQueryBehavior();
        $behavior->owner = $owner;
        $behavior->lensContainsPeople = true;

        $this->bypassSchemaValidation();
        $behavior->beforePrepare();
        // innerJoin was called exactly once -- ensureJoined() detected no existing join
    }

    public function testEnsureJoinedSkipsWhenLensAlreadyInnerJoined(): void
    {
        $subQuery = $this->createMock(Query::class);
        $subQuery->join = [
            ['INNER JOIN', '{{%lens_asset_analyses}} lens', '[[lens.assetId]] = [[elements.id]]'],
        ];
        $subQuery->method('andWhere')->willReturnSelf();
        $subQuery->expects($this->never())->method('innerJoin');

        $owner = new \stdClass();
        $owner->subQuery = $subQuery;

        $behavior = new AssetQueryBehavior();
        $behavior->owner = $owner;
        $behavior->lensContainsPeople = true;

        $this->bypassSchemaValidation();
        $behavior->beforePrepare();
        // innerJoin was never called -- introspection found the existing join
    }

    public function testEnsureJoinedSkipsWhenLensAlreadyLeftJoined(): void
    {
        $subQuery = $this->createMock(Query::class);
        $subQuery->join = [
            ['LEFT JOIN', '{{%lens_asset_analyses}} lens', '[[lens.assetId]] = [[elements.id]]'],
        ];
        $subQuery->method('andWhere')->willReturnSelf();
        $subQuery->expects($this->never())->method('innerJoin');
        $subQuery->expects($this->never())->method('leftJoin');

        $owner = new \stdClass();
        $owner->subQuery = $subQuery;

        $behavior = new AssetQueryBehavior();
        $behavior->owner = $owner;
        $behavior->lensContainsPeople = true;

        $this->bypassSchemaValidation();
        $behavior->beforePrepare();
        // Neither join method called -- LEFT JOIN already covers it
    }

    public function testIntrospectionIgnoresNonLensJoins(): void
    {
        $subQuery = $this->createMock(Query::class);
        $subQuery->join = [
            ['INNER JOIN', '{{%assets}} assets', '[[assets.id]] = [[elements.id]]'],
            ['INNER JOIN', '{{%volumefolders}} vf', '[[vf.id]] = [[assets.folderId]]'],
        ];
        $subQuery->method('andWhere')->willReturnSelf();
        $subQuery->expects($this->once())->method('innerJoin')->willReturnSelf();

        $owner = new \stdClass();
        $owner->subQuery = $subQuery;

        $behavior = new AssetQueryBehavior();
        $behavior->owner = $owner;
        $behavior->lensContainsPeople = true;

        $this->bypassSchemaValidation();
        $behavior->beforePrepare();
        // innerJoin WAS called -- other joins don't count as lens
    }

    public function testMultiplePreparesWithFreshSubQuery(): void
    {
        $this->bypassSchemaValidation();

        // Simulate two prepare() calls with fresh subQueries each time
        // (the original bug scenario -- stale flags would skip the JOIN)
        for ($i = 0; $i < 3; $i++) {
            $subQuery = $this->createMock(Query::class);
            $subQuery->join = []; // Fresh subQuery, no joins
            $subQuery->method('andWhere')->willReturnSelf();
            $subQuery->expects($this->once())->method('innerJoin')->willReturnSelf();

            $owner = new \stdClass();
            $owner->subQuery = $subQuery;

            $behavior = new AssetQueryBehavior();
            $behavior->owner = $owner;
            $behavior->lensContainsPeople = true;

            $behavior->beforePrepare();
            // Each time: introspection sees no join, adds one. No stale state.
        }
    }

    // ==================================================================
    // Schema validation
    // ==================================================================

    public function testSchemaInvalidSkipsAllFiltersAndResetsProperties(): void
    {
        (new \ReflectionProperty(AssetQueryBehavior::class, 'schemaValid'))
            ->setValue(null, false);

        $behavior = $this->createBehaviorWithWorkingSubQuery();
        $originalSubQuery = $behavior->owner->subQuery;
        $behavior->lensStatus = 'completed';
        $behavior->lensContainsPeople = true;

        $behavior->beforePrepare();

        // subQuery untouched (no Lens modifications)
        $this->assertSame($originalSubQuery, $behavior->owner->subQuery);
        // All properties reset
        $this->assertNull($behavior->lensStatus);
        $this->assertNull($behavior->lensContainsPeople);
    }

    public function testSchemaValidationCachedAcrossCalls(): void
    {
        // Set valid once
        (new \ReflectionProperty(AssetQueryBehavior::class, 'schemaValid'))
            ->setValue(null, true);

        $behavior = $this->createBehaviorWithWorkingSubQuery();
        $behavior->lensContainsPeople = true;
        $behavior->beforePrepare();

        // Change to invalid -- should be ignored because cached
        // (we can't actually change it via the method, but we verify
        // the static is still true after the call)
        $ref = new \ReflectionProperty(AssetQueryBehavior::class, 'schemaValid');
        $this->assertTrue($ref->getValue());
    }

    // ==================================================================
    // Flow A: beforePrepare snapshot + restore
    // ==================================================================

    public function testBeforePrepareRestoresCleanSubQueryOnPhpError(): void
    {
        $this->bypassSchemaValidation();
        $behavior = $this->createBehaviorWithThrowingSubQuery();
        $originalSubQuery = $behavior->owner->subQuery;

        $behavior->lensStatus = 'completed';
        $behavior->lensContainsPeople = true;
        $behavior->lensRawWhereConditions = [['lens.status' => 'completed']];

        $behavior->beforePrepare();

        // subQuery replaced with clean snapshot
        $this->assertNotSame($originalSubQuery, $behavior->owner->subQuery);
        // All properties reset
        $this->assertNull($behavior->lensStatus);
        $this->assertNull($behavior->lensContainsPeople);
        $this->assertSame([], $behavior->lensRawWhereConditions);
    }

    public function testBeforePrepareSkipsWhenNoFiltersSet(): void
    {
        $behavior = $this->createBehaviorWithWorkingSubQuery();
        $originalSubQuery = $behavior->owner->subQuery;

        $behavior->beforePrepare();

        // subQuery untouched -- hasAnyLensFilter() returned false
        $this->assertSame($originalSubQuery, $behavior->owner->subQuery);
    }

    // ==================================================================
    // Flow B: safeApplyFilter per-method wrapping
    // ==================================================================

    /**
     * Data provider for all 13 Flow B public methods.
     * Each entry: [method, property, valueToSet, expectedAfterFailure]
     */
    public static function flowBMethodProvider(): array
    {
        return [
            'StatusFilter' => ['lensApplyStatusFilter', 'lensStatus', 'completed', null],
            'ContainsPeopleFilter' => ['lensApplyContainsPeopleFilter', 'lensContainsPeople', true, null],
            'NsfwFlaggedFilter' => ['lensApplyNsfwFlaggedFilter', 'lensNsfwFlagged', true, null],
            'HasWatermarkFilter' => ['lensApplyHasWatermarkFilter', 'lensHasWatermark', true, null],
            'WatermarkTypesFilter' => ['lensApplyWatermarkTypesFilter', 'lensWatermarkTypes', ['stock'], []],
            'ContainsBrandLogoFilter' => ['lensApplyContainsBrandLogoFilter', 'lensContainsBrandLogo', true, null],
            'StockProviderFilter' => ['lensApplyStockProviderFilter', 'lensStockProvider', 'shutterstock', null],
            'HasFocalPointFilter' => ['lensApplyHasFocalPointFilter', 'lensHasFocalPoint', true, null],
            'LowQualityFilter' => ['lensApplyLowQualityFilter', 'lensLowQuality', true, null],
            'WebReadinessFilter' => ['lensApplyWebReadinessFilter', 'lensWebReadinessIssues', ['fileTooLarge'], []],
            'HasTextInImageFilter' => ['lensApplyHasTextInImageFilter', 'lensHasTextInImage', true, null],
            'RawWhereConditions' => ['lensApplyRawWhereConditions', 'lensRawWhereConditions', [['lens.status' => 'completed']], []],
        ];
    }

    /**
     * @dataProvider flowBMethodProvider
     */
    public function testFlowBCatchesExceptionAndResetsProperty(
        string $method,
        string $property,
        mixed $valueToSet,
        mixed $expectedAfterFailure,
    ): void {
        $behavior = $this->createBehaviorWithThrowingSubQuery();
        $behavior->{$property} = $valueToSet;

        $behavior->{$method}();

        $this->assertSame(
            $expectedAfterFailure,
            $behavior->{$property},
            "Property '{$property}' should be reset after {$method} fails",
        );
    }

    /**
     * @dataProvider flowBMethodProvider
     */
    public function testFlowBIsNoOpWhenPropertyIsNull(
        string $method,
        string $property,
        mixed $valueToSet,
        mixed $expectedAfterFailure,
    ): void {
        $behavior = $this->createBehaviorWithThrowingSubQuery();

        // Property at default -- should not call any subQuery methods
        $behavior->{$method}();

        $default = $property === 'lensRawWhereConditions' ? [] : null;
        $this->assertSame($default, $behavior->{$property});
    }

    public function testFlowBIsNoOpWhenSubQueryIsNull(): void
    {
        $owner = new \stdClass();
        $owner->subQuery = null;

        $behavior = new AssetQueryBehavior();
        $behavior->owner = $owner;
        $behavior->lensStatus = 'completed';

        $behavior->lensApplyStatusFilter();

        // Property unchanged -- filter was not attempted
        $this->assertSame('completed', $behavior->lensStatus);
    }

    // ==================================================================
    // Edge cases
    // ==================================================================

    public function testRawWhereConditionsResetsToEmptyArrayNotNull(): void
    {
        $behavior = $this->createBehaviorWithThrowingSubQuery();
        $behavior->lensRawWhereConditions = [['lens.status' => 'completed']];

        $behavior->lensApplyRawWhereConditions();

        $this->assertSame([], $behavior->lensRawWhereConditions);
        $this->assertIsArray($behavior->lensRawWhereConditions);
    }

    public function testMultipleFlowBFailuresDoNotCrash(): void
    {
        $behavior = $this->createBehaviorWithThrowingSubQuery();
        $behavior->lensStatus = 'completed';
        $behavior->lensContainsPeople = true;

        $behavior->lensApplyStatusFilter();
        $behavior->lensApplyContainsPeopleFilter();

        $this->assertNull($behavior->lensStatus);
        $this->assertNull($behavior->lensContainsPeople);
    }

    public function testFlowBFailureDoesNotAffectOtherProperties(): void
    {
        $behavior = $this->createBehaviorWithThrowingSubQuery();
        $behavior->lensStatus = 'completed';
        $behavior->lensContainsPeople = true;

        $behavior->lensApplyStatusFilter();

        $this->assertNull($behavior->lensStatus);
        $this->assertTrue($behavior->lensContainsPeople);
    }

    public function testHasAnyLensFilterReturnsFalseWithDefaults(): void
    {
        $behavior = new AssetQueryBehavior();

        $ref = new \ReflectionMethod(AssetQueryBehavior::class, 'hasAnyLensFilter');
        $this->assertFalse($ref->invoke($behavior));
    }

    public function testHasAnyLensFilterReturnsTrueForAnyProperty(): void
    {
        $properties = [
            'lensStatus' => 'completed',
            'lensContainsPeople' => true,
            'lensNsfwFlagged' => true,
            'lensLowQuality' => true,
            'lensTextSearch' => 'test',
        ];

        $ref = new \ReflectionMethod(AssetQueryBehavior::class, 'hasAnyLensFilter');

        foreach ($properties as $prop => $value) {
            $behavior = new AssetQueryBehavior();
            $behavior->{$prop} = $value;
            $this->assertTrue($ref->invoke($behavior), "hasAnyLensFilter should be true when {$prop} is set");
        }
    }

    public function testHasAnyLensFilterDetectsRawWhereConditions(): void
    {
        $behavior = new AssetQueryBehavior();
        $behavior->lensRawWhereConditions = [['lens.status' => 'completed']];

        $ref = new \ReflectionMethod(AssetQueryBehavior::class, 'hasAnyLensFilter');
        $this->assertTrue($ref->invoke($behavior));
    }
}
