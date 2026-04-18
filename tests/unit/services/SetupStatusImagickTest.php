<?php

declare(strict_types=1);

namespace vitordiniz22\craftlenstests\unit\services;

use Codeception\Test\Unit;
use ReflectionMethod;
use vitordiniz22\craftlens\enums\SetupSeverity;
use vitordiniz22\craftlens\helpers\ImageMetricsAnalyzer;
use vitordiniz22\craftlens\services\SetupStatusService;

/**
 * Unit tests for the Imagick setup check on SetupStatusService.
 *
 * checkImagickAvailable() is a pure function of its input bool, so these
 * tests invoke it directly via reflection and drive both branches. The
 * aggregate/wiring behavior (presence in getSetupStatus, contribution to
 * getWarnings/getCriticalIssues) is asserted against the real pipeline
 * so a stubbed-out check value can't produce a false pass.
 */
class SetupStatusImagickTest extends Unit
{
    private SetupStatusService $service;

    protected function _before(): void
    {
        parent::_before();
        $this->service = new SetupStatusService();
    }

    private function invokeCheck(bool $isResolved): array
    {
        $method = new ReflectionMethod(SetupStatusService::class, 'checkImagickAvailable');
        $method->setAccessible(true);

        return $method->invoke($this->service, $isResolved);
    }

    private function findImagickCheck(array $checks): ?array
    {
        foreach ($checks as $check) {
            if (($check['key'] ?? null) === 'imagick_available') {
                return $check;
            }
        }

        return null;
    }

    // -- checkImagickAvailable() direct invocation: both branches --

    public function testResolvedTrueWhenBoolIsTrue(): void
    {
        $check = $this->invokeCheck(true);

        $this->assertTrue($check['isResolved']);
    }

    public function testResolvedFalseWhenBoolIsFalse(): void
    {
        $check = $this->invokeCheck(false);

        $this->assertFalse($check['isResolved']);
    }

    public function testCheckShapeIsStableAcrossBranches(): void
    {
        // Shape assertions in one place; only isResolved should differ
        // between branches, so we assert the shape from the false case and
        // re-check the stable fields on the true case below.
        $check = $this->invokeCheck(false);

        $this->assertSame('imagick_available', $check['key']);
        $this->assertSame(SetupStatusService::CATEGORY_EXTENSIONS, $check['category']);
        $this->assertSame(SetupSeverity::Warning->value, $check['severity']);
        $this->assertSame('', $check['actionUrl'], 'Imagick install happens off-CP; empty actionUrl is the signal for the banner to hide the primary action button');
        $this->assertSame('', $check['actionLabel']);
        $this->assertNotEmpty($check['docsUrl']);
        $this->assertStringContainsString('Getting-Started', $check['docsUrl']);
        $this->assertStringContainsString('#enabling-imagick-recommended', $check['docsUrl']);
        $this->assertSame([], $check['prerequisites']);
        $this->assertNotEmpty($check['message']);
    }

    public function testShapeUnchangedWhenResolved(): void
    {
        $resolved = $this->invokeCheck(true);
        $unresolved = $this->invokeCheck(false);

        // Every field except isResolved must be identical across branches.
        unset($resolved['isResolved'], $unresolved['isResolved']);
        $this->assertSame($unresolved, $resolved);
    }

    // -- Pipeline wiring: real getSetupStatus() call, no stubbing --

    public function testImagickCheckIsWiredIntoSetupStatus(): void
    {
        $check = $this->findImagickCheck($this->service->getSetupStatus());

        $this->assertNotNull(
            $check,
            'getSetupStatus() must include an imagick_available entry; a regression that drops it from the $cachedStatus array would bypass this wiring'
        );
    }

    public function testSetupStatusReflectsRealHostImagickState(): void
    {
        // This test locks the call-site contract: getSetupStatus() must feed
        // ImageMetricsAnalyzer::isAvailable() into checkImagickAvailable().
        // If someone hardcoded `true` at the call site, this test would fail
        // on a host without Imagick (and vice versa).
        $check = $this->findImagickCheck($this->service->getSetupStatus());
        $this->assertNotNull($check);

        $this->assertSame(
            ImageMetricsAnalyzer::isAvailable(),
            $check['isResolved'],
            'isResolved in getSetupStatus() must track ImageMetricsAnalyzer::isAvailable() on the current host'
        );
    }

    // -- Aggregate bucket contributions: real filtering, no hook to spoof --

    public function testAggregateBucketsMatchHostImagickState(): void
    {
        $hostHasImagick = ImageMetricsAnalyzer::isAvailable();
        $warningKeys = array_column($this->service->getWarnings(), 'key');
        $criticalKeys = array_column($this->service->getCriticalIssues(), 'key');

        // Imagick is a Warning, so it must never leak into critical issues
        // regardless of host state.
        $this->assertNotContains(
            'imagick_available',
            $criticalKeys,
            'Imagick is recommended, not required, so it should never contribute to critical issues'
        );

        if ($hostHasImagick) {
            $this->assertNotContains(
                'imagick_available',
                $warningKeys,
                'Imagick is installed on this host, so the entry must resolve and drop out of warnings'
            );
        } else {
            $this->assertContains(
                'imagick_available',
                $warningKeys,
                'Imagick is missing on this host, so the entry must appear in warnings'
            );
        }
    }
}
