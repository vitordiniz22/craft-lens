<?php

declare(strict_types=1);

namespace vitordiniz22\craftlenstests\integration\twig;

use Codeception\Test\Unit;
use Craft;
use craft\web\View;
use vitordiniz22\craftlens\enums\SetupSeverity;

/**
 * Integration tests for _components/setup-banner.twig.
 *
 * Renders the template with synthetic check arrays so the primary-action /
 * docs-only branching can be exercised without relying on live plugin state.
 * Guards the {% if check.actionUrl %} conditional that was added to support
 * docs-only checks like the Imagick warning: a regression that drops the
 * guard would produce a broken-looking button linking to `/admin/` via
 * cpUrl('') on any such check.
 */
class SetupBannerTest extends Unit
{
    private ?string $originalMode = null;

    protected function _before(): void
    {
        parent::_before();
        // Plugin templates live under the CP template root.
        $view = Craft::$app->getView();
        $this->originalMode = $view->getTemplateMode();
        $view->setTemplateMode(View::TEMPLATE_MODE_CP);
    }

    protected function _after(): void
    {
        if ($this->originalMode !== null) {
            Craft::$app->getView()->setTemplateMode($this->originalMode);
        }
        parent::_after();
    }

    private function render(array $checks): string
    {
        return Craft::$app->getView()->renderTemplate(
            'lens/_components/setup-banner',
            ['checks' => $checks],
        );
    }

    private function docsOnlyCheck(array $overrides = []): array
    {
        return array_merge([
            'key' => 'imagick_available',
            'category' => 'extensions',
            'severity' => SetupSeverity::Warning->value,
            'message' => 'Install Imagick to unlock image quality checks.',
            'actionLabel' => '',
            'actionUrl' => '',
            'docsUrl' => 'https://example.test/wiki/Getting-Started#enabling-imagick-recommended',
            'isResolved' => false,
            'prerequisites' => [],
        ], $overrides);
    }

    private function standardCheck(array $overrides = []): array
    {
        return array_merge([
            'key' => 'ai_provider_api_key',
            'category' => 'ai_provider',
            'severity' => SetupSeverity::Critical->value,
            'message' => 'Add your AI provider API key.',
            'actionLabel' => 'Configure API Key',
            'actionUrl' => 'lens/settings#provider',
            'docsUrl' => 'https://example.test/wiki/Getting-Started#configuring-your-ai-provider',
            'isResolved' => false,
            'prerequisites' => [],
        ], $overrides);
    }

    public function testDocsOnlyCheckRendersLearnMoreButNoActionButton(): void
    {
        $html = $this->render([$this->docsOnlyCheck()]);

        $this->assertStringContainsString('Learn more', $html);
        $this->assertStringContainsString('#enabling-imagick-recommended', $html);
        $this->assertStringNotContainsString(
            'btn small submit',
            $html,
            'Warning checks never render a submit-styled button, but more importantly a docs-only check should not render any primary action button'
        );
        $this->assertStringNotContainsString(
            'lens-setup-banner__item-action">' . PHP_EOL . '                        <a href="/',
            $html,
            'No cpUrl-based action link should render when actionUrl is empty'
        );
    }

    public function testStandardCheckRendersBothLearnMoreAndActionButton(): void
    {
        $html = $this->render([$this->standardCheck()]);

        $this->assertStringContainsString('Learn more', $html);
        $this->assertStringContainsString('Configure API Key', $html);
        // The submit-styled button is specific to critical severity.
        $this->assertStringContainsString('btn small submit', $html);
    }

    public function testDocsOnlyCheckStillShowsMessageAndStatusIndicator(): void
    {
        $html = $this->render([$this->docsOnlyCheck()]);

        $this->assertStringContainsString('Install Imagick to unlock image quality checks.', $html);
        // Warning severity uses the "pending" status dot.
        $this->assertStringContainsString('status pending', $html);
    }

    public function testResolvedChecksAreNotRendered(): void
    {
        $html = $this->render([$this->docsOnlyCheck(['isResolved' => true])]);

        // Banner only renders when there are unresolved checks.
        $this->assertStringNotContainsString('Learn more', $html);
        $this->assertStringNotContainsString('Install Imagick', $html);
    }

    public function testMixedChecksRenderIndependentActionAreas(): void
    {
        $html = $this->render([
            $this->standardCheck(),
            $this->docsOnlyCheck(),
        ]);

        // Both messages present.
        $this->assertStringContainsString('Add your AI provider API key.', $html);
        $this->assertStringContainsString('Install Imagick to unlock image quality checks.', $html);
        // Action button for the standard check, but docs-only check must not
        // have contributed a second button.
        $this->assertSame(
            1,
            substr_count($html, 'btn small'),
            'Only the standard check should render a primary action button; the docs-only check must render the Learn more link alone'
        );
    }
}
