# `.agents/` — two-agent development workflow

This directory holds everything the **CLAUDE ↔ GPT** review loop needs. The
full contract is in [`../CLAUDE.md`](../CLAUDE.md) §7.

## Layout

| Path | Purpose | Tracked? |
|------|---------|----------|
| `project-context.md` | What the product is, actors, constraints, current phase. | yes |
| `architecture.md` | Target module layout and technical decisions. | yes |
| `current-task.md` | The single active task. Updated as work progresses. | yes |
| `scripts/gpt-review.mjs` | Independent reviewer. Bounded context, secret-safe. | yes |
| `scripts/lib/secret-scan.mjs` | Content-based credential detector (2nd secret layer). | yes |
| `scripts/lib/path-policy.mjs` | Path allow/deny + git binary detection. | yes |
| `scripts/lib/verdict.mjs` | 3-verdict model + severity/blocking + self-contradiction normalization. | yes |
| `scripts/lib/findings.mjs` | Finding fingerprint + round-to-round NEW/RESOLVED/STILL_OPEN/NON_BLOCKING comparison. | yes |
| `scripts/lib/loop-status.mjs` | `currentTaskId` + `taskRoundSummary` — per-task round accounting. | yes |
| `scripts/review-loop-status.mjs` | Loop diagnosis: **current-task** round count + finding deltas + budget warnings. | yes |
| `scripts/gpt-review.test.mjs` | `node --test` self-tests for the libs. | yes |
| `scripts/review.sh` | Run gates (self-tests → Pint → tests → PHPStan), **fail-closed**, → reviewer → loop status. | yes |
| `../phpstan.neon` (repo root) | PHPStan/Larastan config, level 6. | yes |
| `reviews/` | Timestamped archive of every verdict (`<iso-ts>-<verdict>.json`). | dir + README tracked; `*.json` gitignored |
| `skills/antigravity-gpt-review/` | Portable source skill this workflow derives from. | yes |
| `gpt-review.json` | Transient latest verdict. | **gitignored** |
| `last-test-run.txt` | Transient captured quality-gate output. | **gitignored** |

## Run a review

```sh
# 1. make sure the key is exported (never commit it)
export OPENAI_API_KEY="sk-..."

# 2. optional overrides
export OPENAI_REVIEW_MODEL="gpt-5.6-sol"   # default
export OPENAI_REVIEW_EFFORT="high"          # default

# 3a. review the current diff only (no quality gates)
node .agents/scripts/gpt-review.mjs

# 3b. or: run the required gates (in order), capture output, and — only if
#     ALL gates pass — run the review + loop status:
#       1 node --test .agents/scripts/*.test.mjs   (harness self-tests)
#       2 vendor/bin/pint --test                   (style)
#       3 php artisan test                         (application tests)
#       4 composer stan                            (PHPStan/Larastan level 6)
.agents/scripts/review.sh

# individual gates
node --test .agents/scripts/*.test.mjs
composer stan            # = phpstan analyse --memory-limit=512M
node .agents/scripts/review-loop-status.mjs   # current-task round diagnosis
```

Exit code: `0` = `APPROVED` **or** `APPROVED_WITH_NOTES` (both complete the
task), `10` = `CHANGES_REQUIRED`, `11` = nothing to review (no changes — **no
verdict is written or archived**, so a clean tree can never manufacture a
passing verdict), `1` = a quality gate failed (**fail-closed: the review was
not run**), anything else = harness error (missing key, API error, malformed
response, withheld/unsafe files, git failure).

## What the reviewer sees

Only, in priority order: `project-context.md`, `architecture.md`,
`current-task.md`, the **staged (index)** and **unstaged (working tree)** diffs
vs `HEAD` — collected and checked separately so neither can hide the other —
the bodies of new/changed files (size- and count-bounded), and the captured
test results. **Never the whole repo.** The assembled request is byte-budgeted
and fails closed if the combined diff or the whole request is too large to
review in one pass.

Safety layers:

- **Sensitive paths** are refused (real `.env*`, keys, dumps, `.aws/`… — the
  placeholder `.env.example` template is allowed through and still scanned).
  Git path lists are read NUL-delimited (`-z`) so exact filenames are checked.
- **Sensitive content** is refused — any credential shape (quoted or unquoted)
  in the diff, a file body, a context doc, the captured test output, or the
  fully assembled request fails the run closed before anything is sent.
- **Binary / non-text** input is refused independently of file extension and of
  `.gitattributes` / textconv: `git diff --numstat --no-textconv`, the
  `Binary files … differ` / `GIT binary patch` diff markers, and a
  full-content UTF-8 + control-byte sniff of every file body and the diff
  itself.
- **Link / special-file safety**: every review-input file is opened
  `O_NOFOLLOW | O_NONBLOCK` and checked on the same descriptor it is read from;
  symlinks, hard-linked files (`nlink ≠ 1`), FIFOs and devices are rejected
  (and a FIFO cannot hang the open). Result + archive writes use
  `O_CREAT | O_EXCL | O_NOFOLLOW` temp/target files. `review.sh` writes the
  capture to a fresh `mktemp` file and atomically renames it over the
  validated destination.

## Verdict contract

```json
{ "verdict": "APPROVED | APPROVED_WITH_NOTES | CHANGES_REQUIRED",
  "summary": "...",
  "findings": [ { "severity": "...", "blocking": false, "category": "...",
                 "fingerprint": "<file>::<category>::<slug>", "file": "...",
                 "line_or_area": "...", "problem": "...", "why_it_matters": "...", "fix": "..." } ],
  "required_fixes": [ "blocking only — empty if none" ],
  "suggested_tests": [ ... ] }
```

- **`APPROVED`** / **`APPROVED_WITH_NOTES`** both complete the task (the latter
  just means non-blocking notes remain — do not re-run the review for them).
- **`CHANGES_REQUIRED`** requires a non-empty `required_fixes`.
- `severity` ≠ `blocking`: a `medium` is not automatically blocking. Only a real
  bug / security / data-integrity / concurrency / requirement-violation /
  regression / production-failure-path finding is `blocking:true`.
- Any other `verdict` value is coerced to `CHANGES_REQUIRED`; a passing verdict
  with a `blocking:true` finding or non-empty `required_fixes` is also coerced;
  a passing verdict with only non-blocking findings becomes `APPROVED_WITH_NOTES`.
- Review-harness observations that don't break feature-review correctness are
  `category:"review-infrastructure"`, `blocking:false`, and never reopened as
  blockers in later rounds (see `review-loop-status.mjs`).
- Each archived verdict is tagged with `task` (the active id from
  `current-task.md`). `review-loop-status.mjs` counts rounds **per task**;
  archives from bootstrap / earlier tasks (no `task`, or a different id) are
  excluded, so the round-budget warnings reflect the task in hand.
