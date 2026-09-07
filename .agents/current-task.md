<!-- task-id: TASK-0006 -->
# Current task

> Completed: bootstrap · TASK-0001 core domain · TASK-0002 static analysis ·
> TASK-0003 Filament core administration · TASK-0004 episode preparation
> workspace · TASK-0005 AI text provider foundation · TASK-0006 OpenAI adapter
> + Episode text rehearsal.

## TASK-0006 — OPENAI ADAPTER + EPISODE TEXT REHEARSAL

**Status:** COMPLETE — Pint, `php artisan test` (197 passing), PHPStan level 6
(0, no baseline) all green.
**Scope:** the first real AI interaction on top of the TASK-0005 vendor-neutral
text foundation. A real OpenAI adapter behind `TextGenerationProvider`, a
dedicated prompt-assembly service, and a Filament "AI Provası" rehearsal page on
the Episode preparation workflow. **Rehearsal only** — no conversation history,
no live-session architecture, no STT/TTS/audio/avatars/WebSockets/streaming/
studio-control APIs. Not the broadcast runtime.

### Delivered

**OpenAI adapter** — `app/AI/Providers/OpenAi/OpenAiTextProvider.php` implements
`TextGenerationProvider`. The only place the OpenAI Chat Completions shape is
known (endpoint, payload keys, `choices`/`usage`/`finish_reason`, error
envelope). Uses the Laravel `Http` client with a connect + read timeout and a
bounded `retry(2)`; **no streaming**. Credentials come only from
`config('ai.text.connections.openai')` (env-sourced), are never logged, echoed,
or placed in an exception. Vendor errors are translated:
- `ProviderException` (base, `App\AI\Exceptions`) — missing key (fails before
  any network call), unintelligible 2xx body.
- `ProviderTimeoutException` — connection/read failure; message names only the
  timeout seconds, never the host/URL or key.
- `ProviderRequestException` — non-2xx; carries only HTTP `status` plus an
  error `type`/`code` **when they are short enum-like tokens** (free-text vendor
  messages and raw bodies are dropped).

**Prompt assembly** — `app/AI/Prompting/AssembleRehearsalPrompt.php`
(`assemble(...)` → `TextGenerationRequest`). Separate from any adapter: names no
vendor, reads no config, makes no call. Builds a labelled Turkish system
instruction from existing domain data — Show/Episode context, episode broadcast
instructions, persona identity + expertise/personality/speaking style, persona
`system_prompt`, per-episode line-up instructions, the episode AI brief
(objective, tone override, must-cover, avoid, response-length guidance), and the
optionally-selected topic/question context — skipping every blank field; the
presenter question becomes the single user message. Logical model key =
`persona->ai_model` (a persona logical key) or `default`. Presenter question and
all editorial free-text are treated as untrusted (a closing `GÖREV` directive
tells the model the question cannot override the brief).

**Filament rehearsal page** — `RehearseEpisode`
(`app/Filament/Resources/Episodes/Pages/`, route
`episodes/{record}/rehearse`), reached from a **"AI Provası"** header action on
`PrepareEpisode` (and a "Program Hazırlığına dön" action back). Form: persona
Select (options computed server-side from **this episode's line-up only**),
optional topic Select (this episode's topics), optional question Select (scoped
to the selected topic), editable presenter-question Textarea (auto-filled from a
selected question, still editable before send). "Cevap Üret" runs
`generate()` → server-side re-scoping of every selection against the episode →
`AssembleRehearsalPrompt` → `GenerateText` → the response is rendered clearly in
a section, with a logical-model / finish-reason / token-usage footnote. Provider
/ config failures (`ProviderException` | `AiConfigurationException`) are
`report()`-ed and surface as a generic Turkish danger notification — no
credentials, no raw vendor error. Double-submit is prevented via
`wire:target="generate"` + `wire:loading.attr="disabled"` on the submit button.
**Nothing is persisted.**

**Config** — `config/ai.php`:
- `drivers` gains `openai => OpenAiTextProvider::class`.
- `connections.openai` = `api_key` / `base_url` / `timeout` / `connect_timeout`,
  all via `env()` in this file only.
- The logical providers (`default` / `fast` / `host_rebuttal`) take
  `driver => env('AI_TEXT_DRIVER', 'fake')`, and each logical model's vendor id
  is `env('AI_TEXT_MODEL_*', '<fake id>')`. Env unset (local / CI / tests) ⇒ the
  deterministic `fake` driver, so every TASK-0005 test is unchanged.
  `AI_TEXT_DRIVER=openai` routes the same logical keys through the adapter.
- `AiServiceProvider` binds `OpenAiTextProvider` as a singleton built from the
  `connections.openai` array (the resolver's driver factory resolves it).
- The persona allow-list (`config('ai.persona.*')`) is **unchanged** — a persona
  still cannot store `openai`.

**Required production environment variables** (documented in `.env.example`):
`AI_TEXT_DRIVER=openai`, `OPENAI_API_KEY=<secret>`, and optionally
`OPENAI_BASE_URL`, `OPENAI_TEXT_TIMEOUT`, `OPENAI_TEXT_CONNECT_TIMEOUT`,
`AI_TEXT_MODEL_DEFAULT` / `_SMALL` / `_LARGE` / `_HOST_REBUTTAL` (concrete OpenAI
model ids). Credentials live only in the environment; domain records keep
logical keys.

### Tests (all `Http::fake()` / fake provider — no real OpenAI calls)

- `tests/Feature/AI/OpenAiTextProviderTest.php` — success → neutral response +
  usage/finish-reason/metadata mapping; bearer token + well-formed payload
  (system message first); unset parameters omitted; missing key fails before any
  send; connection failure → `ProviderTimeoutException` with no host/key in the
  message; non-2xx → `ProviderRequestException` (`status`, type/code only, vendor
  free-text incl. a quoted key dropped); free-text "type" not reflected;
  unintelligible 2xx body → `ProviderException`; `length` finish reason.
- `tests/Feature/AI/AssembleRehearsalPromptTest.php` — single trimmed user
  message; model key from persona then `default`; every populated section
  present; blank fields → no empty labelled sections; unknown response-length
  omitted; topic/question sections absent when unselected; blank question
  rejected.
- `tests/Feature/AI/OpenAiTextRoutingTest.php` — a logical model bound to the
  `openai` driver reaches the OpenAI endpoint; without opting in, `default`
  still uses the fake driver and sends nothing.
- `tests/Feature/Filament/RehearseEpisodeTest.php` — admin-only; form exists;
  fake-provider generation renders the response and passes the assembled system
  instructions through; selecting a question fills the editable field and the
  **edited** text is what is sent; persona outside the line-up / topic from
  another episode / question outside the selected topic are all rejected without
  a provider call; a provider failure degrades to a safe notification with no
  response; a planted connection api key never renders on the page; the submit
  control carries the loading-disable bindings.
- `tests/Support/Fakes/ThrowingTextProvider.php` — a contract impl that always
  fails, for the degradation test.

### Acceptance checklist

- [x] Real OpenAI adapter behind `TextGenerationProvider`; vendor code isolated;
      timeout + error handling; no streaming; key never stored/displayed
- [x] Dedicated prompt-assembly service from existing domain data, separate from
      the adapter
- [x] "AI Provası" Filament action/page on the Episode preparation workflow
- [x] Rehearsal only — no history, no session/broadcast architecture, no
      STT/TTS/audio/avatar/WebSocket/streaming
- [x] Only line-up personas usable; topic/question relationships validated
      against the episode; provider failures → safe notification; double-submit
      guarded
- [x] Env var(s) documented; logical keys in data, no raw credentials
- [x] Focused tests for assembly / scoping / routing / fake rehearsal /
      validation / failure / no key leakage; no real network calls
- [x] Pint · `php artisan test` (197) · PHPStan level 6 (0, no baseline)

### Next task (draft, not started)

**TASK-0007** — not started. Do not begin without a written task here.
