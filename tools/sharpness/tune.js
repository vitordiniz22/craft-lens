#!/usr/bin/env node
// Sharpness calibration grid search. Reads JSONL output from
// `lens/sharpness/profile` and recomputes the verdict under several
// patch-aggregation modes and detail-ramp endpoints, printing the
// confusion matrix per variant. Use this to validate any proposed
// constant change against the labeled dataset before shipping.
//
// Usage: node tune.js [input-dir]

const fs = require('fs');
const path = require('path');

const inputDir = process.argv[2]
  || process.env.LENS_PROFILE_DIR
  || path.join(process.cwd(), 'lens-data');

const groups = ['sharp', 'partialBlur', 'blur'];
const files = Object.fromEntries(groups.map(g => [g, path.join(inputDir, `${g}.jsonl`)]));
function load(g) {
  if (!fs.existsSync(files[g])) {
    console.error(`missing input file: ${files[g]}`);
    return [];
  }
  return fs.readFileSync(files[g], 'utf8').split('\n').filter(Boolean).map(l => JSON.parse(l)).filter(o => o.metrics && !o.error);
}
const data = {};
for (const g of groups) data[g] = load(g);

// Match the constants currently shipped in
// plugins/lens/src/helpers/ImageMetricsAnalyzer.php — keep in sync if
// you change them in PHP.
const RATE = 20.0;
const MIDPOINT = 0.71;
const RAMP_LOW = 0.020;
const RAMP_HIGH = 0.030;
const PATCH_WEIGHT = 0.30;

function sigmoid(x) { return 1 / (1 + Math.exp(-RATE * (x - MIDPOINT))); }
function detailFactor(stdDev, low = RAMP_LOW, high = RAMP_HIGH) {
  return Math.max(0, Math.min(1, (stdDev - low) / (high - low)));
}
function verdict(s) { return s < 0.3 ? 'Blurry' : s < 0.6 ? 'Soft' : 'Sharp'; }

function patchStats(row) {
  const ps = (row.metrics.patchScoresSorted || []).slice().sort((a, b) => a - b);
  if (ps.length < 5) return null;
  return {
    p25: ps[Math.floor(ps.length * 0.25)],
    p50: ps[Math.floor(ps.length * 0.50)],
    p75: ps[Math.floor(ps.length * 0.75)],
    max: ps[ps.length - 1],
    min: ps[0],
  };
}

function recompute(row, opts) {
  const m = row.metrics;
  const ps = patchStats(row);
  const detail = detailFactor(m.baseStdDev, opts.rampLow ?? RAMP_LOW, opts.rampHigh ?? RAMP_HIGH);
  let decay = m.decayScoreGlobal;
  const w = opts.patchWeight ?? PATCH_WEIGHT;

  if (ps) {
    const picked =
      opts.pick === 'p25'   ? ps.p25 :
      opts.pick === 'p50'   ? ps.p50 :
      opts.pick === 'p75'   ? ps.p75 :
      opts.pick === 'max'   ? ps.max :
      ps.max;
    const blend = (1 - w) * m.decayScoreGlobal + w * picked;
    if (opts.mode === 'blend') decay = blend;
    else if (opts.mode === 'floor') decay = Math.max(m.decayScoreGlobal, blend);
    else if (opts.mode === 'pure-max') decay = Math.max(m.decayScoreGlobal, picked);
  }

  const sig = sigmoid(decay);
  const score = sig * detail;
  return { decay, sig, detail, score, verdict: verdict(score) };
}

function confMatrix(opts, label) {
  console.log(`\n--- ${label} ---`);
  console.log('truth\\pred         Blurry  Soft  Sharp   accuracy(target)');
  let totalCorrect = 0, total = 0;
  const targets = { sharp: 'Sharp', partialBlur: 'Sharp', blur: 'Blurry' };
  for (const g of groups) {
    const counts = { Blurry: 0, Soft: 0, Sharp: 0 };
    for (const row of data[g]) {
      const v = recompute(row, opts).verdict;
      counts[v] = (counts[v] || 0) + 1;
    }
    const correct = counts[targets[g]];
    totalCorrect += correct;
    total += data[g].length;
    console.log(`${g.padEnd(18)} ${String(counts.Blurry).padStart(6)} ${String(counts.Soft).padStart(5)} ${String(counts.Sharp).padStart(6)}   ${correct}/${data[g].length} (${targets[g]})`);
  }
  console.log(`overall: ${totalCorrect}/${total}  (${((totalCorrect / total) * 100).toFixed(1)}%)`);
}

console.log(`Reading from: ${inputDir}`);
console.log('=========================================');
console.log('AGGREGATION + PATCH-WEIGHT GRID');
console.log('=========================================');
const variants = [
  { label: 'CURRENT shipped (max FLOOR, w=0.30)', pick: 'max', mode: 'floor', patchWeight: 0.30 },
  { label: 'max blend (always blend)',            pick: 'max', mode: 'blend', patchWeight: 0.30 },
  { label: 'p25 blend (penalize worst patch)',    pick: 'p25', mode: 'blend', patchWeight: 0.30 },
  { label: 'p75 blend (rescue from second-best)', pick: 'p75', mode: 'blend', patchWeight: 0.30 },
  { label: 'max FLOOR, w=0.50',                   pick: 'max', mode: 'floor', patchWeight: 0.50 },
  { label: 'pure-max (max(global, best))',        pick: 'max', mode: 'pure-max' },
];
for (const v of variants) confMatrix(v, v.label);

console.log('\n=========================================');
console.log('DETAIL-RAMP TUNING (mode=floor, w=0.30)');
console.log('=========================================');
const sigVariants = [
  { label: 'shipped: ramp 0.020-0.030',  rampLow: 0.020, rampHigh: 0.030 },
  { label: 'ramp 0.020-0.025  (looser)', rampLow: 0.020, rampHigh: 0.025 },
  { label: 'ramp 0.020-0.035',           rampLow: 0.020, rampHigh: 0.035 },
  { label: 'ramp 0.020-0.040  (older)',  rampLow: 0.020, rampHigh: 0.040 },
  { label: 'ramp 0.018-0.030',           rampLow: 0.018, rampHigh: 0.030 },
  { label: 'ramp 0.022-0.032',           rampLow: 0.022, rampHigh: 0.032 },
];
for (const v of sigVariants) {
  confMatrix({ pick: 'max', mode: 'floor', patchWeight: 0.30, rampLow: v.rampLow, rampHigh: v.rampHigh }, v.label);
}

console.log('\n=========================================');
console.log('PER-ASSET BREAKDOWN  (current shipped settings)');
console.log('=========================================');
const ship = { pick: 'max', mode: 'floor', patchWeight: 0.30, rampLow: 0.020, rampHigh: 0.030 };
console.log('group         file                  detail  decay   sig     score   verdict');
for (const g of groups) {
  for (const row of data[g]) {
    const r = recompute(row, ship);
    console.log(`${g.padEnd(13)} ${(row.filename || '').padEnd(20)} ${r.detail.toFixed(3)}  ${r.decay.toFixed(4)} ${r.sig.toFixed(4)} ${r.score.toFixed(4)}  ${r.verdict}`);
  }
}
