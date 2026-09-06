# Architecture — AI Broadcast Studio

_Last updated: 2026-09-06 (TASK-0001 core domain foundation). Sections 2 and
its module split are the **target** shape; §4a below records what is actually
built._

## 1. Stack

| Concern | Choice | Notes |
|---------|--------|-------|
| Language | PHP 8.4 | `declare(strict_types=1)` in all `app/` files |
| Framework | Laravel 13.x | default skeleton (**exists**) |
| DB (dev/test) | SQLite | `database/database.sqlite`; in-memory for tests |
| DB (prod) | Postgres or MySQL (TBD) | migrations must be portable |
| Queue | database driver to start; Redis later | jobs must be idempotent |
| Style | Laravel Pint (PSR-12) | `vendor/bin/pint` |
| Static analysis | PHPStan + Larastan | **level 6**, `phpstan.neon`; `composer stan`; no baseline; ratchet up in a later task |
| Tests | PHPUnit (Laravel default) | `php artisan test` |
| Review | `gpt-5.6-sol` via `.agents/scripts/gpt-review.mjs` | **exists** |

## 2. Domain module layout (target)

Code is organized by domain under `app/`, each domain owning its models,
services/actions, DTOs, events, jobs, and exceptions.

```
app/
  Broadcast/      Show, Episode, running order
  Debate/         Topic, stance/framing, turn ordering rules
  Session/        LiveSession lifecycle, current-topic state machine, Turn
  AI/
    Contracts/    our interfaces + vendor-neutral DTOs
    Providers/    <Vendor>/ adapters (ONLY place a vendor SDK is imported)
    Prompting/    per-use-case prompt builders
    Routing/      provider selection, fallback, circuit breaking, budgets
  Transcript/     event-sourced-ish log, persistence listeners, replay
  Operator/       control-room actions, policies, operator-facing endpoints
  Support/        cross-cutting value objects, base classes
```

The by-domain module split is **deferred**. For now the core domain models
live flat in `app/Models/` and enums in `app/Enums/` (matching the Laravel
skeleton's `User`); modules will be extracted when service/action/event layers
arrive and the split earns its keep.

## 2a. Core domain — as built (TASK-0001)

Flat layout: `app/Models/*`, `app/Enums/*`, `app/Models/Concerns/HasUuid.php`.

**Tables & keys**

| Table | PK | Public key | Notable columns |
|-------|----|-----------|-----------------|
| `shows` | `id` | `uuid` (unique) | `name`, `slug` (unique), `description?`, `status` (`ShowStatus`, default `draft`, indexed) |
| `ai_personas` | `id` | `uuid` (unique) | persistent identity fields; `ai_provider` / `ai_model` / `voice_provider` / `voice_id` = **logical** config keys (see below); `screen_settings` (json); `status` (`AiPersonaStatus`, indexed) |
| `episodes` | `id` | `uuid` (unique) | `show_id` → `shows` **RESTRICT**, `title`, `episode_number?`, `broadcast_at?` (datetime, indexed), prep/broadcast notes, `status` (`EpisodeStatus`, indexed); index `(show_id, episode_number)` |
| `episode_ai_persona` | `id` | — | `episode_id` → `episodes` **CASCADE**, `ai_persona_id` → `ai_personas` **RESTRICT**, `sort_order` (default 0), `episode_instructions?`; **unique `(episode_id, ai_persona_id)`**, index `(episode_id, sort_order)` |
| `episode_topics` | `id` | `uuid` (unique) | `episode_id` → `episodes` **CASCADE**, `title`, `description?`, `ai_context?`, `presenter_notes?`, `sort_order`; index `(episode_id, sort_order)` |
| `episode_questions` | `id` | `uuid` (unique) | `episode_topic_id` → `episode_topics` **CASCADE**, `question`, `ai_context?`, `presenter_notes?`, `sort_order`; index `(episode_topic_id, sort_order)` |

**AiPersona provider/voice fields — logical keys, not vendor identifiers**

The task specifies the columns `ai_provider`, `ai_model`, `voice_provider`,
`voice_id`. They store **logical configuration keys** (e.g. `default`, `fast`,
`host_rebuttal`), never a vendor name, base URL, or raw model id
(CLAUDE.md §5).

**Enforced now:** `config/ai.php` holds a `persona` allow-list per column
(logical keys only). `AiPersona::booted()` registers a `saving` hook
(`assertLogicalConfigKeys()`) that throws `App\Exceptions\InvalidLogicalConfigKey`
for any non-null value outside its allow-list, so
`AiPersona::create(['ai_provider' => 'openai'])` fails and persists nothing.
`null` is always allowed.

**Follow-up (TASK-0003):** grow `config/ai.php` into the full logical→vendor
resolver (provider + model + params, credentials via `env()` there only).

**Deletion policy (deliberate)**

- A `Show` with episodes **cannot be deleted** (FK `RESTRICT`) — protects
  broadcast history. Retire via `ShowStatus::Archived`.
- An `AiPersona` that is cast in any episode **cannot be deleted** (FK
  `RESTRICT`) — protects the historical line-up. Retire via
  `AiPersonaStatus::Archived`.
- An `Episode` is the aggregate root for its own composition: deleting it
  **cascades** to `episode_ai_persona`, `episode_topics`, and (transitively)
  `episode_questions`.
- **No `SoftDeletes`** — no concrete need; status enums + `RESTRICT` are the
  archival strategy.

**Relationships**

`Show hasMany Episode` · `Episode belongsTo Show` ·
`Episode belongsToMany AiPersona` (pivot `EpisodeAiPersona`, `withPivot`
`sort_order` + `episode_instructions`, `orderByPivot('sort_order')`) ·
`Episode hasMany EpisodeTopic` (ordered) · `EpisodeTopic belongsTo Episode` ·
`EpisodeTopic hasMany EpisodeQuestion` (ordered) ·
`EpisodeQuestion belongsTo EpisodeTopic`.

**Conventions** — `declare(strict_types=1)` everywhere; `#[Fillable([...])]`
attribute (never `$guarded=[]`); `casts()` method for enums, `array`
(`screen_settings`), `datetime` (`broadcast_at`), `integer`; `uuid`
auto-generated and used as the route key via `HasUuid`;
`Model::preventLazyLoading()` outside production (`AppServiceProvider`).

**Not in this task:** AI/STT/TTS integration, realtime broadcast, studio UI,
avatar/lip-sync, Filament admin, controllers/routes, policies, PHPStan.

## 3. Key boundaries

### AI provider abstraction (see CLAUDE.md §5)

```
Domain code ──▶ TextGenerationProvider (interface, app/AI/Contracts)
                     ▲                        ▲
        OpenAiTextProvider            FakeTextProvider (default in testing)
        (app/AI/Providers/OpenAi)     (app/AI/Providers/Fake)
```

- `config/ai.php` maps logical names (`default`, `fast`, `host_rebuttal`,
  per-character) → provider + model + params. Characters store a **logical
  name**, never a vendor.
- Adapters translate vendor errors into `App\AI\Exceptions\*`
  (`ProviderException`, `ProviderTimeoutException`, `ProviderRateLimitException`,
  `ProviderContentFilteredException`). Raw vendor payloads/exceptions never
  escape the adapter.
- A `Routing\ProviderCoordinator` sits in front of the interface for fallback,
  circuit breaking, and per-session / per-character budget enforcement — done
  once, not per call site.

### Session lifecycle

- `Session\LiveSession` is a deterministic state machine
  (`scheduled → live → paused → ended`), with an explicit current-topic pointer
  and turn queue. All transitions emit domain events.
- Non-deterministic work (model calls) happens in queued jobs off the state
  machine; results re-enter as events.

### Transcript

- Every state change / turn is a domain event. `Transcript` module subscribes
  and persists an ordered log. Metrics and (later) realtime push are separate
  subscribers. No inline persistence in controllers/services.

## 4. Cross-cutting decisions

- **Enums** for every closed state set (`SessionStatus`, `TurnRole`, provider
  finish reasons).
- **DTOs** (readonly classes) for provider requests/responses, prompt inputs,
  and API payloads — no loose `array` across module boundaries.
- **Form Requests** for all inbound validation; **Policies** for all operator/
  admin authorization.
- **`env()` only in `config/`.** App code uses `config()`.
- **HTTP client** with explicit `timeout()` + `retry()` + error handling for
  every external call.
- **Idempotency keys** on every job that calls a paid external API.
- **Structured logging**, redacted; never log secrets or full prompt/response
  bodies at `info`.

## 5. Testing strategy

- Unit-test services/actions/DTOs/enums with fakes, minimal framework.
- Feature-test HTTP endpoints, Artisan commands, job dispatch (`RefreshDatabase`).
- Contract-test each provider adapter against `Http::fake()` / recorded
  fixtures — never a live paid API in the normal suite.
- Freeze time, seed randomness, assert ordering explicitly.

## 6. Review workflow (exists)

Two agents: **CLAUDE** implements, **GPT** reviews. Loop and verdict contract
are defined in `CLAUDE.md §7`. The reviewer receives only project-context,
architecture, current-task, the git diff, changed-file bodies, and captured
test results — never the whole repo.

Files:

- `.agents/scripts/gpt-review.mjs` — the reviewer (bounded context, secret-safe).
- `.agents/scripts/lib/secret-scan.mjs` — content-based credential detector.
- `.agents/scripts/lib/path-policy.mjs` — path allow/deny + binary detection.
- `.agents/scripts/lib/verdict.mjs` — 3-verdict model, severity vs blocking, normalization.
- `.agents/scripts/lib/findings.mjs` — finding fingerprint + round comparison.
- `.agents/scripts/lib/loop-status.mjs` — `currentTaskId` + `taskRoundSummary` (per-task rounds).
- `.agents/scripts/review-loop-status.mjs` — loop diagnosis (**current-task** round count, deltas, budget).
- `.agents/scripts/gpt-review.test.mjs` — `node --test` self-tests.
- `.agents/scripts/review.sh` — gates (self-tests → Pint → tests → PHPStan L6), **fail-closed** → reviewer → loop status.
- `phpstan.neon` (repo root) — PHPStan/Larastan level 6 config.
- `.agents/reviews/` — timestamped archive of every verdict (tagged with `task`).
- `.agents/skills/antigravity-gpt-review/` — the portable skill this is derived
  from, kept for reference/reuse.

## 7. Open decisions (not blocking bootstrap)

- Prod database engine (Postgres vs MySQL).
- Realtime transport for the broadcast output (Reverb / Pusher / custom).
- Queue backend for production (Redis vs SQS).
- First concrete AI vendor to implement an adapter for.
- CI provider and pipeline definition.
- Auth model for operators (Sanctum vs session + roles package).
