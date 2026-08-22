<?php

declare(strict_types=1);

namespace vitordiniz22\craftlens\providers;

use Craft;
use craft\elements\Asset;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use Psr\Http\Message\ResponseInterface;
use vitordiniz22\craftlens\dto\AnalysisResult;
use vitordiniz22\craftlens\enums\LogCategory;
use vitordiniz22\craftlens\enums\LogLevel;
use vitordiniz22\craftlens\exceptions\AnalysisException;
use vitordiniz22\craftlens\exceptions\ConfigurationException;
use vitordiniz22\craftlens\helpers\AnalysisImageContext;
use vitordiniz22\craftlens\helpers\ImagePreprocessor;
use vitordiniz22\craftlens\helpers\Logger;
use vitordiniz22\craftlens\helpers\MemoryBudget;
use vitordiniz22\craftlens\helpers\PreprocessResult;
use vitordiniz22\craftlens\helpers\ResponseNormalizer;
use vitordiniz22\craftlens\models\Settings;
use vitordiniz22\craftlens\Plugin;

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
            'timeout' => 45,
            'connect_timeout' => 10,
        ]);
    }

    /**
     * Extract the AI-generated content text from the provider's raw response.
     */
    abstract protected function extractContentText(array $response): string;

    /**
     * Whether the response was truncated due to token limits.
     */
    protected function isResponseTruncated(array $response): bool
    {
        return false;
    }

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
     * Build the provider-specific HTTP request specification.
     *
     * Returns an array describing the outgoing request and how to parse a
     * successful response.
     *
     * @param array{base64: string, mimeType: string} $imageData
     * @return array{method: string, url: string, options: array<string, mixed>, parseResponse: \Closure(ResponseInterface, int): array}
     */
    abstract protected function buildHttpRequest(
        Settings $settings,
        array $imageData,
        string $prompt,
        int $assetId,
    ): array;

    /**
     * Send the analysis request to the provider's API synchronously.
     *
     * @param array{base64: string, mimeType: string} $imageData
     * @return array Raw API response body
     */
    protected function sendRequest(Settings $settings, array $imageData, string $prompt, int $assetId): array
    {
        $spec = $this->buildHttpRequest($settings, $imageData, $prompt, $assetId);
        $payloadSizeBytes = strlen($imageData['base64']);

        return $this->executeApiRequest(function(int $startTime) use ($spec) {
            $response = $this->client->request($spec['method'], $spec['url'], $spec['options']);
            return ($spec['parseResponse'])($response, $startTime);
        }, $assetId, $payloadSizeBytes);
    }

    /**
     * Analyze an image asset using this provider's API.
     */
    final public function analyze(
        AnalysisImageContext $context,
        Settings $settings,
        string $primaryLanguage,
        array $additionalLanguages = [],
        ?PreprocessResult $preparedImage = null,
    ): AnalysisResult {
        $this->validateCredentials($settings);

        $imageData = $this->getBase64ImageData($context, $preparedImage);
        $prompt = $this->buildPrompt($primaryLanguage, $additionalLanguages);
        $response = $this->sendRequest($settings, $imageData, $prompt, $context->asset->id);

        return $this->parseResponse($response);
    }

    /**
     * Request only the Pro-only metadata fields for an asset whose existing
     * analysis is missing them. Same retry/error semantics as `analyze` —
     * only the prompt differs.
     */
    final public function analyzeProCompletion(
        AnalysisImageContext $context,
        Settings $settings,
        string $primaryLanguage,
    ): AnalysisResult {
        $this->validateCredentials($settings);

        $imageData = $this->getBase64ImageData($context);
        $prompt = $this->buildProCompletionPrompt($primaryLanguage);
        $response = $this->sendRequest($settings, $imageData, $prompt, $context->asset->id);

        return $this->parseResponse($response);
    }

    final public function prepareImage(AnalysisImageContext $context): PreprocessResult
    {
        $result = ImagePreprocessor::preprocess($context);
        $this->logPreprocessingOutcome($context->asset, $result);

        return $result;
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

        $plugin = Plugin::getInstance();
        $includeProMetadata = !$plugin->is(Plugin::EDITION_LITE);

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

        if ($includeProMetadata) {
            array_push($instructions, ...$this->buildLongDescriptionInstructions($primaryName));
        }

        $instructions[] = sprintf('- "suggestedTitle": A concise title (2-6 words, Title Case, specific not generic, in %s)', $primaryName);
        $instructions[] = '- "titleConfidence": Your confidence in the title (0.0-1.0)';
        $instructions[] = '  Title rules: NO "Image of/Photo of" prefixes, NO file extensions, be SPECIFIC';

        if ($includeProMetadata) {
            array_push($instructions, ...$this->buildTagsAndOcrInstructions($primaryName));
        }
        $instructions[] = '- "containsPeople": true if the image shows one or more identifiable persons, meaning a recognizable human body or face that a viewer would naturally describe as "a person in the photo". A disembodied hand, foot, or anonymous background silhouette is not enough on its own. Boolean.';
        $instructions[] = '- "faceCount": integer count of human faces showing enough features (eyes, nose, or mouth) to be recognized as a face. Include profile and three-quarter views. Exclude faces fully turned away, fully masked, or too small/blurred to read as a face. If people are clearly present but no faces meet this bar, return 0.';
        $instructions[] = '- "containsPeopleConfidence": 0.0-1.0. High when the image is unambiguously populated or unambiguously empty of people; low only for genuinely borderline cases (distant figures, mannequins, statues).';
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
        $instructions[] = '- "nsfwCategories": Array of objects with "category" (one of: adult, violence, hate, self-harm, drugs) and "confidence" (0.0-1.0). Only include categories with confidence > 0.1';
        $instructions[] = '  Violence category should include: fighting, weapons (guns, knives, swords), blood, injuries, physical assault, warfare, dead bodies, torture';
        $instructions[] = '  Adult category should include: nudity, sexual content, suggestive poses, intimate acts, revealing clothing, shirtless individuals';
        $instructions[] = '- "hasWatermark": You are analyzing this image to determine whether it contains a watermark. A watermark is any semi-transparent or opaque text, logo, symbol, or pattern intentionally overlaid on the image to indicate ownership, copyright, branding, or source. This includes repeating patterns, corner logos, faint overlays, or diagonal text. Carefully examine the entire image, including edges, corners, and low-contrast regions. Identify any elements that appear artificially overlaid rather than part of the natural scene. Distinguish between natural objects (e.g., signs, clothing logos in-scene) and post-processing overlays. (boolean)';
        $instructions[] = '- "watermarkConfidence": How confident you are in your hasWatermark assessment (0.0-1.0). Should be HIGH (0.8-1.0) when the image clearly has a watermark OR clearly has no watermark. Should be LOW (0.3-0.6) only when the image is genuinely ambiguous (e.g., faint overlays, decorative text that might be a watermark)';
        $instructions[] = '- "watermarkType": Type of watermark detected. Must be one of: stock, logo, text, copyright, unknown, or null if no watermark';
        $instructions[] = '- "watermarkDetails": Object with additional details:';
        $instructions[] = '  - "stockProvider": If stock watermark, the provider name (e.g., Shutterstock, Getty, iStock, Adobe Stock)';
        $instructions[] = '- "focalPointX": X coordinate (0.0-1.0, left to right) of the primary subject or visual focal point of the image';
        $instructions[] = '- "focalPointY": Y coordinate (0.0-1.0, top to bottom) of the primary subject or visual focal point of the image';
        $instructions[] = '- "focalPointConfidence": Confidence in the focal point detection (0.0-1.0)';
        $instructions[] = '- "containsBrandLogo": Whether the image contains any recognizable brand logos (boolean)';
        $instructions[] = '- "containsBrandLogoConfidence": How confident you are in your containsBrandLogo assessment (0.0-1.0). Should be HIGH (0.8-1.0) when the image clearly has brand logos OR clearly has no brand logos. Should be LOW (0.3-0.6) only when the image is genuinely ambiguous (e.g., partial logos, generic shapes that might be a brand)';
        $instructions[] = '- "detectedBrands": Array of objects with "brand" (company/brand name of logos that are part of the image composition — physically present in what the photographer captured, not applied on top afterward) and "confidence" (0.0-1.0)';

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
     * Build a prompt that requests ONLY the Pro-only metadata fields
     * (`longDescription`, `tags`, `extractedText`). Used by the bulk Pro-sync
     * flow to backfill records that were originally analyzed under Lite.
     *
     * No alt text, title, NSFW, watermark, focal point, brands, people,
     * translations, or quality signals — those are already populated.
     */
    protected function buildProCompletionPrompt(string $primaryLanguage): string
    {
        $primaryName = $this->languageDisplayName($primaryLanguage);

        $instructions = [sprintf(
            'Analyze this image and provide the following Pro metadata fields in JSON format. Write ALL text fields in %s (%s) only.',
            $primaryName,
            $primaryLanguage,
        )];

        array_push($instructions, ...$this->buildLongDescriptionInstructions($primaryName));
        array_push($instructions, ...$this->buildTagsAndOcrInstructions($primaryName));

        $instructions[] = '';
        $instructions[] = 'Respond ONLY with valid JSON, no markdown or explanation.';

        return implode("\n", $instructions);
    }

    /**
     * Long-description prompt block, shared between the full prompt and the
     * Pro-completion prompt.
     *
     * @return string[]
     */
    private function buildLongDescriptionInstructions(string $primaryName): array
    {
        return [
            sprintf('- "longDescription": A detailed, comprehensive description (6-8 sentences, roughly 130-200 words) providing rich context about the image content, composition, subjects, setting, mood, lighting, color palette, notable details, spatial relationships, and any relevant background elements. Lead with the primary subject and what is happening, then expand into setting, style, and supporting detail. Use concrete nouns rather than vague modifiers. Avoid speculation about people\'s names, intentions, or off-frame context. Be thorough and descriptive to maximize searchability (in %s)', $primaryName),
            '- "longDescriptionConfidence": Your confidence in the long description (0.0-1.0)',
        ];
    }

    /**
     * Tags + OCR prompt block, shared between the full prompt and the
     * Pro-completion prompt.
     *
     * @return string[]
     */
    private function buildTagsAndOcrInstructions(string $primaryName): array
    {
        return [
            sprintf('- "tags": An array of objects with "tag" (lowercase single word or short phrase, in %s) and "confidence" (0.0-1.0), generate at least 35 tags (aim for 35-40)', $primaryName),
            '  Tag vocabulary guidelines for DAM (Digital Asset Management) systems:',
            '  • Prefer COMMON, GENERAL-PURPOSE tags that are widely understood and searchable (e.g., "beach", "sunset", "portrait", "food", "architecture", "business")',
            '  • Avoid overly specific or technical terms unless they are the PRIMARY subject (e.g., prefer "flower" over "chrysanthemum", "car" over "sedan", "building" over "skyscraper" unless specificity is critical)',
            '  • Use industry-standard DAM categories: subjects (people, animals, objects), settings (indoor, outdoor, urban, nature, office), styles (vintage, modern, minimalist), activities (sports, working, eating, meeting), emotions/mood (happy, serious, calm, energetic), and concepts (teamwork, success, growth)',
            '  • Focus on tags that would be useful for search and categorization across a large professional image library',
            '  • Avoid brand names, artist names, or location-specific details unless they are obvious and iconic (e.g., "eiffel tower" is acceptable, but not "paris 16th arrondissement")',
            '  • Prioritize tags that describe WHAT is in the image, not HOW it was made (avoid "bokeh", "long exposure", "f/2.8" unless these are the main subject)',
            '- "extractedText": Array of text transcribed from the image. Group all text on the same physical sign, panel, or board into ONE entry (all its lines together, separated by "\n"). Start a new entry only for text on a different sign or surface. Only include text that is fully visible and clearly readable. If any part of the text is cut off, occluded, or unreadable, omit that entry entirely; do NOT guess, complete, or infer missing letters or words, and do NOT transcribe partial fragments. Skip entries that would contain only an isolated digit, single letter, or disconnected fragment with no meaningful context on its own. Use [] if no fully readable text remains.',
        ];
    }

    /**
     * Parse the raw API response into an AnalysisResult.
     */
    protected function parseResponse(array $response): AnalysisResult
    {
        if ($this->isResponseTruncated($response)) {
            Logger::error(
                LogCategory::AssetProcessing,
                'Response truncated by ' . $this->getName() . ' due to token limit',
            );
            throw AnalysisException::invalidResponse(
                $this->getDisplayName(),
                detail: 'Response was truncated due to token limits. The image may require too many tokens to analyze.'
            );
        }

        $content = ResponseNormalizer::stripMarkdownCodeBlocks($this->extractContentText($response));

        if ($content === '') {
            throw AnalysisException::invalidResponse($this->getDisplayName());
        }

        $data = ResponseNormalizer::safeJsonDecode($content, $this->getDisplayName());

        // Detect language mixing: if a top-level text field is identical to a
        // siteContent translation, the AI used the wrong language. Clear the
        // top-level value so it does not overwrite the main record.
        $data = $this->fixLanguageMixing($data);

        $nsfwScore = ResponseNormalizer::clampConfidence($data['nsfwScore'] ?? 0.0);
        $nsfwConfidence = ResponseNormalizer::clampConfidence($data['nsfwConfidence'] ?? 0.0);
        $detectedBrands = ResponseNormalizer::normalizeDetectedBrands($data['detectedBrands'] ?? [], $this->getDisplayName());
        $usage = $this->extractTokenUsage($response);

        return new AnalysisResult(
            altText: $data['altText'] ?? '',
            altTextConfidence: (float) ($data['altTextConfidence'] ?? 0.0),
            longDescription: $data['longDescription'] ?? '',
            longDescriptionConfidence: (float) ($data['longDescriptionConfidence'] ?? 0.0),
            suggestedTitle: $data['suggestedTitle'] ?? '',
            titleConfidence: (float) ($data['titleConfidence'] ?? 0.0),
            tags: ResponseNormalizer::normalizeTags($data['tags'] ?? [], $this->getDisplayName()),
            extractedText: ResponseNormalizer::normalizeExtractedTextRegions($data['extractedText'] ?? null),
            faceCount: (int) ($data['faceCount'] ?? 0),
            containsPeople: (bool) ($data['containsPeople'] ?? false),
            containsPeopleConfidence: ResponseNormalizer::clampConfidence($data['containsPeopleConfidence'] ?? 0.0),
            nsfwScore: $nsfwScore,
            nsfwConfidence: $nsfwConfidence,
            nsfwCategories: ResponseNormalizer::normalizeNsfwCategories($data['nsfwCategories'] ?? [], $this->getDisplayName()),
            hasWatermark: (bool) ($data['hasWatermark'] ?? false),
            watermarkConfidence: ResponseNormalizer::clampConfidence($data['watermarkConfidence'] ?? 0.0),
            watermarkType: ResponseNormalizer::normalizeWatermarkType($data['watermarkType'] ?? null),
            watermarkDetails: ResponseNormalizer::normalizeWatermarkDetails($data['watermarkDetails'] ?? []),
            containsBrandLogo: !empty($detectedBrands),
            containsBrandLogoConfidence: ResponseNormalizer::clampConfidence($data['containsBrandLogoConfidence'] ?? 0.0),
            detectedBrands: $detectedBrands,
            inputTokens: $usage['inputTokens'],
            outputTokens: $usage['outputTokens'],
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
     * Get base64-encoded image data from the shared analysis context.
     *
     * @return array{base64: string, mimeType: string}
     * @throws AnalysisException
     */
    protected function getBase64ImageData(
        AnalysisImageContext $context,
        ?PreprocessResult $preparedImage = null,
    ): array
    {
        $asset = $context->asset;
        $maxSize = $this->getMaxFileSizeBytes();
        $ownsImageResources = $preparedImage === null;

        try {
            $result = $preparedImage ?? $this->prepareImage($context);

            if ($result->useOriginal) {
                [$payloadBytes, $mimeType] = $this->readOriginalPayload($context, $result, $maxSize);
            } else {
                $payloadBytes = $result->bytes;
                $mimeType = $result->mimeType;
            }

            if ($payloadBytes === '') {
                if ($result->reason === 'stream_unavailable') {
                    throw AnalysisException::assetNotReadable($asset->id);
                }

                throw AnalysisException::imageProcessingFailed(
                    $asset->id,
                    $result->reason ?? 'unknown',
                );
            }

            $payloadSize = strlen($payloadBytes);

            if ($payloadSize > $maxSize) {
                throw AnalysisException::fileTooLarge(
                    providerName: $this->getDisplayName(),
                    assetId: $asset->id,
                    fileSize: $payloadSize,
                    maxSize: $maxSize,
                );
            }

            if (!MemoryBudget::hasUploadHeadroom($payloadSize, payloadLoaded: true)) {
                throw AnalysisException::imageProcessingFailed($asset->id, 'insufficient_request_memory');
            }

            $base64 = base64_encode($payloadBytes);
            $context->releaseRawBytes();
            unset($payloadBytes);

            return [
                'base64' => $base64,
                'mimeType' => $mimeType,
            ];
        } finally {
            if ($ownsImageResources) {
                $context->releaseImageResources();
            }
        }
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function readOriginalPayload(
        AnalysisImageContext $context,
        PreprocessResult $result,
        int $maxSize,
    ): array {
        $asset = $context->asset;
        $path = $context->getLocalPath();

        if ($path === null) {
            throw AnalysisException::assetNotReadable($asset->id);
        }

        $fileSize = @filesize($path);
        $fileSize = $fileSize === false ? (int) ($asset->size ?? 0) : $fileSize;

        if ($fileSize > $maxSize) {
            throw AnalysisException::fileTooLarge(
                providerName: $this->getDisplayName(),
                assetId: $asset->id,
                fileSize: $fileSize,
                maxSize: $maxSize,
            );
        }

        if (!MemoryBudget::hasUploadHeadroom($fileSize)) {
            throw AnalysisException::imageProcessingFailed($asset->id, 'insufficient_request_memory');
        }

        $bytes = $context->getRawBytes();

        if ($bytes === null || $bytes === '') {
            throw AnalysisException::assetNotReadable($asset->id);
        }

        return [
            $bytes,
            ImagePreprocessor::detectMimeType($bytes, $result->mimeType),
        ];
    }

    private function logPreprocessingOutcome(Asset $asset, PreprocessResult $result): void
    {
        if ($result->wasProcessed) {
            $originalBytes = $result->originalBytes ?? 0;
            Logger::info(
                LogCategory::AssetProcessing,
                'Image preprocessed for AI upload',
                $asset->id,
                [
                    'originalBytes' => $originalBytes,
                    'processedBytes' => $result->processedBytes,
                    'originalDimensions' => "{$result->originalWidth}x{$result->originalHeight}",
                    'processedDimensions' => "{$result->processedWidth}x{$result->processedHeight}",
                    'mimeType' => $result->mimeType,
                    'reductionRatio' => $originalBytes > 0
                        ? round(($result->processedBytes ?? 0) / $originalBytes, 3)
                        : null,
                ],
            );
            return;
        }

        if ($result->reason !== null) {
            Logger::info(
                LogCategory::AssetProcessing,
                "Image preprocessing skipped: {$result->reason}",
                $asset->id,
                ['mimeType' => $result->mimeType],
            );
        }
    }

    protected const MAX_RETRIES = 2;
    protected const RETRYABLE_STATUS_CODES = [429, 500, 502, 503, 504];
    protected const MAX_RETRY_AFTER_SECONDS = 30;

    /**
     * Execute an HTTP request with standardized Guzzle error handling and retry logic.
     *
     * Retries on transient errors (429, 500, 502, 503, 504 and connection/timeout
     * failures) with exponential backoff. For 429 responses, respects the
     * Retry-After header when present.
     *
     * @param callable(int): array $request Receives start time (hrtime), returns parsed response body
     * @throws AnalysisException
     */
    protected function executeApiRequest(callable $request, int $assetId, ?int $payloadSizeBytes = null): array
    {
        $lastException = null;

        for ($attempt = 0; $attempt <= self::MAX_RETRIES; $attempt++) {
            $startTime = hrtime(true);

            try {
                return $request($startTime);
            } catch (ConnectException $e) {
                $elapsed = (int) ((hrtime(true) - $startTime) / 1_000_000);
                $sanitizedMessage = $this->sanitizeErrorMessage($e->getMessage());

                if ($attempt < self::MAX_RETRIES) {
                    $delay = (int) (2 ** ($attempt + 1)); // 2s, 4s
                    Logger::apiCall(
                        provider: $this->getName(),
                        message: 'Connection failed, attempt ' . ($attempt + 1) . '/' . (self::MAX_RETRIES + 1) . " - retrying in {$delay}s: {$sanitizedMessage}",
                        assetId: $assetId,
                        responseTimeMs: $elapsed,
                        httpStatusCode: null,
                        level: LogLevel::Warning->value,
                        context: $this->buildApiCallContext($attempt + 1, null, $payloadSizeBytes, 'timeout'),
                    );
                    $lastException = $e;
                    sleep($delay);
                    continue;
                }

                Logger::apiCall(
                    provider: $this->getName(),
                    message: 'Connection failed: ' . $sanitizedMessage,
                    assetId: $assetId,
                    responseTimeMs: $elapsed,
                    httpStatusCode: null,
                    level: LogLevel::Error->value,
                    context: $this->buildApiCallContext($attempt + 1, null, $payloadSizeBytes, 'timeout'),
                );
                throw AnalysisException::apiError(
                    $this->getDisplayName(),
                    'Connection failed: ' . $sanitizedMessage,
                    $assetId
                );
            } catch (RequestException $e) {
                $elapsed = (int) ((hrtime(true) - $startTime) / 1_000_000);
                $statusCode = $e->hasResponse() ? $e->getResponse()->getStatusCode() : null;
                $parsedBody = $this->parseErrorResponseBody($e);
                $errorMessage = ($parsedBody['message'] ?? null) ?: $this->getDefaultErrorMessage($statusCode);
                $providerError = [
                    'providerErrorCode' => $parsedBody['code'] ?? null,
                    'providerErrorType' => $parsedBody['type'] ?? null,
                ];
                $errorType = $this->errorTypeFromStatus($statusCode, $errorMessage);

                if ($statusCode !== null && in_array($statusCode, self::RETRYABLE_STATUS_CODES, true) && $attempt < self::MAX_RETRIES) {
                    $delay = $this->getRetryDelay($e, $attempt);
                    Logger::apiCall(
                        provider: $this->getName(),
                        message: "Retryable error (HTTP {$statusCode}), attempt " . ($attempt + 1) . '/' . (self::MAX_RETRIES + 1) . " - retrying in {$delay}s: {$errorMessage}",
                        assetId: $assetId,
                        responseTimeMs: $elapsed,
                        httpStatusCode: $statusCode,
                        level: LogLevel::Warning->value,
                        context: array_merge(
                            $this->buildApiCallContext($attempt + 1, $statusCode, $payloadSizeBytes, $errorType),
                            $providerError,
                        ),
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
                    context: array_merge(
                        $this->buildApiCallContext($attempt + 1, $statusCode, $payloadSizeBytes, $errorType),
                        $providerError,
                    ),
                );
                throw AnalysisException::apiError(
                    $this->getDisplayName(),
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
                    context: $this->buildApiCallContext($attempt + 1, null, $payloadSizeBytes, 'unknown'),
                );
                throw AnalysisException::apiError(
                    $this->getDisplayName(),
                    $sanitizedMessage,
                    $assetId
                );
            }
        }

        // Should not reach here, but handle defensively
        $errorMessage = $lastException !== null
            ? $this->sanitizeErrorMessage($lastException->getMessage())
            : 'Request failed after retries';
        throw AnalysisException::apiError($this->getDisplayName(), $errorMessage, $assetId);
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
                $this->getDisplayName(),
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
                $this->getDisplayName(),
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
                throw ConfigurationException::apiKeyInvalidForProvider($this->getDisplayName());
            }

            if ($statusCode === 429) {
                throw ConfigurationException::rateLimitExceededForProvider($this->getDisplayName());
            }

            if ($statusCode !== null && $statusCode >= 500) {
                throw ConfigurationException::providerUnavailable($this->getDisplayName(), $statusCode);
            }

            throw ConfigurationException::connectionFailed($this->getDisplayName());
        } catch (GuzzleException $e) {
            throw ConfigurationException::connectionFailed($this->getDisplayName());
        }
    }

    /**
     * Parse a provider's error response body once (Guzzle response streams are
     * single-use). Returns the sanitized message plus the structured `code`
     * and `type` keys when the body decodes as JSON in the standard
     * `{"error": {...}}` envelope shape, all of which feed the log details
     * panel for failure diagnosis.
     *
     * @return array{message: ?string, code: mixed, type: mixed}
     */
    protected function parseErrorResponseBody(RequestException $e): array
    {
        $empty = ['message' => null, 'code' => null, 'type' => null];

        if (!$e->hasResponse()) {
            return $empty;
        }

        try {
            $bodyContents = (string) $e->getResponse()->getBody();
        } catch (\RuntimeException) {
            return $empty;
        }

        $body = json_decode($bodyContents, true);

        if (!is_array($body) || !isset($body['error'])) {
            return $empty;
        }

        $error = $body['error'];
        $message = is_array($error) ? ($error['message'] ?? null) : null;

        return [
            'message' => is_string($message) ? $this->sanitizeErrorMessage($message) : null,
            'code' => is_array($error) ? ($error['code'] ?? null) : null,
            'type' => is_array($error) ? ($error['type'] ?? null) : null,
        ];
    }

    /**
     * Standard context fields attached to every apiCall log entry: model,
     * attempt counter, payload size, and a coarse error category.
     *
     * @return array<string, mixed>
     */
    protected function buildApiCallContext(int $attemptNumber, ?int $statusCode, ?int $payloadSizeBytes, ?string $errorType): array
    {
        return [
            'model' => $this->getCurrentModel(),
            'attemptNumber' => $attemptNumber,
            'payloadSizeBytes' => $payloadSizeBytes,
            'errorType' => $errorType,
        ];
    }

    /**
     * Map an HTTP status code to the same coarse error category that
     * AssetAnalysisService uses, so log details panels show the same
     * vocabulary regardless of where the error originated.
     */
    protected function errorTypeFromStatus(?int $statusCode, string $message): string
    {
        if ($statusCode === 401 || $statusCode === 403) {
            return 'auth';
        }

        if ($statusCode === 429) {
            return 'rate_limit';
        }

        if ($statusCode !== null && $statusCode >= 400 && $statusCode < 500) {
            if (stripos($message, 'quota') !== false) {
                return 'quota';
            }
            return 'provider_error';
        }

        if ($statusCode !== null && $statusCode >= 500) {
            return 'provider_error';
        }

        return 'unknown';
    }

    protected function getCurrentModel(): string
    {
        return Plugin::getInstance()->getSettings()->getCurrentModel();
    }
}
