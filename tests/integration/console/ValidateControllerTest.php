<?php

declare(strict_types=1);

namespace vitordiniz22\craftlenstests\integration\console;

use Codeception\Test\Unit;
use Craft;
use vitordiniz22\craftlens\console\controllers\ValidateController;
use vitordiniz22\craftlens\exceptions\AnalysisException;
use vitordiniz22\craftlens\exceptions\ConfigurationException;
use vitordiniz22\craftlens\Plugin;
use vitordiniz22\craftlens\services\AiProviderService;
use yii\console\ExitCode;

class ValidateControllerTest extends Unit
{
    private CapturingValidateController $controller;

    protected function _before(): void
    {
        parent::_before();
        $this->controller = new CapturingValidateController('validate', Craft::$app);
        $this->controller->interactive = false;
    }

    public function testIndexHappyPath(): void
    {
        $stub = new class extends AiProviderService {
            public function init(): void
            {
            }

            public function testConnection(): void
            {
            }
        };

        [$exitCode, $stdout, $stderr] = $this->runWithAiProvider($stub);

        $this->assertSame(ExitCode::OK, $exitCode);
        $this->assertSame('', $stderr);
        $this->assertStringContainsString('Validating Lens configuration', $stdout);
        $this->assertStringContainsString('API credentials are valid', $stdout);
    }

    public function testIndexAnalysisExceptionReturnsConfigExit(): void
    {
        $stub = new class extends AiProviderService {
            public function init(): void
            {
            }

            public function testConnection(): void
            {
                throw new AnalysisException('bad key');
            }
        };

        [$exitCode, $stdout, $stderr] = $this->runWithAiProvider($stub);

        $this->assertSame(ExitCode::CONFIG, $exitCode);
        $this->assertStringContainsString('Validating Lens configuration', $stdout);
        $this->assertStringContainsString('API Error: bad key', $stderr);
    }

    public function testIndexConfigurationExceptionReturnsConfigExit(): void
    {
        $stub = new class extends AiProviderService {
            public function init(): void
            {
            }

            public function testConnection(): void
            {
                throw new ConfigurationException('no provider');
            }
        };

        [$exitCode, $stdout, $stderr] = $this->runWithAiProvider($stub);

        $this->assertSame(ExitCode::CONFIG, $exitCode);
        $this->assertStringContainsString('Configuration Error: no provider', $stderr);
    }

    public function testIndexUnexpectedThrowableReturnsUnspecifiedError(): void
    {
        $stub = new class extends AiProviderService {
            public function init(): void
            {
            }

            public function testConnection(): void
            {
                throw new \RuntimeException('boom');
            }
        };

        [$exitCode, $stdout, $stderr] = $this->runWithAiProvider($stub);

        $this->assertSame(ExitCode::UNSPECIFIED_ERROR, $exitCode);
        $this->assertStringContainsString('Unexpected error: boom', $stderr);
    }

    /**
     * Swap the aiProvider component, run the action, restore. Mirrors the
     * try/finally pattern in ProcessControllerTest::testRetryFailedHandlesServiceException.
     *
     * @return array{0:int,1:string,2:string}
     */
    private function runWithAiProvider(AiProviderService $stub): array
    {
        $original = Plugin::getInstance()->aiProvider;
        Plugin::getInstance()->set('aiProvider', $stub);

        try {
            return $this->capture(fn () => $this->controller->actionIndex());
        } finally {
            Plugin::getInstance()->set('aiProvider', $original);
        }
    }

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
class CapturingValidateController extends ValidateController
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
