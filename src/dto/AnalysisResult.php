<?php

declare(strict_types=1);

namespace vitordiniz22\craftlens\dto;

/**
 * Immutable data transfer object for AI analysis results.
 */
readonly class AnalysisResult
{
    /**
     * Maximum length for string values in log context before truncation.
     */
    private const LOG_TRUNCATE_LENGTH = 200;
    /**
     * @param string $altText Generated alt text description
     * @param float $altTextConfidence Confidence score for alt text (0.0 - 1.0)
     * @param string $longDescription Generated detailed description (paragraph-length)
     * @param float $longDescriptionConfidence Confidence score for long description (0.0 - 1.0)
     * @param string $suggestedTitle Generated title (2-6 words, Title Case)
     * @param float $titleConfidence Confidence score for title (0.0 - 1.0)
     * @param array<array{tag: string, confidence: float}> $tags Detected tags with confidence scores
     * @param string|null $extractedText Visible text detected in image (signs, labels, etc.)
     * @param int $faceCount Number of faces/people detected
     * @param bool $containsPeople Whether the image contains people
     * @param float $containsPeopleConfidence Confidence in people/face detection (0.0 - 1.0)
     * @param float $nsfwScore NSFW severity score (0.0 - 1.0)
     * @param float $nsfwConfidence Confidence in NSFW assessment (0.0 - 1.0)
     * @param array<array{category: string, confidence: float}> $nsfwCategories NSFW category breakdown
     * @param bool $hasWatermark Whether a watermark was detected
     * @param float $watermarkConfidence Confidence score for watermark detection (0.0 - 1.0)
     * @param string|null $watermarkType Type of watermark: stock, logo, text, copyright, unknown
     * @param array{stockProvider?: string} $watermarkDetails Additional watermark details
     * @param bool $containsBrandLogo Whether brand logos were detected
     * @param float $containsBrandLogoConfidence Confidence in brand detection (0.0 - 1.0)
     * @param array<array{brand: string, confidence: float}> $detectedBrands Detected brands with confidence
     * @param int $inputTokens Number of input tokens used (OpenAI)
     * @param int $outputTokens Number of output tokens used (OpenAI)
     * @param float|null $focalPointX Focal point X coordinate (0.0-1.0, left to right)
     * @param float|null $focalPointY Focal point Y coordinate (0.0-1.0, top to bottom)
     * @param float|null $focalPointConfidence Confidence in focal point detection (0.0-1.0)
     */
    public function __construct(
        public string $altText,
        public float $altTextConfidence,
        public string $longDescription,
        public float $longDescriptionConfidence,
        public string $suggestedTitle,
        public float $titleConfidence,
        public array $tags,
        public ?string $extractedText,
        public int $faceCount,
        public bool $containsPeople,
        public float $containsPeopleConfidence,
        public float $nsfwScore = 0.0,
        public float $nsfwConfidence = 0.0,
        public array $nsfwCategories = [],
        public bool $hasWatermark = false,
        public float $watermarkConfidence = 0.0,
        public ?string $watermarkType = null,
        public array $watermarkDetails = [],
        public bool $containsBrandLogo = false,
        public float $containsBrandLogoConfidence = 0.0,
        public array $detectedBrands = [],
        public int $inputTokens = 0,
        public int $outputTokens = 0,
        public ?float $focalPointX = null,
        public ?float $focalPointY = null,
        public ?float $focalPointConfidence = null,
        public array $siteContent = [],
    ) {
    }

    /**
     * Return a summary of the analysis for logging purposes.
     * Truncates long strings.
     *
     * @return array<string, mixed>
     */
    public function toLogContext(): array
    {
        $context = [
            'altText' => $this->altText,
            'altTextConfidence' => $this->altTextConfidence,
            'longDescription' => $this->longDescription,
            'longDescriptionConfidence' => $this->longDescriptionConfidence,
            'suggestedTitle' => $this->suggestedTitle,
            'titleConfidence' => $this->titleConfidence,
            'tags' => $this->tags,
            'extractedText' => $this->extractedText,
            'faceCount' => $this->faceCount,
            'containsPeople' => $this->containsPeople,
            'containsPeopleConfidence' => $this->containsPeopleConfidence,
            'nsfwScore' => $this->nsfwScore,
            'nsfwConfidence' => $this->nsfwConfidence,
            'nsfwCategories' => $this->nsfwCategories,
            'hasWatermark' => $this->hasWatermark,
            'watermarkConfidence' => $this->watermarkConfidence,
            'watermarkType' => $this->watermarkType,
            'watermarkDetails' => $this->watermarkDetails,
            'containsBrandLogo' => $this->containsBrandLogo,
            'containsBrandLogoConfidence' => $this->containsBrandLogoConfidence,
            'detectedBrands' => $this->detectedBrands,
            'inputTokens' => $this->inputTokens,
            'outputTokens' => $this->outputTokens,
            'focalPointX' => $this->focalPointX,
            'focalPointY' => $this->focalPointY,
            'focalPointConfidence' => $this->focalPointConfidence,
            'siteContent' => $this->siteContent,
        ];

        foreach ($context as $key => $value) {
            if (is_string($value) && mb_strlen($value) >

            self::LOG_TRUNCATE_LENGTH) {
                $context[$key] = mb_strimwidth($value, 0, self::LOG_TRUNCATE_LENGTH, '…');
            }
        }

        return $context;
    }

    /**
     * Create an empty/failed result.
     */
    public static function empty(): self
    {
        return new self(
            altText: '',
            altTextConfidence: 0.0,
            longDescription: '',
            longDescriptionConfidence: 0.0,
            suggestedTitle: '',
            titleConfidence: 0.0,
            tags: [],
            extractedText: null,
            faceCount: 0,
            containsPeople: false,
            containsPeopleConfidence: 0.0,
            hasWatermark: false,
            watermarkConfidence: 0.0,
            watermarkType: null,
            watermarkDetails: [],
            containsBrandLogo: false,
            containsBrandLogoConfidence: 0.0,
            detectedBrands: [],
        );
    }
}
