# `.agents/` — task context for the implementation agent

Claude is the sole implementation agent. This directory holds the context it
reads at the start of every task and the local quality-gate runner. The full
coding contract is in [`../CLAUDE.md`](../CLAUDE.md).

## Layout

| Path | Purpose | Tracked? |
|------|---------|----------|
| `project-context.md` | What the product is, actors, constraints, current phase. | yes |
| `architecture.md` | Target module layout and technical decisions; §§2a–2d record what is actually built. | yes |
| `current-task.md` | The single active task. Updated as work progresses. | yes |
| `scripts/review.sh` | Run the local quality gates in order. | yes |
| `../phpstan.neon` (repo root) | PHPStan/Larastan config, level 6. | yes |

## Quality gates

```sh
.agents/scripts/review.sh
```

runs, in order, stopping at the first failure:

1. `vendor/bin/pint --test` — style
2. `php artisan test` — application tests
3. `composer stan` — PHPStan/Larastan level 6 (`phpstan analyse --memory-limit=512M`)

A task is not done until all three pass. There is no automated external review
step.
