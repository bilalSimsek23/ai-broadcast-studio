// Verdict normalization for the GPT review harness.
//
// Pure and dependency-free so it can be unit tested.
//
// THREE valid verdicts (CLAUDE.md section 7 - Review governance):
//   APPROVED             task requirements met, no unresolved blocking finding,
//                        and nothing further to note.
//   APPROVED_WITH_NOTES  task requirements met, no unresolved blocking finding,
//                        but non-blocking notes / suggestions remain. This
//                        STILL COMPLETES THE TASK - it must not trigger another
//                        review round on its own.
//   CHANGES_REQUIRED     at least one concrete unresolved BLOCKING finding AND
//                        a non-empty required_fixes list.
//
// An explicit CHANGES_REQUIRED is NEVER promoted to a passing verdict - that
// would be a fake approval. If it arrives without a blocker or a required fix
// it is kept as CHANGES_REQUIRED and made actionable (a synthetic finding +
// "re-run the review" fix).
//
// Severity is NOT the same as blocking. A finding blocks only when it is
// critical/high, OR it carries `blocking: true`, OR it appears in
// `required_fixes`. A lone `medium`/`low` note with `blocking` unset/false and
// no `required_fixes` does NOT flip a passing verdict - that is exactly what
// stops an endless self-review loop over non-blocking (often
// review-infrastructure) findings.

const VALID = new Set(['APPROVED', 'APPROVED_WITH_NOTES', 'CHANGES_REQUIRED']);
const HARD_BLOCKING = new Set(['critical', 'high']);

/** A passing verdict completes the task. */
export function isPassing(verdict) {
  return verdict === 'APPROVED' || verdict === 'APPROVED_WITH_NOTES';
}

/** True if this finding must hold the task in CHANGES_REQUIRED. */
export function isBlockingFinding(f) {
  if (!f || typeof f !== 'object') return false;
  if (f.blocking === true) return true;
  if (f.blocking === false) return false;
  return HARD_BLOCKING.has(f.severity);
}

/**
 * @param {any} review parsed reviewer JSON
 * @returns {{verdict:'APPROVED'|'APPROVED_WITH_NOTES'|'CHANGES_REQUIRED', summary:string, findings:any[], required_fixes:string[], suggested_tests:string[], [k:string]:any}}
 */
export function normalizeReview(review) {
  const r = { ...(review && typeof review === 'object' ? review : {}) };

  // A malformed/garbage verdict is a broken response: fail safe to
  // CHANGES_REQUIRED and do NOT then re-normalize it away to a pass.
  const wasMalformed = !VALID.has(r.verdict);
  if (wasMalformed) {
    r.summary = `Invalid verdict ${JSON.stringify(r.verdict)} coerced to CHANGES_REQUIRED. ` + (r.summary || '');
    r.verdict = 'CHANGES_REQUIRED';
  }
  if (!Array.isArray(r.findings)) r.findings = [];
  if (!Array.isArray(r.required_fixes)) r.required_fixes = [];
  if (!Array.isArray(r.suggested_tests)) r.suggested_tests = [];
  if (typeof r.summary !== 'string') r.summary = String(r.summary ?? '');

  const blockers = r.findings.filter(isBlockingFinding);

  if (isPassing(r.verdict)) {
    if (blockers.length || r.required_fixes.length) {
      // Self-contradictory: passing verdict but real blockers present.
      r.verdict = 'CHANGES_REQUIRED';
      r.summary =
        `Verdict coerced to CHANGES_REQUIRED: the response was ${JSON.stringify(review && review.verdict)} ` +
        `but listed ${blockers.length} blocking finding(s) and ${r.required_fixes.length} required fix(es). ` + r.summary;
    } else {
      // Keep the label honest: notes present => APPROVED_WITH_NOTES; else APPROVED.
      r.verdict = r.findings.length > 0 ? 'APPROVED_WITH_NOTES' : 'APPROVED';
    }
  }

  if (r.verdict === 'CHANGES_REQUIRED' && r.required_fixes.length === 0) {
    if (blockers.length > 0) {
      // The reviewer forgot required_fixes - synthesize from the blockers.
      r.required_fixes = blockers.map((f) => (f && (f.fix || f.problem)) || 'Address the blocking finding.');
      r.summary = `Normalized: CHANGES_REQUIRED had an empty required_fixes list; ` +
        `synthesized ${r.required_fixes.length} from blocking finding(s). ` + r.summary;
    } else {
      // CHANGES_REQUIRED with no blocker and no fix is an INCONSISTENT response.
      // Never promote it to a pass (that would be a fake approval). Keep it
      // failing and make it actionable: re-run the review.
      r.findings.unshift({
        severity: 'medium',
        blocking: true,
        category: 'review-infrastructure',
        fingerprint: 'reviewer-response::review-infrastructure::changes-required-without-blocker-or-fix',
        file: '(reviewer response)',
        line_or_area: 'verdict / findings / required_fixes',
        problem: 'Reviewer returned CHANGES_REQUIRED with no blocking finding and an empty required_fixes list - an inconsistent response.',
        why_it_matters: 'The change is not approved, but the response gives nothing to act on.',
        fix: 'Re-run the GPT review. If it recurs, inspect the review input for truncation or formatting problems.',
      });
      r.required_fixes = ['Re-run the GPT review: the response was CHANGES_REQUIRED but listed no blocking finding and no required fix.'];
      r.summary = `Normalized: inconsistent CHANGES_REQUIRED (no blocker, no fix) kept as CHANGES_REQUIRED and made actionable. ` + r.summary;
    }
  }

  return r;
}
