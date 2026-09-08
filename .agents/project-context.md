# Project context — AI Broadcast Studio

_Last updated: 2026-09-08 (TASK-0007 studio live realtime voice prototype)_

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
- No websocket/broadcasting (Reverb/Pusher) infrastructure. (TASK-0007's
  `/studio/live` voice prototype uses browser WebRTC straight to OpenAI with a
  backend-minted ephemeral key — no realtime server, no broadcasting.)
- No STT/TTS transcription; the realtime voice prototype plays audio only, no
  transcript. Broader voice (recorded TTS, STT pipelines) still untasked.
- No multi-tenant / white-label concerns yet.
- No CI/CD pipeline definition yet (planned, not in bootstrap).

## Current phase

**Studio live realtime voice prototype (TASK-0007).** Done so far: bootstrap,
TASK-0001 core domain, TASK-0002 static-analysis baseline, TASK-0003 Filament
admin, TASK-0004 episode preparation workspace, TASK-0005 vendor-neutral
text-generation foundation (`app/AI/**`), TASK-0006 real OpenAI text adapter
(Responses API, env-gated) + `AssembleRehearsalPrompt` + the Filament "AI
Provası" rehearsal page. TASK-0007 adds the **first realtime feature**: an
admin-only full-screen `/studio/live` page where the studio host and the AI
hold an uninterrupted spoken **Turkish** debate — browser mic ↔ OpenAI
Realtime over **WebRTC**, with the API key minted into a short-lived ephemeral
secret by the Laravel backend (never sent to the browser). No text/transcript;
a clean broadcast view can hide the operator controls leaving only the orb.
**20-minute** session cap (config `STUDIO_LIVE_MAX_SECONDS`, default 1200,
raisable to 60 min; the browser reads the value from the backend). A separate
`RealtimeVoiceProvider` capability (`fake` default driver, `openai` in prod). See
`.agents/architecture.md` §2f. **Prototype only** — nothing persisted; **still
no** transcript/history, per-session spend caps, Episode/persona coupling,
STT, studio display, avatar, or conversation engine.

## Environment facts

- PHP 8.4, Laravel 13.x, Composer 2.x.
- Local dev DB: SQLite (`database/database.sqlite`).
- Quality gates: `vendor/bin/pint --test` · `php artisan test` · `composer stan`
  (PHPStan/Larastan level 6). No automated external review.
- AI text driver: unset ⇒ deterministic `fake` (local / CI / tests). Production
  sets `AI_TEXT_DRIVER=openai` + `OPENAI_API_KEY` (see `.env.example`); the key
  lives only in the environment, never in source/DB.
