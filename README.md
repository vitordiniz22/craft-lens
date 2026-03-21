<p align="center">
  <img src="./resources/icon.svg" width="100" height="100" alt="Lens">
</p>

<h1 align="center">Lens</h1>

<p align="center">
  AI Asset Intelligence for Craft CMS
</p>

<p align="center">
  <a href="https://craftcms.com"><img src="https://img.shields.io/badge/Craft_CMS-5.8+-red.svg" alt="Craft CMS 5.8+"></a>
  <a href="https://php.net"><img src="https://img.shields.io/badge/PHP-8.3+-blue.svg" alt="PHP 8.3+"></a>
  <a href="#license"><img src="https://img.shields.io/badge/License-Proprietary-green.svg" alt="License"></a>
</p>

---

Lens uses AI to automatically analyze every image in your asset library, generating alt text, titles, descriptions, semantic tags, color palettes, focal points, and more. It detects faces, flags NSFW content, spots watermarks, identifies brands, extracts text via OCR, and finds duplicate images across your library.

Choose your AI provider (**OpenAI**, **Google Gemini**, or **Anthropic Claude**) and turn your asset library from a pile of `IMG_4382.jpg` files into an organized, accessible, and fully searchable media system. Everything Lens generates is editable, reviewable, and queryable directly from your Twig templates.

[[Image: Lens Dashboard. Show the full dashboard with library health stats, coverage metrics (analyzed vs total), attention items panel highlighting assets that need action, and the analysis status breakdown. Use a library with 50+ analyzed assets so the dashboard looks populated and useful.]]

## How It Works

1. **Upload.** Drop images into any enabled volume. Lens analyzes them automatically on upload, or trigger analysis manually from the asset editor.
2. **Analyze.** AI generates alt text, titles, descriptions, tags, colors, focal points, detects faces, flags content issues, extracts text, and more, all in a single request.
3. **Review & Apply.** Edit any suggestion inline, approve via the Review Queue, or let Lens apply results automatically.

## Features

### Search & Discovery

- **Smart search** across all AI-generated metadata with relevance ranking, typo tolerance, and support for 14 languages
- **20+ filters** to find assets by tag, color, people, quality, NSFW status, watermarks, brands, and more
- **Duplicate detection** surfaces visually similar images via perceptual hashing so you stop re-uploading the same file
- **Asset Browser** to explore your entire library through AI metadata with quick filter presets and CSV export
- **Enhanced Asset Search** replaces native search in asset selector modals with Lens AI search, so you can find assets by their AI-generated metadata instead of just filenames

[[Image: Asset Browser. Show the search page with a query like "outdoor" entered, 2-3 active filter chips (e.g. "Contains People", a color filter), the quick filter buttons visible, and a grid of 8-12 matching asset thumbnails. The filter panel should be expanded to show the variety of available filters.]]

### Automatic Tagging & Descriptions

- **Alt text** generated in each site's language, with translations for multisite installs and confidence scoring
- **Title suggestions** that replace Craft's auto-generated titles with meaningful, descriptive names
- **Long descriptions** that give images rich context and feed the search index for better discoverability
- **Semantic tags** that actually describe what's in the image, 20-25 per asset, each scored by confidence
- **Dominant colors** extracted as a 6-color palette with hex values and percentages, making your library searchable by color

### Content Detection

- **Faces and people** with 6-tier detection: no people, no faces, individual, duo, small group, large group
- **NSFW scoring** with category breakdown (adult, violence, hate, self-harm, drugs) to catch unsafe content before it goes live
- **Watermarks** identified by type (text overlay, logo, stock provider) and position to flag assets that shouldn't be published
- **Brand/logo recognition** to instantly find every asset featuring a specific brand
- **Stock photo detection** to identify which assets came from stock providers
- **OCR** extracts text directly from images, fully searchable

[[Image: Analysis panel in the asset editor. Show a real asset with all sections visible: suggested title and alt text with a confidence badge, semantic tags displayed as chips, the 6-color palette with hex values and percentages, quality metrics with verdicts, people detection showing "Individual", and a web readiness check. Pick a visually interesting photo so the analysis results are compelling.]]

### Quality & Assessment

- **Image quality analysis** covering sharpness, brightness, contrast, noise, JPEG compression quality, and color profile detection via Imagick
- **Web readiness** flags oversized files, low resolutions, and unsupported formats so you can fix them before they slow down your site
- **Text-in-image detection** flags images with embedded text as an accessibility reminder
- **Focal point detection** automatically sets the focal point on the primary subject so Craft's image transforms crop around what matters

### Review Workflow

- **Review Queue** lets you review, edit, and approve AI suggestions before they're applied to your assets, in Focus mode for one-at-a-time review or Bulk mode for batch actions
- **Two-panel Focus View** with a large image preview on the left and all AI-generated metadata on the right, fully editable inline
- **Focal point editing** lets you click anywhere on the image to set the focal point, accept or override the AI suggestion
- **Bulk Processing** to analyze entire volumes with real-time progress tracking, cost estimation, and retry for failed assets
- **Confidence thresholds** flag low-confidence results so you can focus review time where it matters

[[Image: Review Queue Focus View. Show the two-panel layout with a large image preview on the left (focal point crosshair visible if possible), and the right panel displaying editable title, alt text, long description, tag chips, color swatches, and the approve/skip/reject buttons. The keyboard shortcut hints (A/S/R) should be visible in the toolbar area. Pick an asset with rich metadata to showcase the depth of analysis.]]

[[Image: Bulk Processing. Show the processing-in-progress state with the progress bar partially filled (e.g. "Processing 34 of 127"), the cost estimate visible, and the volume selector dropdown. This shows the scale of what Pro can handle.]]

### Language & Multisite

- **Language-aware AI** generates all text in your site's language. English site gets English alt text. Add a Spanish site and Lens generates Spanish too.
- **Per-site alt text & titles** for multisite installs with different languages, with native translations for each site generated in a single AI request at no extra cost
- **Base-language grouping** means `en-US` and `en-GB` share one English translation, `fr-FR` and `fr-CA` share one French translation, so no API calls are wasted
- **Zero configuration** because Lens reads your site languages and volume translation settings automatically

## Editions

Lens is available in two editions. **Lite** is free and includes AI analysis for every image (alt text, titles, descriptions, colors, focal points, people and content detection), auto-processing on upload, the analysis panel, multisite translations, 23 asset query methods, and 10 condition rules. **Pro** adds semantic tags, OCR text extraction, the Asset Browser, Review Queue, Bulk Processing, duplicate detection, enhanced asset search, and 2 additional condition rules.

Available on the [Craft Plugin Store](https://plugins.craftcms.com/lens).

## Requirements

- **Craft CMS** 5.8.0 or later
- **PHP** 8.3 or later
- **MySQL** 8.0+
- An API key from one of: [OpenAI](https://platform.openai.com/), [Google AI](https://ai.google.dev/), or [Anthropic](https://www.anthropic.com/)
- **Imagick PHP extension** (recommended) enables local quality analysis (sharpness, brightness, contrast, JPEG quality, color profile detection). Without it, the Quality section is hidden and only Web Readiness checks are shown.

## Installation

### From the Plugin Store

1. Go to **Settings** &rarr; **Plugins** in your Craft control panel
2. Search for "Lens"
3. Click **Install**

### With Composer

```bash
composer require vitordiniz22/craft-lens
php craft plugin/install lens
```

### Getting Started

1. Navigate to **Lens** &rarr; **Settings** in the control panel
2. Select your AI provider (OpenAI, Gemini, or Claude)
3. Enter your API key (supports environment variables like `$OPENAI_API_KEY`)
4. Choose which volumes to enable for analysis
5. Upload an image. Lens analyzes it automatically and displays results in the **Lens Analysis** panel on the asset editor.

[[Image: Settings page. Show the AI provider configuration with one provider selected (OpenAI recommended for familiarity), the API key field with the environment variable placeholder visible, the model dropdown, auto-process toggle, require review toggle, and the volume checkboxes with at least 2 volumes listed.]]

## Documentation

Full documentation is available on the [GitHub Wiki](https://github.com/vitordiniz22/craft-lens/wiki), including:

- [Getting Started](https://github.com/vitordiniz22/craft-lens/wiki/Getting-Started)
- [Configuration](https://github.com/vitordiniz22/craft-lens/wiki/Configuration)
- [Console Commands](https://github.com/vitordiniz22/craft-lens/wiki/Console-Commands)
- [Templating](https://github.com/vitordiniz22/craft-lens/wiki/Templating)
- [Asset Query Extensions](https://github.com/vitordiniz22/craft-lens/wiki/Asset-Query-Extensions)
- [Condition Rules](https://github.com/vitordiniz22/craft-lens/wiki/Condition-Rules)
- [Editions](https://github.com/vitordiniz22/craft-lens/wiki/Editions)
- [Cost & Pricing](https://github.com/vitordiniz22/craft-lens/wiki/Cost-and-Pricing)
- [Privacy & Data](https://github.com/vitordiniz22/craft-lens/wiki/Privacy-and-Data)

## Support

- **Documentation**: [GitHub Wiki](https://github.com/vitordiniz22/craft-lens/wiki)
- **Issues**: [GitHub Issues](https://github.com/vitordiniz22/craft-lens/issues)

## License

This plugin is proprietary software. See [LICENSE.md](./LICENSE.md) for details.

---

<p align="center">
  Created by <a href="https://github.com/vitordiniz22">Vitor Diniz</a>
</p>
