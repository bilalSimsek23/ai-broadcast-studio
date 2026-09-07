# Project context — AI Broadcast Studio

_Last updated: 2026-09-06 (bootstrap)_

## What it is

A Laravel-based broadcast platform for a **live television format** in which a
**real human host in a studio** debates predefined topics **on air** with **one
or more on-screen AI characters**.

The host and control room are the users. AI characters are the "other side" of
the debate, rendered on the broadcast output and driven by language models
through a vendor-neutral abstraction.

## Primary actors

| Actor | Role |
|-------|------|
| **Host** | The person on camera. Speaks (audio, later transcribed) or types prompts; leads the debate. |
| **Operator / control room** | Runs the session: start/stop, cut to topic, mute/force an AI turn, inject moderator notes. |
| **Producer / admin** | Configures shows, episodes, topics, and AI characters before air. |
| **AI character** | A configured persona bound to a logical model. Produces debate turns. Not a user. |
| **Viewer** | Watches the broadcast output. Not an authenticated user of this system. |

## Core nouns (domain vocabulary)

_Modelled (TASK-0001):_

- **Show** (`shows`) — a recurring program. Has a `ShowStatus` lifecycle.
- **AiPersona** (`ai_personas`) — a **persistent** AI character: identity,
  personality, provider/voice bindings, `screen_settings`. Its identity is
  kept separate from any episode-specific context.
- **Episode** (`episodes`) — one broadcast instalment of a Show, with prep and
  broadcast notes and an `EpisodeStatus` lifecycle.
- **Episode line-up** (`episode_ai_persona` pivot) — which personas are in an
  episode, in what order, with per-episode instructions.
- **EpisodeTopic** (`episode_topics`) — a subject to be debated within an
  episode (with `ai_context` / `presenter_notes`).
- **EpisodeQuestion** (`episode_questions`) — a question / discussion point
  under a topic.

_Not modelled yet (later tasks):_

- **Live Session** — the on-air run of an episode: lifecycle, current topic, turn order.
- **Turn** — one contribution (host or AI character) within a topic.
- **Transcript / Event log** — the ordered, persisted record of a live session.
- **Provider** — an AI vendor adapter behind our interface; product code never names one.

## Hard constraints

1. **Broadcast-critical.** A provider failure must never crash or freeze a live
   session. Degrade: skip turn / fall back / surface to operator.
2. **Latency-sensitive.** AI turn round-trips are time-bounded and cancelable.
3. **Vendor-neutral.** No vendor name, SDK, base URL, or model id outside
   `app/AI/Providers/<Vendor>/` and `config/ai.php`.
4. **Auditable.** Every session state change is a domain event; the transcript
   is complete enough to replay the session.
5. **Cost-bounded.** Per-session and per-character spend caps enforced before
   dispatching a paid call.
6. **Secrets stay out of the repo** — never in source, tests, fixtures or docs.
7. **Untrusted text.** Host transcripts and operator free-text are untrusted
   input to prompts (prompt-injection aware).

## Non-goals (for now)

- No public viewer accounts / viewer-facing web app.
- No real-time websocket/broadcasting infrastructure until a live-session
  feature is actually scheduled.
- No voice (STT/TTS) until explicitly tasked; interfaces are reserved, not built.
- No multi-tenant / white-label concerns yet.
- No CI/CD pipeline definition yet (planned, not in bootstrap).

## Current phase

**AI text provider foundation (TASK-0005).** Done so far: bootstrap,
TASK-0001 core domain, TASK-0002 static-analysis baseline,
TASK-0003 Filament admin, TASK-0004 episode preparation workspace. TASK-0005
adds the **vendor-neutral text-generation foundation** in `app/AI/**`: the
`TextGenerationProvider` contract, neutral request/response DTOs, a
`LogicalModelResolver` (logical provider/model key → provider + vendor model id
+ default params, driven by a new `config('ai.text')` section), a deterministic
network-free `FakeTextProvider`, and one thin `GenerateText` application
service. See `.agents/architecture.md` §2d for the
`Episode data → [future] prompt assembly → GenerateText → resolver → provider →
[future] vendor adapter` boundary. **Still no** real vendor call, prompt
assembly from Episode/AiPersona data, STT/TTS, realtime broadcast, studio
display, avatar/lip-sync, or conversation engine.

## Environment facts

- PHP 8.4, Laravel 13.x, Composer 2.x.
- Local dev DB: SQLite (`database/database.sqlite`).
- Quality gates: `vendor/bin/pint --test` · `php artisan test` · `composer stan`
  (PHPStan/Larastan level 6). No automated external review.
