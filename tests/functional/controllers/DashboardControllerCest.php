<?php

declare(strict_types=1);

namespace vitordiniz22\craftlenstests\functional\controllers;

use FunctionalTester;

/**
 * Functional tests for DashboardController.
 *
 * Demonstrates the Cest test format used for HTTP-level testing.
 * Cest classes use a tester actor ($I) injected into each test method,
 * providing BDD-style assertions like amOnPage() and seeResponseCodeIs().
 *
 * NOTE: Full functional testing (amOnPage, seeResponseCodeIs, etc.) requires
 * additional setup for the Craft web application context (log dispatcher,
 * authenticated sessions, etc.). This starter test validates that the
 * functional suite is wired correctly. Expand with HTTP tests as needed.
 *
 * To add HTTP tests later:
 * 1. Create a user fixture in tests/_data/
 * 2. Configure Craft's log dispatcher for test context
 * 3. Authenticate: $I->amLoggedInAs($user)
 * 4. Then test CP pages: $I->amOnPage('/admin/lens')
 */
class DashboardControllerCest
{
    public function _before(FunctionalTester $I): void
    {
    }

    /**
     * Verify the functional test suite is properly configured.
     * This confirms Codeception, the Craft module, and the Cest format
     * are all wired correctly.
     */
    public function testFunctionalSuiteIsConfigured(FunctionalTester $I): void
    {
        $I->assertNotNull(\Craft::$app, 'Craft app should be available');
        $I->assertNotNull(
            \vitordiniz22\craftlens\Plugin::getInstance(),
            'Lens plugin should be installed'
        );
    }
}
