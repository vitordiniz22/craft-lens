<?php

declare(strict_types=1);

namespace vitordiniz22\craftlenstests\integration\twig;

use Codeception\Test\Unit;
use Craft;
use vitordiniz22\craftlens\enums\DuplicateResolution;
use vitordiniz22\craftlens\Plugin;
use vitordiniz22\craftlens\records\AssetTagRecord;
use vitordiniz22\craftlens\records\DuplicateGroupRecord;
use vitordiniz22\craftlens\services\SetupStatusService;
use vitordiniz22\craftlens\twig\LensTwigGlobal;
use vitordiniz22\craftlenstests\_support\Helpers\AnalysisRecordFixtures;

/**
 * Integration tests for service-backed methods on LensTwigGlobal.
 *
 * Every test arranges known state (edition, AI key, settings flags) because
 * the services under test are branchy — assertions on "whatever the env gave
 * us" pass vacuously.
 */
class LensTwigGlobalServicesTest extends Unit
{
    use AnalysisRecordFixtures;

    private LensTwigGlobal $global;

    private string $originalEdition;

    private bool $originalRequireReview;

    private string $originalAiProvider;

    private string $originalOpenaiApiKey;

    private array $originalEnabledVolumes;

    private bool $originalEnableSemanticSearch;

    protected function _before(): void
    {
        parent::_before();
        $this->global = new LensTwigGlobal();

        $plugin = Plugin::getInstance();
        $settings = $plugin->getSettings();

        $this->originalEdition = $plugin->edition;
        $this->originalRequireReview = $settings->requireReviewBeforeApply;
        $this->originalAiProvider = $settings->aiProvider;
        $this->originalOpenaiApiKey = $settings->openaiApiKey;
        $this->originalEnabledVolumes = $settings->enabledVolumes;
        $this->originalEnableSemanticSearch = $settings->enableSemanticSearch;

        // SetupStatusService caches per-instance. Reset BEFORE the test so
        // mutations we make during arrange-phase aren't masked by a cached
        // snapshot from a previous test.
        $plugin->set('setupStatus', SetupStatusService::class);
    }

    protected function _after(): void
    {
        $plugin = Plugin::getInstance();
        $settings = $plugin->getSettings();

        $plugin->edition = $this->originalEdition;
        $settings->requireReviewBeforeApply = $this->originalRequireReview;
        $settings->aiProvider = $this->originalAiProvider;
        $settings->openaiApiKey = $this->originalOpenaiApiKey;
        $settings->enabledVolumes = $this->originalEnabledVolumes;
        $settings->enableSemanticSearch = $this->originalEnableSemanticSearch;

        $plugin->set('setupStatus', SetupStatusService::class);

        $this->cleanupAnalysisRecords();
        parent::_after();
    }

    // -- getAnalysis() --

    public function testGetAnalysisReturnsRecordForKnownAsset(): void
    {
        $record = $this->createAnalysisRecord('completed');

        $found = $this->global->getAnalysis($record->assetId);

        $this->assertNotNull($found);
        $this->assertSame($record->id, $found->id);
    }

    public function testGetAnalysisReturnsNullForMissingAsset(): void
    {
        // Seed an unrelated record so "null" can't be the method always returning null.
        $this->createAnalysisRecord('completed');

        $this->assertNull($this->global->getAnalysis(999999));
    }

    // -- getTagsForAnalysis() --

    public function testGetTagsForAnalysisReturnsRecordsOrderedByConfidence(): void
    {
        $record = $this->createAnalysisRecord('completed');

        $this->insertTag($record->assetId, $record->id, 'sunset', 0.6);
        $this->insertTag($record->assetId, $record->id, 'beach', 0.9);

        $tags = $this->global->getTagsForAnalysis($record->id);

        $this->assertCount(2, $tags);
        $this->assertSame('beach', $tags[0]->tag);
        $this->assertSame('sunset', $tags[1]->tag);
    }

    public function testGetTagsForAnalysisReturnsBothAiAndUserTags(): void
    {
        // Locks the contract: no isAi filter at the Twig layer. Templates that
        // want to split AI vs user tags must do it themselves.
        $record = $this->createAnalysisRecord('completed');
        $this->insertTag($record->assetId, $record->id, 'ai-tag', 0.8, true);
        $this->insertTag($record->assetId, $record->id, 'user-tag', 0.7, false);

        $tags = $this->global->getTagsForAnalysis($record->id);

        $this->assertCount(2, $tags);
        $names = array_map(fn($t) => $t->tag, $tags);
        $this->assertContains('ai-tag', $names);
        $this->assertContains('user-tag', $names);
    }

    public function testGetTagsForAnalysisReturnsEmptyWhenNoTags(): void
    {
        $record = $this->createAnalysisRecord('completed');

        $this->assertSame([], $this->global->getTagsForAnalysis($record->id));
    }

    // -- getSetupStatus() / getCriticalIssues() / hasCriticalIssues() --

    public function testGetSetupStatusIncludesAiProviderCheckWhenKeyMissing(): void
    {
        $this->configureMissingAiKey();

        $keys = array_column($this->global->getSetupStatus(), 'key');

        $this->assertContains('ai_provider_api_key', $keys);
    }

    public function testGetCriticalIssuesContainsAiProviderWhenKeyMissing(): void
    {
        $this->configureMissingAiKey();

        $issues = $this->global->getCriticalIssues();
        $keys = array_column($issues, 'key');

        $this->assertNotEmpty($issues);
        $this->assertContains('ai_provider_api_key', $keys);
        foreach ($issues as $issue) {
            $this->assertSame('critical', $issue['severity']);
            $this->assertFalse($issue['isResolved']);
        }
    }

    public function testHasCriticalIssuesTrueWhenAiKeyMissing(): void
    {
        $this->configureMissingAiKey();

        $this->assertTrue($this->global->hasCriticalIssues());
    }

    public function testHasCriticalIssuesFalseWhenAiConfiguredAndNoOtherCriticalIssues(): void
    {
        $this->configureValidAiKey();
        $this->configureEnabledVolume();

        $this->assertFalse($this->global->hasCriticalIssues());
        $this->assertSame([], array_values($this->global->getCriticalIssues()));

        // Lock in that both critical checks ran and resolved — not just absent.
        $byKey = array_column($this->global->getSetupStatus(), null, 'key');
        $this->assertArrayHasKey('ai_provider_api_key', $byKey);
        $this->assertArrayHasKey('volumes_enabled', $byKey);
        $this->assertTrue($byKey['ai_provider_api_key']['isResolved']);
        $this->assertTrue($byKey['volumes_enabled']['isResolved']);

        // Independent predicate paths (not routed through getCriticalIssues).
        $this->assertTrue($this->global->isFeatureAvailable('analysis'));
        $this->assertSame([], $this->global->getFeatureRequirements('analysis'));
    }

    // -- isFeatureAvailable() / getFeatureRequirements() --

    public function testIsFeatureAvailableForUnknownFeatureDefaultsTrue(): void
    {
        $this->configureMissingAiKey();

        // Unknown features default-true regardless of setup state.
        $this->assertTrue($this->global->isFeatureAvailable('something-unknown'));
    }

    public function testIsFeatureAvailableFalseForAnalysisWhenAiMissing(): void
    {
        $this->configureMissingAiKey();

        $this->assertFalse($this->global->isFeatureAvailable('analysis'));
    }

    public function testIsFeatureAvailableFalseForTagExtractionWhenAiMissing(): void
    {
        $this->configureMissingAiKey();

        $this->assertFalse($this->global->isFeatureAvailable('tag_extraction'));
    }

    public function testGetFeatureRequirementsIncludesAiCheckWhenMissing(): void
    {
        $this->configureMissingAiKey();

        $keys = array_column($this->global->getFeatureRequirements('analysis'), 'key');

        $this->assertContains('ai_provider_api_key', $keys);
    }

    public function testGetFeatureRequirementsEmptyForUnknownFeature(): void
    {
        $this->assertSame([], $this->global->getFeatureRequirements('something-unknown'));
    }

    // -- analysis_panel_added warning --

    public function testAnalysisPanelWarningUnresolvedWhenVolumeHasNoPanel(): void
    {
        $this->configureValidAiKey();
        $this->configureEnabledVolume();

        $byKey = array_column($this->global->getSetupStatus(), null, 'key');

        $this->assertArrayHasKey('analysis_panel_added', $byKey);
        $this->assertSame('warning', $byKey['analysis_panel_added']['severity']);
        $this->assertFalse($byKey['analysis_panel_added']['isResolved']);

        // Warning severity must not leak into critical set.
        $this->assertFalse($this->global->hasCriticalIssues());
    }

    public function testAnalysisPanelWarningResolvedWhenPanelAttached(): void
    {
        $this->configureValidAiKey();
        $volumeId = $this->configureEnabledVolume();
        $this->attachLensAnalysisElementToVolume($volumeId);
        Plugin::getInstance()->set('setupStatus', SetupStatusService::class);

        $byKey = array_column($this->global->getSetupStatus(), null, 'key');

        $this->assertArrayHasKey('analysis_panel_added', $byKey);
        $this->assertTrue($byKey['analysis_panel_added']['isResolved']);
    }

    // -- semantic_search_enabled info (Pro only) --

    public function testSemanticSearchInfoUnresolvedWhenSettingOff(): void
    {
        $plugin = Plugin::getInstance();
        $plugin->edition = Plugin::EDITION_PRO;
        $plugin->getSettings()->enableSemanticSearch = false;
        $plugin->set('setupStatus', SetupStatusService::class);

        $byKey = array_column($this->global->getSetupStatus(), null, 'key');

        $this->assertArrayHasKey('semantic_search_enabled', $byKey);
        $this->assertSame('info', $byKey['semantic_search_enabled']['severity']);
        $this->assertFalse($byKey['semantic_search_enabled']['isResolved']);
    }

    public function testSemanticSearchInfoResolvedWhenSettingOn(): void
    {
        $plugin = Plugin::getInstance();
        $plugin->edition = Plugin::EDITION_PRO;
        $plugin->getSettings()->enableSemanticSearch = true;
        $plugin->set('setupStatus', SetupStatusService::class);

        $byKey = array_column($this->global->getSetupStatus(), null, 'key');

        $this->assertArrayHasKey('semantic_search_enabled', $byKey);
        $this->assertTrue($byKey['semantic_search_enabled']['isResolved']);
    }

    public function testSemanticSearchEntryAbsentOnLiteEdition(): void
    {
        $plugin = Plugin::getInstance();
        $plugin->edition = Plugin::EDITION_LITE;
        $plugin->set('setupStatus', SetupStatusService::class);

        $keys = array_column($this->global->getSetupStatus(), 'key');

        $this->assertNotContains('semantic_search_enabled', $keys);
    }

    // -- first_analysis_complete info --

    public function testFirstAnalysisInfoUnresolvedWhenNoAnalyzedRecords(): void
    {
        $byKey = array_column($this->global->getSetupStatus(), null, 'key');

        $this->assertArrayHasKey('first_analysis_complete', $byKey);
        $this->assertSame('info', $byKey['first_analysis_complete']['severity']);
        $this->assertFalse($byKey['first_analysis_complete']['isResolved']);
    }

    public function testFirstAnalysisInfoResolvedAfterCompletedAnalysis(): void
    {
        $this->createAnalysisRecord('completed');
        Plugin::getInstance()->set('setupStatus', SetupStatusService::class);

        $byKey = array_column($this->global->getSetupStatus(), null, 'key');

        $this->assertArrayHasKey('first_analysis_complete', $byKey);
        $this->assertTrue($byKey['first_analysis_complete']['isResolved']);
    }

    // -- getDuplicateCount() --

    public function testGetDuplicateCountReturnsZeroForAssetWithoutDuplicates(): void
    {
        $record = $this->createAnalysisRecord('completed');

        $this->assertSame(0, $this->global->getDuplicateCount($record->assetId));
    }

    public function testGetDuplicateCountCountsUnresolvedGroupsOnBothSides(): void
    {
        $canonical = $this->createAnalysisRecord('completed');
        $dupeA = $this->createAnalysisRecord('completed');
        $dupeB = $this->createAnalysisRecord('completed');
        $resolved = $this->createAnalysisRecord('completed');

        $this->insertDuplicateGroup($canonical->assetId, $dupeA->assetId);
        $this->insertDuplicateGroup($dupeB->assetId, $canonical->assetId);
        $this->insertDuplicateGroup($canonical->assetId, $resolved->assetId, DuplicateResolution::Kept->value);

        $this->assertSame(2, $this->global->getDuplicateCount($canonical->assetId));
    }

    public function testGetDuplicateCountReturnsZeroWhenAllResolved(): void
    {
        $canonical = $this->createAnalysisRecord('completed');
        $other = $this->createAnalysisRecord('completed');

        $this->insertDuplicateGroup($canonical->assetId, $other->assetId, DuplicateResolution::Kept->value);

        $this->assertSame(0, $this->global->getDuplicateCount($canonical->assetId));
    }

    // -- getIsPro() / getIsLite() --

    public function testGetIsProReflectsProEdition(): void
    {
        Plugin::getInstance()->edition = Plugin::EDITION_PRO;

        $this->assertTrue($this->global->getIsPro());
        $this->assertFalse($this->global->getIsLite());
    }

    public function testGetIsLiteReflectsLiteEdition(): void
    {
        Plugin::getInstance()->edition = Plugin::EDITION_LITE;

        $this->assertTrue($this->global->getIsLite());
        $this->assertFalse($this->global->getIsPro());
    }

    // -- getIsReviewActive() --

    public function testGetIsReviewActiveFalseWhenNotPro(): void
    {
        $plugin = Plugin::getInstance();
        $plugin->edition = Plugin::EDITION_LITE;
        $plugin->getSettings()->requireReviewBeforeApply = true;

        $this->assertFalse($this->global->getIsReviewActive());
    }

    public function testGetIsReviewActiveFalseWhenProButSettingOff(): void
    {
        $plugin = Plugin::getInstance();
        $plugin->edition = Plugin::EDITION_PRO;
        $plugin->getSettings()->requireReviewBeforeApply = false;

        $this->assertFalse($this->global->getIsReviewActive());
    }

    public function testGetIsReviewActiveTrueWhenProAndSettingOn(): void
    {
        $plugin = Plugin::getInstance();
        $plugin->edition = Plugin::EDITION_PRO;
        $plugin->getSettings()->requireReviewBeforeApply = true;

        $this->assertTrue($this->global->getIsReviewActive());
    }

    // -- arrange helpers --

    private function configureMissingAiKey(): void
    {
        $settings = Plugin::getInstance()->getSettings();
        $settings->aiProvider = 'openai';
        $settings->openaiApiKey = '';
        // Re-instantiate to drop the cached status array.
        Plugin::getInstance()->set('setupStatus', SetupStatusService::class);
    }

    private function configureValidAiKey(): void
    {
        $settings = Plugin::getInstance()->getSettings();
        $settings->aiProvider = 'openai';
        $settings->openaiApiKey = 'sk-test-fake-key-for-integration-tests';
        Plugin::getInstance()->set('setupStatus', SetupStatusService::class);
    }

    private function configureEnabledVolume(): int
    {
        $volumeId = $this->createTestVolume(handle: 'lenscritical', name: 'Lens Critical Test');
        $volume = Craft::$app->getVolumes()->getVolumeById($volumeId);

        $settings = Plugin::getInstance()->getSettings();
        $settings->enabledVolumes = [$volume->uid];
        Plugin::getInstance()->set('setupStatus', SetupStatusService::class);

        return $volumeId;
    }

    private function insertTag(int $assetId, int $analysisId, string $tag, float $confidence, bool $isAi = true): void
    {
        $record = new AssetTagRecord();
        $record->assetId = $assetId;
        $record->analysisId = $analysisId;
        $record->tag = $tag;
        $record->tagNormalized = mb_strtolower($tag);
        $record->confidence = $confidence;
        $record->isAi = $isAi;
        $record->save(false);
    }

    private function insertDuplicateGroup(
        int $canonicalAssetId,
        int $duplicateAssetId,
        ?string $resolution = null,
    ): void {
        $record = new DuplicateGroupRecord();
        $record->canonicalAssetId = $canonicalAssetId;
        $record->duplicateAssetId = $duplicateAssetId;
        $record->hammingDistance = 2;
        $record->similarity = 0.95;
        $record->resolution = $resolution;
        $record->save(false);
    }
}
