# Architecture — AI Broadcast Studio

_Last updated: 2026-09-08 (TASK-0007 studio live realtime voice prototype).
Section 2 and its module split are the **target** shape; §§2a–2f record what is
actually built._

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

**Follow-up:** the logical→vendor **text** resolver landed in TASK-0005
(`config('ai.text')` + `app/AI/**`, see §2d); the first real adapter — OpenAI
Chat Completions — landed in TASK-0006 (see §2e), env-gated so logical keys stay
vendor-neutral. Still to come: other text vendors, and the analogous `voice`
resolver for `voice_provider` / `voice_id`.

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

## 2b. Admin UI — as built (TASK-0003)

**Filament v5** panel at `/admin` (`app/Providers/Filament/AdminPanelProvider.php`,
`app/Filament/**`). The admin UI is **only a management surface** over the
TASK-0001 domain models — it does not own domain logic, validation semantics,
or the deletion policy. Invariants live in the models/DB; the UI mirrors them:

- **Access:** `users.is_admin` boolean; `User implements FilamentUser` →
  `canAccessPanel()` = `is_admin`. Guests → panel login; non-admins → 403.
  No RBAC package.
- **Resources:** `ShowResource`, `AiPersonaResource`, `EpisodeResource`
  (nav group "Yayın Yönetimi"); `EpisodeTopicResource` is nav-hidden and
  reached only from an Episode. Class names English; UI labels Turkish.
- **Nested data** via relation managers (not deep nested forms):
  Episode → AiPersonas (pivot `sort_order` + `episode_instructions`),
  Episode → Topics, EpisodeTopic → Questions. Ordering via drag-reorder on
  the `sort_order` columns.
- **Vendor neutrality:** the `ai_provider` / `ai_model` / `voice_provider` /
  `voice_id` form fields are Selects populated from `config('ai.persona.*')`,
  so the UI cannot submit a vendor name. The `AiPersona` saving-hook
  (TASK-0001) remains the authoritative guard.
- **`screen_settings`** is edited as structured fields
  (`display_name`, `display_title`, `avatar_position`, `avatar_size`,
  `lower_third_enabled`) mapped onto the JSON column — no raw-JSON textarea.
- **Delete/archive:** `App\Filament\Support\AdminActions` — the delete row
  action is hidden while a record is FK-protected (Show with episodes,
  AiPersona in a line-up) and a `before` hook re-checks and notifies; an
  "Arşivle" action sets `status = *::Archived`. The DB RESTRICT constraints
  are untouched.

**AI text generation is a rehearsal tool, not the broadcast runtime.** As of
TASK-0006: the vendor-neutral *text* foundation (§2d), a **real OpenAI adapter**
behind the contract, a rehearsal **prompt-assembly** service, and a Filament
**"AI Provası"** page that generates one persona response from an Episode's
editorial data (§2e). TASK-0007 adds a **realtime voice prototype** — the
admin-only `/studio/live` page: browser mic ↔ OpenAI Realtime (Turkish),
WebRTC + backend-minted ephemeral key, orb-only UI (§2f). Still missing:
STT/TTS transcription, studio display / avatar, conversation history/transcript
persistence, per-session spend caps, and coupling the realtime session to an
Episode's persona + brief. Persona binding columns still hold logical keys only.

**Not yet built:** everything in the bullet above, plus a public/viewer web
app, queue workers config, CI.

## 2c. Episode preparation — as built (TASK-0004)

The editorial workspace for preparing a weekly episode before broadcast.

**Application layer** (`app/Application/Episodes/`, framework-agnostic — no
Filament, no HTTP):

- `Readiness/ReadinessCheck` — a `readonly` value object: `key`, `label`,
  `passed`, `blocking`, `hint`.
- `Readiness/EpisodeReadiness` — `readonly` VO over a list of checks:
  `isReady()`, `checks()`, `blockingIssues()`, `toArray()` →
  `{is_ready, checks[], blocking_issues[]}`.
- `Readiness/AssessEpisodeReadiness` — `__invoke(Episode): EpisodeReadiness`.
  The **single source of truth** for "is this episode ready to broadcast?":
  show assigned · title · main_topic · broadcast_at · ≥1 AI persona · ≥1 topic
  · ≥1 question · AI brief (objective + must-cover points) — whitespace-only
  does not count.
- `MakeEpisodeReady` — `__invoke(Episode): EpisodeReadiness`. The **only**
  supported writer of `status → Ready`. Runs in a `DB::transaction`, re-reads
  the row with `lockForUpdate()`, rejects a non-preparable source state up
  front — independent of readiness — with
  `App\Exceptions\InvalidEpisodeTransition`, then `lockForUpdate()`s the
  readiness **witness rows** (line-up, topics, per-topic questions) so a
  concurrent relation-manager delete of the last one blocks behind this
  transition rather than committing between the check and the write. It then
  assesses that fresh instance and transitions only when every blocking check
  passes. The transition itself is a **query-builder `update()`** (fires no
  model events, so it is the one write the model hooks below let through)
  reached only after the locked checks. All locks are held across the check
  and the write. Already-`Ready` is idempotent (no throw, no write). The
  caller's instance is synced to the persisted state. Returns the assessment;
  no exception for the ordinary "not ready yet" case.
  **DB-engine note:** dev/test run SQLite, which serializes all writes
  globally and treats `FOR UPDATE` as a no-op, so the assess→write gap does
  not exist there and the automated suite exercises the logic sequentially.
  The row locks are what protect Postgres/MySQL and **must be verified with a
  two-connection test against the real engine before production release**. A
  readiness-affecting edit made *after* a committed Ready transition is the
  operator's responsibility — the status is not auto-downgraded (reverting
  Ready/Live/Completed is out of scope for TASK-0004).
- `DuplicateEpisode` — start next week from an existing episode. Copies:
  show, AI line-up (personas + order + `episode_instructions`), optionally
  `broadcast_instructions`. **Never** copies: status (→ Draft), broadcast_at,
  episode_number, main_topic, purpose, the briefs, topics/questions, any
  session history.

**Ready-transition invariant** (defense in depth): `Ready` is a **guarded
status**. `Episode::booted()` registers `creating` **and** `updating` hooks
that refuse *every* event-driven write of `status = Ready` — a model
`save()` / `update()`, or `create()` — with
`App\Exceptions\InvalidEpisodeTransition::unauthorizedReadyWrite()`, writing
nothing, whatever the caller (Filament form, tinker, a future API, other
application code). There is **no public authorization switch** to flip. The
one path that reaches `Ready` is `MakeEpisodeReady`'s query-builder `update()`
— events don't fire for it, and it only runs inside that service's row-locked
transaction after the lifecycle + readiness checks, which is what makes the
transition atomic (a pre-update observer check on an unlocked row could be
split by a concurrent Live/Archived commit). Fixtures that need a `Ready` row
(`EpisodeFactory::scheduled()`) reach it with an explicit event-free write
after creation. The Filament status Select also does not offer `Ready`; the
path is **Program Hazırlığı → "Yayına Hazırla"** (`MakeEpisodeReady`).

**Filament** (`app/Filament/Resources/Episodes/Pages/PrepareEpisode.php`,
extends `EditRecord`, route `/admin/episodes/{uuid}/prepare`): a curated
single-screen workspace — summary + purpose + broadcast instructions +
presenter brief + AI brief form, a live **readiness panel** and a read-only
**summary panel** (Blade partials calling the application services), the
existing AI-line-up and Topics **relation managers** (reliable, not deeply
nested forms), and header actions "Yayına Hazırla" / "Ayrıntılı düzenleme".
The readiness panel is **form-state aware**: the readiness scalar fields are
`live(onBlur: true)` and the panel assesses a candidate built from the current
(possibly unsaved) form values, so it reflects edits before Save. "Yayına
Hazırla" **persists the form first** (`$this->save(...)`, which also validates
it) and then calls `MakeEpisodeReady`, catching `InvalidEpisodeTransition`
into an operator notification. The Episode list has a "Yeni bölüm oluştur
(kopyala)" action (`DuplicateEpisode`).

**Four distinct editorial concerns — do not conflate:**

| Belongs to | Field(s) | Purpose |
|---|---|---|
| **AiPersona** (persistent identity) | `system_prompt`, `personality`, … | The character, week-independent. Never episode-specific. |
| **Episode** (this episode's AI brief) | `ai_objective`, `ai_tone_override`, `must_cover_points`, `avoid_points`, `response_length_guidance` | How the AI voices should approach *this* broadcast. Consumed later by runtime prompt assembly (not built). |
| **EpisodeTopic** | `ai_context` | AI context for *one debate topic*. |
| **EpisodeAiPersona** (line-up pivot) | `episode_instructions` | Notes for *one persona in one episode*. |

Plus the human **presenter brief** on the Episode
(`opening_notes`, `key_points`, `questions_to_push`, `closing_notes`).
All preparation fields are **separate nullable columns** (not one JSON blob):
queryable, simple per-field validation, clean for future prompt assembly / API.

**Still NOT built:** STT/TTS, live broadcast engine, studio display,
avatar/lip-sync, conversation history. Live/Completed status transitions are out
of scope. (The vendor-neutral text *foundation* landed in TASK-0005, §2d;
rehearsal prompt assembly + a real OpenAI adapter + the "AI Provası" page landed
in TASK-0006, §2e — a rehearsal tool, still no live-session runtime.)

## 2d. AI text generation foundation — as built (TASK-0005)

The vendor-neutral base the future rehearsal / studio conversation features
call into. **No vendor SDK, no network, no prompt assembly** — those are later
tasks. Everything lives in `app/AI/**` and is framework-agnostic apart from
`AiServiceProvider`.

**The boundary (each arrow = "calls"):**

```
Episode editorial data (ai_objective, topics, briefs, persona notes …)
  → [future] prompt assembly            ← NOT in TASK-0005
    → App\AI\GenerateText               thin application service
      → App\AI\Resolution\LogicalModelResolver   logical key → provider + vendor model id + defaults
        → App\AI\Contracts\TextGenerationProvider
          ├─ App\AI\Providers\Fake\FakeTextProvider     (today; deterministic, no network)
          └─ App\AI\Providers\<Vendor>\…                ← NOT in TASK-0005 (real adapter)
```

**Contract** — `Contracts/TextGenerationProvider::generate(ResolvedTextGenerationRequest): TextGenerationResponse`.
A vendor SDK class or vendor-shaped payload never crosses this line. Network
concerns (timeout/retry/rate-limit) and their exception hierarchy belong to the
future real adapters, deliberately not in this contract yet.

**Neutral DTOs** (`Dtos/**`, `final readonly`) + enums (`Enums/{Role,FinishReason}`):
`Message` / `MessageList` (ordered, non-empty), `GenerationParameters`
(small surface — `temperature`, `maxOutputTokens` — validated centrally; unknown
keys rejected; `mergedWith()` = caller override wins field-by-field over the
logical-model default), `TextGenerationRequest` (application-facing, **logical**
model key), `ResolvedTextGenerationRequest` (provider-facing, resolved **vendor**
model id; carries the logical keys only for telemetry), `TokenUsage`
(vendor-neutral counts, keys `input_tokens` / `output_tokens` / `total_tokens`,
no billing), `ResponseMetadata` (`logicalProvider` / `logicalModel` are what the
app logs/branches on; `providerModelId` is diagnostic-only and never a logical
key or a domain-model value), `TextGenerationResponse`.

**Resolver** — `Resolution/LogicalModelResolver` (container singleton) is
**framework-agnostic**: `AiServiceProvider` (the one Laravel-aware file in
`app/AI/**`) hands it a plain `ai.text` config array and a
`Closure(string): object` driver factory; the resolver imports no Illuminate
class. `provider(key)` and `model(key)` **never fall back silently**:
`UnknownProviderKey` / `UnknownModelKey` for an unregistered key,
`InvalidTextConfiguration` for a structurally broken / blank binding
(`trim() === ''`) or a driver class that is not a `TextGenerationProvider`.
Exception messages **never echo a caller-supplied key** (it may be a pasted
credential) or a raw config value — `InvalidTextConfiguration` names only the
logical key whose binding is broken, and only after it is confirmed present in
config, via the `SafeIdentifier` formatter. `GenerationParameters` also rejects
non-finite (`NAN` / `INF`) temperatures.

**`config('ai.text')`** — `providers` (logical provider key → `driver`;
superset of `ai.persona.ai_provider`), `models` (logical model key → provider +
vendor model id + default `parameters`; keys align with `ai.persona.ai_model`
plus `host_rebuttal`), `drivers` (driver key → class), `connections`
(per-driver credentials/transport via `env()` in `config/ai.php` only).
As of TASK-0006 (§2e): `drivers` = `fake` + `openai`; the provider `driver`s and
vendor model ids are `env()`-gated (`AI_TEXT_DRIVER`, `AI_TEXT_MODEL_*`) with the
`fake` values as the default, so an unset environment (local / CI / tests) is
100% deterministic and `AI_TEXT_DRIVER=openai` routes the same logical keys
through the real adapter. The persona logical-key invariant (TASK-0001, §2a) is
unchanged: a persona still validates against `config('ai.persona.*')`, never
against this section, and still cannot store a vendor name.

**`GenerateText`** — the one thin entry point: resolve → merge overrides over
configured defaults → call the provider → return its neutral response
unchanged. Knows nothing about Filament, Episode, AiPersona, or readiness.

## 2e. OpenAI adapter + Episode text rehearsal — as built (TASK-0006)

The first real AI interaction, on top of §2d. A **rehearsal tool** — no
persistence, no live-session runtime, no STT/TTS/audio/avatar/WebSocket/
streaming.

```
Episode editorial data ─▶ AssembleRehearsalPrompt.assemble()  (app/AI/Prompting)
                            → TextGenerationRequest (logical model key)
                              → GenerateText → LogicalModelResolver
                                → OpenAiTextProvider  (app/AI/Providers/OpenAi)
                                  → Http (Responses API, connect+read timeout, retry(2), no stream)
Filament "AI Provası" page (RehearseEpisode) orchestrates the above; renders one response; persists nothing.
```

**`OpenAiTextProvider`** — implements `TextGenerationProvider`; the only place
the OpenAI HTTP shape lives. Targets the **Responses API**
(`POST {base_url}/responses`) — the current surface for the GPT-5.x family.
Neutral → OpenAI mapping (adapter-only): `systemInstructions` → top-level
`instructions`; conversation turns → `input` (role/content items);
`maxOutputTokens` → `max_output_tokens`. Response: assistant text is aggregated
from `output[].content[]` `output_text` parts (`reasoning`/`refusal` items
skipped); `usage.input_tokens`/`output_tokens` → `TokenUsage`; finish reason is
derived from `status` + `incomplete_details.reason`.
**`temperature` is NOT sent by default** — GPT-5.x reasoning models reject any
non-default value with `HTTP 400 unsupported_value`; the neutral layer still
carries it, and the adapter forwards it only when the connection sets
`send_sampling_params` (env `OPENAI_TEXT_SEND_SAMPLING`, default false — for a
GPT-4-class deployment).
Credentials come only from `config('ai.text.connections.openai')` (env-sourced)
via an `AiServiceProvider` singleton — never a constructor literal, never
persisted, never logged/echoed/in an exception. Vendor error translation (new
`App\AI\Exceptions`): `ProviderException` (base — missing key fails before any
send; no output text), `ProviderTimeoutException` (connection/read failure;
message names only the timeout seconds), `ProviderRequestException` (non-2xx;
carries only HTTP `status` + short enum-like `type`/`code`/`param` — vendor
free-text and raw bodies are dropped). A raw Guzzle/HTTP exception or vendor
payload never escapes `generate()`.

**`AssembleRehearsalPrompt`** — builds the `TextGenerationRequest` from
Show/Episode context, episode broadcast instructions, persona identity +
expertise/personality/speaking-style + `system_prompt`, per-episode line-up
instructions, the episode AI brief (objective, tone override, must-cover, avoid,
response-length guidance), and the optionally-selected topic/question context;
blank fields are skipped; the presenter question is the single user message.
Logical model key = `persona->ai_model` or `default`. Names no vendor, reads no
config, makes no call. Presenter question + editorial free-text are untrusted
(a closing `GÖREV` directive: the question cannot override the brief).

**`RehearseEpisode`** — Filament resource page, route
`episodes/{record}/rehearse`, from a "AI Provası" header action on
`PrepareEpisode`. Persona Select options are computed server-side from **this
episode's line-up only**; topic/question Selects are episode/topic-scoped; the
presenter question auto-fills from a selected question but stays editable.
`generate()` re-scopes every selection against the episode, assembles, calls
`GenerateText`, and renders the response with a logical-model/finish-reason/
token footnote. `ProviderException | AiConfigurationException` → `report()` + a
generic Turkish danger notification (no credentials, no raw vendor error).
Double-submit guarded via `wire:target` + `wire:loading.attr="disabled"`.

## 2f. Studio live realtime voice prototype — as built (TASK-0007)

The first realtime feature. A full-screen `/studio/live` page for an
uninterrupted spoken **Turkish** debate between the studio host and the AI —
mic in, voice out, a pulsing orb, **no text / no transcript**. This is where
CLAUDE.md §2.9 "no premature realtime infra" is deliberately lifted; it stays
minimal (WebRTC only, no realtime server, no broadcasting).

**Decisions:** standalone (no Episode/AiPersona coupling yet) · admin-only ·
WebRTC + ephemeral key · 10-minute auto-end.

```
Browser mic  ─▶  RTCPeerConnection  ──(SDP, Bearer=ephemeral secret)──▶  OpenAI Realtime  ─▶  <audio> + AnalyserNode ─▶ orb
     ▲                                                                        ▲
     │   POST /studio/live/session (admin, throttled)                         │
     └── StudioLiveController → MintStudioSession → RealtimeVoiceProvider ─────┘
                                    (OpenAiRealtimeProvider mints the ephemeral secret from the standing key)
```

**Capability (separate from §2d text):**
`app/AI/Contracts/RealtimeVoiceProvider::createClientSession(RealtimeSessionRequest): RealtimeSessionToken`.
- `RealtimeSessionRequest` — `instructions` + optional `voiceOverride`.
- `RealtimeSessionToken` — `clientSecret` (short-lived ephemeral), `expiresAt`,
  `model`, `voice`. Constructor **rejects a `sk-…` value** (leak guard);
  `toArray()` never carries a standing credential.
- `OpenAiRealtimeProvider` (`app/AI/Providers/OpenAi/`) — `POST
  {base}/realtime/client_secrets`, standing key as Bearer, `session` body
  (`type`/`model`/`instructions`/`audio.output.voice`); parses flat and nested
  secret shapes; `retry(1)` + timeouts; same `ProviderException` family as
  §2e (no key/URL/free-text in messages). The standing key never leaves the
  process.
- `FakeRealtimeVoiceProvider` (`app/AI/Providers/Fake/`) — offline default
  driver; deterministic `ek_fake_…` secret.
- `MintStudioSession` + `StudioSession` (`app/AI/Realtime/`) — thin service:
  reads the standing Turkish brief + 10-min cap + WebRTC URL from
  `config('ai.realtime')`, calls the bound provider, returns
  `{client_secret, expires_at, model, voice, session_max_seconds, webrtc_url}`.
- `AiServiceProvider` binds all three; driver from `config('ai.realtime.driver')`
  (`fake` default, `openai` in prod).

**HTTP** (`routes/web.php`, `App\Http\Controllers\StudioLiveController`,
`App\Http\Middleware\EnsureStudioOperator`):
`GET /studio/live` (full-screen Blade) and `POST /studio/live/session`
(`throttle:12,1`, `ProviderException` → `report()` + generic `503
{error:"realtime_unavailable"}`). Both admin-gated — guest → `/admin/login`
(or 401 JSON), non-admin → 403.

**Frontend** — `resources/views/studio/live.blade.php`, standalone dark page,
inline vanilla JS, no build step: `getUserMedia` → `RTCPeerConnection` → SDP
exchange with the ephemeral secret; remote audio drives a `<canvas>` orb via an
`AnalyserNode`; 10-min countdown auto-hangs-up; Turkish status line; Bağlan /
Görüşmeyi bitir.

**Config** — `config/ai.php` → `ai.realtime` (`driver`, `session_max_seconds`,
`webrtc_url`, `instructions`, `drivers`, `connections.openai` reusing
`OPENAI_API_KEY` / `OPENAI_BASE_URL`). All env keys in `.env.example`.

## 3. Key boundaries

### AI provider abstraction (see CLAUDE.md §5)

```
Domain code ──▶ GenerateText ──▶ LogicalModelResolver ──▶ TextGenerationProvider (interface, app/AI/Contracts)
                                                               ▲                    ▲
                                                   FakeTextProvider          <Vendor>TextProvider  (NOT built)
                                                   (app/AI/Providers/Fake)   (app/AI/Providers/<Vendor>)
```

- **Built (TASK-0005 §2d, TASK-0006 §2e, TASK-0007 §2f):** the text interface,
  neutral DTOs, `config('ai.text')`, the `LogicalModelResolver`, `GenerateText`,
  the deterministic `FakeTextProvider`, a real `OpenAiTextProvider` (env-gated),
  the `ProviderException` / `ProviderTimeoutException` /
  `ProviderRequestException` translation hierarchy, `AssembleRehearsalPrompt`,
  the "AI Provası" rehearsal page, and — separately — the realtime voice
  capability (`RealtimeVoiceProvider` + `OpenAiRealtimeProvider` /
  `FakeRealtimeVoiceProvider` + `MintStudioSession` + `/studio/live`).
  Characters store a **logical name**, never a vendor.
- **Not built yet:** other vendor adapters; `ProviderRateLimitException` /
  `ProviderContentFilteredException` (429/filter are `ProviderRequestException`
  cases today); a `Routing\ProviderCoordinator` in front of the interface for
  fallback / circuit breaking / per-session / per-character budgets (done once,
  not per call site); conversation history / transcript persistence; coupling
  the realtime session to an Episode persona + brief; the live conversation
  engine.

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

## 6. Quality gates

Claude is the sole implementation agent — there is no automated external
reviewer. A task is done when all local gates pass:

- `.agents/scripts/review.sh` — runs, in order, stopping at the first failure:
  `vendor/bin/pint --test` → `php artisan test` → `composer stan`.
- `phpstan.neon` (repo root) — PHPStan/Larastan level 6 config, no baseline.

## 7. Open decisions (not blocking bootstrap)

- Prod database engine (Postgres vs MySQL).
- Realtime transport for the broadcast output (Reverb / Pusher / custom).
- Queue backend for production (Redis vs SQS).
- First concrete AI vendor to implement an adapter for.
- CI provider and pipeline definition.
- Auth model for operators (Sanctum vs session + roles package).
