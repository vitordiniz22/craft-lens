<?php

declare(strict_types=1);

namespace vitordiniz22\craftlenstests\integration\providers;

use Codeception\Test\Unit;
use ReflectionMethod;
use vitordiniz22\craftlens\Plugin;
use vitordiniz22\craftlens\providers\BaseAiProvider;
use vitordiniz22\craftlenstests\integration\providers\_support\TestAiProvider;

/**
 * Locks in where the site owner's custom instructions land in the analysis
 * prompt, and that an empty setting leaves the prompt untouched.
 */
class BaseAiProviderPromptTest extends Unit
{
    private const CUSTOM = 'Use British English spelling. Refer to people only as "a person".';
    private const TONE_NOTE = "Follow the site owner's tone.";

    private string $originalCustomInstructions;
    private string $originalEdition;

    protected function _before(): void
    {
        parent::_before();

        $plugin = Plugin::getInstance();
        $this->originalCustomInstructions = $plugin->getSettings()->customInstructions;
        $this->originalEdition = $plugin->edition;
        $plugin->edition = Plugin::EDITION_PRO;
    }

    protected function _after(): void
    {
        $plugin = Plugin::getInstance();
        $plugin->getSettings()->customInstructions = $this->originalCustomInstructions;
        $plugin->edition = $this->originalEdition;

        parent::_after();
    }

    public function testEmptyCustomInstructionsAddNothing(): void
    {
        $prompt = $this->buildPrompt('   ');

        $this->assertStringNotContainsString('SITE OWNER', $prompt);
        $this->assertStringNotContainsString(self::TONE_NOTE, $prompt);
    }

    public function testCustomInstructionsSitBetweenOpeningAndFieldRules(): void
    {
        $prompt = $this->buildPrompt(self::CUSTOM);

        $this->assertSame(1, substr_count($prompt, self::CUSTOM), 'Custom text must appear exactly once');

        $customPos = $this->positionOf($prompt, self::CUSTOM);
        $accuracyPos = $this->positionOf($prompt, 'ACCURACY:');
        $this->assertLessThan($accuracyPos, $customPos);
        $this->assertLessThan($this->positionOf($prompt, '- "altText"'), $accuracyPos);
        $this->assertStringEndsWith('Respond ONLY with valid JSON, no markdown or explanation.', $prompt);
    }

    public function testToneNoteOnlyOnAltTextAndLongDescription(): void
    {
        $prompt = $this->buildPrompt(self::CUSTOM);

        $this->assertMatchesRegularExpression('/^- "altText":.*' . preg_quote(self::TONE_NOTE, '/') . '$/m', $prompt);
        $this->assertMatchesRegularExpression('/^- "longDescription":.*' . preg_quote(self::TONE_NOTE, '/') . '$/m', $prompt);
        $this->assertDoesNotMatchRegularExpression('/^- "suggestedTitle":.*' . preg_quote(self::TONE_NOTE, '/') . '/m', $prompt);
    }

    public function testTranslatedAltTextFollowsTone(): void
    {
        $prompt = $this->buildPrompt(self::CUSTOM, ['pt-BR']);

        $this->assertStringContainsString("following the site owner's tone", $prompt);
    }

    public function testProCompletionPromptIncludesCustomInstructions(): void
    {
        Plugin::getInstance()->getSettings()->customInstructions = self::CUSTOM;

        $method = new ReflectionMethod(BaseAiProvider::class, 'buildProCompletionPrompt');
        $prompt = $method->invoke(new TestAiProvider(maxFileSizeBytes: 1), 'en');

        $this->assertLessThan(
            $this->positionOf($prompt, '- "longDescription"'),
            $this->positionOf($prompt, self::CUSTOM),
        );
    }

    private function positionOf(string $haystack, string $needle): int
    {
        $pos = strpos($haystack, $needle);
        $this->assertNotFalse($pos, "Prompt is missing: {$needle}");

        return $pos;
    }

    /**
     * @param string[] $additionalLanguages
     */
    private function buildPrompt(string $customInstructions, array $additionalLanguages = []): string
    {
        Plugin::getInstance()->getSettings()->customInstructions = $customInstructions;

        $method = new ReflectionMethod(BaseAiProvider::class, 'buildPrompt');

        return $method->invoke(new TestAiProvider(maxFileSizeBytes: 1), 'en', $additionalLanguages);
    }
}
