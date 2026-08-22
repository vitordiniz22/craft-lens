<?php

declare(strict_types=1);

namespace vitordiniz22\craftlenstests\integration\twig;

use Codeception\Test\Unit;
use Craft;
use craft\web\View;

class BulkCompleteStateTest extends Unit
{
    private ?string $originalMode = null;
    private ?string $originalCookieValidationKey = null;

    protected function _before(): void
    {
        parent::_before();
        $view = Craft::$app->getView();
        $this->originalMode = $view->getTemplateMode();
        $view->setTemplateMode(View::TEMPLATE_MODE_CP);

        $request = Craft::$app->getRequest();
        $this->originalCookieValidationKey = $request->cookieValidationKey;
        $request->cookieValidationKey = 'bulk-complete-state-test-key';
    }

    protected function _after(): void
    {
        if ($this->originalMode !== null) {
            Craft::$app->getView()->setTemplateMode($this->originalMode);
        }
        Craft::$app->getRequest()->cookieValidationKey = $this->originalCookieValidationKey;
        parent::_after();
    }

    public function testSuccessfulRunSeparatesHistoricalFailuresFromOutcome(): void
    {
        $html = $this->render([
            'failed' => 2,
            'unprocessed' => 2,
            'analyzed' => 80,
        ], 0);

        $this->assertStringContainsString('Processing Complete', $html);
        $this->assertStringContainsString('Retry 2 Previously Failed', $html);
        $this->assertStringContainsString('name="volumeId" value="5"', $html);
        $this->assertStringNotContainsString('Processing Completed with Errors', $html);
        $this->assertStringNotContainsString('7 / 7', $html);
    }

    public function testFailedRunReportsSessionOutcomeAndLibraryRetryCount(): void
    {
        $html = $this->render([
            'failed' => 3,
            'unprocessed' => 3,
            'analyzed' => 79,
        ], 1);

        $this->assertStringContainsString('Processing Completed with Errors', $html);
        $this->assertStringContainsString('6 / 7', $html);
        $this->assertStringContainsString('Retry All 3 Failed', $html);
    }

    public function testReadyStateRetryPostsSelectedVolume(): void
    {
        $html = Craft::$app->getView()->renderTemplate('lens/_bulk/_state-ready', [
            'stats' => [
                'totalImages' => 3,
                'analyzed' => 1,
                'unprocessed' => 2,
                'failed' => 2,
            ],
            'volumes' => [],
            'selectedVolumeId' => 5,
        ]);

        $this->assertSame(2, substr_count($html, 'name="volumeId" value="5"'));
    }

    private function render(array $stats, int $sessionFailed): string
    {
        return Craft::$app->getView()->renderTemplate('lens/_bulk/_state-complete', [
            'stats' => $stats,
            'session' => [
                'initialUnprocessed' => 7,
                'durationFormatted' => '53s',
                'actualCost' => 0.007,
            ],
            'failureReasons' => [
                'groups' => [],
                'totalFailed' => $sessionFailed,
            ],
            'modelName' => 'gemini-2.5-flash-lite',
            'selectedVolumeId' => 5,
        ]);
    }
}
