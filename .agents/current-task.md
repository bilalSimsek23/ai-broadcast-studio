<!-- task-id: TASK-0005 -->
# Current task

> Completed: bootstrap · TASK-0001 core domain · TASK-0002 static analysis ·
> TASK-0003 Filament core administration · TASK-0004 episode preparation
> workspace · TASK-0005 AI text provider foundation.

## TASK-0005 — AI TEXT PROVIDER FOUNDATION

**Status:** COMPLETE — all quality gates pass; no open issues.
**Scope:** the vendor-neutral text-generation foundation — contracts, neutral
DTOs, a logical provider/model resolver, a deterministic fake provider, and one
thin application service. **No** real OpenAI/Anthropic calls, STT/TTS,
streaming, conversation history, rehearsal UI, studio runtime, or prompt
assembly from Episode/AiPersona data.

### Delivered (`app/AI/**`, framework-agnostic except the service provider)

**Contract** — `Contracts/TextGenerationProvider`:
`generate(ResolvedTextGenerationRequest): TextGenerationResponse`. Vendor SDK
classes / vendor request-response shapes never cross this boundary.

**Neutral DTOs** (`Dtos/**`, all `final readonly`) + enums (`Enums/**`):
- `Enums/Role` (`system` · `user` · `assistant`), `Enums/FinishReason`
  (`stop` · `length` · `content_filter` · `other`).
- `Message` (role + non-empty content), `MessageList` (ordered, non-empty).
- `GenerationParameters` — the small surface: `temperature` (0.0–2.0),
  `maxOutputTokens` (1–100000). Type/range validated centrally in the ctor and
  in `fromArray()` (unknown keys rejected; non-finite `temperature` rejected).
  `mergedWith()` defines precedence: a non-null caller override wins
  field-by-field over the logical-model default.
- `TextGenerationRequest` — application-facing: **logical** model key +
  `MessageList` + optional system instructions + optional parameter overrides.
- `ResolvedTextGenerationRequest` — provider-facing: resolved **vendor** model
  id + logical keys (telemetry only) + messages + merged parameters + system.
- `TokenUsage` (`inputTokens?` / `outputTokens?` / `totalTokens()`), neutral
  array keys `input_tokens` / `output_tokens` / `total_tokens`; no billing.
- `ResponseMetadata` (`logicalProvider`, `logicalModel`, `providerModelId?`
  [diagnostic only], `finishReason?`), `TextGenerationResponse` (text +
  metadata + usage).

**Resolver / registry** — `Resolution/LogicalModelResolver` (container
singleton, **framework-agnostic** — `AiServiceProvider` hands it a plain
`ai.text` array + a driver-factory closure): `provider(key)` →
`TextGenerationProvider`; `model(key)` → `ResolvedTextModel` (provider +
logical keys + vendor model id + default params). Never falls back silently —
`UnknownProviderKey` / `UnknownModelKey` for an unregistered key,
`InvalidTextConfiguration` for a structurally broken / blank binding (blank =
`trim() === ''`).

**Fake provider** — `Providers/Fake/FakeTextProvider` (application namespace,
container singleton): implements the contract, no network, deterministic
(default = fixed echo derived only from the request; or scripted via
`respondWith()` / `queueResponse()` / `reportUsage()`), records every call
(`calls()` / `lastCall()` / `callCount()`).

**Application service** — `GenerateText` (thin): logical model key → resolve →
merge overrides over defaults → call provider → return its neutral response
unchanged. No Filament; no Episode / AiPersona / readiness / prompt assembly.

**Exceptions** — `Exceptions/**`: `AiConfigurationException` (base),
`UnknownProviderKey`, `UnknownModelKey`, `InvalidTextConfiguration`,
`InvalidGenerationParameters`, plus a `SafeIdentifier` formatter. Messages
**never echo a caller-supplied key** (it could be a pasted credential) nor a
raw config value; `InvalidTextConfiguration` names only the logical key whose
binding is broken (already proven to exist in config) via `SafeIdentifier`.

**Config** — `config/ai.php` gains a `text` section: `providers` (logical
provider key → driver; superset of `ai.persona.ai_provider`), `models` (logical
model key → provider + vendor model id + default params; keys match
`ai.persona.ai_model` plus `host_rebuttal`), `drivers` (driver → class; only
`fake` today), `connections` (per-driver, empty; credentials via `env()` in
this file only, later). `bootstrap/providers.php` registers `AiServiceProvider`.

**Boundary (documented in architecture §2d):**
`Episode editorial data → [future] prompt assembly → GenerateText → LogicalModelResolver → TextGenerationProvider → [future] vendor adapter`.
TASK-0005 does **not** assemble Episode/AiPersona prompts and makes no vendor call.

### Tests — `tests/Unit/AI/**` (pure) + `tests/Feature/AI/**` (container/config)

`GenerationParameters` (ranges, non-finite, `fromArray`, merge precedence) ·
`Message` / `MessageList` (non-empty, order) · `TokenUsage` (neutral keys,
total) · `SafeIdentifier` (redaction) · `LogicalModelResolver` (provider +
model resolution, unknown provider/model, malformed / blank config,
driver-not-a-provider, persona keys all resolve, no key echoed) ·
`FakeTextProvider` (implements contract, deterministic, records, scriptable) ·
`GenerateText` (routes to the bound provider via a second recording provider,
system+messages pass through unchanged, defaults applied, override precedence,
invalid params rejected pre-call, neutral response returned, neutral usage,
fake path needs no credentials) · AiPersona logical-key invariant intact after
the config expansion. Whole suite: **164 tests / 535 assertions passing.**

### Acceptance checklist

- [x] Vendor-neutral contract + neutral request/response DTOs
- [x] Logical provider/model resolver; unknown/malformed keys fail explicitly
- [x] Deterministic fake provider (no network, records input, configurable)
- [x] One thin `GenerateText` service; no Filament / Episode / AiPersona / prompt assembly
- [x] Small validated parameter surface; documented override-vs-default precedence
- [x] Safe exception hierarchy; no secrets in messages
- [x] `env()` only in `config/ai.php`; no secrets in DB
- [x] AiPersona logical-key invariant still valid; existing stored keys resolve
- [x] Pint · Laravel tests (164 / 535 assertions) · PHPStan level 6 (0, no baseline)

### Next task (draft, not started)

**TASK-0006** — first real vendor adapter behind `TextGenerationProvider`
(`app/AI/Providers/<Vendor>/`, `Http` client with timeout/retry, vendor→neutral
error translation) wired via a new `config('ai.text.drivers')` entry.
