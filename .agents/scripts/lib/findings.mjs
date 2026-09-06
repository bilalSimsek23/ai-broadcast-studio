// Finding identity + round-to-round comparison for the review loop.
//
// Pure and dependency-free. The goal (CLAUDE.md section 7) is that the SAME
// underlying concern is recognised across review rounds so it cannot be
// re-raised as a "new" blocker just by rewording it.

/**
 * Stable-ish fingerprint for a finding. Prefers the reviewer-supplied
 * `fingerprint`; otherwise derives one from file + category + a normalized
 * slice of the root cause.
 * @param {any} f
 * @returns {string}
 */
export function fingerprint(f) {
  if (f && typeof f.fingerprint === 'string' && f.fingerprint.trim()) {
    return f.fingerprint.trim().toLowerCase();
  }
  const norm = (s) => String(s ?? '').toLowerCase().replace(/[\s`'"]+/g, ' ').trim();
  const file = norm(f && f.file) || '(unspecified)';
  const cat = norm(f && f.category) || 'uncategorized';
  const rootCause = norm(f && (f.problem || f.line_or_area)).slice(0, 100);
  return `${file}::${cat}::${rootCause}`;
}

/**
 * Compare the previous round's findings with the current round's.
 * @param {any[]} prevFindings
 * @param {any[]} currFindings
 * @param {(f:any)=>boolean} isBlocking predicate (from lib/verdict.mjs)
 * @returns {{NEW:any[], STILL_OPEN:any[], RESOLVED:any[], NON_BLOCKING:any[]}}
 *   Each entry: { fingerprint, severity, category, file, blocking }.
 */
export function classifyFindings(prevFindings, currFindings, isBlocking = () => false) {
  const prev = new Map((prevFindings || []).map((f) => [fingerprint(f), f]));
  const curr = new Map((currFindings || []).map((f) => [fingerprint(f), f]));
  const out = { NEW: [], STILL_OPEN: [], RESOLVED: [], NON_BLOCKING: [] };

  for (const [fp, f] of curr) {
    const rec = {
      fingerprint: fp,
      severity: f.severity ?? 'unknown',
      category: f.category ?? 'uncategorized',
      file: f.file ?? '(unspecified)',
      blocking: !!isBlocking(f),
    };
    if (!rec.blocking) out.NON_BLOCKING.push(rec);
    else if (prev.has(fp)) out.STILL_OPEN.push(rec);
    else out.NEW.push(rec);
  }
  for (const [fp, f] of prev) {
    if (!curr.has(fp)) out.RESOLVED.push({ fingerprint: fp, severity: f.severity ?? 'unknown', file: f.file ?? '(unspecified)' });
  }
  return out;
}
