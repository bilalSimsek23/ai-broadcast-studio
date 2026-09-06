<!-- task-id: TASK-0002 -->
# Current task

> Completed: **bootstrap** (`APPROVED_WITH_NOTES`) · **TASK-0001 core domain
> foundation** (`APPROVED`, 0 findings). Both archived under `.agents/reviews/`.

## TASK-0002 — STATIC ANALYSIS BASELINE

**Status:** ✅ COMPLETE — GPT verdict `APPROVED_WITH_NOTES`, 0 blocking findings,
1 non-blocking doc note (fixed post-verdict). 1 review round.
(`.agents/reviews/2026-09-06T17-25-09-629Z-APPROVED_WITH_NOTES.json`)
**Owner:** CLAUDE (implementation) · GPT (review)
**Scope:** tooling only — PHPStan/Larastan baseline + review-workflow
integration + per-task round counter. **No** `app/AI/Contracts`, provider
impls, `FakeTextProvider`, resolver, STT/TTS, Filament, studio UI, or new
domain models.

### Delivered

**PHPStan / Larastan**
- `larastan/larastan:^3.0` (resolves `v3.11.0` + `phpstan/phpstan 2.2.x`) as a
  dev dependency (compatible with Laravel 13 / PHP 8.4).
- `phpstan.neon` at **level 6**, `includes` the Larastan extension.
- Analysis scope: `app/`, `config/`, `database/factories/`,
  `database/seeders/`, `routes/`. **Migrations excluded** (one-shot declarative
  schema DSL; poor noise/benefit ratio) — documented in the neon file.
- `vendor/bin/phpstan analyse` runs **clean at level 6** — **no baseline
  file**, **no broad ignores**. `reportUnmatchedIgnoredErrors: true`.
- `composer stan` (= `phpstan analyse --memory-limit=512M`) as the one-command
  developer gate (the default 128M PHP CLI limit is not enough for Larastan).

**Existing-code typing fixes (no behaviour change)**
- `AiPersona::episodes()` / `Episode::aiPersonas()` PHPDoc: completed the
  `BelongsToMany<Related, $this, EpisodeAiPersona, 'pivot'>` template params
  to match the custom `->using()` pivot. Schema, domain semantics, deletion
  policy, enum behaviour and vendor-neutrality rules are unchanged.

**Review workflow**
- `.agents/scripts/review.sh`: gates reordered to the ideal sequence
  (1 harness self-tests → 2 Pint → 3 `php artisan test` → 4 PHPStan level 6)
  and made **fail-closed** — any failed/missing gate aborts with exit 1 and
  the GPT review is **not** run. PHPStan runs with `--memory-limit=512M`.
- The captured `.agents/last-test-run.txt` (command + exit + concise result
  per gate) is what the reviewer sees; on a clean run PHPStan's section is
  just `[OK] No errors`, so no analyzer-output bloat reaches GPT context.

**Round counter (per-task aware)**
- `gpt-review.mjs` now tags every archived verdict with `task` = the active id
  from `.agents/current-task.md` (`<!-- task-id: TASK-xxxx -->` marker, else
  first `# TASK-xxxx` heading, else `null`).
- New `.agents/scripts/lib/loop-status.mjs` (`currentTaskId`,
  `taskRoundSummary`) + rewritten `review-loop-status.mjs` count **only the
  current task's rounds**; legacy/untagged archives (bootstrap, TASK-0001) are
  excluded. Budget warnings (>5 / >10) fire on the task-scoped count. Old
  archive files without `task` don't break the script.

### Acceptance checklist

- [x] PHPStan/Larastan installed, level 6 active
- [x] `vendor/bin/phpstan analyse` clean, no broad baseline/ignore
- [x] Existing domain behaviour unchanged (typing/PHPDoc only)
- [x] `review.sh` runs the PHPStan gate, fail-closed
- [x] Analysis result provided to GPT (command + exit + summary in capture)
- [x] Round counter is current-task aware
- [x] `node --test .agents/scripts/*.test.mjs` passing
- [x] `vendor/bin/pint --test` passing
- [x] `php artisan test` passing
- [x] GPT verdict `APPROVED_WITH_NOTES`
- [x] No unresolved blocking finding

### Resolved after the verdict (non-blocking doc note)

- `.agents/architecture.md` §2a said the logical→vendor resolver follow-up was
  "TASK-0002"; corrected to **TASK-0003** (and removed a stale "(TASK-0002)"
  from the §1 static-analysis row). Docs only — no code / gate impact, so no
  re-review.

### Next task (draft, not started)

**TASK-0003** — `app/AI/Contracts` (`TextGenerationProvider` + neutral DTOs) +
`FakeTextProvider` + the full `config/ai.php` logical→vendor resolver.
