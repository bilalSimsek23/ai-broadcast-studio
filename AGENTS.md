# AGENTS.md

This repository uses a two-agent development loop.

- **Implementation agent (CLAUDE):** read [`CLAUDE.md`](CLAUDE.md) in full before
  any work. It is the coding contract (architecture, Laravel rules, testing,
  AI provider abstraction, security, review workflow).
- **Review agent (GPT):** an independent reviewer invoked via
  `node .agents/scripts/gpt-review.mjs`. It returns a machine-readable verdict:
  `APPROVED`, `APPROVED_WITH_NOTES`, or `CHANGES_REQUIRED`.

Task-specific context lives in [`.agents/`](.agents/):

- `.agents/project-context.md` — what the product is and current constraints.
- `.agents/architecture.md` — target structure and technical decisions.
- `.agents/current-task.md` — the single active task.
- `.agents/reviews/` — archived review results.

A task is complete when the latest GPT review verdict is `APPROVED` **or**
`APPROVED_WITH_NOTES` (the latter just means non-blocking notes remain — it
still completes the task; see `CLAUDE.md` §7 "Review governance").
