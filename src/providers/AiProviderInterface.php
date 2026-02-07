<?php

declare(strict_types=1);

namespace vitordiniz22\craftlens\providers;

use craft\elements\Asset;
use vitordiniz22\craftlens\dto\AnalysisResult;
use vitordiniz22\craftlens\models\Settings;

/**
 * Interface for AI image analysis providers.
 */
interface AiProviderInterface
{
    /**
     * Returns the unique identifier for this provider.
     */
    public function getName(): string;

    /**
     * Returns the display name for this provider.
     */
    public function getDisplayName(): string;

    /**
     * Analyzes an image asset and returns the analysis result.
     *
     * @throws \vitordiniz22\craftlens\exceptions\AnalysisException
     */
    public function analyze(Asset $asset, Settings $settings): AnalysisResult;

    /**
     * Validates that the provider credentials are configured correctly.
     *
     * @throws \vitordiniz22\craftlens\exceptions\ConfigurationException
     */
    public function validateCredentials(Settings $settings): void;
}
