# Review history

Each GPT review run writes a timestamped copy of its verdict here:

```
<ISO-8601-timestamp>-<VERDICT>.json
e.g. 2026-09-06T12-30-05-000Z-CHANGES_REQUIRED.json
```

The files are the same JSON as `.agents/gpt-review.json` (the transient
"latest" pointer), preserved per round so the CLAUDE ↔ GPT iteration history is
auditable.

The `*.json` files are **gitignored** — this is a local, on-disk history. Only
this README is tracked so the directory exists on a fresh clone. If you want a
particular verdict kept in version control, add it explicitly with
`git add -f .agents/reviews/<file>.json`.
