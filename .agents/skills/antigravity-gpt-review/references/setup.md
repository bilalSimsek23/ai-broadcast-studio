# Setup

This skill is already wired into **AI Broadcast Studio**. The reviewer script
lives at `.agents/scripts/gpt-review.mjs` (adapted from the portable skill) and
the wrapper at `.agents/scripts/review.sh`.

## 1. Provide the API key

In the terminal session that runs the loop:

```bash
export OPENAI_API_KEY="YOUR_KEY_HERE"
```

Optional overrides (defaults shown):

```bash
export OPENAI_REVIEW_MODEL="gpt-5.6-sol"
export OPENAI_REVIEW_EFFORT="high"
export GPT_REVIEW_TEST_RESULTS=".agents/last-test-run.txt"
```

For persistence with zsh, add the exports to `~/.zshrc` and open a new
terminal. Prefer a keychain / secret manager over shell history for the key.
Never put the key in a file inside this repository.

## 2. Run a review

From the repository root:

```bash
# reviewer only (uses whatever is already in .agents/last-test-run.txt, if any)
node .agents/scripts/gpt-review.mjs

# full: capture Pint + PHPStan + php artisan test, then review
.agents/scripts/review.sh
```

Exit code `0` = `APPROVED` or `APPROVED_WITH_NOTES` (both complete the task);
`10` = `CHANGES_REQUIRED`; `11` = nothing to review (no verdict written); any
other code is a harness error (missing key, API failure, malformed response, or
changed files that could not be shown to the reviewer).

## 3. Outputs

- `.agents/gpt-review.json` — latest verdict (transient, gitignored).
- `.agents/reviews/<timestamp>-<verdict>.json` — archived per round
  (gitignored `*.json`; the directory and its README are tracked).

## Porting this skill to another repository

1. Copy `.agents/skills/antigravity-gpt-review/` and
   `.agents/scripts/gpt-review.mjs` into the target repo.
2. Add `.agents/project-context.md`, `.agents/architecture.md`, and
   `.agents/current-task.md` (the reviewer reads these for context).
3. Gitignore `.agents/gpt-review.json`, `.agents/last-test-run.txt`, and
   `.agents/reviews/*.json` (see `assets/.gitignore-snippet`).
4. Adjust `review.sh` to the target project's quality-gate commands.
