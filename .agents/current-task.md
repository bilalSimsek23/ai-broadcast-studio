<!-- task-id: TASK-0007 -->
# Current task

> Completed: bootstrap · TASK-0001 core domain · TASK-0002 static analysis ·
> TASK-0003 Filament core administration · TASK-0004 episode preparation
> workspace · TASK-0005 AI text provider foundation · TASK-0006 OpenAI adapter
> + Episode text rehearsal · TASK-0007 studio live realtime voice prototype.

## TASK-0007 — STUDIO LIVE REALTIME VOICE PROTOTYPE

**Status:** COMPLETE — Pint, `php artisan test` (234 passing), PHPStan level 6
(0, no baseline) all green. Acceptance is a manual Chrome check (below) — NOT
yet performed.

**Amended 2026-09-08:** session cap is **20 min** (not 10);
`STUDIO_LIVE_MAX_SECONDS` default is **1200**; the browser reads the length
only from the backend (no duplicated hardcoded value); added a **clean
broadcast view** that hides the operator controls (status/countdown/buttons)
leaving only the orb, without disabling the time limit or auto-close.

**Goal:** the first working prototype of an uninterrupted spoken Turkish
debate between a real studio host and the AI. A new full-screen route
`/studio/live`: browser mic → OpenAI Realtime → played back, with only a
pulsing orb (no text, no transcript); operator controls (Bağlan / Yayın
görünümü / Görüşmeyi bitir + status line + countdown) that can be hidden for
broadcast.

**Decisions (confirmed with the user):** standalone (no Episode/AiPersona
coupling yet) · authenticated only (admin) · WebRTC + ephemeral key · session
auto-ends at 20 min (configurable up to 60).

### Delivered

**Realtime voice capability** — separate from the `text` layer:
- `app/AI/Contracts/RealtimeVoiceProvider` —
  `createClientSession(RealtimeSessionRequest): RealtimeSessionToken`.
- DTOs (`app/AI/Dtos/`, `final readonly`): `RealtimeSessionRequest`
  (`instructions` + optional `voiceOverride`), `RealtimeSessionToken`
  (`clientSecret` + `expiresAt` + `model` + `voice`; constructor **rejects a
  secret that looks like a standing `sk-…` key**; `toArray()` carries no
  credential beyond the ephemeral secret).
- `app/AI/Providers/OpenAi/OpenAiRealtimeProvider` — the only place the OpenAI
  realtime shape lives. `POST {base}/realtime/client_secrets` with the standing
  key as Bearer, `session.type/model/instructions/audio.output.voice` body;
  parses both the flat (`{value, expires_at, session}`) and nested
  (`{client_secret:{…}}`) shapes; `retry(1)` + connect/read timeout. Reuses the
  `ProviderException` / `ProviderTimeoutException` / `ProviderRequestException`
  hierarchy — no key/URL/free-text ever in a message. The standing key never
  leaves the process; the browser only ever gets the ephemeral secret.
- `app/AI/Providers/Fake/FakeRealtimeVoiceProvider` — offline default driver;
  deterministic `ek_fake_…` secret (never `sk-`), records calls.
- `app/AI/Realtime/MintStudioSession` (thin service) + `StudioSession` DTO —
  reads the standing Turkish brief + time cap + WebRTC URL from config, asks
  the bound provider for an ephemeral session, returns the JSON the browser
  needs. `app/AI/AiServiceProvider` binds all three and selects the driver
  from `config('ai.realtime.driver')` (`fake` default, `openai` in prod).

**HTTP surface** — `routes/web.php`:
- `GET /studio/live` → `StudioLiveController@show` (full-screen Blade).
- `POST /studio/live/session` → `StudioLiveController@session` →
  `MintStudioSession`; returns `{client_secret, expires_at, model, voice,
  session_max_seconds, webrtc_url}`; a `ProviderException` is `report()`ed and
  becomes a generic `503 {error:"realtime_unavailable"}` (no key, no vendor
  text). Rate-limited `throttle:12,1`.
- Both behind `App\Http\Middleware\EnsureStudioOperator` — guest → redirect to
  `/admin/login` (or `401` for a JSON call), authenticated non-admin → `403`.

**Frontend** — `resources/views/studio/live.blade.php`, standalone dark
full-screen page, inline vanilla JS, no build step:
- `Bağlan`: `POST /studio/live/session` → `getUserMedia({audio})` →
  `RTCPeerConnection`, add mic track + `recvonly` transceiver → `createOffer`
  → `POST {webrtc_url}?model=…` with `Authorization: Bearer <ephemeral>` and
  `Content-Type: application/sdp` → `setRemoteDescription(answer)`.
- Remote audio → hidden `<audio autoplay>` + an `AnalyserNode`; a `<canvas>`
  orb (radial-gradient sphere + ring) whose radius/glow tracks the AI's speech
  amplitude, gentle idle breathing otherwise. **No transcript / text output.**
- Countdown length is `session_max_seconds` from the backend response — the
  browser holds **no** copy of the number (`startTimer(s.session_max_seconds)`,
  no fallback literal). At zero → `hangup('Süre doldu')`.
- **One clean-shutdown path** (`hangup`) used by both the time limit and
  `Görüşmeyi bitir`: `pc.close()`, stop + release every mic track,
  `audioCtx.close()`, `sink.pause()` + detach the stream, restore the operator
  view.
- **Clean broadcast view**: `Yayın görünümü` adds `body.clean`, which hides
  `#controls` / `#status` / `#timer` (orb only, cursor hidden). `Esc` or a
  click returns to the controls; `mousemove` briefly shows an "Esc" hint. The
  toggle is view-only — the countdown keeps running and auto-close still fires;
  `hangup` also clears clean mode so controls reappear when the session ends.
- Turkish status line: Hazır / Bağlanıyor… / Bağlı — konuşabilirsiniz /
  Bağlantı kesildi / Süre doldu.

**Config** — `config/ai.php` gains an `ai.realtime` section: `driver`
(`AI_REALTIME_DRIVER`, default `fake`), `session_max_seconds`
(`STUDIO_LIVE_MAX_SECONDS`, **default 1200 = 20 min**, clamped to [30, 3600]),
`webrtc_url`
(`OPENAI_REALTIME_WEBRTC_URL`), `instructions` (`STUDIO_LIVE_INSTRUCTIONS`,
built-in Turkish debate brief), `drivers`, and `connections.openai`
(`api_key` reusing `OPENAI_API_KEY`, `base_url` reusing `OPENAI_BASE_URL`,
`OPENAI_REALTIME_MODEL` default `gpt-realtime`, `OPENAI_REALTIME_VOICE` default
`marin`, timeouts). All documented in `.env.example`.

### Required production environment variables

`AI_REALTIME_DRIVER=openai`, `OPENAI_API_KEY=<secret>` (already set for text).
Optional: `OPENAI_REALTIME_MODEL` (default `gpt-realtime`),
`OPENAI_REALTIME_VOICE` (default `marin`), `STUDIO_LIVE_MAX_SECONDS`
(default 1200 = 20 min; set 1800–2400 for 30–40 min rehearsals — **if the
environment currently has `STUDIO_LIVE_MAX_SECONDS=600` it must be changed to
1200 or removed**), `STUDIO_LIVE_INSTRUCTIONS`, `OPENAI_REALTIME_WEBRTC_URL`
(default `https://api.openai.com/v1/realtime/calls`),
`OPENAI_REALTIME_TIMEOUT` / `OPENAI_REALTIME_CONNECT_TIMEOUT`. The API key is
only ever minted into a short-lived ephemeral secret server-side and is never
sent to the browser.

### Tests (no real OpenAI network calls)

- `tests/Unit/AI/RealtimeSessionTokenTest.php` — neutral `toArray()` keys only;
  rejects empty secret, a `sk-…` secret, non-positive expiry, blank model/voice.
- `tests/Feature/AI/OpenAiRealtimeProviderTest.php` — `Http::fake` mint →
  neutral token; Bearer = standing key + `session` body shape; voice override
  in payload + token; nested `client_secret` shape; missing key fails before
  any send; connection failure → `ProviderTimeoutException` (no host/key);
  non-2xx → `ProviderRequestException` (status/type/code, vendor free-text +
  key dropped); no `value` → `ProviderException`; a standing key echoed as the
  secret is rejected, not forwarded.
- `tests/Feature/AI/FakeRealtimeVoiceProviderTest.php` — implements contract;
  `ek_fake_` secret never `sk-`; deterministic; records calls + voice override.
- `tests/Feature/AI/MintStudioSessionTest.php` — **default length is 1200s**;
  honours a configured length incl. a 40-min (2400s) rehearsal; clamps out of
  range to [30, 3600]; unusable config value → 1200; forwards the configured
  brief + `webrtc_url`.
- `tests/Feature/Studio/StudioLivePageTest.php` — guest → `/admin/login`;
  non-admin → 403; admin → 200 with the orb + buttons + session endpoint, the
  `Yayın görünümü` clean-view toggle + `body.clean` + `Süre doldu` shutdown
  branch, `startTimer(s.session_max_seconds)` and **no `|| 600` literal**, and
  **no transcript / no key**.
- `tests/Feature/Studio/StudioLiveSessionTest.php` — guest → 401; non-admin →
  403; fake driver → usable JSON, standing key never in the body, no HTTP;
  **default `session_max_seconds` = 1200**, configurable to 2400 or 480;
  openai driver (`Http::fake`) → mints via the API, Bearer = standing key,
  brief forwarded, key not in the response; upstream 401 → safe `503`
  `realtime_unavailable`, no key; endpoint is rate-limited (13th call → 429).

### Acceptance checklist

- [x] New `/studio/live` full-screen route; orb-only UI, no text/transcript
- [x] Browser mic → OpenAI Realtime (Türkçe) → played back; orb reacts to AI speech
- [x] Bağlan / Görüşmeyi bitir + Turkish status indicator
- [x] API key never in the frontend — backend mints an ephemeral WebRTC session
- [x] Admin-gated route + rate-limited, `report()`ed safe failure
- [x] **20-minute** auto-end; length is backend config only (no hardcoded
      browser copy); configurable up to 60 min for longer rehearsals
- [x] Clean broadcast view hides the operator controls (orb only) without
      disabling the time limit / auto-close; operator can return via Esc/click
- [x] One clean-shutdown path (close peer, stop playback, release mic) for both
      the time limit and `Görüşmeyi bitir`
- [x] No existing feature touched (additive: new routes/controller/middleware/
      config section/contract/adapter/DTOs/view/tests)
- [x] Pint · `php artisan test` (234) · PHPStan level 6 (0, no baseline)
- [ ] **Manual (Chrome) — NOT yet performed:** log in as admin → open
      `/studio/live` → Bağlan → allow mic → hold a few Turkish back-and-forth
      turns → try `Yayın görünümü` + Esc → Görüşmeyi bitir; separately let the
      countdown reach zero and confirm it disconnects, stops audio and releases
      the mic. (Requires `AI_REALTIME_DRIVER=openai` + a real `OPENAI_API_KEY`.)

### Next task (draft, not started)

**TASK-0008** — not started. Do not begin without a written task here.
Likely follow-ups: bind `/studio/live` to a specific Episode (persona identity
+ AI brief + topics via the TASK-0006 prompt assembly), a persisted
transcript/event log, per-session spend caps.
