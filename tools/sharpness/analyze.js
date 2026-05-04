#!/usr/bin/env node
// Sharpness profile analysis. Reads JSONL output from `lens/sharpness/profile`
// and prints per-group distributions, the current confusion matrix, an ASCII
// scatter (decayFinal vs baseStdDev), per-asset rows, and threshold sweeps.
//
// Usage: node analyze.js [input-dir]
// Default input-dir: ./lens-data (in the current working directory)

const fs = require('fs');
const path = require('path');

const inputDir = process.argv[2]
  || process.env.LENS_PROFILE_DIR
  || path.join(process.cwd(), 'lens-data');

const groups = ['sharp', 'partialBlur', 'blur'];
const files = Object.fromEntries(groups.map(g => [g, path.join(inputDir, `${g}.jsonl`)]));

function load(group) {
  if (!fs.existsSync(files[group])) {
    console.error(`missing input file: ${files[group]}`);
    return [];
  }
  const lines = fs.readFileSync(files[group], 'utf8').split('\n').filter(Boolean);
  const rows = [];
  for (const line of lines) {
    let obj;
    try { obj = JSON.parse(line); } catch (e) { continue; }
    if (!obj.metrics || obj.error) continue;
    const m = obj.metrics;
    rows.push({
      group,
      assetId: obj.assetId,
      filename: obj.filename,
      sourceWidth: m.sourceWidth,
      sourceHeight: m.sourceHeight,
      analysisWidth: m.analysisWidth,
      analysisHeight: m.analysisHeight,
      baseStdDev: m.baseStdDev,
      r03: m.retentionSigma0_3,
      r07: m.retentionSigma0_7,
      r15: m.retentionSigma1_5,
      decayGlobal: m.decayScoreGlobal,
      decayBlended: m.decayScoreBlended,
      decayFinal: m.decayScoreFinal,
      sobel: m.sobelScore,
      patchRan: m.patchAnalysisRan,
      patchBest: m.patchBest,
      patchWeight: m.patchWeight,
      sharpnessScore: m.sharpnessScore,
      verdict: m.verdict,
    });
  }
  return rows;
}

const data = {};
for (const g of groups) data[g] = load(g);

function stats(arr) {
  if (arr.length === 0) return null;
  const sorted = [...arr].sort((a, b) => a - b);
  const n = sorted.length;
  const mean = sorted.reduce((s, v) => s + v, 0) / n;
  const variance = sorted.reduce((s, v) => s + (v - mean) ** 2, 0) / n;
  return {
    n,
    min: sorted[0],
    p10: sorted[Math.floor(n * 0.10)],
    p25: sorted[Math.floor(n * 0.25)],
    median: sorted[Math.floor(n * 0.50)],
    p75: sorted[Math.floor(n * 0.75)],
    p90: sorted[Math.floor(n * 0.90)],
    max: sorted[n - 1],
    mean,
    stddev: Math.sqrt(variance),
  };
}

function fmt(v, w = 8, p = 4) {
  if (v === null || v === undefined) return ' '.repeat(w);
  if (typeof v !== 'number') return String(v).padStart(w);
  return v.toFixed(p).padStart(w);
}

function printStatsTable(metric, label) {
  console.log(`\n=== ${label} ===`);
  console.log('group        n   min     p10     p25     median  p75     p90     max     mean    stddev');
  for (const g of groups) {
    const arr = data[g].map(r => r[metric]).filter(v => v !== null && v !== undefined && !Number.isNaN(v));
    const s = stats(arr);
    if (!s) continue;
    console.log(`${g.padEnd(11)} ${String(s.n).padStart(2)} ${fmt(s.min)}${fmt(s.p10)}${fmt(s.p25)}${fmt(s.median)}${fmt(s.p75)}${fmt(s.p90)}${fmt(s.max)}${fmt(s.mean)}${fmt(s.stddev)}`);
  }
}

console.log(`Reading from: ${inputDir}`);
console.log('========================================');
console.log('PER-GROUP DISTRIBUTIONS');
console.log('========================================');
printStatsTable('baseStdDev',     'baseStdDev (absolute Laplacian energy, normalized)');
printStatsTable('r03',            'retentionSigma0.3 (variance retained at small blur)');
printStatsTable('r07',            'retentionSigma0.7 (primary discriminator)');
printStatsTable('r15',            'retentionSigma1.5 (variance retained at heavy blur)');
printStatsTable('decayGlobal',    'decayScoreGlobal (3-sigma combination, pre-patch)');
printStatsTable('decayBlended',   'decayScoreBlended (after patch rescue)');
printStatsTable('decayFinal',     'decayScoreFinal (input to sigmoid)');
printStatsTable('sobel',          'sobelScore (gradient magnitude)');
printStatsTable('patchBest',      'patchBest (best 1/9 patch decay)');
printStatsTable('sharpnessScore', 'sharpnessScore (FINAL post-sigmoid * detailFactor)');

console.log('\n========================================');
console.log('CURRENT CONFUSION MATRIX (rows = true, cols = predicted)');
console.log('========================================');
console.log('truth\\pred   Blurry  Soft  Sharp');
for (const g of groups) {
  const counts = { Blurry: 0, Soft: 0, Sharp: 0 };
  for (const r of data[g]) counts[r.verdict] = (counts[r.verdict] || 0) + 1;
  console.log(`${g.padEnd(12)} ${String(counts.Blurry).padStart(6)} ${String(counts.Soft).padStart(5)} ${String(counts.Sharp).padStart(6)}`);
}

console.log('\n========================================');
console.log('SCATTER  decayFinal (x: 0..1)  vs  baseStdDev (y: 0..0.5)');
console.log('         S=sharp   P=partialBlur   B=blur   *=overlap');
console.log('========================================');
const W = 60, H = 20;
const grid = Array.from({ length: H }, () => Array(W).fill(' '));
const yMax = 0.5;
function place(r, ch) {
  if (r.decayFinal === undefined || r.baseStdDev === undefined) return;
  const x = Math.max(0, Math.min(W - 1, Math.round(r.decayFinal * (W - 1))));
  const y = Math.max(0, Math.min(H - 1, Math.round((1 - Math.min(r.baseStdDev, yMax) / yMax) * (H - 1))));
  if (grid[y][x] === ' ') grid[y][x] = ch;
  else if (grid[y][x] !== ch) grid[y][x] = '*';
}
for (const r of data.sharp) place(r, 'S');
for (const r of data.partialBlur) place(r, 'P');
for (const r of data.blur) place(r, 'B');
for (let y = 0; y < H; y++) {
  const yVal = ((1 - y / (H - 1)) * yMax).toFixed(2);
  console.log(yVal.padStart(5) + ' |' + grid[y].join(''));
}
console.log('      +' + '-'.repeat(W));
console.log('       0       0.2     0.4     0.6     0.8     1.0');

console.log('\n========================================');
console.log('PER-ASSET RESULTS (sorted by sharpnessScore desc)');
console.log('========================================');
console.log('group         file                  baseStdDev decay   sobel   sharpScore verdict');
const all = [...data.sharp, ...data.partialBlur, ...data.blur].sort((a, b) => (b.sharpnessScore ?? 0) - (a.sharpnessScore ?? 0));
for (const r of all) {
  console.log(
    `${r.group.padEnd(13)} ${(r.filename || '').padEnd(20)} ${fmt(r.baseStdDev, 10)} ${fmt(r.decayFinal, 7)} ${fmt(r.sobel, 7)} ${fmt(r.sharpnessScore, 10)} ${r.verdict || ''}`
  );
}

console.log('\n========================================');
console.log('OUTLIERS / EXAMINATION CANDIDATES');
console.log('========================================');
console.log('\n[sharp volume, called Blurry or Soft]');
for (const r of data.sharp) {
  if (r.verdict !== 'Sharp') {
    console.log(`  ${r.filename}  baseStdDev=${fmt(r.baseStdDev)} decay=${fmt(r.decayFinal)} score=${fmt(r.sharpnessScore)} -> ${r.verdict}`);
  }
}
console.log('\n[blur volume, called Sharp or Soft]');
for (const r of data.blur) {
  if (r.verdict !== 'Blurry') {
    console.log(`  ${r.filename}  baseStdDev=${fmt(r.baseStdDev)} decay=${fmt(r.decayFinal)} score=${fmt(r.sharpnessScore)} -> ${r.verdict}`);
  }
}
console.log('\n[partialBlur verdicts spread]');
for (const r of data.partialBlur) {
  console.log(`  ${r.filename}  baseStdDev=${fmt(r.baseStdDev)} decay=${fmt(r.decayFinal)} score=${fmt(r.sharpnessScore)} -> ${r.verdict}`);
}
