<?php

declare(strict_types=1);

namespace vitordiniz22\craftlens\providers;

use Craft;
use craft\elements\Asset;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use vitordiniz22\craftlens\dto\AnalysisResult;
use vitordiniz22\craftlens\enums\LogCategory;
use vitordiniz22\craftlens\models\Settings;
use vitordiniz22\craftlens\enums\LogLevel;
use vitordiniz22\craftlens\exceptions\AnalysisException;
use vitordiniz22\craftlens\exceptions\ConfigurationException;
use vitordiniz22\craftlens\helpers\Logger;
use vitordiniz22\craftlens\helpers\ResponseNormalizer;

/**
 * Base class for AI providers with shared functionality.
 *
 * Extracts common prompt building, response parsing, image loading,
 * and HTTP error handling logic shared across all providers.
 */
abstract class BaseAiProvider implements AiProviderInterface
{
    protected Client $client;

    public function __construct()
    {
        $this->client = Craft::createGuzzleClient([
            'timeout' => 120,
            'connect_timeout' => 10,
        ]);
    }

    /**
     * Extract the AI-generated content text from the provider's raw response.
     */
    abstract protected function extractContentText(array $response): string;

    /**
     * Extract token usage from the provider's raw response.
     *
     * @return array{inputTokens: int, outputTokens: int}
     */
    abstract protected function extractTokenUsage(array $response): array;

    /**
     * Get the maximum file size in bytes that this provider accepts for base64 encoding.
     * Accounts for 33% base64 encoding overhead.
     *
     * @return int Maximum file size in bytes
     */
    abstract protected function getMaxFileSizeBytes(): int;

    /**
     * Send the analysis request to the provider's API.
     *
     * @param array{base64: string, mimeType: string} $imageData
     * @return array Raw API response body
     */
    abstract protected function sendRequest(Settings $settings, array $imageData, string $prompt, int $assetId): array;

    /**
     * Analyze an image asset using this provider's API.
     */
    final public function analyze(
        Asset $asset,
        Settings $settings,
        string $primaryLanguage,
        array $additionalLanguages = [],
    ): AnalysisResult {
        $this->validateCredentials($settings);

        $imageData = $this->getBase64ImageData($asset);
        $prompt = $this->buildPrompt($primaryLanguage, $additionalLanguages);
        $response = $this->sendRequest($settings, $imageData, $prompt, $asset->id);

        return $this->parseResponse($response);
    }

    /**
     * Builds the analysis prompt for image analysis.
     *
     * @param string $primaryLanguage Language code for all generated text
     * @param string[] $additionalLanguages Extra languages for per-site alt text and title
     */
    protected function buildPrompt(string $primaryLanguage, array $additionalLanguages = []): string
    {
        $primaryName = $this->languageDisplayName($primaryLanguage);

        $hasTranslations = !empty($additionalLanguages);
        $languageNote = $hasTranslations
            ? sprintf('Write all TOP-LEVEL text fields in %s (%s). Translations for other languages go in a separate "siteContent" object (described below).', $primaryName, $primaryLanguage)
            : sprintf('Write ALL text fields in %s (%s) only.', $primaryName, $primaryLanguage);

        $instructions = [sprintf(
            'Analyze this image and provide the following information in JSON format. LANGUAGE: %s',
            $languageNote
        )];

        $instructions[] = sprintf('- "altText": A natural, descriptive alt text for accessibility (1-2 sentences, in %s)', $primaryName);
        $instructions[] = '- "altTextConfidence": Your confidence in the alt text (0.0-1.0)';

        $instructions[] = sprintf('- "longDescription": A detailed, comprehensive description (3-5 sentences) providing rich context about the image content, composition, subjects, setting, mood, notable details, and any relevant background elements. Be thorough and descriptive to maximize searchability (in %s)', $primaryName);
        $instructions[] = '- "longDescriptionConfidence": Your confidence in the long description (0.0-1.0)';

        $instructions[] = sprintf('- "suggestedTitle": A concise title (2-6 words, Title Case, specific not generic, in %s)', $primaryName);
        $instructions[] = '- "titleConfidence": Your confidence in the title (0.0-1.0)';
        $instructions[] = '  Title rules: NO "Image of/Photo of" prefixes, NO file extensions, be SPECIFIC';

        $instructions[] = sprintf('- "tags": An array of objects with "tag" (lowercase single word or short phrase, in %s) and "confidence" (0.0-1.0), generate at least 20 tags (aim for 20-25)', $primaryName);
        $instructions[] = '  Tag vocabulary guidelines for DAM (Digital Asset Management) systems:';
        $instructions[] = '  • Prefer COMMON, GENERAL-PURPOSE tags that are widely understood and searchable (e.g., "beach", "sunset", "portrait", "food", "architecture", "business")';
        $instructions[] = '  • Avoid overly specific or technical terms unless they are the PRIMARY subject (e.g., prefer "flower" over "chrysanthemum", "car" over "sedan", "building" over "skyscraper" unless specificity is critical)';
        $instructions[] = '  • Use industry-standard DAM categories: subjects (people, animals, objects), settings (indoor, outdoor, urban, nature, office), styles (vintage, modern, minimalist), activities (sports, working, eating, meeting), emotions/mood (happy, serious, calm, energetic), and concepts (teamwork, success, growth)';
        $instructions[] = '  • Focus on tags that would be useful for search and categorization across a large professional image library';
        $instructions[] = '  • Avoid brand names, artist names, or location-specific details unless they are obvious and iconic (e.g., "eiffel tower" is acceptable, but not "paris 16th arrondissement")';
        $instructions[] = '  • Prioritize tags that describe WHAT is in the image, not HOW it was made (avoid "bokeh", "long exposure", "f/2.8" unless these are the main subject)';
        $instructions[] = '- "dominantColors": An array of objects with "hex" (color in #RRGGBB format) and "percentage" (0.0-1.0), generate exactly 6 colors';
        $instructions[] = '- "extractedText": Any visible text in the image (signs, labels, packaging, etc.), or null if none';
        $instructions[] = '- "containsPeople": Carefully examine the image for ANY human presence. Set to true if the image contains:';
        $instructions[] = '  - People facing the camera (even if face is small/unclear)';
        $instructions[] = '  - People from behind, side, or any angle where face is not visible';
        $instructions[] = '  - Silhouettes or shadows of people';
        $instructions[] = '  - Partial views of people (e.g., just hands, legs, torso)';
        $instructions[] = '  - People at any distance (far away or close up)';
        $instructions[] = '  Set to false ONLY if you are confident no human is present. Boolean.';
        $instructions[] = '- "faceCount": Count human faces that are recognizable as faces, even if partially visible. Important rules:';
        $instructions[] = '  - Count a face if you can identify it as a human face, even at an angle, in profile, partially turned, or in motion';
        $instructions[] = '  - Count faces where at least some facial features (eyes, nose, mouth, jawline) are visible, even if not all are clear';
        $instructions[] = '  - DO count faces in action scenes (fighting, sports, dancing) even if slightly blurred by motion';
        $instructions[] = '  - DO count faces seen from the side (profile view) or at three-quarter angles';
        $instructions[] = '  - Do NOT count faces that are completely hidden (facing fully away, fully covered by mask/object)';
        $instructions[] = '  - Do NOT count heavily pixelated faces where no features can be made out at all';
        $instructions[] = '  - If people are visible but ALL faces are fully obscured/from behind, set faceCount to 0 but containsPeople to true';
        $instructions[] = '  - Return integer, 0 if no faces visible but people may still be present';
        $instructions[] = '- "containsPeopleConfidence": How confident you are in your containsPeople and faceCount assessment (0.0-1.0). Should be HIGH (0.8-1.0) when the image clearly shows people OR clearly shows no people. Should be LOW (0.3-0.6) only when the image is genuinely ambiguous (e.g., distant figures, mannequins, statues that might be people)';
        $instructions[] = '- "nsfwScore": Overall NSFW/unsafe content confidence score (0.0-1.0). This should reflect ANY content that may be inappropriate for general audiences, including:';
        $instructions[] = '  • Sexual/adult content (nudity, sexual acts, suggestive poses, lingerie, revealing clothing)';
        $instructions[] = '  • Violence (fighting, weapons, blood, injuries, gore, dead bodies, torture)';
        $instructions[] = '  • Hate symbols or imagery';
        $instructions[] = '  • Self-harm imagery';
        $instructions[] = '  • Drug use or paraphernalia';
        $instructions[] = '  Scoring guidance (use these as MINIMUM scores; if multiple factors apply, use the HIGHEST applicable range or above):';
        $instructions[] = '    0.1-0.2: Mildly suggestive (form-fitting clothing, mild innuendo, cartoon violence)';
        $instructions[] = '    0.2-0.4: Moderately suggestive (shirtless individuals, dark/macabre themes, visible non-graphic injuries, suggestive poses)';
        $instructions[] = '    0.4-0.6: Partial nudity, weapons in threatening context, blood without gore, restraints or bondage imagery';
        $instructions[] = '    0.6-0.8: Explicit nudity, graphic violence/gore, explicit drug use';
        $instructions[] = '    0.8-1.0: Extreme content (pornography, extreme gore, torture)';
        $instructions[] = '  IMPORTANT: When MULTIPLE concerning elements appear together, scores should COMPOUND. For example: shirtless person (0.25) + suggestive objects/restraints (0.2) + dark unsettling composition (0.15) together should score at least 0.4-0.5, not just 0.25.';
        $instructions[] = '- "nsfwConfidence": How confident you are in your nsfwScore assessment (0.0-1.0). Should be HIGH (0.8-1.0) when content is clearly safe OR clearly unsafe. Should be LOW (0.3-0.6) only when the image is genuinely ambiguous (e.g., borderline suggestive content, artistic nudity, fantasy violence)';
        $instructions[] = '- "nsfwCategories": Array of objects with "category" (one of: adult, violence, hate, self-harm, sexual-minors, drugs) and "confidence" (0.0-1.0). Only include categories with confidence > 0.1';
        $instructions[] = '  Violence category should include: fighting, weapons (guns, knives, swords), blood, injuries, physical assault, warfare, dead bodies, torture';
        $instructions[] = '  Adult category should include: nudity, sexual content, suggestive poses, intimate acts, revealing clothing, shirtless individuals';
        $instructions[] = '- "overallQualityScore": Combined technical quality assessment (0.0-1.0) for professional DAM use';
        $instructions[] = '  CRITICAL: This score must reflect production-readiness and usability. Quality detracting factors that MUST reduce the score:';
        $instructions[] = '  • Watermarks (visible overlays, stock photo watermarks, distracting logos): Reduce score significantly (by 30-50%) depending on obtrusiveness. Obtrusive watermarks should score ≤0.4';
        $instructions[] = '  • Low resolution/pixelation: Images below 1024x768 or with visible pixelation/compression artifacts should score ≤0.5. Thumbnail-sized images (<512px) should score ≤0.3';
        $instructions[] = '  • Heavy compression artifacts: JPEG artifacts, color banding, posterization, blocky compression should reduce score proportionally to severity';
        $instructions[] = '  • Multiple issues compound: An image with watermark + low resolution + noise should score ≤0.3. Multiple major issues should result in scores below 0.2';
        $instructions[] = '  Example scoring for context:';
        $instructions[] = '    - Pristine high-resolution, professional photo = 0.9-1.0';
        $instructions[] = '    - Good quality with minor issues (slight noise, small size) = 0.6-0.8';
        $instructions[] = '    - Watermarked stock photo (obtrusive) = 0.2-0.4';
        $instructions[] = '    - Low-resolution (800x600) with watermark = 0.1-0.3';
        $instructions[] = '    - Thumbnail with multiple issues = 0.0-0.2';
        $instructions[] = '- "hasWatermark": Whether the image contains any visible watermark (boolean)';
        $instructions[] = '- "watermarkConfidence": How confident you are in your hasWatermark assessment (0.0-1.0). Should be HIGH (0.8-1.0) when the image clearly has a watermark OR clearly has no watermark. Should be LOW (0.3-0.6) only when the image is genuinely ambiguous (e.g., faint overlays, decorative text that might be a watermark)';
        $instructions[] = '- "watermarkType": Type of watermark detected. Must be one of: stock, logo, text, copyright, unknown, or null if no watermark';
        $instructions[] = '- "watermarkDetails": Object with additional details:';
        $instructions[] = '  - "position": Where the watermark appears (center, corner, diagonal, tiled, edge)';
        $instructions[] = '  - "detectedText": Any text visible in the watermark';
        $instructions[] = '  - "stockProvider": If stock watermark, the provider name (e.g., Shutterstock, Getty, iStock, Adobe Stock)';
        $instructions[] = '  - "isObtrusive": Whether the watermark significantly obscures the image content (boolean)';
        $instructions[] = '- "focalPointX": X coordinate (0.0-1.0, left to right) of the primary subject or visual focal point of the image';
        $instructions[] = '- "focalPointY": Y coordinate (0.0-1.0, top to bottom) of the primary subject or visual focal point of the image';
        $instructions[] = '- "focalPointConfidence": Confidence in the focal point detection (0.0-1.0)';
        $instructions[] = '- "containsBrandLogo": Whether the image contains any recognizable brand logos (boolean)';
        $instructions[] = '- "containsBrandLogoConfidence": How confident you are in your containsBrandLogo assessment (0.0-1.0). Should be HIGH (0.8-1.0) when the image clearly has brand logos OR clearly has no brand logos. Should be LOW (0.3-0.6) only when the image is genuinely ambiguous (e.g., partial logos, generic shapes that might be a brand)';
        $instructions[] = '- "detectedBrands": Array of objects with "brand" (company/brand name), "confidence" (0.0-1.0), and "position" (location in image)';

        if (!empty($additionalLanguages)) {
            $langEntries = array_map(fn(string $code) => sprintf('%s (%s)', $this->languageDisplayName($code), $code), $additionalLanguages);
            $langList = implode(', ', $langEntries);
            $instructions[] = '';
            $instructions[] = '--- TRANSLATIONS ---';
            $instructions[] = sprintf(
                'The top-level fields above are in %s. Now provide TRANSLATIONS of "altText" and "suggestedTitle" '
                . 'into each of the following languages: %s.',
                $primaryName,
                $langList
            );
            $instructions[] = 'Return these translations in a "siteContent" object keyed by language code. '
                . 'Each entry MUST include both "altText" and "suggestedTitle" fully translated into that language. '
                . 'Do NOT leave altText or suggestedTitle as null, empty, or in the original language.';
            $instructions[] = 'Expected structure:';
            $instructions[] = '"siteContent": {';

            foreach ($additionalLanguages as $lang) {
                $langName = $this->languageDisplayName($lang);
                $instructions[] = sprintf(
                    '  "%s": {"altText": "... (in %s)", "altTextConfidence": 0.0-1.0, "suggestedTitle": "... (in %s)", "titleConfidence": 0.0-1.0},',
                    $lang,
                    $langName,
                    $langName
                );
            }
            $instructions[] = '}';
        }

        $instructions[] = '';
        $instructions[] = 'Respond ONLY with valid JSON, no markdown or explanation.';

        return implode("\n", $instructions);
    }

    /**
     * Parse the raw API response into an AnalysisResult.
     */
    protected function parseResponse(array $response): AnalysisResult
    {
        $content = ResponseNormalizer::stripMarkdownCodeBlocks($this->extractContentText($response));

        if ($content === '') {
            throw AnalysisException::invalidResponse($this->getName());
        }

        $data = ResponseNormalizer::safeJsonDecode($content, $this->getName());

        // Detect language mixing: if a top-level text field is identical to a
        // siteContent translation, the AI used the wrong language. Clear the
        // top-level value so it does not overwrite the main record.
        $data = $this->fixLanguageMixing($data);

        $nsfwScore = ResponseNormalizer::clampConfidence($data['nsfwScore'] ?? 0.0);
        $nsfwConfidence = ResponseNormalizer::clampConfidence($data['nsfwConfidence'] ?? 0.0);
        $detectedBrands = ResponseNormalizer::normalizeDetectedBrands($data['detectedBrands'] ?? [], $this->getName());
        $usage = $this->extractTokenUsage($response);

        return new AnalysisResult(
            altText: $data['altText'] ?? '',
            altTextConfidence: (float) ($data['altTextConfidence'] ?? 0.0),
            longDescription: $data['longDescription'] ?? '',
            longDescriptionConfidence: (float) ($data['longDescriptionConfidence'] ?? 0.0),
            suggestedTitle: $data['suggestedTitle'] ?? '',
            titleConfidence: (float) ($data['titleConfidence'] ?? 0.0),
            tags: ResponseNormalizer::normalizeTags($data['tags'] ?? [], $this->getName()),
            dominantColors: ResponseNormalizer::normalizeColors($data['dominantColors'] ?? [], $this->getName()),
            extractedText: $data['extractedText'] ?? null,
            faceCount: (int) ($data['faceCount'] ?? 0),
            containsPeople: (bool) ($data['containsPeople'] ?? false),
            containsPeopleConfidence: ResponseNormalizer::clampConfidence($data['containsPeopleConfidence'] ?? 0.0),
            rawResponse: $response,
            customPromptResult: null,
            nsfwScore: $nsfwScore,
            nsfwConfidence: $nsfwConfidence,
            nsfwCategories: ResponseNormalizer::normalizeNsfwCategories($data['nsfwCategories'] ?? [], $this->getName()),
            isFlaggedNsfw: $nsfwScore >= 0.5,
            hasWatermark: (bool) ($data['hasWatermark'] ?? false),
            watermarkConfidence: ResponseNormalizer::clampConfidence($data['watermarkConfidence'] ?? 0.0),
            watermarkType: ResponseNormalizer::normalizeWatermarkType($data['watermarkType'] ?? null),
            watermarkDetails: ResponseNormalizer::normalizeWatermarkDetails($data['watermarkDetails'] ?? []),
            containsBrandLogo: !empty($detectedBrands),
            containsBrandLogoConfidence: ResponseNormalizer::clampConfidence($data['containsBrandLogoConfidence'] ?? 0.0),
            detectedBrands: $detectedBrands,
            inputTokens: $usage['inputTokens'],
            outputTokens: $usage['outputTokens'],
            overallQualityScore: ResponseNormalizer::clampConfidence($data['overallQualityScore'] ?? 0.0),
            focalPointX: isset($data['focalPointX']) ? ResponseNormalizer::clampConfidence((float) $data['focalPointX']) : null,
            focalPointY: isset($data['focalPointY']) ? ResponseNormalizer::clampConfidence((float) $data['focalPointY']) : null,
            focalPointConfidence: isset($data['focalPointConfidence']) ? ResponseNormalizer::clampConfidence((float) $data['focalPointConfidence']) : null,
            siteContent: $this->parseSiteContent($data['siteContent'] ?? []),
        );
    }

    /**
     * Detect and fix language mixing in the AI response.
     *
     * When the AI returns the same text in a top-level field and in a
     * siteContent translation entry, it almost certainly used the wrong
     * language for the top-level field. Clear the top-level value so
     * the downstream dual-write logic keeps the previous (correct) value.
     *
     * @param array<string, mixed> $data Decoded JSON response
     * @return array<string, mixed> Sanitized response
     */
    private function fixLanguageMixing(array $data): array
    {
        $siteContent = $data['siteContent'] ?? [];

        if (!is_array($siteContent) || empty($siteContent)) {
            return $data;
        }

        foreach ($siteContent as $lang => $entry) {
            if (!is_array($entry)) {
                continue;
            }

            // Title: if top-level matches a translation, the AI used the wrong language
            $scTitle = $entry['suggestedTitle'] ?? '';
            $topTitle = $data['suggestedTitle'] ?? '';
            if ($scTitle !== '' && $topTitle !== '' && mb_strtolower($topTitle) === mb_strtolower($scTitle)) {
                $data['suggestedTitle'] = '';
                Logger::warning(
                    LogCategory::AssetProcessing,
                    sprintf('AI returned identical suggestedTitle in top-level and siteContent[%s] — cleared top-level to prevent wrong language', $lang),
                );
            }

            // Alt text: same check
            $scAlt = $entry['altText'] ?? '';
            $topAlt = $data['altText'] ?? '';
            if ($scAlt !== '' && $topAlt !== '' && mb_strtolower($topAlt) === mb_strtolower($scAlt)) {
                $data['altText'] = '';
                Logger::warning(
                    LogCategory::AssetProcessing,
                    sprintf('AI returned identical altText in top-level and siteContent[%s] — cleared top-level to prevent wrong language', $lang),
                );
            }
        }

        return $data;
    }

    /**
     * Parse and validate the siteContent structure from AI response.
     *
     * @return array<string, array{altText: string, suggestedTitle: string, altTextConfidence?: float, titleConfidence?: float}>
     */
    private function parseSiteContent(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }

        $result = [];

        foreach ($raw as $lang => $entry) {
            if (!is_string($lang) || !is_array($entry)) {
                continue;
            }

            $altText = $entry['altText'] ?? '';
            $suggestedTitle = $entry['suggestedTitle'] ?? '';

            if ($altText === '' && $suggestedTitle === '') {
                continue;
            }

            $result[$lang] = [
                'altText' => (string) $altText,
                'suggestedTitle' => (string) $suggestedTitle,
                'altTextConfidence' => isset($entry['altTextConfidence'])
                    ? ResponseNormalizer::clampConfidence((float) $entry['altTextConfidence'])
                    : null,
                'titleConfidence' => isset($entry['titleConfidence'])
                    ? ResponseNormalizer::clampConfidence((float) $entry['titleConfidence'])
                    : null,
            ];
        }

        return $result;
    }

    /**
     * Convert a locale code (e.g. "en", "pt-BR") to a human-readable language name.
     *
     * Uses PHP's Locale class when intl is available, otherwise falls back to
     * the locale code itself — still clearer than a bare code in the prompt.
     */
    private function languageDisplayName(string $code): string
    {
        if (class_exists(\Locale::class)) {
            $name = \Locale::getDisplayLanguage($code, 'en');
            if ($name !== '' && $name !== $code) {
                return $name;
            }
        }

        return $code;
    }

    /**
     * Get base64-encoded image data from an asset.
     *
     * @return array{base64: string, mimeType: string}
     * @throws AnalysisException
     */
    protected function getBase64ImageData(Asset $asset): array
    {
        $fileSize = $asset->size;
        $maxSize = $this->getMaxFileSizeBytes();

        if ($fileSize !== null && $fileSize > $maxSize) {
            throw AnalysisException::fileTooLarge(
                providerName: $this->getDisplayName(),
                assetId: $asset->id,
                fileSize: $fileSize,
                maxSize: $maxSize
            );
        }

        $stream = $asset->getStream();

        if ($stream === null) {
            throw AnalysisException::assetNotReadable($asset->id);
        }

        try {
            $contents = stream_get_contents($stream);

            if ($contents === false) {
                throw AnalysisException::assetNotReadable($asset->id);
            }

            $mimeType = $asset->getMimeType() ?? 'image/jpeg';

            return ['base64' => base64_encode($contents), 'mimeType' => $mimeType];
        } finally {
            fclose($stream);
        }
    }

    private const MAX_RETRIES = 2;
    private const RETRYABLE_STATUS_CODES = [429, 502, 503];
    private const MAX_RETRY_AFTER_SECONDS = 30;

    /**
     * Execute an HTTP request with standardized Guzzle error handling and retry logic.
     *
     * Retries on transient errors (429, 502, 503) with exponential backoff.
     * For 429 responses, respects the Retry-After header when present.
     *
     * @param callable(int): array $request Receives start time (hrtime), returns parsed response body
     * @throws AnalysisException
     */
    protected function executeApiRequest(callable $request, int $assetId): array
    {
        $lastException = null;

        for ($attempt = 0; $attempt <= self::MAX_RETRIES; $attempt++) {
            $startTime = hrtime(true);

            try {
                return $request($startTime);
            } catch (ConnectException $e) {
                $elapsed = (int) ((hrtime(true) - $startTime) / 1_000_000);
                $sanitizedMessage = $this->sanitizeErrorMessage($e->getMessage());
                Logger::apiCall(
                    provider: $this->getName(),
                    message: 'Connection failed: ' . $sanitizedMessage,
                    assetId: $assetId,
                    responseTimeMs: $elapsed,
                    httpStatusCode: null,
                    level: LogLevel::Error->value,
                );
                throw AnalysisException::apiError(
                    $this->getName(),
                    'Connection failed: ' . $sanitizedMessage,
                    $assetId
                );
            } catch (RequestException $e) {
                $elapsed = (int) ((hrtime(true) - $startTime) / 1_000_000);
                $statusCode = $e->hasResponse() ? $e->getResponse()->getStatusCode() : null;
                $errorMessage = $this->extractErrorMessage($e) ?? $this->getDefaultErrorMessage($statusCode);

                if ($statusCode !== null && in_array($statusCode, self::RETRYABLE_STATUS_CODES, true) && $attempt < self::MAX_RETRIES) {
                    $delay = $this->getRetryDelay($e, $attempt);
                    Logger::apiCall(
                        provider: $this->getName(),
                        message: "Retryable error (HTTP {$statusCode}), attempt " . ($attempt + 1) . '/' . (self::MAX_RETRIES + 1) . " - retrying in {$delay}s: {$errorMessage}",
                        assetId: $assetId,
                        responseTimeMs: $elapsed,
                        httpStatusCode: $statusCode,
                        level: LogLevel::Warning->value,
                    );
                    $lastException = $e;
                    sleep($delay);
                    continue;
                }

                Logger::apiCall(
                    provider: $this->getName(),
                    message: $errorMessage,
                    assetId: $assetId,
                    responseTimeMs: $elapsed,
                    httpStatusCode: $statusCode,
                    level: LogLevel::Error->value,
                );
                throw AnalysisException::apiError(
                    $this->getName(),
                    $errorMessage,
                    $assetId,
                    $statusCode
                );
            } catch (GuzzleException $e) {
                $elapsed = (int) ((hrtime(true) - $startTime) / 1_000_000);
                $sanitizedMessage = $this->sanitizeErrorMessage($e->getMessage());
                Logger::apiCall(
                    provider: $this->getName(),
                    message: $sanitizedMessage,
                    assetId: $assetId,
                    responseTimeMs: $elapsed,
                    httpStatusCode: null,
                    level: LogLevel::Error->value,
                );
                throw AnalysisException::apiError(
                    $this->getName(),
                    $sanitizedMessage,
                    $assetId
                );
            }
        }

        // Should not reach here, but handle defensively
        $errorMessage = $lastException !== null
            ? $this->sanitizeErrorMessage($lastException->getMessage())
            : 'Request failed after retries';
        throw AnalysisException::apiError($this->getName(), $errorMessage, $assetId);
    }

    /**
     * Calculate retry delay with exponential backoff, respecting Retry-After header.
     */
    private function getRetryDelay(RequestException $e, int $attempt): int
    {
        $baseDelay = (int) (2 ** ($attempt + 1)); // 2s, 4s

        if ($e->hasResponse()) {
            $retryAfter = $e->getResponse()->getHeaderLine('Retry-After');

            if ($retryAfter !== '' && is_numeric($retryAfter)) {
                return min((int) $retryAfter, self::MAX_RETRY_AFTER_SECONDS);
            }
        }

        return $baseDelay;
    }

    /**
     * Extract error message from a provider's error response body.
     */
    protected function extractErrorMessage(RequestException $e): ?string
    {
        if (!$e->hasResponse()) {
            return null;
        }

        try {
            $bodyContents = $e->getResponse()->getBody()->getContents();
        } catch (\RuntimeException) {
            return null;
        }
        $body = json_decode($bodyContents, true);

        if (!is_array($body)) {
            return null;
        }

        $message = $body['error']['message'] ?? null;

        return $message !== null ? $this->sanitizeErrorMessage($message) : null;
    }

    /**
     * Sanitize an error message by truncating and stripping potential API keys.
     */
    protected function sanitizeErrorMessage(string $message): string
    {
        $message = mb_substr($message, 0, 500);

        // Strip common API key patterns
        $message = preg_replace('/\bsk-[a-zA-Z0-9]{20,}\b/', '[REDACTED]', $message);
        $message = preg_replace('/\bAIza[a-zA-Z0-9_-]{30,}\b/', '[REDACTED]', $message);
        $message = preg_replace('/[?&](key|api_key|apikey)=[^&\s]+/', '$1=[REDACTED]', $message);

        return $message;
    }

    /**
     * Get a human-readable error message for common HTTP status codes.
     */
    protected function getDefaultErrorMessage(?int $statusCode): string
    {
        return match ($statusCode) {
            400 => "Invalid request to {$this->getDisplayName()} API",
            401 => "Invalid API key or unauthorized access. Please check your {$this->getDisplayName()} API key in the plugin settings.",
            403 => 'Access denied - check your API key permissions',
            404 => 'The requested model was not found',
            429 => 'Rate limit exceeded - please try again later',
            500, 502, 503 => "{$this->getDisplayName()} service temporarily unavailable",
            default => 'Request failed',
        };
    }

    /**
     * Parse and validate a JSON response body.
     *
     * @throws AnalysisException
     */
    protected function parseJsonBody(string $body, int $assetId): array
    {
        $data = json_decode($body, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw AnalysisException::invalidResponse(
                $this->getName(),
                $assetId,
                'JSON parsing failed: ' . json_last_error_msg()
            );
        }

        return $data;
    }

    /**
     * Check for API-level error in the response body.
     *
     * @throws AnalysisException
     */
    protected function checkApiError(array $body, int $assetId): void
    {
        if (isset($body['error'])) {
            throw AnalysisException::apiError(
                $this->getName(),
                $body['error']['message'] ?? 'Unknown API error',
                $assetId
            );
        }
    }

    /**
     * Execute a lightweight API request to verify credentials.
     * Subclasses provide the URL, headers, and method.
     *
     * @throws ConfigurationException
     */
    protected function executeTestRequest(string $url, array $headers, string $method = 'GET'): void
    {
        try {
            $this->client->request($method, $url, [
                'headers' => $headers,
                'timeout' => 10,
            ]);
        } catch (RequestException $e) {
            $statusCode = $e->hasResponse() ? $e->getResponse()->getStatusCode() : null;

            // Parse the API response body for a clean error message
            $apiMessage = $e->hasResponse() ? $this->extractErrorMessage($e) : null;

            if ($apiMessage) {
                Logger::warning(
                    LogCategory::Configuration,
                    sprintf('%s API test failed (HTTP %s): %s', $this->getDisplayName(), $statusCode ?? 'N/A', $apiMessage),
                );
            }

            if ($statusCode === 400 || $statusCode === 401 || $statusCode === 403) {
                throw new ConfigurationException(
                    "Your {$this->getDisplayName()} API key is invalid. Please check your credentials in the plugin settings."
                );
            }

            if ($statusCode === 429) {
                throw new ConfigurationException(
                    "{$this->getDisplayName()} rate limit exceeded. Please wait a moment and try again."
                );
            }

            if ($statusCode !== null && $statusCode >= 500) {
                throw new ConfigurationException(
                    "{$this->getDisplayName()} is experiencing issues (HTTP {$statusCode}). Please try again later."
                );
            }

            throw new ConfigurationException(
                "Could not connect to {$this->getDisplayName()}. Please check your internet connection and plugin settings."
            );
        } catch (GuzzleException $e) {
            throw new ConfigurationException(
                "Could not connect to {$this->getDisplayName()}. Please check your internet connection and try again."
            );
        }
    }
}
