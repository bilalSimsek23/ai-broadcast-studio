// Content-based secret detection for the GPT review harness.
//
// Path/extension denial (path-policy.mjs) stops whole files like `.env` or
// `id_rsa`. This module is the second layer: it scans the *text* that would be
// sent to the reviewer (diff hunks, new-file bodies, context docs, captured
// test output, and the final assembled request) for high-confidence credential
// shapes, so a key accidentally pasted into a .php / .md / .json / log file is
// caught before transmission.
//
// Design:
//   - High precision. A false positive blocks a review; we would rather miss an
//     exotic format than cry wolf on every UUID.
//   - Never echo the secret. Callers get a label + a redacted fingerprint only.
//   - Placeholder exemption is WHOLE-VALUE: a real credential that merely
//     *begins* with "changeme" or "example-" is still reported.
//   - No dependencies.

/**
 * @typedef {{ label: string, sample: string }} SecretHit
 * `sample` is a redacted fingerprint (first 3 chars + length), safe to log.
 */

// Assignment keywords for the generic "<key> = <value>" rule.
const ASSIGN_KEY = '(?:password|passwd|pwd|secret|api[_-]?key|access[_-]?key|access[_-]?token|auth[_-]?token|client[_-]?secret|private[_-]?key|encryption[_-]?key|app[_-]?key)';
// Value forms: a quoted string of >=12 non-quote chars, or an unquoted token of
// >=20 chars with no whitespace/quote/comment punctuation (unquoted needs to be
// longer to stay precise).
const ASSIGN_VAL = '(?:"[^"\\n]{12,}"|\'[^\'\\n]{12,}\'|`[^`\\n]{12,}`|[^\\s"\'`#;,()]{20,})';

const RULES = [
  // OpenAI / Anthropic style keys. Require >= 20 trailing key chars so the
  // literal placeholder "sk-..." and "sk-REDACTED" do not match.
  { label: 'OpenAI-style API key', re: /\bsk-(?:proj-|ant-|svcacct-)?[A-Za-z0-9_-]{20,}\b/g },
  { label: 'Anthropic API key', re: /\bsk-ant-[A-Za-z0-9_-]{20,}\b/g },
  // AWS
  { label: 'AWS access key id', re: /\b(?:AKIA|ASIA|AGPA|AIDA|AROA|ANPA|ANVA)[A-Z0-9]{16}\b/g },
  { label: 'AWS secret access key assignment', re: /aws_secret_access_key\s*[=:]\s*["']?[A-Za-z0-9/+]{40}["']?/gi },
  // Google
  { label: 'Google API key', re: /\bAIza[A-Za-z0-9_-]{35}\b/g },
  { label: 'Google OAuth client secret', re: /\bGOCSPX-[A-Za-z0-9_-]{20,}\b/g },
  // GitHub
  { label: 'GitHub token', re: /\b(?:ghp|gho|ghu|ghs|ghr|github_pat)_[A-Za-z0-9_]{20,}\b/g },
  // Slack
  { label: 'Slack token', re: /\bxox[baprs]-[A-Za-z0-9-]{10,}\b/g },
  // Stripe
  { label: 'Stripe secret key', re: /\b(?:sk|rk)_(?:live|test)_[A-Za-z0-9]{20,}\b/g },
  // Generic bearer / JWT
  { label: 'Bearer token literal', re: /\bBearer\s+[A-Za-z0-9._~+/-]{24,}=*/g },
  { label: 'JSON Web Token', re: /\beyJ[A-Za-z0-9_-]{8,}\.[A-Za-z0-9_-]{8,}\.[A-Za-z0-9_-]{8,}\b/g },
  // Private keys (PEM/OpenSSH/PGP). Split the marker so this source file does
  // not itself trip the scanner when it is part of an untracked-file body.
  { label: 'private key block', re: new RegExp('-----BEGIN [A-Z0-9 ]{0,40}' + 'PRIVATE KEY-----', 'g') },
  { label: 'PGP private key block', re: new RegExp('-----BEGIN PGP ' + 'PRIVATE KEY BLOCK-----', 'g') },
  // Passwords / secrets assigned to a value in config-ish text: dotenv
  // (KEY=val / KEY: val), JSON / YAML ("key": "val"), and PHP (['key' => 'val']).
  // Quoted OR bare value; the key may carry a trailing quote before the operator.
  { label: 'inline credential assignment', re: new RegExp(ASSIGN_KEY + '\\b["\'`]?\\s*(?:=>|::|[:=])\\s*' + ASSIGN_VAL, 'gi'), valueGroup: true },
];

// Exact tokens that, as the WHOLE normalized value, mean "not a real secret".
const PLACEHOLDER_TOKENS = new Set([
  'changeme', 'change_me', 'change-me', 'redacted', 'placeholder', 'dummy',
  'example', 'sample', 'test', 'testing', 'fake', 'none', 'null', 'nil',
  'true', 'false', 'todo', 'tbd', 'unset', 'undefined', 'na', 'n/a',
  'your_key_here', 'your-key-here', 'yourkeyhere', 'your_token_here',
  'your_api_key_here', 'your_secret_here', 'my_secret', 'secret', 'password',
  'api_key', 'apikey', 'token', 'value', 'string', 'base64:',
]);

/** Whole normalized value is filler / a template marker. */
function isPlaceholderValue(v) {
  const s = String(v).trim().replace(/^["'`]|["'`]$/g, '').toLowerCase();
  if (!s) return true;
  if (PLACEHOLDER_TOKENS.has(s)) return true;
  if (/^(.)\1{2,}$/.test(s)) return true;                       // aaaa / 0000 / ....
  if (/^[x0._-]{3,}$/.test(s)) return true;                     // xxxx, x0x0, ---- , ....
  if (/^<[^>]+>$/.test(s)) return true;                         // <your-token>
  if (/^\$\{[^}]+\}$/.test(s) || /^\{\{.*\}\}$/.test(s)) return true; // ${VAR}, {{ x }}
  if (/^%[a-z0-9_]+%$/i.test(s)) return true;                   // %VAR%
  // whole value is placeholder-word(s) joined by _ or -
  if (/^(your|my|the|a)?[_-]?(example|sample|test|fake|dummy|placeholder|changeme|change|real|some|new|old)([_-](value|placeholder|key|secret|token|password|here|goes|to|be|replaced|example))*$/.test(s)) return true;
  return false;
}

function redactedFingerprint(match) {
  return `${match.slice(0, 3)}…(${match.length} chars)`;
}

/**
 * Scan `text` for credential shapes.
 * @param {string} text
 * @returns {SecretHit[]} de-duplicated hits (empty if clean)
 */
export function findSecrets(text) {
  if (!text) return [];
  /** @type {Map<string, SecretHit>} */
  const hits = new Map();
  for (const rule of RULES) {
    const { label, re } = rule;
    re.lastIndex = 0;
    let m;
    while ((m = re.exec(text)) !== null) {
      const raw = m[0];
      if (raw.length === 0) { re.lastIndex++; continue; }

      // Determine the value portion for placeholder testing.
      let value;
      if (rule.valueGroup) {
        // strip "<key><quote?><op>" where op is => :: : or =
        value = raw.replace(/^.*?["'`]?\s*(?:=>|::|[:=])\s*/s, '');
      } else {
        value = raw
          .replace(/^(?:sk-(?:proj-|ant-|svcacct-)?|AIza|GOCSPX-|xox[baprs]-|(?:ghp|gho|ghu|ghs|ghr|github_pat)_|(?:sk|rk)_(?:live|test)_|Bearer\s+|AKIA|ASIA|AGPA|AIDA|AROA|ANPA|ANVA)/i, '');
      }
      if (isPlaceholderValue(value)) {
        if (re.lastIndex === m.index) re.lastIndex++;
        continue;
      }

      const key = `${label}:${redactedFingerprint(raw)}`;
      if (!hits.has(key)) hits.set(key, { label, sample: redactedFingerprint(raw) });
      if (re.lastIndex === m.index) re.lastIndex++; // guard against zero-width
    }
  }
  return [...hits.values()];
}

/**
 * @param {string} text
 * @returns {boolean}
 */
export function hasSecret(text) {
  return findSecrets(text).length > 0;
}
