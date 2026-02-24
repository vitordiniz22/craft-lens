<?php

declare(strict_types=1);

namespace vitordiniz22\craftlens\console\controllers;

use craft\console\Controller;
use craft\helpers\Console;
use vitordiniz22\craftlens\enums\LogCategory;
use vitordiniz22\craftlens\exceptions\AnalysisException;
use vitordiniz22\craftlens\exceptions\ConfigurationException;
use vitordiniz22\craftlens\helpers\Logger;
use vitordiniz22\craftlens\Plugin;
use yii\console\ExitCode;

/**
 * Validates the AI provider configuration.
 */
class ValidateController extends Controller
{
    /**
     * Tests the API connection with the configured provider.
     */
    public function actionIndex(): int
    {
        $this->stdout("Validating Lens configuration...\n\n");

        try {
            Plugin::getInstance()->aiProvider->testConnection();
            $this->stdout("API credentials are valid.\n", Console::FG_GREEN);
            return ExitCode::OK;
        } catch (AnalysisException $e) {
            $this->stderr("API Error: {$e->getMessage()}\n", Console::FG_RED);
            Logger::error(LogCategory::AssetProcessing, "Validation API error: {$e->getMessage()}", exception: $e);
            return ExitCode::CONFIG;
        } catch (ConfigurationException $e) {
            $this->stderr("Configuration Error: {$e->getMessage()}\n", Console::FG_RED);
            Logger::error(LogCategory::Configuration, "Validation config error: {$e->getMessage()}", exception: $e);
            return ExitCode::CONFIG;
        } catch (\Throwable $e) {
            $this->stderr("Unexpected error: {$e->getMessage()}\n", Console::FG_RED);
            Logger::error(LogCategory::Configuration, "Unexpected validation error: {$e->getMessage()}", exception: $e);
            return ExitCode::UNSPECIFIED_ERROR;
        }
    }
}
