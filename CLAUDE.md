# CLAUDE.md — AI Broadcast Studio

> This file is the contract for how code is written in this repository.
> It is read by the implementation agent (Claude) at the start of every task.

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
- **Static analysis** with **PHPStan / Larastan** — installed, **level 6**,
  config in `phpstan.neon` (scope: `app/`, `config/`, `database/factories/`,
  `database/seeders/`, `routes/`; migrations excluded). Run with
  `composer stan` (= `phpstan analyse --memory-limit=512M`). **No broad
  baseline / ignore**; do not lower the level or add a global ignore to make
  code pass. Fix the real issue, or add a *specific* message-on-path ignore
  with a written reason. Ratchet the level up in a later task.
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
  without tests is not acceptable.
- **Quality gates — run all of these before considering a task done**, in
  order:
  ```sh
  vendor/bin/pint --test    # style
  php artisan test          # application tests
  composer stan             # PHPStan/Larastan level 6 (--memory-limit=512M)
  ```
  `.agents/scripts/review.sh` runs all three in order and stops at the first
  failure. A task is not done until every gate passes.

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
  only. Keys are never logged and never returned in API responses.
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
  - Never write a real credential into source, tests, fixtures, or docs.
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

## 7. Workflow

Claude is the sole implementation agent. There is no automated external
reviewer.

1. Read `.agents/current-task.md`, `.agents/project-context.md`, and
   `.agents/architecture.md`.
2. Implement the task completely — code, tests, and any doc updates the task
   calls for.
3. Run the local quality gates (§4) and make them all pass:
   ```sh
   .agents/scripts/review.sh     # Pint --test → php artisan test → composer stan
   ```
   A task is not done while any gate fails.
4. Keep changes scoped to the current task. If a task says "STOP" or "do not
   proceed", stop after the gates pass — do not start the next task.
5. Update `.agents/current-task.md` / `.agents/architecture.md` /
   `.agents/project-context.md` when the task changes what they describe.

### Final report (every completed task)

```
Tests executed:      commands run + pass/fail (+ failure summary if any)
Files changed:       …
Known limitations:   …
```

---

## 8. Current status

See `.agents/current-task.md` for the active task and
`.agents/architecture.md` §§2a–2d for what is actually built.
