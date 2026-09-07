# AGENTS.md

Claude is the implementation agent. Read [`CLAUDE.md`](CLAUDE.md) in full before
any work — it is the coding contract (architecture, Laravel rules, testing,
AI provider abstraction, security, workflow).

Before a task is considered done, all local quality gates must pass. Run them
with:

```sh
.agents/scripts/review.sh    # vendor/bin/pint --test → php artisan test → composer stan
```

(or each individually: `vendor/bin/pint --test`, `php artisan test`,
`composer stan`).

Task-specific context lives in [`.agents/`](.agents/):

- `.agents/project-context.md` — what the product is and current constraints.
- `.agents/architecture.md` — target structure and technical decisions.
- `.agents/current-task.md` — the single active task (`<!-- task-id: TASK-xxxx -->`).
