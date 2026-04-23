# Release Notes for Lens

## 1.0.0-beta.2 - 2026-04-22

- Retired the custom Asset Browser in favor of the native Craft asset index, with Lens filters, quick-access sources (Needs Review, Not Analysed, Missing Alt Text, NSFW Flagged, etc.), sort options, and table columns merged into the native experience
- Added image preprocessing before AI upload (resize, EXIF stripping, decode-cost guard) to shrink payloads for faster requests and stay under provider upload limits (notably Claude's ~5MB cap)
- Search now works across every configured site's base language, so a query in any language hits assets analyzed in any other language
- AI provider calls now retry on connection timeouts and 500/504 responses for better resilience

## 1.0.0-beta.1 - 2026-04-17

- Onboarding overhaul: explicit volume opt-in, dashboard checklist with locked prerequisites and severity-tinted lock icons, per-asset volume-not-enabled panel state, reprocess-controller guard
- Image-quality rework: histogram-based too-dark/too-bright verdict with shadow/highlight clipping, removed overallQualityScore aggregate and Low Quality filter, replaced Web Readiness umbrella with standalone File Too Large rule
- OCR as regions: extractedTextAi stores an array of region objects instead of a single flattened string
- Various UI and stability fixes

## 1.0.0-alpha.3 - 2026-04-13

- Cancel in-flight analyses from the asset page or Queue Manager with checkpoint-based cancellation
- Rewritten quality engine: blur-decay sharpness, percentile-spread contrast, improved compression analysis
- Updated AI providers to GPT-5.4 and Claude 4.6 model families with current pricing
- Simplified safety detection UI and refined watermark and brand detection prompts
- Quality of life updates

## 1.0.0-alpha.2 - 2026-03-23

- Search index now includes AI original values, site translations, and language-specific stemming
- "Analyze with Lens" element action replaces "Find Duplicates" (supports re-analysis)
- Mobile-first setup banner, improved onboarding flow
- Auto-apply AI metadata to all sites after analysis
- Alt proxy field styled as native field (blue accent)
- Various UI and stability fixes

## 1.0.0-alpha.1 - 2026-03-22

### Added
- Initial alpha release of Lens, an AI-powered image analysis plugin for Craft CMS.
- AI image analysis: alt text, titles, descriptions, tags, OCR, quality scoring, and focal point detection.
- Content detection: people/faces, NSFW scoring, watermarks, brand logos, and stock photo identification.
- Duplicate detection via perceptual hashing with similarity scores.
- Three AI providers: OpenAI GPT, Google Gemini, and Anthropic Claude.
- Control Panel: Dashboard, Asset Browser with filters and search, Review Queue (Focus/Bulk modes), and Bulk Processing.
- Analysis Panel in the asset editor with inline editing and "Revert to AI" support.
- Configurable review-before-apply workflow.
- Automatic processing on asset upload and file replace.
- Console commands for batch processing, duplicate scanning, and statistics.
- Asset query extensions and condition rules for filtering by analysis data.
- Twig variable for accessing analysis data in templates.
