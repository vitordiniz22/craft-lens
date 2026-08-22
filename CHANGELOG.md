# Release Notes for Lens

## Unreleased

- Fixed Image Assessment failures on some ImageMagick 7 servers.
- Image Assessment is now optional and disabled by default; enable it on servers with Imagick and sufficient memory.
- Reduced image-analysis memory usage, removed low-resolution upload substitutions, and added recovery for interrupted queue jobs.
- Duplicate hashing is now Imagick-only. After updating, run `php craft lens/duplicates/rebuild-hashes` once to refresh existing hashes.

## 1.0.0 - 2026-06-10

- First stable release.

## 1.0.0-beta.7 - 2026-05-09

- Fixed Image Assessment section disappearing on ImageMagick 7 builds.
- Database logging is now opt-in via the `LENS_LOGS` env variable, with richer diagnostic context on each entry.
- Old Lens log entries are now auto-deleted daily. Retention is configurable on the Settings page (default 15 days).
- Disabled-volume notice on the asset edit page now offers a shortcut to edit the volume's field layout alongside the link to Lens settings.
- Refreshed the setup banner with clearer copy and direct links to each volume's field layout.

## 1.0.0-beta.6 - 2026-05-07

- Fixed Image Assessment section not appearing on some Imagick builds

## 1.0.0-beta.5 - 2026-05-05

- Recalibrated sharpness scoring: replaced the hard-cliff floor and worst-patch penalty with an absolute-energy ramp and best-patch rescue for more accurate scores on textured and partially soft images
- Suppressed Craft's "asset has been updated" reload banner after Lens apply and inline-edit actions
- Focal point editor now shows the asset's current focal point alongside the AI suggestion when they differ
- Fixed the confidence badge disappearing when reverting a detection toggle to its AI value

## 1.0.0-beta.4 - 2026-05-04

- Improved sharpness scoring accuracy; brightness and contrast now run on a downscaled working buffer for speed without changing scores
- Cancelling a bulk run now clears Pending records so cancelled assets immediately show "ready for analysis" again
- UI polish: removed the dead Bulk Concurrency setting from settings

## 1.0.0-beta.3 - 2026-05-04

- Per-asset wall time roughly halved: one job per asset, single shared image decode for metrics and AI preprocessing
- Added Sync Pro Metadata backfill for filling Pro-only fields on Lite-analyzed assets, with progress and cost tracking
- Duplicate detection now surfaces as a dashboard attention card and a dedicated Pro asset source
- Stability and UI polish

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
