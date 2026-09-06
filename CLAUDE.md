# CLAUDE.md — AI Broadcast Studio

> This file is the contract for how code is written in this repository.
> It is read by the implementation agent (CLAUDE) at the start of every task.
> The independent review agent (GPT) is also given this file as context.

---

## 1. Project purpose

**AI Broadcast Studio** is a Laravel-based broadcast platform that lets a **real
human host in a television studio** debate predefined topics **live, on air**,
with **one or more on-screen AI characters**.

Core capabilities the product must eventually support (NOT to be built during
bootstrap):

- Define **shows**, **episodes**, and **debate topics** ahead of air time.
- Configure one or more **AI characters**, each with its own persona, stance,
  voice, and provider/model binding.
- Drive a **live session** where the host speaks (via transcribed audio or typed
  prompts) and AI characters respond in near real time, on screen.
- Route AI character turns through a **provider-agnostic abstraction** so the
  underlying model vendor can change without touching product code.
- Keep a **full transcript / event log** of every live session for replay,
  compliance, and post-production.
- Give the control room **operator tooling**: start/stop, mute a character,
  force a turn, inject a moderator note, cut to a topic.

The product is latency-sensitive and broadcast-critical: correctness, graceful
degradation, and observability outrank feature velocity.

---

## 2. Architectural principles

1. **Domain-first modularity.** Organize code by domain (`Broadcast`, `Debate`,
   `AI`, `Transcript`, `Operator`), not by technical layer only. A domain owns
   its models, services, DTOs, events, and jobs.
2. **Thin controllers / thin console commands.** HTTP controllers, Artisan
   commands, and queue jobs orchestrate only. Business rules live in services or
   actions that are independently testable without the framework booting an HTTP
   kernel.
3. **Explicit boundaries via interfaces.** Anything that talks to the outside
   world (AI vendors, TTS/STT, realtime transport, storage) sits behind an
   interface defined in our code. Concrete adapters implement it.
4. **Deterministic core, non-deterministic edges.** Keep model/vendor
   randomness at the edge. Core orchestration (turn ordering, topic state,
   session lifecycle) must be deterministic and unit-testable with fakes.
5. **Events as the integration backbone.** State changes in a live session are
   emitted as domain events. Transcript persistence, metrics, and realtime
   push are event listeners, not inline side effects.
6. **Fail safe on air.** Any AI/provider failure degrades gracefully (skip
   turn, fall back to secondary provider, surface to operator) and never
   crashes the live session or blocks the host.
7. **Idempotent jobs.** Every queued job must be safe to retry. Use explicit
   idempotency keys for anything that calls a paid external API.
8. **Configuration over hardcoding.** Personas, provider bindings, timeouts,
   and rate limits are configuration/data, not literals in business code.
9. **No premature realtime infra.** Until a live-session feature is actually
   scheduled, do not add websockets/broadcasting infra "just in case".
10. **Backward-compatible migrations.** Migrations are additive where possible;
    destructive changes require a documented two-step (expand / contract).

---

## 3. Laravel coding rules

- **Target:** PHP 8.4, Laravel 13.x. Use modern PHP (constructor property
  promotion, readonly properties, enums, first-class callable syntax, typed
  properties, `match`).
- **`declare(strict_types=1);`** at the top of every PHP file in `app/`.
- **Everything typed.** Full parameter, return, and property types. No bare
  `array` where a DTO or a shaped value object is meaningful. Avoid `mixed`
  unless genuinely unavoidable and documented.
- **PSR-12** formatting, enforced by **Laravel Pint** (`vendor/bin/pint`).
- **Static analysis** with **PHPStan / Larastan** at a level agreed in
  `.agents/architecture.md` (start at level 6, ratchet up). Do not lower the
  level to make code pass.
- **Naming**
  - Controllers: singular resource + `Controller` (`EpisodeController`).
  - Services: `XyzService`; single-purpose operations: `XyzAction` with
    `execute()` / `handle()`.
  - Interfaces: capability name, no `I` prefix (`TextGenerationProvider`).
  - Enums for every closed set of states (`SessionStatus`, `TurnRole`).
- **Eloquent**
  - Guard against N+1: eager-load explicitly; `Model::preventLazyLoading()` in
    non-production environments.
  - No queries in Blade / no queries in loops.
  - Prefer explicit `$fillable`; never `$guarded = []`.
  - Money/time as value objects or integer minor units — never floats.
- **Validation** via Form Request classes or explicit `Validator` calls, never
  ad-hoc `$request->all()` into `create()`.
- **Authorization** via Policies / Gates, checked in the controller or form
  request — never only in the UI.
- **Config & env**
  - `env()` is only ever called inside `config/*.php`. Application code reads
    `config(...)`.
  - Every new config key ships with a sane default and is documented in
    `.env.example`.
- **HTTP to third parties** uses the `Http` client with explicit `timeout()`,
  `retry()` with backoff, and `throw()` / explicit error handling.
- **Queues:** long or external-API work goes to a queued job. Jobs declare
  `$tries`, `$backoff`, and `$timeout`, and are idempotent.
- **No business logic in migrations, factories, or seeders.**
- **Routes** are RESTful and named; no closures in route files for anything
  non-trivial.

---

## 4. Testing rules

- **Framework:** PHPUnit (Laravel default). Feature tests use
  `RefreshDatabase`. Prefer SQLite in-memory for speed; anything relying on
  DB-engine-specific locking must be noted and also exercised against the real
  engine before release.
- **Every change ships with tests.** New behavior → new tests. Bug fix → a
  regression test that fails before the fix.
- **Test pyramid**
  - Unit tests for services/actions/DTOs/enums with **no framework container
    where feasible**, using fakes for provider interfaces.
  - Feature tests for HTTP endpoints, commands, and job dispatch.
  - Contract tests for each AI provider adapter against a recorded/fake
    transport — never hitting a live paid API in the normal suite.
- **No real external API calls in tests.** Use `Http::fake()`, fake provider
  implementations, and fixture responses. A test that needs a real key is
  `->markTestSkipped()` unless explicitly in an opt-in group.
- **Determinism:** freeze time (`Carbon::setTestNow`), seed randomness, and
  assert on ordering explicitly. No `sleep()` in tests.
- **Coverage expectation:** domain/service layer is the priority for coverage;
  aim high there rather than chasing a global percentage. New service code
  without tests is a review blocker.
- **Commands to run before requesting review:**
  ```sh
  node --test .agents/scripts/*.test.mjs   # review-harness self-tests
  vendor/bin/pint --test
  vendor/bin/phpstan analyse                # once installed
  php artisan test
  ```
  `.agents/scripts/review.sh` runs all of these, captures their output to
  `.agents/last-test-run.txt`, and passes it to the GPT reviewer (see §7).

---

## 5. AI provider abstraction rules

The point of the abstraction is that **product/domain code never names a
vendor**.

- **Interfaces live in `app/AI/Contracts/`** (our namespace), for example:
  - `TextGenerationProvider` — given a structured prompt/context, returns a
    structured completion (text + usage + finish reason + latency).
  - `SpeechToTextProvider`, `TextToSpeechProvider` — if/when voice is built.
  - `ProviderResponse` / `ProviderUsage` DTOs — vendor-neutral shapes.
- **Adapters live in `app/AI/Providers/<Vendor>/`** and are the *only* place a
  vendor **SDK/client library** may be imported or a vendor-specific request/
  response shape handled.
- **Vendor identifiers live only in `config/ai.php`.** Provider names, concrete
  model id strings, base URLs, and per-provider parameters/credentials are
  declared there (credentials via `env()` in that file only). Adapters receive
  the model id and endpoint from config; they do not hardcode them. Product/
  domain code and Eloquent data reference a **logical name** only.
- **Selection is config-driven.** `config/ai.php` maps logical names
  (`default`, `fast`, `host_rebuttal`, per-character bindings) to a provider +
  model + parameters. Characters reference a logical name, not a vendor.
- **A `NullProvider` / `FakeProvider`** implementation is provided for local
  dev and tests and is the default in the `testing` environment.
- **Every provider call must**
  - be time-bounded and cancelable;
  - report token usage and latency in a uniform DTO;
  - translate vendor errors into our own exception hierarchy
    (`ProviderException`, `ProviderTimeoutException`,
    `ProviderRateLimitException`, `ProviderContentFilteredException`);
  - never let a raw vendor exception or vendor payload leak past the adapter.
- **No secrets in code.** Provider keys come from env via `config/ai.php`
  only. Keys are never logged, never returned in API responses, never sent to
  the GPT reviewer.
- **Fallback / resilience** (routing to a secondary provider, circuit
  breaking) is implemented once in a coordinator in front of the interface,
  not copy-pasted per call site.
- **Prompt construction** is centralized (a prompt builder per use case) so it
  can be tested and versioned; no string concatenation of prompts scattered
  across controllers.
- **Cost & rate control:** per-session and per-character budgets enforced
  before dispatching a call; exceeding a budget is an operator-visible event,
  not a silent drop.

---

## 6. Security rules

- **Secrets**
  - Never commit `.env`, keys, tokens, or credentials. `.env` is gitignored.
  - `.env.example` holds keys with placeholder/empty values only.
  - Nothing secret is ever passed into the GPT review input (the review script
    strips/《refuses》 secret-bearing paths — do not defeat it).
- **Input handling:** validate and type every inbound payload. Treat host
  audio transcripts and any operator free-text as untrusted input to prompts
  (guard against prompt injection influencing tool use / system behavior).
- **Output handling:** AI-generated text rendered in any web UI is escaped by
  default (Blade `{{ }}`); never `{!! !!}` on model output. On-air lower-thirds
  / captions are sanitized.
- **AuthZ:** operator and admin actions are policy-protected and role-gated.
  Live-session control endpoints require an authenticated operator and are
  rate-limited.
- **Transport:** all external calls over HTTPS with verified certs. No
  disabling TLS verification.
- **Least privilege:** queue workers, storage, and DB users get only the
  access they need. Signed URLs for any media artifact exposure.
- **Logging:** structured logs, no PII beyond what's necessary, no secrets, no
  full prompt/response bodies at `info` level (use `debug` + redaction, opt-in).
- **Dependencies:** pin versions; run `composer audit` in CI; no unreviewed new
  direct dependency in a feature PR without calling it out for review.
- **Rate limiting & abuse:** all public and operator endpoints have explicit
  throttles. External AI spend is capped per session (see §5).
- **CSRF/session:** standard Laravel protections stay on; no route excluded
  from CSRF without written justification in the review.

---

## 7. Claude → GPT review workflow

Development runs as a **two-agent loop**:

| Role  | Agent  | Responsibility                                    |
|-------|--------|--------------------------------------------------|
| CLAUDE | implementation | Understands the task, writes code + tests, runs quality gates. |
| GPT    | independent review | Reviews the diff + project context, returns a machine-readable verdict. |

### Loop

1. **CLAUDE** reads `.agents/current-task.md`, `.agents/project-context.md`,
   and `.agents/architecture.md`, then implements the task completely
   (code + tests + docs).
2. **CLAUDE** runs the quality gates and captures output:
   ```sh
   node --test .agents/scripts/*.test.mjs   # review-harness self-tests
   vendor/bin/pint --test
   vendor/bin/phpstan analyse                # once installed
   php artisan test
   ```
3. **CLAUDE** runs the review:
   ```sh
   node .agents/scripts/gpt-review.mjs
   ```
   or the wrapper that also records test output and archives the result:
   ```sh
   .agents/scripts/review.sh
   ```
4. The reviewer writes `.agents/gpt-review.json` (transient, gitignored) and a
   timestamped copy under `.agents/reviews/`. `review.sh` then prints
   `review-loop-status.mjs` (round count + NEW/RESOLVED/STILL_OPEN/NON_BLOCKING
   vs the previous round).
5. Read the `verdict` (three valid values):
   - **`APPROVED`** → task requirements met, no unresolved blocking finding,
     nothing further to note. Task is done. Report and stop.
   - **`APPROVED_WITH_NOTES`** → task requirements met, no unresolved blocking
     finding, but non-blocking notes / suggestions remain. **Task is done.**
     Put the notes in the final report; **do not** start a new review round
     just for them.
   - **`CHANGES_REQUIRED`** → at least one concrete unresolved finding with
     `blocking: true` (⇔ non-empty `required_fixes`). CLAUDE validates every
     finding against the code and the governance below, fixes the **real
     blockers**, adds/adjusts a requested test when it materially protects the
     fix, re-runs the quality gates, and goes to step 3.
6. Target **1–3 rounds**. If a finding is demonstrably wrong or non-blocking,
   do not implement it: record why and keep the code correct.
7. A task is **not complete** until the latest verdict is `APPROVED` or
   `APPROVED_WITH_NOTES` **and** the archived verdict came from a real reviewer
   response about actual changes (a clean tree exits `11` and writes no
   verdict — it cannot manufacture a passing verdict).

### Review governance (anti–self-review-loop)

The review exists to check the **current task's production/application code**,
not to iteratively redesign the review harness.

- **Primary scope:** current-task requirements, the `git diff`, Laravel
  application code, migrations, DB integrity, authorization, security,
  validation, concurrency, idempotency, error handling, provider abstractions,
  regressions, automated tests.
- **Harness code** (`.agents/scripts/**`, `.agents/skills/**`) is **not** the
  subject of a normal feature review.
  **Bootstrap exception:** while the current task *is* the project bootstrap,
  or the task's own diff changes review-infrastructure files, the harness is
  in scope for that task. Once bootstrap is `APPROVED`/`APPROVED_WITH_NOTES`,
  the harness leaves the blocking scope of later feature reviews — it returns
  only if a task directly changes it, or a concrete new problem is found that
  breaks feature-review correctness.
- **Harness finding = blocker only if** it concretely: (a) makes the harness
  emit a wrong/fake passing verdict; (b) prevents the review from running;
  (c) leaks `OPENAI_API_KEY` or another secret; (d) seriously corrupts or
  drops the review input (critical diff/context lost); (e) makes current-task
  verification concretely unreliable. Everything else about the review tool —
  it lives in the repo, the implementation agent could in theory edit the
  reviewer, moving the reviewer out of the repo, signed/pinned reviewer,
  defense-in-depth, optional hardening, architectural preference, style —
  is a **NON-BLOCKING REVIEW INFRASTRUCTURE NOTE**: `category:"review-infrastructure"`,
  `blocking:false`, `severity:"low"`, never in `required_fixes`, never
  `CHANGES_REQUIRED`.
- **Severity ≠ blocking.** `severity` is impact size; `blocking` is a separate
  boolean. A finding is `blocking:true` **only** for a real bug with a
  concrete failure path, a security vulnerability, a data-loss/integrity risk,
  a concurrency/idempotency failure, a current-task requirement violation, a
  meaningful regression, or a production failure path. Architectural
  preference, optional refactor, code style, future hardening, speculative
  concerns, and reviewer-infrastructure recommendations are `blocking:false`
  by default. **A `medium` severity finding is not automatically blocking.**
- **Finding identity / stability.** Every finding carries a `fingerprint`
  (`<file>::<category>::<short-root-cause-slug>`). Each round, CLAUDE compares
  the new findings against the previous round's archived verdict and labels
  each **NEW / RESOLVED / STILL_OPEN / NON_BLOCKING**
  (`node .agents/scripts/review-loop-status.mjs` prints this). A non-blocking
  note, once reported and judged not tied to feature correctness, must **not**
  be reopened as a blocker. Re-raise a finding only if the prior fix genuinely
  failed, a new diff reintroduced it, or there is new concrete evidence with a
  described failure path. **Rewording the same concern is not a new finding.**
- **Out-of-scope observations.** A genuine Critical/High problem outside the
  current task may be reported with `category:"out-of-scope"`, `blocking:false`,
  and `problem` beginning `OUT_OF_SCOPE_NOTE:`. It does not block the task.
- **Round budget.** Target 1–3 rounds. Over **5 rounds** → before any further
  code change, run `review-loop-status.mjs`, separate resolved / still-open /
  repeated / non-blocking / genuinely-new findings, and only fix real
  unresolved blockers. Over **10 rounds** → **stop the fix/review loop**,
  report it as a *review-loop failure*, and do a written root-cause analysis
  first. Repeatedly changing code for the same non-blocking finding is
  prohibited.
- **Approval rule.** Current-task requirements met + relevant tests pass + no
  unresolved `blocking:true` finding in production code ⇒ `APPROVED` (no
  notes) or `APPROVED_WITH_NOTES` (notes present). Either one completes the
  task.

### Review context budget

To control token usage, a normal review is **never** given the whole repo.
The reviewer receives only, in priority order:

1. `.agents/project-context.md`
2. `.agents/architecture.md`
3. `.agents/current-task.md`
4. the staged + unstaged `git diff` against `HEAD` (unified, bounded)
5. the necessary context of changed / new files
6. the relevant tests
7. the captured test results (commands run, pass/fail, failure summary)

Do not add repository files the reviewer does not need; do not hide context
that is actually needed for a correctness judgement just to save tokens.
Secret-bearing and binary paths are refused by the review script.

### Verdict contract

The review output is JSON:

```json
{
  "verdict": "APPROVED | APPROVED_WITH_NOTES | CHANGES_REQUIRED",
  "summary": "…",
  "findings": [ {
    "severity": "critical|high|medium|low",
    "blocking": true,
    "category": "correctness|security|data-integrity|concurrency|error-handling|tests|architecture|review-infrastructure|out-of-scope|style|…",
    "fingerprint": "<file>::<category>::<short-root-cause-slug>",
    "file": "…", "line_or_area": "…", "problem": "…", "why_it_matters": "…", "fix": "…"
  } ],
  "required_fixes": ["ONLY blocking fixes — empty if none"],
  "suggested_tests": ["…"]
}
```

`verdict` is **only ever** one of the three values above. Normalization
(`.agents/scripts/lib/verdict.mjs`) enforces the contract both directions:

- a malformed / unknown `verdict` string → `CHANGES_REQUIRED` (fail safe);
- a passing verdict with a `blocking:true` finding or a non-empty
  `required_fixes` → `CHANGES_REQUIRED`;
- a passing verdict whose only findings are non-blocking → `APPROVED_WITH_NOTES`
  (or `APPROVED` if there are no findings at all);
- a `CHANGES_REQUIRED` with a blocker but an empty `required_fixes` →
  `required_fixes` synthesized from the blockers (kept `CHANGES_REQUIRED`);
- a `CHANGES_REQUIRED` with **no** blocker and an empty `required_fixes` is an
  inconsistent response — it is **never** promoted to a pass (that would be a
  fake approval); it stays `CHANGES_REQUIRED` with a synthetic blocking finding
  and a "re-run the review" `required_fix`.

So `CHANGES_REQUIRED` always carries at least one blocking finding **and** a
non-empty `required_fixes`, and an explicit `CHANGES_REQUIRED` never becomes a
passing verdict.

### Configuration

| Variable | Default | Purpose |
|----------|---------|---------|
| `OPENAI_API_KEY` | *(required, from environment)* | Auth for the reviewer API. Never stored in the repo. |
| `OPENAI_REVIEW_MODEL` | `gpt-5.6-sol` | Override the review model. |
| `OPENAI_REVIEW_EFFORT` | `high` | Reasoning effort. |
| `OPENAI_REVIEW_TIMEOUT_MS` | `300000` | Total wall-clock bound on the reviewer API call (min `10000`). Timeout/transport failures exit `3`. |
| `GPT_REVIEW_OUTPUT` | `.agents/gpt-review.json` | Transient result path. Must be a flat `.agents/<name>.json`, gitignored, untracked, not a symlink. |
| `GPT_REVIEW_TEST_RESULTS` | `.agents/last-test-run.txt` | Captured test/quality-gate output fed to the reviewer. Must be a flat `.agents/<name>.txt`, gitignored, untracked, not a symlink; honored by both `review.sh` and `gpt-review.mjs`. |

Exit codes: `0` = `APPROVED` **or** `APPROVED_WITH_NOTES` (task complete);
`10` = `CHANGES_REQUIRED`; `11` = nothing to review (no verdict written — a
clean tree cannot manufacture a passing verdict); anything else = harness error.

### Final report (every completed task)

```
Final verdict:            APPROVED | APPROVED_WITH_NOTES
Review rounds:            N
Resolved blockers:        …
Remaining non-blocking notes:  …   (incl. NON-BLOCKING REVIEW INFRASTRUCTURE NOTEs)
Tests executed:           commands + pass/fail (+ failure summary if any)
Files changed:            …
Known limitations:        …
GPT final verdict:        <verbatim summary + verdict>
```

Remaining review-infrastructure suggestions are listed as notes, not as
reasons the feature is incomplete.

---

## 8. Current status

**Bootstrap phase.** No product features yet. See `.agents/current-task.md` for
the active task. Do not begin feature implementation until bootstrap reaches a
passing verdict (`APPROVED` or `APPROVED_WITH_NOTES`) and a feature task is
written into `current-task.md`.
