# Current task

> Bootstrap (repo + Claude/GPT workflow + review governance) is **done**
> — `APPROVED_WITH_NOTES`, archived under `.agents/reviews/`.

## TASK-0001 — CORE DOMAIN FOUNDATION

**Status:** in review — implementation + tests complete, running GPT review.
**Owner:** CLAUDE (implementation) · GPT (review)
**Scope:** base data model only — models, relations, enums, migrations,
factories, tests. **No** AI/STT/TTS/realtime/UI/avatar/Filament/HTTP.

### Delivered

**Enums** (`app/Enums/`, backed string, `declare(strict_types=1)`)
- `ShowStatus`: Draft, Active, Inactive, Archived
- `AiPersonaStatus`: Draft, Active, Inactive, Archived (separate from
  `ShowStatus` by design — lifecycles expected to diverge)
- `EpisodeStatus`: Draft, Preparing, Ready, Live, Completed, Archived
  (+ `isLive()` / `isConcluded()` helpers)

**Migrations** (`database/migrations/2026_09_06_17000{1..6}_*`)
- `shows`, `ai_personas`, `episodes`, `episode_ai_persona`, `episode_topics`,
  `episode_questions` — with FKs, indexes, and a **unique
  `(episode_id, ai_persona_id)`** on the pivot.
- Deletion policy is deliberate (see `.agents/architecture.md` §2a):
  Show-with-episodes = **RESTRICT**; AiPersona-in-a-line-up = **RESTRICT**;
  Episode children (pivot / topics / questions) = **CASCADE**.
- **No SoftDeletes** — status enums + RESTRICT are the archival strategy.

**Models** (`app/Models/`)
- `Show`, `AiPersona`, `Episode`, `EpisodeTopic`, `EpisodeQuestion`, and the
  `EpisodeAiPersona` pivot model (`sort_order` cast to int).
- `App\Models\Concerns\HasUuid` — auto `uuid` on create + `getRouteKeyName()`
  → `uuid`.
- `#[Fillable([...])]` per column; `casts()` for enums / `array`
  (`screen_settings`) / `datetime` (`broadcast_at`) / `integer`.
- Relations: `Show→episodes`; `Episode→show, aiPersonas, topics`;
  `AiPersona→episodes`; `EpisodeTopic→episode, questions`;
  `EpisodeQuestion→topic`. Pivot exposes `sort_order` + `episode_instructions`
  and orders by `sort_order`.
- `AppServiceProvider`: `Model::preventLazyLoading(! isProduction())`.

**Factories** (`database/factories/`) — one per model, each producing valid
related graphs (`Episode::factory()->forShow($s)`, `->forEpisode()`,
`->forTopic()`, status states).

**Tests** — `tests/Feature/Domain/*` (RefreshDatabase) + `tests/Unit/Enums/*`.
Covers the 10 required behaviours + integrity-failure paths (RESTRICT on
Show/AiPersona delete, duplicate pivot → `QueryException`, bad FK rejected,
cascade on Episode/Topic delete). **34 tests / 75 assertions passing.**

### Acceptance checklist

- [x] Migrations valid (`migrate:fresh` clean)
- [x] Models + relations correct (tinker + tests)
- [x] Enums present
- [x] DB integrity constraints present (unique + FK restrict/cascade)
- [x] Factories present
- [x] Tests passing (34/34)
- [x] Pint passing
- [ ] GPT verdict `APPROVED` / `APPROVED_WITH_NOTES`
- [ ] No unresolved blocking finding

### Next task (draft, not started)

**TASK-0002** — Install & configure PHPStan/Larastan (level 6) + enforce it in
`review.sh`; then `app/AI/Contracts` + `FakeTextProvider` + `config/ai.php`.
