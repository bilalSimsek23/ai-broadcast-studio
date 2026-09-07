#!/usr/bin/env bash
# AI Broadcast Studio - run the local quality gates in order.
#
# Usage:  .agents/scripts/review.sh
#
# Runs, in order, and stops at the first failure:
#   1. Laravel Pint (style)          vendor/bin/pint --test
#   2. PHPUnit (php artisan test)     application tests
#   3. PHPStan / Larastan (level 6)   composer stan
#
# Exit code: 0 = all gates passed, 1 = a gate failed.
#
# (There is no automated external/GPT review step - Claude is the sole
#  implementation agent and these local gates are the quality bar.)

set -uo pipefail

repo_root="$(git rev-parse --show-toplevel 2>/dev/null || pwd)"
cd "$repo_root" || exit 1

fail=0

run_gate() {
  local label="$1"; shift
  echo "===================================================================="
  echo ">> ${label}"
  echo "\$ $*"
  echo "--------------------------------------------------------------------"
  if "$@"; then
    echo ">> ${label}: PASS"
  else
    local status=$?
    echo ">> ${label}: FAIL (exit ${status})"
    fail=1
  fi
  echo
}

run_gate "Laravel Pint (style)"              vendor/bin/pint --test
run_gate "PHPUnit (php artisan test)"        php artisan test
run_gate "PHPStan / Larastan (level 6)"      composer stan

if [ "$fail" -ne 0 ]; then
  echo "One or more quality gates failed. Fix them and re-run."
  exit 1
fi

echo "All quality gates passed."
exit 0
