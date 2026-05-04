# Sharpness profiling tools

Diagnostic + calibration harness for `ImageMetricsAnalyzer::measureSharpness()`.
Use these when you want to validate a proposed change against a labeled
dataset before shipping new constants.

## Workflow

1. **Set up labeled volumes.** Create one Craft volume per ground-truth
   class (typical layout: `sharp`, `partialBlur`, `blur`) and upload
   labeled images into each.

2. **Profile each volume.** The console command runs Imagick metrics
   only — no AI calls, no DB writes to analysis records. It does emit
   one structured log row per asset to `lens_logs`, which is what the
   tooling reads back.

   ```sh
   mkdir -p /tmp/lens-data
   ddev exec bash -c "cd /var/www/html/web && php /var/www/html/craft lens/sharpness/profile sharp"        > /tmp/lens-data/sharp.jsonl
   ddev exec bash -c "cd /var/www/html/web && php /var/www/html/craft lens/sharpness/profile partialBlur"  > /tmp/lens-data/partialBlur.jsonl
   ddev exec bash -c "cd /var/www/html/web && php /var/www/html/craft lens/sharpness/profile blur"         > /tmp/lens-data/blur.jsonl
   ```

   The `cd /var/www/html/web` is needed when volumes use relative
   `path:` settings (the CLI default cwd is `/var/www/html`, where the
   relative volume paths don't resolve).

3. **Run analysis.** Per-group distributions, confusion matrix, ASCII
   scatter, per-asset breakdown, outlier list:

   ```sh
   node tools/sharpness/analyze.js /tmp/lens-data
   ```

4. **Run calibration grid.** Confusion matrix under each candidate
   patch-aggregation mode and detail-ramp endpoint:

   ```sh
   node tools/sharpness/tune.js /tmp/lens-data
   ```

   Edit the `variants` / `sigVariants` arrays in `tune.js` to add new
   candidates. The constants at the top of `tune.js` (`RATE`,
   `MIDPOINT`, `RAMP_LOW`, `RAMP_HIGH`, `PATCH_WEIGHT`) must match the
   shipped values in `plugins/lens/src/helpers/ImageMetricsAnalyzer.php`
   for the "CURRENT shipped" row to be a real baseline.

## Inputs

`analyze.js` and `tune.js` accept an input directory as their first
argument. They look for `sharp.jsonl`, `partialBlur.jsonl`, and
`blur.jsonl` inside that directory. If a file is missing they print a
warning and skip that group.

The default directory is `./lens-data` relative to the working
directory; `LENS_PROFILE_DIR` env var also works.

## Adding a new ground-truth class

Both scripts assume the three groups `sharp`, `partialBlur`, `blur`. To
extend (e.g. add `motion-blur`), edit the `groups` array at the top of
each script and the `targets` map in `tune.js::confMatrix`.

## Calibration history

The two structural decisions baked into the current shipped algorithm
(May 2026) were both validated through this harness:

- **Detail-energy ramp** replaces the old hard cliff at
  `baseStdDev < 0.015 → score 0.05`. Endpoints `0.020 → 0.030`
  calibrated against a 60-image dataset where blur class topped out at
  baseStdDev 0.028.
- **Patch "global as floor"** replaces unconditional patch blending.
  Patches now only rescue (when best-patch decay > global decay), never
  penalize. This unlocks bokeh / shallow-DoF detection without
  hurting uniformly sharp images.
