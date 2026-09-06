#!/usr/bin/env node
// Review-loop diagnosis (CLAUDE.md section 7 - round budget).
//
// Reads the archived verdicts under .agents/reviews/ and prints:
//   - how many review rounds have run
//   - the latest verdict
//   - NEW / STILL_OPEN / RESOLVED / NON_BLOCKING findings between the last two
//     rounds (by fingerprint)
//   - a warning once the round budget (5 / 10) is exceeded
//
// Read-only. Never calls the API. Exit 0 always (it is a report, not a gate).

import { readdirSync, readFileSync, realpathSync } from 'node:fs';
import { resolve, dirname, sep } from 'node:path';
import { execFileSync } from 'node:child_process';
import { classifyFindings } from './lib/findings.mjs';
import { isBlockingFinding } from './lib/verdict.mjs';

let repoRoot = process.cwd();
try { repoRoot = realpathSync(execFileSync('git', ['rev-parse', '--show-toplevel'], { encoding: 'utf8' }).trim()); } catch {}
const reviewsDir = resolve(repoRoot, '.agents/reviews');
if (!(realpathSync(reviewsDir) === reviewsDir || realpathSync(reviewsDir).startsWith(dirname(reviewsDir) + sep))) {
  // best-effort containment; carry on
}

let files;
try {
  files = readdirSync(reviewsDir).filter((f) => /\.json$/.test(f)).sort();
} catch {
  console.log('No .agents/reviews/ archive yet - run a review first.');
  process.exit(0);
}
if (files.length === 0) {
  console.log('No archived verdicts yet.');
  process.exit(0);
}

function load(name) {
  try { return JSON.parse(readFileSync(resolve(reviewsDir, name), 'utf8')); } catch { return null; }
}

const rounds = files.length;
const latest = load(files[files.length - 1]);
const prev = files.length > 1 ? load(files[files.length - 2]) : null;

console.log(`Review rounds archived: ${rounds}`);
console.log(`Latest verdict:         ${latest ? latest.verdict : '(unreadable)'}  [${files[files.length - 1]}]`);

if (latest && Array.isArray(latest.findings)) {
  const blocking = latest.findings.filter(isBlockingFinding);
  const nonBlocking = latest.findings.filter((f) => !isBlockingFinding(f));
  console.log(`Latest findings:        ${latest.findings.length} total  (${blocking.length} blocking, ${nonBlocking.length} non-blocking)`);
  console.log(`Latest required_fixes:  ${Array.isArray(latest.required_fixes) ? latest.required_fixes.length : 0}`);
}

if (prev && latest) {
  const c = classifyFindings(prev.findings, latest.findings, isBlockingFinding);
  const show = (label, arr) => {
    console.log(`\n${label} (${arr.length}):`);
    for (const r of arr) console.log(`  - [${r.severity}${r.category ? '/' + r.category : ''}] ${r.file}\n      ${r.fingerprint}`);
  };
  show('NEW blocking findings this round', c.NEW);
  show('STILL_OPEN blocking findings', c.STILL_OPEN);
  show('RESOLVED since last round', c.RESOLVED);
  show('NON_BLOCKING notes (do NOT re-open as blockers)', c.NON_BLOCKING);

  if (c.NEW.length === 0 && c.STILL_OPEN.length > 0) {
    console.log('\nNote: no genuinely new blockers - only carried-over ones. Verify the prior fixes actually landed.');
  }
  if (c.NEW.length === 0 && c.STILL_OPEN.length === 0 && c.NON_BLOCKING.length > 0) {
    console.log('\nNote: only non-blocking notes remain -> APPROVED_WITH_NOTES territory. Do not start another round for these.');
  }
}

if (rounds > 10) {
  console.log('\n*** ROUND BUDGET EXCEEDED (>10): STOP automatic fix/review. Report a review-loop failure and do root-cause analysis before any further code change. ***');
} else if (rounds > 5) {
  console.log('\n*** Round budget exceeded (>5): before changing code again, separate resolved / still-open / repeated / non-blocking / genuinely-new findings and only fix real unresolved blockers. ***');
}

process.exit(0);
