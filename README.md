<p align="center">
  <img src="./resources/icon.svg" width="100" height="100" alt="Lens">
</p>

<h1 align="center">Lens</h1>

<p align="center">
  AI-powered asset intelligence for Craft CMS
</p>

<p align="center">
  <a href="https://craftcms.com"><img src="https://img.shields.io/badge/Craft_CMS-5.8+-red.svg" alt="Craft CMS 5.8+"></a>
  <a href="https://php.net"><img src="https://img.shields.io/badge/PHP-8.3+-blue.svg" alt="PHP 8.3+"></a>
  <a href="#license"><img src="https://img.shields.io/badge/License-Proprietary-green.svg" alt="License"></a>
</p>

---

Lens uses AI to automatically analyze and enrich your media library. Generate accessible alt text, extract dominant colors, detect faces and NSFW content, find duplicates, and organize assets with intelligent tagging. Works with **OpenAI GPT**, **Google Gemini**, and **Anthropic Claude**.

<p align="center">
  <img src="./resources/screenshots/dashboard.png" alt="Lens Dashboard" width="800">
</p>

## Features

### AI-Powered Analysis
- **Alt text generation** with confidence scoring for accessibility
- **Title suggestions** for better asset organization
- **Long descriptions** for detailed context
- **Tag extraction** with semantic understanding
- **Dominant color** palette extraction

### Content Detection
- **Face detection** and people presence identification
- **NSFW scoring** with category breakdown (violence, adult, etc.)
- **Watermark detection** with type classification (text overlay, logo, stock provider)
- **Brand/logo recognition** for marketing assets
- **OCR text extraction** from images

### Image Quality & Metadata
- **Quality scoring** including sharpness, exposure, and noise metrics
- **Focal point detection** for smart cropping
- **EXIF metadata extraction** (camera info, GPS coordinates, dates)
- **Duplicate detection** via perceptual hashing

### Human-in-the-Loop Workflow
- **Review Queue** with three modes: Browse, Focus, and Bulk
- **Keyboard shortcuts** for rapid review (A=Approve, S=Skip, R=Reject)
- **Inline editing** of AI suggestions before applying
- **Confidence thresholds** for filtering high-quality analyses

## Control Panel

| Section | Description |
|---------|-------------|
| **Dashboard** | Health metrics, usage stats, cost projections, and quick actions |
| **Asset Browser** | Advanced search with 20+ filters, saved searches, and CSV export |
| **Review Queue** | Approve, reject, or skip AI suggestions with keyboard shortcuts |
| **Bulk Processing** | Process entire volumes with real-time progress and cost estimation |
| **Logs** | Comprehensive logging with retry capability for failed analyses |
| **Settings** | AI provider configuration, volume selection, and workflow options |

## Requirements

- **Craft CMS** 5.8.0 or later
- **PHP** 8.3 or later
- **MySQL** 8.0+ or **PostgreSQL** 13+
- An API key from one of: [OpenAI](https://platform.openai.com/), [Google AI](https://ai.google.dev/), or [Anthropic](https://www.anthropic.com/)

## Installation

### From the Plugin Store

1. Go to **Settings** &rarr; **Plugins** in your Craft control panel
2. Search for "Lens"
3. Click **Install**

### With Composer

```bash
# Require the package
composer require vitordiniz22/craft-lens

# Install the plugin
php craft plugin/install lens
```

### After Installation

1. Navigate to **Lens** &rarr; **Settings**
2. Select your AI provider (OpenAI, Gemini, or Claude)
3. Enter your API key (supports environment variables)
4. Choose which volumes to enable
5. Configure your review workflow preferences

## Configuration

Store API keys securely using environment variables:

```php
// config/lens.php
return [
    'aiProvider' => 'openai',
    'openaiApiKey' => '$OPENAI_API_KEY',
    'autoProcessOnUpload' => true,
    'requireReviewBeforeApply' => false,
    'enabledVolumes' => ['*'], // Or specific volume handles
];
```

### Settings Reference

| Setting | Default | Description |
|---------|---------|-------------|
| `aiProvider` | `openai` | AI provider: `openai`, `gemini`, or `claude` |
| `openaiApiKey` | — | OpenAI API key (supports `$ENV_VAR` syntax) |
| `openaiModel` | `gpt-5.2` | Model: `gpt-5.2`, `gpt-5-mini`, `gpt-5-nano` |
| `geminiApiKey` | — | Google Gemini API key |
| `geminiModel` | `gemini-2.5-flash` | Model: `gemini-2.5-flash`, `gemini-2.5-flash-lite`, `gemini-2.5-pro` |
| `claudeApiKey` | — | Anthropic Claude API key |
| `claudeModel` | `claude-sonnet-4-5-20250929` | Model: `claude-sonnet-4-5-*`, `claude-opus-4-5-*`, `claude-haiku-4-5-*` |
| `autoProcessOnUpload` | `true` | Analyze assets automatically when uploaded |
| `reprocessOnFileReplace` | `true` | Re-analyze assets when files are replaced |
| `requireReviewBeforeApply` | `false` | Require manual approval before applying AI suggestions |
| `detectStockProviders` | `true` | Identify stock photo provider watermarks |
| `enabledVolumes` | `['*']` | Volume handles to process, or `['*']` for all |
| `logRetentionDays` | `30` | Days to retain log entries |

## Console Commands

Manage Lens from the command line:

```bash
# Display analysis statistics
php craft lens/stats

# Process all unprocessed assets
php craft lens/process-all

# Reprocess already-analyzed assets
php craft lens/process-all --reprocess

# Process a specific volume
php craft lens/process-volume <handle>

# List all available volumes
php craft lens/list-volumes

# Validate AI provider credentials
php craft lens/validate

# Scan for duplicate images
php craft lens/scan-duplicates

# Retry all failed analyses
php craft lens/retry-failed

# View tag frequency statistics
php craft lens/tag-stats

# View color palette statistics
php craft lens/color-stats
```

## Asset Query Extensions

Lens extends Craft's asset queries with AI-powered filtering capabilities.

### Twig Examples

```twig
{# Assets with people detected #}
{% set peoplePhotos = craft.assets()
    .lensContainsPeople(true)
    .all() %}

{# Assets flagged as potentially NSFW #}
{% set flaggedAssets = craft.assets()
    .lensNsfwFlagged(true)
    .all() %}

{# High-confidence analyses only #}
{% set trustedAssets = craft.assets()
    .lensConfidence('>= 0.8')
    .all() %}

{# Assets with watermarks detected #}
{% set watermarkedAssets = craft.assets()
    .lensWatermarkFlagged(true)
    .all() %}

{# Assets with extracted text (OCR) #}
{% set textAssets = craft.assets()
    .lensHasExtractedText(true)
    .all() %}
```

### Available Condition Rules

Lens registers 12 custom condition rules for use in element sources and queries:

| Condition Rule | Description |
|----------------|-------------|
| **Lens Status** | Filter by analysis status (pending, approved, rejected, failed) |
| **AI Confidence** | Filter by confidence score threshold |
| **Contains People** | Filter by face/person detection |
| **Has AI Tags** | Filter assets with/without AI-generated tags |
| **NSFW Flagged** | Filter by content safety scoring |
| **Watermark Detected** | Filter by watermark presence |
| **Watermark Type** | Filter by watermark classification |
| **Stock Provider** | Filter by detected stock photo source |
| **Contains Brand Logo** | Filter by brand/logo detection |
| **Has Extracted Text** | Filter by OCR text presence |
| **Has GPS Coordinates** | Filter by EXIF location data |
| **Usage Count** | Filter by how many times asset is referenced |

## Templating

### Accessing Analysis Data

Use the `lens` Twig global to access AI-generated metadata:

```twig
{% set analysis = lens.getAnalysis(asset.id) %}

{% if analysis %}
    {# Alt text with fallback #}
    <img src="{{ asset.url }}"
         alt="{{ analysis.altText ?? asset.title }}">

    {# Display suggested title #}
    {% if analysis.suggestedTitle %}
        <h3>{{ analysis.suggestedTitle }}</h3>
    {% endif %}

    {# Display AI-generated tags #}
    {% set tags = lens.getTagsForAnalysis(analysis.id) %}
    <div class="tags">
        {% for tag in tags %}
            <span class="tag" title="Confidence: {{ (tag.confidence * 100)|round }}%">
                {{ tag.tag }}
            </span>
        {% endfor %}
    </div>

    {# Display dominant colors #}
    {% set colors = lens.getColorsForAnalysis(analysis.id) %}
    <div class="color-palette">
        {% for color in colors %}
            <span class="color-swatch"
                  style="background: {{ color.hex }}"
                  title="{{ color.hex }} ({{ (color.percentage * 100)|round }}%)">
            </span>
        {% endfor %}
    </div>

    {# Check for duplicates #}
    {% set duplicateCount = lens.getDuplicateCount(asset.id) %}
    {% if duplicateCount > 0 %}
        <p class="warning">This asset has {{ duplicateCount }} potential duplicate(s)</p>
    {% endif %}
{% endif %}
```

### Field Layout Element

Add the **Lens Analysis** element to any asset field layout to display AI metadata directly in the asset editor sidebar.

### Template Helpers

```twig
{# Check if title was auto-generated by Craft #}
{% if lens.isAutoGeneratedTitle(asset) %}
    <p>Consider updating this asset's title</p>
{% endif %}

{# Check plugin setup status #}
{% if lens.hasCriticalIssues() %}
    <div class="alert">Lens requires configuration</div>
{% endif %}

{# Check feature availability #}
{% if lens.isFeatureAvailable('duplicateDetection') %}
    {# Show duplicate detection UI #}
{% endif %}
```

## Events

Lens dispatches events for custom integrations:

```php
use vitordiniz22\craftlens\events\AnalysisCompleteEvent;
use vitordiniz22\craftlens\services\AssetAnalysisService;
use yii\base\Event;

// Listen for completed analyses
Event::on(
    AssetAnalysisService::class,
    AssetAnalysisService::EVENT_ANALYSIS_COMPLETE,
    function(AnalysisCompleteEvent $event) {
        $asset = $event->asset;
        $analysis = $event->analysis;

        // Custom logic after analysis completes
        // e.g., send notification, update external system, etc.
    }
);
```

## Database Schema

Lens creates 8 database tables to store analysis data:

| Table | Purpose |
|-------|---------|
| `lens_asset_analyses` | Main analysis data with all detection fields and confidence scores |
| `lens_asset_tags` | Indexed tags for fast filtering and aggregation |
| `lens_asset_colors` | Extracted color palettes with percentages |
| `lens_analysis_content` | Raw AI responses and error messages |
| `lens_ocr_content` | Extracted text from images (OCR) |
| `lens_exif_metadata` | Camera info, GPS coordinates, and image metadata |
| `lens_duplicate_groups` | Perceptual hash duplicate pairs with similarity scores |
| `lens_logs` | Processing logs with retry capability |

### Dual-Column Pattern

Lens uses a sophisticated dual-column pattern for editable fields, preserving both AI suggestions and user edits:

- `fieldName` — The active value (user-edited or AI-generated)
- `fieldNameAi` — The original AI-generated value (never overwritten)
- `fieldNameEditedBy` — User ID of who last edited (null = not edited)
- `fieldNameEditedAt` — Timestamp of last edit
- `fieldNameConfidence` — AI confidence score (0-1)

This pattern enables features like "revert to AI suggestion" and audit trails.

## Cost Awareness

Lens tracks token usage and provides cost estimates before processing. The Bulk Processing page shows projected costs based on your selected provider.

**Typical costs per image** (estimates vary by image complexity):

| Provider | Model | Approximate Cost |
|----------|-------|------------------|
| OpenAI | GPT-5.2 | ~$0.01–0.03 |
| OpenAI | GPT-5-mini | ~$0.003–0.01 |
| Google | Gemini 2.5 Flash | ~$0.001–0.005 |
| Anthropic | Claude Sonnet 4.5 | ~$0.01–0.02 |

Configure `requireReviewBeforeApply` to manually approve analyses before they're applied, giving you control over quality vs. automation.

## Changelog

See [CHANGELOG.md](./CHANGELOG.md) for version history.

## Support

- **Documentation**: [https://github.com/vitordiniz22/craft-lens](https://github.com/vitordiniz22/craft-lens)
- **Issues**: [GitHub Issues](https://github.com/vitordiniz22/craft-lens/issues)
- **Email**: vitordiniz22@gmail.com

### Reporting Issues

When reporting issues, please include:
1. Craft CMS and PHP versions
2. Lens plugin version
3. AI provider and model being used
4. Steps to reproduce
5. Relevant log entries from **Lens** &rarr; **Logs**

## License

This plugin is proprietary software. See [LICENSE.md](./LICENSE.md) for details.

---

<p align="center">
  Brought to you by <a href="https://github.com/vitordiniz22">Vitor Diniz</a>
</p>
