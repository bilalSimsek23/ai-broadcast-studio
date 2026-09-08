<!-- task-id: TASK-0007 -->
# Current task

> Completed: bootstrap · TASK-0001 core domain · TASK-0002 static analysis ·
> TASK-0003 Filament core administration · TASK-0004 episode preparation
> workspace · TASK-0005 AI text provider foundation · TASK-0006 OpenAI adapter
> + Episode text rehearsal · TASK-0007 studio live realtime voice prototype.

## TASK-0007 — STUDIO LIVE REALTIME VOICE PROTOTYPE

**Status:** COMPLETE — Pint, `php artisan test` (270 passing), PHPStan level 6
(0, no baseline) all green. Real Chrome / microphone / multi-device / visual /
end-to-end voice acceptance is MANUAL and has NOT been run automatically (Blade
renders + JS `node --check` + PHPUnit only).

**Amendments after the first live prova (2026-09-08):**
1. Session cap **20 min** (`STUDIO_LIVE_MAX_SECONDS` default 1200); the browser
   reads the length only from the backend response (no literal).
2. `/studio/live` is now the **clean broadcast output**: steady state is ONLY
   the orb — no buttons, status, countdown, hint or any text. All operator
   controls moved to a Filament admin page.
3. New Filament page **"Canlı Yayın Kontrolü"** (`/admin/studio-control`): the
   director's console — Bağlan / Görüşmeyi bitir / Mikrofonu sessize al /
   Mikrofonu aç, connection + mute state, remaining time, **AI Ses Girişi** /
   **AI Ses Çıkışı** physical device selectors + **AI Sesi** voice picker,
   "Cihazları Yenile".
5b. The AI voice defaults to a **male** voice (`cedar`); the director may
   switch it per session from the "AI Sesi" `<select>` (allow-list
   `config('ai.realtime.voices')`, validated server-side; unknown → default).
   The choice is persisted browser-local like the deviceIds.
4. Studio-room noise handling: getUserMedia `echoCancellation` /
   `noiseSuppression` / `autoGainControl` (config → response, no literal) and a
   MILD OpenAI `server_vad` raise + `far_field` `noise_reduction`
   (`config('ai.realtime.audio')`), barge-in kept on.
5. Orb enlarged to `min(70vw, 70vh)` and made mathematically contained — at max
   amplitude the outer glow is exactly `S*0.49`, never clipped at any
   resolution; the CSS `drop-shadow` (which the container could clip) is gone.
6. **Studio Control visual redesign (presentation only — no behaviour change).**
   Root cause of the "raw" look: the page used app-level Tailwind utility
   classes, but Filament panel pages load Filament's own compiled CSS (published
   to `public/css/filament` on `composer install`), **not** `resources/css/app.css`
   — and that app build doesn't scan `resources/views/filament/**` anyway
   (Tailwind v4, `@source` list). So those utilities resolved to nothing.
   Fix: rebuilt the markup with Filament's own `fi-*` components
   (`<x-filament::section>` / `badge` / `button` / `input.wrapper` +
   `input.select`) which are styled by the shipped stylesheet, plus a small
   **page-scoped `<style>` of plain CSS** (grid / status tiles / alerts — not
   utility classes, so nothing to purge, no Vite dependency). 2-column desktop
   layout, `$maxContentWidth = Width::SevenExtraLarge`, status tiles, typed
   connection badge, styled "Yayın Ekranı" banner. No production asset-pipeline
   bug found — Filament serves its own CSS and it was already loading.
7. **Episode Preparation → Realtime integration (production flow).** A live
   session is now bound to a prepared, **Ready** Episode + its speaking persona,
   and the AI starts the conversation already knowing everything editorial. No
   new DB / model / migration. Pieces:
   - `app/AI/Prompting/AssembleEpisodeBriefing` — the ONE provider-independent
     "everything about this prepared episode" string builder (Show + Episode +
     persona + slot + full topics/questions). `AssembleRehearsalPrompt` now
     delegates to it and only adds its single-question emphasis + `GÖREV`;
     realtime uses the same builder + a fixed `CANLI YAYIN GÖREVİ` directive.
     Presenter-only fields (`opening_notes` / `key_points` / `questions_to_push`
     / `closing_notes`, `EpisodeTopic.presenter_notes`,
     `EpisodeQuestion.presenter_notes`) are deliberately excluded.
   - `app/AI/Realtime/ResolveStudioEpisode` + `StudioEpisodeContext` +
     `StudioEpisodeUnavailable` — domain-level validation (episode given /
     exists / Ready; ≥1 line-up persona; a persona UUID required only when the
     line-up has >1; a given persona must be in THIS line-up). No silent
     fallback — the controller returns a `422 {error, message}`.
   - `MintStudioSession::__invoke(?string $voice, ?StudioEpisodeContext $ctx)` —
     with a context it builds the briefing + directive; without one it falls
     back to `config('ai.realtime.instructions')` (standalone), gated by
     `config('ai.realtime.allow_standalone_session')` (env
     `STUDIO_LIVE_ALLOW_STANDALONE`, **default false**).
   - `StudioLiveController::session()` reads `episode` + `persona` from the body,
     resolves+validates, mints. `StudioControl` page: "Yayın Hazırlığı" section
     — Ready-episode `<select>` (label "Program — Bölüm N — Başlık") + a
     "Canlı AI Karakteri" `<select>` shown only for multi-persona line-ups
     (single persona auto-selected, `sort_order` first as default) + a summary
     (Program / Bölüm / Ana Konu / AI Karakteri / Yayın Durumu). Both persisted
     browser-local and sent as `episodeUuid` / `personaUuid` on the same
     `BroadcastChannel` `devices` message; `/studio/live` forwards `episode` /
     `persona` in the mint `POST`. BAĞLAN disabled until an episode + persona
     are chosen. Episode/persona selectors disable once connected.
   - **Real demo Episode briefing size:** 10 650 characters / ~2 170 words —
     comfortably within OpenAI Realtime `session.instructions` limits; **no
     truncation applied.**

**Goal:** the first working prototype of an uninterrupted spoken Turkish debate
between a real studio host and the AI, split into a **clean broadcast layer**
(`/studio/live`, orb only) and an **operator layer** (Filament) in the same
browser.

**Decisions (confirmed with the user):** authenticated only (admin) · WebRTC +
ephemeral key · session auto-ends at 20 min (config, up to 60) · device
discovery + deviceId management entirely in the reji browser (never Laravel) ·
**a live session binds a Ready Episode + one line-up persona; no episode ⇒
refused (unless `allow_standalone_session`)** · no new DB / model / migration.

### Architecture (E / G)

`/studio/live` stays the SOLE owner of `getUserMedia`, the
`RTCPeerConnection` and the `<audio>` sink, and renders only the orb. The
Filament **Canlı Yayın Kontrolü** page holds no media: it enumerates the reji
machine's audio devices in the browser (`navigator.mediaDevices.enumerateDevices`),
shows the selectors + transport/mute/state, persists the chosen deviceIds in
`localStorage` (per-machine, never server config), and drives `/studio/live`
over a **same-origin `BroadcastChannel('studio-live')`**. Protocol: control →
broadcast `{type:'cmd', cmd:'connect'|'hangup'|'mute'|'unmute'}` and
`{type:'devices', inputId, outputId}`; broadcast → control `{type:'state', …}`
every second (doubles as a liveness heartbeat) + `{type:'hello'}` on load. **No
server-side realtime/audio relay** — the server only does auth / session
minting / config (CLAUDE.md §2.9, §5). The two pages must be open in the same
browser (typical single reji PC: broadcast output on one monitor / OBS source,
control on another).

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
  key as Bearer; `session` body carries `type`/`model`/`instructions`/
  `audio.output.voice` and, when configured, `audio.input` (`noise_reduction`
  + `server_vad` tuning + `create_response`/`interrupt_response`). Parses flat +
  nested secret shapes; `retry(1)` + timeouts; reuses the `ProviderException` /
  `ProviderTimeoutException` / `ProviderRequestException` hierarchy — no
  key/URL/free-text in a message. The standing key never leaves the process.
- `app/AI/Providers/Fake/FakeRealtimeVoiceProvider` — offline default driver;
  deterministic `ek_fake_…` secret (never `sk-`), records calls.
- `app/AI/Realtime/MintStudioSession` (thin service) + `StudioSession` DTO —
  reads the standing Turkish brief + time cap + WebRTC URL + getUserMedia
  constraints from config, asks the bound provider for an ephemeral session,
  returns the JSON the broadcast page needs (incl. `audio_constraints`).
  `app/AI/AiServiceProvider` binds all three and selects the driver from
  `config('ai.realtime.driver')` (`fake` default, `openai` in prod), passing
  `config('ai.realtime.audio.turn_detection')` + `noise_reduction` to the
  OpenAI adapter.
- `app/Filament/Pages/StudioControl` + its Alpine view — the operator console
  (device selectors, transport/mute, state readouts); no PHP media logic,
  drives the broadcast page over `BroadcastChannel`.

**HTTP surface** — `routes/web.php`:
- `GET /studio/live` → `StudioLiveController@show` — the clean broadcast Blade
  (orb only).
- `POST /studio/live/session` (body `{voice?, episode?, persona?}`) →
  `ResolveStudioEpisode` (→ `422 {error, message}` on any invalid selection,
  NO silent fallback) → `MintStudioSession($voice, $context)`; returns
  `{client_secret, expires_at, model, voice, session_max_seconds, webrtc_url,
  audio_constraints:{echoCancellation,noiseSuppression,autoGainControl}}`; a
  `ProviderException` is `report()`ed → generic `503
  {error:"realtime_unavailable"}` (no key/vendor text). `throttle:12,1`.
  Missing `episode` → `422 episode_required` unless
  `config('ai.realtime.allow_standalone_session')`.
- Both behind `App\Http\Middleware\EnsureStudioOperator` — guest → `/admin/login`
  (or `401` JSON), non-admin → `403`.
- The Filament page **`App\Filament\Pages\StudioControl`** (`/admin/studio-control`)
  is auto-discovered; panel-gated to admins + its own `canAccess()` check.

**Broadcast layer** — `resources/views/studio/live.blade.php`: `<canvas id="orb">`
+ hidden `<audio>` + inline vanilla JS, nothing else visible. Owns the mic +
`RTCPeerConnection` + sink. Listens on `BroadcastChannel('studio-live')` for
`cmd` (connect/hangup/mute/unmute) and `devices` (input/output deviceId);
publishes `state` (connected, muted, remainingSeconds, status, inputActive,
outputSupported, deviceError, deviceLost) every 2s + on change. `connect()`:
`POST /studio/live/session` → `getUserMedia({audio:{ …constraints, deviceId:{exact}
if chosen }})` → peer + `recvonly` transceiver → SDP `POST` with the ephemeral
Bearer → `setRemoteDescription`; `sink.setSinkId(outputId)` when supported.
`getUserMedia` with a chosen device that fails → `deviceError` (NO silent
default fallback). `devicechange` while live and the active input is gone →
`deviceLost`. Countdown = `session_max_seconds` from the response (no literal);
at zero → `hangup()`. One `hangup()` path (time limit AND operator end): close
peer, stop playback, stop + release every mic track. Mute = `track.enabled=false`
on the selected input track only — peer/session untouched. Autoplay unlock:
one click anywhere on the broadcast screen. Orb: `min(70vw,70vh)`, contained by
construction (max glow = `S*0.49`), no CSS drop-shadow.

**Operator layer** — `resources/views/filament/pages/studio-control.blade.php`
(Alpine + Filament `fi-*` components + a page-scoped plain-CSS `<style>` for
grid/tiles/alerts; wide desktop layout `Width::SevenExtraLarge`, 2-column
Ses Yönlendirme | Canlı Oturum cards, 3 status tiles, typed connection badge,
"Yayın Ekranı" banner with an "aç" button). Behaviour: device permission
handling + `enumerateDevices()` for `audioinput` / `audiooutput` with same-name
disambiguation; a **Yayın Hazırlığı** section — **Yayın Bölümü** `<select>`
(Ready episodes only, label "Program — Bölüm N — Başlık") + **Canlı AI
Karakteri** `<select>` (shown only for multi-persona line-ups; single persona
auto-selected, `sort_order` first as default) + a Program / Bölüm / Ana Konu /
AI Karakteri / Yayın Durumu summary; **AI Ses Girişi** / **AI Ses Çıkışı**
selects + **AI Sesi** select (options from `config('ai.realtime.voices')`,
default `cedar` / male, disabled while connected); "Cihazları Yenile" +
`mediaDevices.ondevicechange` auto-refresh; localStorage persistence
(`studio.control.inputDeviceId` / `…outputDeviceId` / `…voiceId` /
`…episodeUuid` / `…personaUuid`) reloaded on open, auto-selected/synced;
the voice + deviceIds + `episodeUuid` + `personaUuid` ride the same
`{type:'devices', …}` BroadcastChannel message and `/studio/live` sends
`{voice, episode, persona}` in the mint `POST` body (all validated
server-side); **Bağlan** (disabled until broadcast alive + input + episode +
persona) / **Görüşmeyi bitir** / **Mikrofonu sessize al** / **Mikrofonu aç**;
readouts for Bağlantı / Mikrofon (AÇIK·SESSİZ) / Kalan süre / Durum; critical
red banner on `deviceLost`, error on `deviceError`, "Bu tarayıcı ses çıkışı
seçimini desteklemiyor" when `outputSupported === false`.

**Config** — `config/ai.php` `ai.realtime`: `driver` (`AI_REALTIME_DRIVER`,
default `fake`), `session_max_seconds` (`STUDIO_LIVE_MAX_SECONDS`, **default
1200 = 20 min**, clamped [30, 3600]), `webrtc_url`, `instructions`, **`audio`**
(new); **`voices`** allow-list (default `cedar`, male). `connections.openai.voice`
default is now **`cedar`** (`OPENAI_REALTIME_VOICE`). `MintStudioSession`
validates a requested voice against `voices` keys and passes it as the
`RealtimeSessionRequest` override (unknown → null → provider default). Config
`audio`
(new): `constraints.{echoCancellation,noiseSuppression,autoGainControl}`
(`STUDIO_LIVE_ECHO_CANCELLATION` / `_NOISE_SUPPRESSION` / `_AUTO_GAIN`, all
default true; passed through to the browser), `noise_reduction`
(`STUDIO_LIVE_NOISE_REDUCTION`, default `far_field`), `turn_detection.{threshold,
prefix_padding_ms, silence_duration_ms}` (`STUDIO_LIVE_VAD_THRESHOLD` 0.6 /
`_PREFIX_MS` 300 / `_SILENCE_MS` 500). `OpenAiRealtimeProvider` folds the
`noise_reduction` + `server_vad` tuning (with `create_response` +
`interrupt_response` always true) into `session.audio.input`; empty when
unconfigured. `drivers` + `connections.openai` unchanged. **`allow_standalone_session`**
(`STUDIO_LIVE_ALLOW_STANDALONE`, **default false**) gates whether a session may
open with no episode; `instructions` is now only the standalone fallback brief.
`.env.example` documents every key. **Physical deviceIds are NEVER server
config** — they live only in the reji browser's localStorage.

**Episode → briefing** — `app/AI/Prompting/AssembleEpisodeBriefing::forEpisode(Episode, AiPersona, ?string $lineupInstructions): string`
is the single provider-independent builder. `AssembleRehearsalPrompt` calls it
+ adds the rehearsal `SEÇİLİ KONU/SORU` emphasis + `GÖREV` directive;
`MintStudioSession` calls it + appends the fixed `REALTIME_DIRECTIVE`
("CANLI YAYIN GÖREVİ:" — no chatbot closings, stay in character, name the
persona not "ChatGPT", the counterpart is "the presenter", don't ask for a
re-brief, follow response-length, allow barge-in, don't invent facts).
`app/AI/Realtime/ResolveStudioEpisode` (+ `StudioEpisodeContext`,
`StudioEpisodeUnavailable`) is the domain validator the controller uses.

### Required production environment variables

`AI_REALTIME_DRIVER=openai`, `OPENAI_API_KEY=<secret>` (already set for text).
Keep `STUDIO_LIVE_ALLOW_STANDALONE` **false** (default) — a normal studio
session must select a Ready episode.
Optional tuning: `OPENAI_REALTIME_MODEL` (`gpt-realtime`), `OPENAI_REALTIME_VOICE`
(`cedar` — **male** default; director can switch per session in Studio Control),
`STUDIO_LIVE_MAX_SECONDS` (1200; 1800–2400 for 30–40 min rehearsals —
**if the environment still has `STUDIO_LIVE_MAX_SECONDS=600` it MUST be changed
to 1200 or removed**), `STUDIO_LIVE_INSTRUCTIONS`, `STUDIO_LIVE_NOISE_REDUCTION`
(`far_field`), `STUDIO_LIVE_VAD_THRESHOLD` (`0.6`), `STUDIO_LIVE_VAD_SILENCE_MS`
(`500`), `STUDIO_LIVE_VAD_PREFIX_MS` (`300`), `STUDIO_LIVE_ECHO_CANCELLATION` /
`_NOISE_SUPPRESSION` / `_AUTO_GAIN` (`true`), `OPENAI_REALTIME_WEBRTC_URL`,
`OPENAI_REALTIME_TIMEOUT` / `_CONNECT_TIMEOUT`. The API key is only ever minted
into a short-lived ephemeral secret server-side, never sent to the browser.

### Tests (no real OpenAI network / no real browser or audio hardware)

- `tests/Unit/AI/RealtimeSessionTokenTest.php` — neutral keys only; rejects empty
  / `sk-…` secret, non-positive expiry, blank model/voice.
- `tests/Feature/AI/OpenAiRealtimeProviderTest.php` — mint → neutral token;
  Bearer = standing key + `session` body; voice override; nested `client_secret`
  shape; missing key fails pre-send; connection failure → `ProviderTimeoutException`;
  non-2xx → `ProviderRequestException` (status/type/code only); no `value` →
  `ProviderException`; standing key echoed back is rejected. **New:** no
  `audio.input` when unconfigured; configured tuning → `session.audio.input`
  with `noise_reduction.type=far_field`, `server_vad` threshold/silence/prefix
  **and `interrupt_response=true`** (barge-in); `noise_reduction:'off'` omitted.
- `tests/Feature/AI/FakeRealtimeVoiceProviderTest.php` — contract; `ek_fake_`
  secret; deterministic; records calls + voice override.
- `tests/Feature/AI/MintStudioSessionTest.php` — default length 1200; honours a
  configured length incl. 2400; clamps to [30, 3600]; unusable value → 1200;
  forwards brief + `webrtc_url`; passes getUserMedia constraints through from
  config (default all-on; `autoGainControl:false` reflected); no requested
  voice → provider default; an allow-listed voice → override; an unknown voice
  → ignored.
- `tests/Feature/AI/AssembleEpisodeBriefingTest.php` — **all** §5 Show/Episode/
  persona/slot fields present with labels; **presenter-only fields (opening/
  key_points/questions_to_push/closing_notes, topic/question presenter_notes)
  never appear**; ALL topics + ALL questions in `sort_order`; blank fields
  skipped; response-length keyword/prose/stray-token handling; real demo
  Episode briefing size reported (**10 650 chars / ~2 170 words**, no
  truncation).
- `tests/Feature/AI/ResolveStudioEpisodeTest.php` — missing / unknown / non-Ready
  episode rejected with the right `reason`; single-persona line-up auto-selects;
  multi-persona needs an explicit persona; chosen persona used; persona outside
  the line-up rejected; context eager-loaded (`show`, `topics`).
- `tests/Feature/Studio/StudioLiveEpisodeSessionTest.php` — no episode → `422
  episode_required`; unknown → `episode_not_found`; non-Ready → `episode_not_ready`;
  persona outside line-up → `persona_not_in_lineup`; multi-persona no persona →
  `persona_required`; **single-persona happy path: the mint instructions contain
  "Gerçeğin Peşinde", "Hikmet", the persona title, the episode title + main
  topic + ai_objective + must_cover + avoid, the line-up instruction, the
  persona system_prompt, EVERY topic title, EVERY question, and "CANLI YAYIN
  GÖREVİ:" — and NO presenter-only markers**; multi-persona uses the chosen
  persona; openai driver forwards the briefing as `session.instructions` with
  the standing key intact; standalone still works when explicitly allowed.
- `tests/Feature/Studio/StudioLivePageTest.php` — guest → `/admin/login`;
  non-admin → 403; admin → **only the orb**: no `<button>`, no `#controls` /
  `#connect` / `#hangup` / `#timer` / `#status`, no "Yayın görünümü"; carries
  `BroadcastChannel('studio-live')`, `startTimer(s.session_max_seconds)` (no
  `|| 600`), `deviceId: { exact: inputDeviceId }`, `setSinkId`; no transcript /
  no key.
- `tests/Feature/Studio/StudioLiveSessionTest.php` — guest → 401; non-admin →
  403; fake driver → usable JSON incl. `audio_constraints` (all true by
  default; `noiseSuppression:false` when configured), key never in body, no
  HTTP; default `session_max_seconds` 1200, configurable 2400/480; openai
  driver (`Http::fake`) → mints via API, Bearer = standing key, brief forwarded,
  **no voice picked → `audio.output.voice` = `cedar`**; `voice:'ash'` →
  `audio.output.voice` = `ash`; an unknown voice → falls back to `cedar`;
  key not in response; upstream 401 → safe `503`; rate-limited (13th → 429).
- `tests/Feature/Filament/StudioControlPageTest.php` — guest → `/admin/login`;
  non-admin → 403; admin → console with all four transport/mute buttons, the
  input/output device selectors + **AI Sesi** picker (Cedar/Marin options),
  the **Yayın Hazırlığı / Yayın Bölümü** episode selector (`episodeUuid` /
  `personaUuid` in the JS), "Cihazları Yenile", Kalan süre / Mikrofon readouts,
  `BroadcastChannel('studio-live')` + `enumerateDevices` + `localStorage`, the
  unsupported-output message; no key / `client_secret` / `sk-`. Separately:
  **only Ready episodes are listed** (Draft/Preparing are not).

### Acceptance checklist

- [x] `/studio/live` broadcast output = orb only (no controls/text/counter/hint)
- [x] Filament "Canlı Yayın Kontrolü" console: Bağlan / bitir / mute / unmute +
      connection + mute state + remaining time
- [x] Mute = `track.enabled=false` on the selected input track; peer/AI session
      not dropped; unmute resumes the same session
- [x] AI Ses Girişi + AI Ses Çıkışı device selectors (`enumerateDevices`,
      `getUserMedia deviceId:{exact}`, `setSinkId`), permission flow, explicit
      error + reselect when a chosen device is gone (no silent default)
- [x] "Cihazları Yenile" + `ondevicechange` auto-refresh; critical
      warning when the live input device disappears
- [x] deviceIds persisted browser-local (`localStorage`), never server config;
      reloaded + auto-selected on open
- [x] getUserMedia `echoCancellation`/`noiseSuppression`/`autoGainControl` +
      config-driven `far_field` noise reduction + mild `server_vad` raise
      (barge-in preserved); no magic numbers in the frontend
- [x] Orb `min(70vw,70vh)`, pulse mathematically contained (max glow `S*0.49`),
      no clipping at any resolution, no container-clipped CSS drop-shadow
- [x] 20-minute auto-end; length from backend config only; up to 60 min
- [x] One clean-shutdown path (peer close + stop playback + release mic) for
      both the time limit and the operator end command
- [x] No backend device enumeration; no server-side audio relay; existing
      WebRTC/ephemeral-key + admin gating unchanged
- [x] No existing feature or test broken (additive only)
- [x] AI voice is **male by default** (`cedar`) and switchable per session from
      Studio Control against a server-validated config allow-list
- [x] A live session binds a **Ready Episode + one line-up persona**; the AI
      starts already knowing the show / persona identity / episode topic /
      brief / all discussion topics + questions (single shared
      `AssembleEpisodeBriefing`, reused by rehearsal + realtime); presenter-only
      fields excluded; validated server-side (`ResolveStudioEpisode`), no silent
      fallback; **no DB / model / migration**
- [x] Pint · `php artisan test` (270) · PHPStan level 6 (0, no baseline) · both
      inline scripts `node --check` clean
- [ ] **Manual (real Chrome + ≥2 audio interfaces + real OpenAI key) — NOT
      performed here.** See the delivery-report acceptance checklist (demo
      Episode: Gerçeğin Peşinde / Hikmet / Cahiliye Döneminden İslam'a).

### Next task (draft, not started)

**TASK-0008** — not started. Do not begin without a written task here.
Likely follow-ups: a persisted transcript / event log for a live session;
per-session spend caps; the human-host model/field (deferred product decision);
a persona `voice_id` → realtime-voice resolver.
