#!/usr/bin/env node
// Review-loop diagnosis (CLAUDE.md section 7 - round budget).
//
// Reads the archived verdicts under .agents/reviews/ and prints:
//   - how many review rounds belong to the CURRENT task (from current-task.md);
//     legacy archives without a `task` field are not counted toward any task
//   - the latest verdict
//   - NEW / STILL_OPEN / RESOLVED / NON_BLOCKING findings between the last two
//     rounds OF THIS TASK (by fingerprint)
//   - a warning once the task's round budget (5 / 10) is exceeded
//
// Read-only. Never calls the API. Exit 0 always (it is a report, not a gate).

import { readdirSync, readFileSync, realpathSync, existsSync } from 'node:fs';
import { resolve } from 'node:path';
import { execFileSync } from 'node:child_process';
import { classifyFindings } from './lib/findings.mjs';
import { isBlockingFinding } from './lib/verdict.mjs';
import { currentTaskId, taskRoundSummary } from './lib/loop-status.mjs';

let repoRoot = process.cwd();
try {
  repoRoot = realpathSync(execFileSync('git', ['rev-parse', '--show-toplevel'], { encoding: 'utf8' }).trim());
} catch {}
const reviewsDir = resolve(repoRoot, '.agents/reviews');

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
  try {
    const obj = JSON.parse(readFileSync(resolve(reviewsDir, name), 'utf8'));
    obj.name = name;
    return obj;
  } catch {
    return { name, unreadable: true };
  }
}

const archives = files.map(load); // chronological (filenames are ISO timestamps)

let taskMd = '';
try {
  const p = resolve(repoRoot, '.agents/current-task.md');
  if (existsSync(p)) taskMd = readFileSync(p, 'utf8');
} catch {}
const taskId = currentTaskId(taskMd);

const summary = taskRoundSummary(archives, taskId);
const latest = archives[archives.length - 1];

console.log(`Current task:            ${taskId ?? '(not derivable from current-task.md)'}`);
if (summary.taskRounds === null) {
  console.log(`Rounds for this task:    n/a (no task id - cannot scope; ${summary.total} verdict(s) archived overall)`);
} else {
  console.log(`Rounds for this task:    ${summary.taskRounds}   (of ${summary.total} archived overall; ${summary.untaggedArchives} legacy/untagged)`);
}
console.log(`Latest verdict:          ${latest && latest.verdict ? latest.verdict : '(unreadable)'}  [${latest.name}]`);

// Finding delta between the last two rounds OF THIS TASK (fall back to the last
// two archives overall only when the task cannot be scoped).
const scoped = summary.taskRounds !== null ? summary.rounds : archives;
const latestScoped = scoped[scoped.length - 1];
const prevScoped = scoped.length > 1 ? scoped[scoped.length - 2] : null;

if (latestScoped && Array.isArray(latestScoped.findings)) {
  const blocking = latestScoped.findings.filter(isBlockingFinding);
  console.log(`Latest findings:         ${latestScoped.findings.length} total  (${blocking.length} blocking, ${latestScoped.findings.length - blocking.length} non-blocking)`);
  console.log(`Latest required_fixes:   ${Array.isArray(latestScoped.required_fixes) ? latestScoped.required_fixes.length : 0}`);
}

if (prevScoped && latestScoped) {
  const c = classifyFindings(prevScoped.findings, latestScoped.findings, isBlockingFinding);
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

const n = summary.taskRounds;
if (n !== null && n > 10) {
  console.log(`\n*** ROUND BUDGET EXCEEDED for ${taskId} (>10): STOP automatic fix/review. Report a review-loop failure and do root-cause analysis before any further code change. ***`);
} else if (n !== null && n > 5) {
  console.log(`\n*** Round budget exceeded for ${taskId} (>5): before changing code again, separate resolved / still-open / repeated / non-blocking / genuinely-new findings and only fix real unresolved blockers. ***`);
}

process.exit(0);
