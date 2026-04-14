<?php

declare(strict_types=1);

namespace vitordiniz22\craftlenstests\integration\twig;

use Codeception\Test\Unit;
use vitordiniz22\craftlens\enums\DuplicateResolution;
use vitordiniz22\craftlens\Plugin;
use vitordiniz22\craftlens\records\AssetColorRecord;
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

    // -- getColorsForAnalysis() --

    public function testGetColorsForAnalysisReturnsRecords(): void
    {
        $record = $this->createAnalysisRecord('completed');

        $this->insertColor($record->assetId, $record->id, '#FF0000', 0.5);
        $this->insertColor($record->assetId, $record->id, '#00FF00', 0.3);

        $colors = $this->global->getColorsForAnalysis($record->id);

        $this->assertCount(2, $colors);
        $hexes = array_map(fn($c) => $c->hex, $colors);
        $this->assertContains('#FF0000', $hexes);
        $this->assertContains('#00FF00', $hexes);
    }

    public function testGetColorsForAnalysisReturnsBothAutoAndUserColors(): void
    {
        $record = $this->createAnalysisRecord('completed');
        $this->insertColor($record->assetId, $record->id, '#AAAAAA', 0.5, true);
        $this->insertColor($record->assetId, $record->id, '#BBBBBB', 0.5, false);

        $colors = $this->global->getColorsForAnalysis($record->id);

        $this->assertCount(2, $colors);
    }

    public function testGetColorsForAnalysisReturnsEmptyWhenNoColors(): void
    {
        $record = $this->createAnalysisRecord('completed');

        $this->assertSame([], $this->global->getColorsForAnalysis($record->id));
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
        // AI configured clears the only critical check in a fresh test env
        // (the volume check is only added once a volume exists).
        $this->configureValidAiKey();

        $this->assertFalse($this->global->hasCriticalIssues());
        $this->assertSame([], array_values($this->global->getCriticalIssues()));
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

    private function insertColor(int $assetId, int $analysisId, string $hex, float $percentage, bool $isAutoGenerated = true): void
    {
        $record = new AssetColorRecord();
        $record->assetId = $assetId;
        $record->analysisId = $analysisId;
        $record->hex = $hex;
        $record->percentage = $percentage;
        $record->isAutoGenerated = $isAutoGenerated;
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
