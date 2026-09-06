// Path-based allow/deny policy for the GPT review harness.
//
// Pure, dependency-free, and side-effect-free so it can be unit tested without
// importing gpt-review.mjs (which runs a review on import).

// Binary, generated, archived, or secret-bearing files must never be
// transmitted to the external API - they can leak local data and blow the
// prompt budget.
export const DENY_EXT = /\.(zip|tar|gz|tgz|bz2|xz|7z|rar|jar|war|png|jpe?g|gif|bmp|ico|webp|svgz|pdf|mp4|mov|avi|mkv|mp3|wav|woff2?|ttf|eot|otf|class|o|a|so|dylib|dll|exe|bin|wasm|sqlite|sqlite3|db|db-shm|db-wal|dump|sql|pem|key|crt|cer|p12|pfx|keystore|jks|asc|gpg|kdbx)$/i;
export const DENY_NAME = /(^|\/)([^/]*\.env(\.[^/]+)?|\.envrc|.*\.ya?ml\.enc|id_rsa|id_ed25519|id_dsa|.*\.sqlite(-shm|-wal)?|secrets?(\.[^/]+)?|credentials?(\.[^/]+)?|\.npmrc|\.netrc|\.pgpass|\.htpasswd|auth\.json|service-account.*\.json)$/i;

// Credential-ish data files: the WHOLE basename (minus a data/text extension)
// IS a credential word - so `token.txt`, `api-key.json`, `passwords.csv` are
// refused, while code like `Token.php` or `tokenizer.js` is not.
const CRED_WORD = '(?:access[-_]?|auth[-_]?|api[-_]?|client[-_]?|app[-_]?|refresh[-_]?|bearer[-_]?|private[-_]?|secret[-_]?)?(?:tokens?|passwords?|passwd|secrets?|keys?|api[-_]?keys?|apikeys?|credentials?)';
const CRED_EXT = '(?:\\.(?:txt|text|json|jsonc|csv|tsv|log|ya?ml|ini|conf|cfg|properties|env|key|pem|b64|base64|dat|out|bak|secret))?';
export const DENY_CRED_FILE = new RegExp('(^|/)' + CRED_WORD + CRED_EXT + '$', 'i');
// Whole directories whose contents are always secrets, regardless of filename.
export const DENY_DIR = /(^|\/)\.(aws|ssh|gnupg|azure|kube)(\/|$)/i;

// Exact tracked paths that look sensitive by pattern but are, by contract,
// placeholder-only templates that the reviewer must be able to inspect. Their
// contents are still run through the content secret scanner.
export const ALLOW_PATHS = new Set(['.env.example']);

/**
 * @param {string} p repo-relative path (forward slashes)
 * @returns {boolean} true if the path must not be sent to the reviewer as content
 */
export function isSensitivePath(p) {
  if (ALLOW_PATHS.has(p)) return false;
  return DENY_EXT.test(p) || DENY_NAME.test(p) || DENY_DIR.test(p) || DENY_CRED_FILE.test(p);
}

/**
 * Paths that `git diff --numstat -z` reports as binary (added/deleted columns
 * are both "-"). Filename-independent: catches extensionless and unknown-type
 * binaries that DENY_EXT would miss. Expects NUL-delimited output so pathnames
 * are exact (no C-quoting), including names with spaces, tabs, or Unicode.
 *
 * `-z` record shape (no renames): `<added>\t<deleted>\t<path>` per NUL field.
 * With renames enabled a record can end `...\t\t` followed by two extra NUL
 * fields (old, new path); those are skipped defensively.
 *
 * @param {string} numstatZ raw `git diff --numstat -z` output
 * @returns {string[]}
 */
export function binaryPathsFromNumstat(numstatZ) {
  if (!numstatZ) return [];
  const fields = numstatZ.split('\0');
  const out = [];
  for (let i = 0; i < fields.length; i++) {
    const rec = fields[i];
    if (!rec) continue;
    const tab1 = rec.indexOf('\t');
    if (tab1 < 0) continue;
    const tab2 = rec.indexOf('\t', tab1 + 1);
    if (tab2 < 0) continue;
    const added = rec.slice(0, tab1);
    const deleted = rec.slice(tab1 + 1, tab2);
    let path = rec.slice(tab2 + 1);
    if (path === '') {
      // rename record: next two NUL fields are old/new path
      i += 2;
      path = fields[i] || '';
    }
    if (added === '-' && deleted === '-' && path) out.push(path);
  }
  return out;
}

/**
 * Filename-independent binary sniff for a file body / diff, independent of git
 * attributes and textconv. Conservative: a NUL byte, invalid UTF-8, or a high
 * ratio of non-text control bytes means "binary".
 *
 * Pass `{ full: true }` to inspect the ENTIRE buffer (used for our size-capped
 * inputs) rather than only the first 8 KiB - a text prefix followed by binary
 * data must not slip through.
 *
 * @param {Buffer|Uint8Array} buf
 * @param {{ full?: boolean }} [opts]
 * @returns {boolean}
 */
export function looksBinary(buf, { full = false } = {}) {
  if (!buf || buf.length === 0) return false;
  const sample = full ? buf : buf.subarray(0, 8192);
  if (sample.includes(0)) return true;
  try {
    new TextDecoder('utf-8', { fatal: true }).decode(sample);
  } catch {
    return true; // not valid UTF-8
  }
  let ctrl = 0;
  for (const b of sample) {
    // allow tab(0x09) LF(0x0a) VT(0x0b) FF(0x0c) CR(0x0d) and ESC(0x1b)
    if ((b < 0x09 || (b > 0x0d && b < 0x20)) && b !== 0x1b) ctrl++;
  }
  return ctrl / sample.length > 0.3;
}

/** True if a unified-diff text reports any binary file change. */
export function diffReportsBinary(diffText) {
  if (!diffText) return false;
  return /^Binary files .* differ$/m.test(diffText) || /^GIT binary patch$/m.test(diffText);
}
