---
name: antigravity-gpt-review
description: Autonomous developer-reviewer loop for AI Broadcast Studio. CLAUDE implements a task (code + tests + quality gates); GPT independently reviews the staged + unstaged git diff against the project context and current task and returns a machine-readable verdict (APPROVED / APPROVED_WITH_NOTES / CHANGES_REQUIRED). Use for any coding task that should continue through implementation, tests, GPT review, fixes, and re-review until it reaches a passing verdict.
---

# Antigravity + GPT Review Loop (AI Broadcast Studio)

Act as the implementation agent (**CLAUDE**). GPT is an independent reviewer,
not the implementer. The authoritative description of this workflow is
[`../../../CLAUDE.md`](../../../CLAUDE.md) §7; this file is the operational
checklist.

## Required environment

`OPENAI_API_KEY` must exist in the shell environment. Never print, commit, or
store the key in repository files, and never let it into the review input.

Optional environment variables:

- `OPENAI_REVIEW_MODEL` — default `gpt-5.6-sol`.
- `OPENAI_REVIEW_EFFORT` — default `high`.
- `GPT_REVIEW_OUTPUT` — default `.agents/gpt-review.json` (must be a
  dedicated, git-ignored `.agents/<name>.json` path).
- `GPT_REVIEW_TEST_RESULTS` — default `.agents/last-test-run.txt`; its contents
  are included as the "test results" review context.

## Reviewer location in this repo

The portable reviewer script has been adapted for this repository and lives at:

```
.agents/scripts/gpt-review.mjs      # the reviewer
.agents/scripts/review.sh           # quality-gate capture + reviewer wrapper
```

It sends only a **bounded** slice to the model — project context, architecture,
current task, the `git diff` vs `HEAD`, new/changed file bodies, and captured
test results — never the whole repository. Secret-bearing and binary paths are
refused.

## Loop

1. Read `.agents/current-task.md`, `.agents/project-context.md`, and
   `.agents/architecture.md`. Inspect the existing repository before editing.
2. Implement the task completely. Preserve existing architecture and
   conventions (see `CLAUDE.md`) unless change is necessary and justified.
3. Run the quality gates and capture their output:
   `.agents/scripts/review.sh` does this for you (`node --test
   .agents/scripts/*.test.mjs`, Pint, PHPStan if installed, `php artisan test`
   → `.agents/last-test-run.txt`), then runs the reviewer. To run the reviewer
   alone: `node .agents/scripts/gpt-review.mjs`.
4. Read `.agents/gpt-review.json` (+ the timestamped copy under
   `.agents/reviews/`) and the `review-loop-status.mjs` summary that
   `review.sh` prints.
5. Three verdicts (see `CLAUDE.md` §7 "Review governance"):
   - **`APPROVED`** / **`APPROVED_WITH_NOTES`** → task complete. Record any
     notes in the final report; **do not** start another round just for
     non-blocking notes.
   - **`CHANGES_REQUIRED`** → fix only the **`blocking:true`** findings
     (`required_fixes`). Add/adjust tests the review requests when they
     materially protect the fix. Re-run the quality gates. Run GPT review
     again.
6. Target 1–3 rounds. Over 5 → run `review-loop-status.mjs`, separate
   RESOLVED / STILL_OPEN / NEW / NON_BLOCKING, and only fix real unresolved
   blockers. Over 10 → stop the loop, report a review-loop failure, do
   root-cause analysis first. Never change code repeatedly for the same
   non-blocking finding, and never re-open a settled non-blocking
   review-infrastructure note as a blocker.
7. If a finding is demonstrably incorrect or non-blocking, do not implement it.
   Record why in `current-task.md` / the next round's notes, keep the code
   correct, and continue.
8. Stop early only for a genuine blocker: external credentials, unavailable
   infrastructure, destructive production action, or an ambiguous product
   decision that cannot be safely inferred.
9. On completion, produce the final report (see `CLAUDE.md` §7 "Final report"):
   final verdict, review rounds, resolved blockers, remaining non-blocking
   notes, tests executed, files changed, known limitations, GPT final verdict.
   Update `current-task.md` to point at the next task.

## Safety boundaries

- Do not deploy to production unless the task explicitly asks for it.
- Do not run destructive database commands.
- Do not expose secrets to review input. Before review, ensure `.env`,
  credentials, keys, dumps, and vendor/build artifacts are not in the diff
  (the script refuses them, but do not rely solely on that).
- Do not commit or push unless the user explicitly requests it.
- Treat GPT findings as review input; verify each against the repository
  before changing code.
