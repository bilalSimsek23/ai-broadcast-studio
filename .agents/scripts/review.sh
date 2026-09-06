#!/usr/bin/env bash
# AI Broadcast Studio - run the quality gates, capture their output, then run
# the independent GPT review with that output as context.
#
# Usage:  .agents/scripts/review.sh
#
# - Runs the REQUIRED quality gates in order and captures their output to
#   $GPT_REVIEW_TEST_RESULTS (default .agents/last-test-run.txt):
#     1. review harness self-tests (node --test)
#     2. Laravel Pint (style)
#     3. PHPUnit (php artisan test)
#     4. PHPStan / Larastan (static analysis, level 6)
# - The destination is validated (dedicated .agents/<name>.txt, not a symlink,
#   not tracked, gitignored) and written via a fresh temp file + atomic rename,
#   so a planted symlink/hard link at that path can never be followed or
#   truncated.
# - FAIL-CLOSED: if ANY gate fails or is missing, the GPT review is NOT run and
#   the script exits 1. A broken build is never sent as if all gates passed.
# - Otherwise runs .agents/scripts/gpt-review.mjs (which reads the same capture
#   file plus project context + git diff) and then review-loop-status.mjs.
# - Exit code: 0 = APPROVED or APPROVED_WITH_NOTES (task complete),
#   10 = CHANGES_REQUIRED, 11 = nothing to review, 1 = a quality gate failed,
#   anything else = harness error.

set -uo pipefail

repo_root="$(git rev-parse --show-toplevel 2>/dev/null || pwd)"
cd "$repo_root" || exit 1

out="${GPT_REVIEW_TEST_RESULTS:-.agents/last-test-run.txt}"

# --- validate the capture destination (mirrors gpt-review.mjs) --------------
case "$out" in
  *..*|.agents/*/*) echo "review.sh: GPT_REVIEW_TEST_RESULTS must be a flat .agents/<name>.txt path (no sub-dirs, no '..')" >&2; exit 2 ;;
esac
if [[ ! "$out" =~ ^\.agents/[A-Za-z0-9][A-Za-z0-9._-]*\.txt$ ]]; then
  echo "review.sh: GPT_REVIEW_TEST_RESULTS must match .agents/<name>.txt (got: $out)" >&2
  exit 2
fi
if [ -L .agents ] || [ ! -d .agents ]; then
  echo "review.sh: .agents is not a real directory" >&2; exit 2
fi
if [ -e "$out" ] && [ ! -f "$out" ]; then
  echo "review.sh: $out exists and is not a regular file (symlink/dir?) - refusing" >&2; exit 2
fi
if [ -L "$out" ]; then
  echo "review.sh: $out is a symlink - refusing" >&2; exit 2
fi
if git ls-files --error-unmatch -- "$out" >/dev/null 2>&1; then
  echo "review.sh: $out is tracked by git - it must be a transient, gitignored file" >&2; exit 2
fi
if ! git check-ignore -q -- "$out"; then
  echo "review.sh: $out is not gitignored - add it to .gitignore" >&2; exit 2
fi

tmp="$(mktemp ".agents/.review-capture.XXXXXX")" || { echo "review.sh: mktemp failed" >&2; exit 2; }
chmod 600 "$tmp"
trap 'rm -f "$tmp"' EXIT

gates_failed=0
failed_list=""

# Every gate here is REQUIRED. A failed (or missing) gate is fail-closed: the
# GPT review is NOT run, so a broken build can never be sent as if all quality
# gates had passed.
run_gate() {
  local label="$1"; shift
  {
    echo "===================================================================="
    echo "## ${label}"
    echo "\$ $*"
    echo "--------------------------------------------------------------------"
  } >> "$tmp"
  if [ ! -x "$1" ] && ! command -v "$1" >/dev/null 2>&1; then
    echo "(MISSING - $1 not available)" >> "$tmp"
    echo ">> ${label}: FAIL (command not found: $1)"
    gates_failed=$((gates_failed + 1))
    failed_list="${failed_list}  - ${label} (command not found)\n"
    return 0
  fi
  # Quality gates run repository-controlled code (tests, hooks, plugins). Strip
  # the paid reviewer credential from their environment - only the final
  # `node .agents/scripts/gpt-review.mjs` call below keeps it.
  local status=0
  env -u OPENAI_API_KEY -u OPENAI_REVIEW_MODEL -u OPENAI_REVIEW_EFFORT -u OPENAI_REVIEW_TIMEOUT_MS "$@" >> "$tmp" 2>&1 || status=$?
  echo "-> exit ${status}" >> "$tmp"
  if [ "$status" -eq 0 ]; then
    echo ">> ${label}: PASS"
  else
    echo ">> ${label}: FAIL (exit ${status})"
    gates_failed=$((gates_failed + 1))
    failed_list="${failed_list}  - ${label} (exit ${status})\n"
  fi
  return 0
}

# Ideal order: harness self-tests -> style -> app tests -> static analysis -> GPT.
echo "Capturing quality gates to ${out} ..."
run_gate "Review harness self-tests (node --test)"  node --test .agents/scripts/*.test.mjs
run_gate "Laravel Pint (style)"                      vendor/bin/pint --test
run_gate "PHPUnit (php artisan test)"                php artisan test
run_gate "PHPStan / Larastan (static, level 6)"      vendor/bin/phpstan analyse --no-progress --memory-limit=512M

{
  echo "===================================================================="
  echo "## captured $(date -u +%Y-%m-%dT%H:%M:%SZ)"
  echo "## gates failed: ${gates_failed}"
} >> "$tmp"

# atomic replace of the (validated, non-symlink) destination
mv -f "$tmp" "$out"
trap - EXIT

if [ "$gates_failed" -gt 0 ]; then
  echo
  echo "FAIL-CLOSED: ${gates_failed} quality gate(s) failed - NOT sending to GPT review:"
  printf '%b' "$failed_list"
  echo "Full output: ${out}. Fix the gate(s) and re-run."
  exit 1
fi

echo
echo "Running GPT review ..."
node .agents/scripts/gpt-review.mjs
rc=$?

echo
echo "--- review loop status ---"
node .agents/scripts/review-loop-status.mjs || true

# 0 = APPROVED or APPROVED_WITH_NOTES (task complete); 10 = CHANGES_REQUIRED;
# 11 = nothing to review; anything else = harness error.
exit $rc
