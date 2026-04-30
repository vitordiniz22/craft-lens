<p align="center">
  <img src="./src/icon.svg" width="100" height="100" alt="Lens">
</p>

<h1 align="center">Lens</h1>

<p align="center">
  <strong>Describe it. Find it.</strong>
</p>

<p align="center">
  <a href="https://craftcms.com"><img src="https://img.shields.io/badge/Craft_CMS-5.0+-red.svg" alt="Craft CMS 5.0+"></a>
  <a href="https://php.net"><img src="https://img.shields.io/badge/PHP-8.2+-blue.svg" alt="PHP 8.2+"></a>
  <a href="#license"><img src="https://img.shields.io/badge/License-Craft-green.svg" alt="License"></a>
  <a href="https://github.com/vitordiniz22/craft-lens/actions/workflows/tests.yml"><img src="https://github.com/vitordiniz22/craft-lens/actions/workflows/tests.yml/badge.svg" alt="Tests"></a>
</p>

Lens uses AI to analyze every image in your asset library and indexes the results (tags, descriptions, alt text, OCR'd text) into Craft's native search and every asset picker. Editors find images by what's in them instead of relying on filenames or folder structure.

Beyond search, Lens flags duplicates before they pile up, identifies brands and people, sets focal points so Craft's image transforms crop around the subject, catches NSFW content and watermarks, audits image quality, and includes pre-built filtered views so editors browse by what's in the picture.

Multi-provider by design: bring your own API key for **OpenAI**, **Google Gemini**, or **Anthropic Claude**, and switch whenever. No middleman, no vendor lock-in.

## How It Works

1. **Drop in images.** Lens analyzes them automatically on upload, on demand from the asset editor, or in bulk across entire volumes.
2. **Get rich metadata in one pass.** Alt text in every site language, descriptions, 35+ semantic tags, focal points, NSFW and watermark flags, and OCR'd text, all from a single AI call per image.
3. **Find everything, immediately.** Lens metadata feeds Craft's native search, pre-built sources, and condition rules across the asset browser and every image picker.

## Features

### Search & Discovery

- **Search by what's in the picture.** `[Pro]` Type *"outdoor"*, *"team meeting"*, into Craft's asset search and Lens ranks results by AI-generated tags, descriptions, alt text, and OCR, alongside Craft's native title and filename matching. The same smart search powers every image picker on every entry, so editors find the right photo without leaving the entry they're writing.
- **Pre-built views in your asset library.** Not Analyzed, Failed Analyses, Missing Alt Text, NSFW Flagged, Missing Focal Point, Contains People, Has Watermark, Has Brand Logo. Build your own with Lens condition rules.
- **Duplicate detection** `[Pro]` surfaces visually similar images so you stop re-uploading the same file, flags duplicates on each asset's edit page, and lets you find similar images to any asset on demand.

### Automatic Tagging & Descriptions

- **Alt text** generated in each site's language, with translations for multisite installs and confidence scoring
- **Title suggestions** that replace Craft's auto-generated titles with meaningful, descriptive names
- **Long descriptions** `[Pro]` that give images rich context and feed the search index for better discoverability
- **Semantic tags** `[Pro]` that actually describe what's in the image, 35-40 per asset, each scored by confidence

### Content Detection

- **Faces and people** with 6-tier detection: no people, people without visible faces, individual, duo, small group, large group
- **NSFW scoring** with category breakdown (adult, violence, hate, self-harm, drugs) to catch unsafe content before it goes live
- **Watermarks** identified by type (text, logo, stock, copyright) to flag assets that may need review before publishing
- **Brand/logo recognition** names the specific brands in each image and lets you filter by brand in custom views, with full search-box ranking on Pro
- **Focal point detection** generates a focal point suggestion on the primary subject and applies it to assets that don't already have one, so Craft's image transforms crop around what matters
- **OCR** `[Pro]` extracts text from images, fully searchable

### Image Assessment

- **Quality analysis** flags blurry or soft images, ones that are too dark or too bright, low-contrast (flat) shots, heavily compressed files, and non-sRGB color profiles, with a recommendation for each issue

### Bulk Processing `[Pro]`

- **Analyze entire volumes** with real-time progress tracking, cost estimation, and retry for failed assets

### Language & Multisite

- **Language-aware AI** generates all text in your site's language. English site gets English alt text. Add a Spanish site and Lens generates Spanish too.
- **Per-site alt text & titles** for multisite installs with different languages, with native translations for each site generated in a single AI request, without re-sending the image per language
- **Base-language grouping** means `en-US` and `en-GB` share one English translation, `fr-FR` and `fr-CA` share one French translation, so no API calls are wasted
- **Zero configuration** because Lens reads your site languages and volume translation settings automatically

## Editions

### Lite, for single editors and small libraries

Every image gets AI-generated alt text, focal points, NSFW and watermark flags, brand recognition and much more. Craft's asset library gets 9 pre-built filtered views and 13 condition rules so you can navigate by what's actually in the picture, not just the filename. Free. Always.

**Lite includes:**

- AI analysis on every upload: alt text, titles, focal points, people and face detection, NSFW scoring, watermark and brand recognition, image quality assessment
- Per-site translations for multisite installs (English site gets English alt text, Spanish site gets Spanish, automatically)
- 9 pre-built views in Craft's asset library
- 13 condition rules to build your own custom views
- All three AI providers: OpenAI, Gemini, Claude

### Pro, for teams and libraries that grow

Pro turns your image library into a searchable knowledge base. Search across alt text, descriptions, semantic tags, and OCR'd text, in Craft's asset browser and inside every image picker. Bulk-process entire volumes with cost preview and retry. Catch duplicates before they multiply.

**Pro adds:**

- **Semantic search** across every field the analysis produces, ranking results in Craft's asset library and in the image picker on every entry
- **OCR text extraction** so the words inside screenshots, signs, and document images are searchable
- **35+ semantic tags per asset**, each scored by confidence, all searchable
- **Bulk processing** of entire volumes with real-time progress, cost estimates, and retry for failed assets
- **Duplicate detection** via perceptual hashing, flagged on every asset edit page and findable on demand
- **Cost tracking** by provider, asset, and month so you always know what your AI usage is costing
- **1 extra pre-built view and 7 extra condition rules** for sharper filtering (status, stock provider, text-in-image, tags, duplicates, etc)

Available on the <a href="https://plugins.craftcms.com/lens" target="_blank" rel="noopener">Craft Plugin Store</a>.

## Requirements

- **Craft CMS** 5.0.0 or later
- **PHP** 8.2 or later
- **MySQL** 8.0+, **MariaDB** 10.6+, or **PostgreSQL** 14+
- An API key from one of: [OpenAI](https://platform.openai.com/), [Google AI](https://ai.google.dev/), or [Anthropic](https://www.anthropic.com/)
- **Imagick PHP extension** (recommended) enables local quality metrics (sharpness, brightness, contrast, compression quality, and color profile detection). Without it, those metrics are hidden; the rest of the Image Assessment section still works.

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
3. Enter your API key (supports environment variables like `$OPENAI_API_KEY`, `$GEMINI_API_KEY`, or `$ANTHROPIC_API_KEY`) and choose a model
4. Choose which volumes to enable for analysis
5. In each enabled volume's field layout (**Settings** &rarr; **Assets** &rarr; *[Volume]* &rarr; **Field Layout**), drag the **Lens Analysis** UI component into the layout so AI results appear on the asset editor
6. Upload an image. Lens analyzes it automatically and displays results in the **Lens Analysis** panel on the asset editor.

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

- **Bugs**: Report via [GitHub Issues](https://github.com/vitordiniz22/craft-lens/issues). Include your Craft version, PHP version, the AI provider you're using, and clear reproduction steps.
- **Feature requests**: Also via GitHub Issues. Describe the use case so it can be prioritized against other work.
- **Security issues**: Please do not file public issues for security concerns. Use GitHub's private [security advisory](https://github.com/vitordiniz22/craft-lens/security/advisories/new) instead.

## License

This plugin is distributed under the Craft License. See [LICENSE.md](./LICENSE.md) for the full terms.