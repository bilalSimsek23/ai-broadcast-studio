// Tests for the review harness's safety libraries.
//
//   node --test .agents/scripts/*.test.mjs
//
// No dependencies - built-in node:test runner. These protect the harness's
// central guarantees: a credential in an otherwise-allowed file (.php / .md /
// .json / log), quoted or unquoted, must be caught before anything is sent to
// the reviewer API; sensitive/binary paths and non-text bodies must be refused.
//
// The fake-credential fixtures are assembled from split string literals on
// purpose (broken exactly where each detector's pattern anchors), so this test
// file itself contains no contiguous secret-shaped substring and is safe to
// include in a review.

import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { execFileSync } from 'node:child_process';
import { findSecrets, hasSecret } from './lib/secret-scan.mjs';
import { isSensitivePath, binaryPathsFromNumstat, looksBinary, diffReportsBinary } from './lib/path-policy.mjs';
import { normalizeReview, isBlockingFinding } from './lib/verdict.mjs';
import { fingerprint, classifyFindings } from './lib/findings.mjs';

const FAKE = {
  openaiKey: 'sk-' + 'proj-Ab12Cd34Ef56Gh78Ij90Kl12Mn34Op56',
  awsId: 'AKIA' + 'J3Q6ZK7NLP2WD4RS', // AKIA + exactly 16
  pem: '-----BEGIN RSA PRIV' + 'ATE KEY-----\nMIIEpAIBAAKCAQEA\n-----END RSA PRIV' + 'ATE KEY-----',
  jwt: 'eyJ' + 'hbGciOiJIUzI1NiJ9.eyJzdWIiOiIxMjM0NTY3ODkwIn0.dozjgNryP4J3jVmNHl0w5N_XgL0n3I9PlF',
  quotedPw: 'DB_PASS' + 'WORD="s3cr3tP4ssw0rd-9xQ7z"',
  unquotedPw: 'DB_PASS' + 'WORD=Correct-Horse-Battery-Staple-99',
  realWithPlaceholderPrefix: 'client_sec' + 'ret=changeme-Ax9f8Kq2LmZp3RtVnQw7Hs',
  jsonCred: '{"client_sec' + 'ret":"' + 'Zx98Yw76Vu54Ts32Rq10PoNm"}',       // JSON quoted key
  phpCred: "'" + 'pass' + "word' => '" + "Mn34Bv56Cx78Zq90La12Ke" + "'",      // PHP => assignment
  jsonPlaceholderCred: '{"api_' + 'key":"YOUR_KEY_HERE"}',
};

test('flags an OpenAI-style secret key in prose', () => {
  const text = `const key = "${FAKE.openaiKey}";`;
  assert.equal(hasSecret(text), true);
  const hits = findSecrets(text);
  assert.ok(hits.some((h) => /OpenAI/.test(h.label)));
  assert.ok(hits.every((h) => !h.sample.includes('Cd34Ef56'))); // never echoes the secret body
});

test('flags an AWS access key id', () => {
  assert.equal(hasSecret(`aws_access_key_id = ${FAKE.awsId}`), true);
});

test('flags a private key block regardless of surrounding text', () => {
  assert.equal(hasSecret(`junk before\n${FAKE.pem}\njunk after`), true);
});

test('flags a JWT', () => {
  assert.equal(hasSecret(`Authorization: ${FAKE.jwt}`), true);
});

test('flags a QUOTED inline credential assignment', () => {
  assert.equal(hasSecret(FAKE.quotedPw), true);
});

test('flags an UNQUOTED inline credential assignment', () => {
  assert.equal(hasSecret(FAKE.unquotedPw), true);
  assert.equal(hasSecret('export API_' + 'KEY=abcdefghij0123456789KLMNOP'), true);
});

test('flags a real value that merely BEGINS with a placeholder word', () => {
  assert.equal(hasSecret(FAKE.realWithPlaceholderPrefix), true);
});

test('flags credentials in JSON ("key":"val") and PHP (\'key\' => \'val\') forms', () => {
  assert.equal(hasSecret(FAKE.jsonCred), true);
  assert.equal(hasSecret(FAKE.phpCred), true);
});

test('a secret in the HEAD of a long buffer is still detected (pre-truncation scan invariant)', () => {
  // gpt-review.mjs scans the full decoded buffer BEFORE slicing a head/tail for
  // the prompt, so a credential whose prefix would be cut off still fails closed.
  const head = `Authorization: Bearer ${'a1B2c3D4e5F6g7H8i9J0k1L2m3N4o5P6'}\n`;
  const buf = head + 'x'.repeat(200_000); // tail-only transmission would drop `head`
  assert.equal(hasSecret(buf), true);
  assert.equal(hasSecret(buf.slice(-60_000)), false); // proves the head must be scanned
});

test('does NOT flag a placeholder value in JSON/PHP key forms', () => {
  assert.deepEqual(findSecrets(FAKE.jsonPlaceholderCred), []);
  assert.deepEqual(findSecrets("'password' => env('DB_PASSWORD')"), []);
  assert.deepEqual(findSecrets('"client_secret": ""'), []);
});

test('does NOT flag the "sk-..." documentation placeholder', () => {
  const docs = [
    'export OPENAI_API_KEY="sk-..."',
    'set OPENAI_API_KEY to your key (looks like sk-...)',
    'OPENAI_API_KEY="YOUR_KEY_HERE"',
    'the key `sk-...` goes here',
  ].join('\n');
  assert.deepEqual(findSecrets(docs), []);
});

test('does NOT flag whole-value dummy/example/template values', () => {
  const docs = [
    'password: changeme',
    'client_secret="example-value-placeholder"',
    'api_key = "xxxxxxxxxxxxxxxx"',
    'token=your_token_here',
    'APP_KEY=${APP_KEY}',
    'SECRET=<your-secret-here>',
    'AUTH_TOKEN=',
  ].join('\n');
  assert.deepEqual(findSecrets(docs), []);
});

test('does NOT flag ordinary code / prose / config references', () => {
  const clean = `
    class EpisodeController extends Controller {
      public function store(StoreEpisodeRequest $request): RedirectResponse {
        $episode = Episode::create($request->validated());
        return to_route('episodes.show', $episode);
      }
    }
    // config('services.openai.key') resolves the provider key at runtime.
    'timeout' => env('AI_REQUEST_TIMEOUT', 30),
  `;
  assert.deepEqual(findSecrets(clean), []);
});

test('review.sh strips OPENAI_API_KEY from quality-gate subprocesses', () => {
  const here = new URL('.', import.meta.url).pathname;
  const sh = readFileSync(here + 'review.sh', 'utf8');
  // the gate runner must scrub the paid credential
  assert.match(sh, /env -u OPENAI_API_KEY[^\n]*"\$@"/);
  // and the `env -u` pattern actually removes it
  const out = execFileSync('env', ['-u', 'OPENAI_API_KEY', 'node', '-e', 'process.stdout.write(process.env.OPENAI_API_KEY ? "PRESENT" : "ABSENT")'],
    { env: { ...process.env, OPENAI_API_KEY: 'DUMMY' }, encoding: 'utf8' });
  assert.equal(out, 'ABSENT');
});

test('the harness source files contain no contiguous secret', () => {
  const here = new URL('.', import.meta.url).pathname;
  for (const f of ['gpt-review.test.mjs', 'lib/secret-scan.mjs', 'lib/path-policy.mjs', 'lib/verdict.mjs',
                   'lib/findings.mjs', 'gpt-review.mjs', 'review-loop-status.mjs', 'review.sh']) {
    assert.deepEqual(findSecrets(readFileSync(here + f, 'utf8')), [], `${f} should not trip the scanner`);
  }
});

// --- path policy ---------------------------------------------------------

test('denies real .env and secret/key files', () => {
  for (const p of ['.env', '.env.local', '.env.production', 'config/id_rsa', 'storage/app/backup.sql',
                   'app/cert.pem', 'database/database.sqlite', '.aws/credentials', 'auth.json']) {
    assert.equal(isSensitivePath(p), true, `${p} should be sensitive`);
  }
});

test('denies credential-ish data filenames (word + data extension)', () => {
  for (const p of ['token.txt', 'tokens.json', 'access-token.txt', 'api-key.txt', 'api_key.json',
                   'apikey.txt', 'private-key.json', 'passwords.csv', 'password.txt', 'secret.yaml',
                   'keys.json', 'storage/exports/access_tokens.csv', 'refresh-token.b64']) {
    assert.equal(isSensitivePath(p), true, `${p} should be sensitive`);
  }
});

test('still allows credential-shaped names that are CODE, not data', () => {
  for (const p of ['app/Models/Token.php', 'app/Models/PersonalAccessToken.php',
                   'resources/js/tokenizer.js', 'app/Http/Controllers/PasswordController.php',
                   'app/Support/api-key-helper.php', 'tests/Feature/Auth/PasswordResetTest.php',
                   'config/secrets_map.php']) {
    assert.equal(isSensitivePath(p), false, `${p} should NOT be sensitive`);
  }
});

test('allows the .env.example placeholder template only at the repo root', () => {
  assert.equal(isSensitivePath('.env.example'), false);
  assert.equal(isSensitivePath('deploy/.env.example'), true);
});

test('denies dotenv files whatever the prefix or path', () => {
  for (const p of ['.env', '.env.local', '.env.production', 'production.env', 'local.env',
                   'service.env.local', 'config/local.env', 'deploy/app.env.production']) {
    assert.equal(isSensitivePath(p), true, `${p} should be sensitive`);
  }
});

test('allows ordinary source paths', () => {
  for (const p of ['app/AI/Contracts/TextGenerationProvider.php', 'config/ai.php',
                   'routes/web.php', '.agents/architecture.md', 'tests/Feature/EpisodeTest.php']) {
    assert.equal(isSensitivePath(p), false, `${p} should not be sensitive`);
  }
});

test('binaryPathsFromNumstat parses NUL-delimited numstat, exact paths only', () => {
  // `git diff --numstat -z --no-renames` shape: "<add>\t<del>\t<path>" per NUL field.
  const z = [
    '12\t3\tapp/Foo.php',
    '-\t-\tpublic/logo',                 // extensionless binary
    '0\t0\troutes/web.php',
    '-\t-\tstorage/blob.dat',
    '-\t-\tbïn ary with spaces',         // spaces + non-ASCII, NOT C-quoted under -z
  ].join('\0') + '\0';
  assert.deepEqual(binaryPathsFromNumstat(z), ['public/logo', 'storage/blob.dat', 'bïn ary with spaces']);
  assert.deepEqual(binaryPathsFromNumstat(''), []);
  // a text file that merely contains a literal "-\t-" in its diff stats is not misread
  assert.deepEqual(binaryPathsFromNumstat('5\t0\tnotes-\t-todo.md\0'), []);
});

test('looksBinary: NUL byte, invalid UTF-8, and control-heavy buffers are binary', () => {
  assert.equal(looksBinary(Buffer.from([0x41, 0x00, 0x42])), true);        // NUL
  assert.equal(looksBinary(Buffer.from([0xc3, 0x28])), true);              // invalid UTF-8
  assert.equal(looksBinary(Buffer.from(Array(200).fill(0x07))), true);     // BEL-heavy
});

test('looksBinary: plain UTF-8 text and empty buffers are not binary', () => {
  assert.equal(looksBinary(Buffer.from('#!/usr/bin/env node\nconst x = 1;\n', 'utf8')), false);
  assert.equal(looksBinary(Buffer.from('Ünïcödé prose — with em dash and tabs\t\tok\n', 'utf8')), false);
  assert.equal(looksBinary(Buffer.alloc(0)), false);
});

test('looksBinary {full}: a long text prefix followed by binary bytes is caught', () => {
  const prefix = Buffer.from('x'.repeat(9000) + '\n', 'utf8'); // > 8 KiB of clean text
  const poisoned = Buffer.concat([prefix, Buffer.from([0x00, 0xff, 0xfe])]);
  assert.equal(looksBinary(poisoned), false, 'default 8 KiB sample misses the tail');
  assert.equal(looksBinary(poisoned, { full: true }), true, 'full scan catches it');
});

test('diffReportsBinary detects binary markers in a unified diff', () => {
  assert.equal(diffReportsBinary('diff --git a/logo b/logo\nBinary files a/logo and b/logo differ\n'), true);
  assert.equal(diffReportsBinary('diff --git a/x b/x\nGIT binary patch\n...'), true);
  assert.equal(diffReportsBinary('diff --git a/a.php b/a.php\n@@ -1 +1 @@\n-a\n+b\n'), false);
});

// --- verdict normalization (3-verdict model) --------------------------

test('normalizeReview: clean APPROVED with no findings stays APPROVED', () => {
  const r = normalizeReview({ verdict: 'APPROVED', summary: 'ok', findings: [], required_fixes: [], suggested_tests: [] });
  assert.equal(r.verdict, 'APPROVED');
  assert.equal(r.summary, 'ok');
});

test('normalizeReview: a pass with only non-blocking notes becomes APPROVED_WITH_NOTES', () => {
  for (const start of ['APPROVED', 'APPROVED_WITH_NOTES']) {
    const r = normalizeReview({
      verdict: start, summary: 's',
      findings: [{ severity: 'low', blocking: false, file: 'a', problem: 'nit' }],
      required_fixes: [], suggested_tests: [],
    });
    assert.equal(r.verdict, 'APPROVED_WITH_NOTES');
  }
});

test('normalizeReview: APPROVED_WITH_NOTES with no findings downgrades to APPROVED', () => {
  const r = normalizeReview({ verdict: 'APPROVED_WITH_NOTES', summary: 's', findings: [], required_fixes: [], suggested_tests: [] });
  assert.equal(r.verdict, 'APPROVED');
});

test('normalizeReview: invalid / missing verdict -> CHANGES_REQUIRED', () => {
  for (const v of ['approve', 'LGTM', undefined, null, '', 'BLOCK']) {
    const r = normalizeReview({ verdict: v, summary: 's', findings: [], required_fixes: [], suggested_tests: [] });
    assert.equal(r.verdict, 'CHANGES_REQUIRED');
    assert.match(r.summary, /coerced to CHANGES_REQUIRED/);
  }
});

test('normalizeReview: a pass carrying a critical/high finding -> CHANGES_REQUIRED', () => {
  for (const sev of ['critical', 'high']) {
    const r = normalizeReview({
      verdict: 'APPROVED_WITH_NOTES', summary: 's',
      findings: [{ severity: sev, blocking: true, file: 'x', problem: 'p' }],
      required_fixes: [], suggested_tests: [],
    });
    assert.equal(r.verdict, 'CHANGES_REQUIRED', `severity ${sev} must block`);
  }
});

test('normalizeReview: a pass carrying blocking:true (any severity) -> CHANGES_REQUIRED', () => {
  const r = normalizeReview({
    verdict: 'APPROVED', summary: 's',
    findings: [{ severity: 'medium', blocking: true, file: 'x', problem: 'real bug w/ failure path' }],
    required_fixes: [], suggested_tests: [],
  });
  assert.equal(r.verdict, 'CHANGES_REQUIRED');
});

test('normalizeReview: a lone medium note with blocking:false does NOT block', () => {
  const r = normalizeReview({
    verdict: 'APPROVED', summary: 's',
    findings: [{ severity: 'medium', blocking: false, category: 'review-infrastructure', file: 'x', problem: 'NON-BLOCKING REVIEW INFRASTRUCTURE NOTE: ...' }],
    required_fixes: [], suggested_tests: [],
  });
  assert.equal(r.verdict, 'APPROVED_WITH_NOTES');
});

test('normalizeReview: non-empty required_fixes always -> CHANGES_REQUIRED', () => {
  const r = normalizeReview({
    verdict: 'APPROVED', summary: 's',
    findings: [{ severity: 'medium', blocking: true, file: 'x', problem: 'blocker' }],
    required_fixes: ['do the thing'], suggested_tests: [],
  });
  assert.equal(r.verdict, 'CHANGES_REQUIRED');
});

test('normalizeReview: explicit CHANGES_REQUIRED is NEVER promoted to a pass', () => {
  // inconsistent (no blocker, no fix) -> stays failing, made actionable
  const noNotes = normalizeReview({ verdict: 'CHANGES_REQUIRED', summary: 's', findings: [], required_fixes: [], suggested_tests: [] });
  assert.equal(noNotes.verdict, 'CHANGES_REQUIRED');
  assert.ok(noNotes.required_fixes.length >= 1);
  assert.ok(noNotes.findings.some((f) => f.blocking === true));

  const onlyNotes = normalizeReview({
    verdict: 'CHANGES_REQUIRED', summary: 's',
    findings: [{ severity: 'low', blocking: false, file: 'x', problem: 'note' }],
    required_fixes: [], suggested_tests: [],
  });
  assert.equal(onlyNotes.verdict, 'CHANGES_REQUIRED');
  assert.ok(onlyNotes.required_fixes.length >= 1);
});

test('normalizeReview: CHANGES_REQUIRED with a blocker but empty required_fixes synthesizes one', () => {
  const r = normalizeReview({
    verdict: 'CHANGES_REQUIRED', summary: 's',
    findings: [{ severity: 'high', blocking: true, file: 'x', problem: 'SQLi', fix: 'parameterize the query' }],
    required_fixes: [], suggested_tests: [],
  });
  assert.equal(r.verdict, 'CHANGES_REQUIRED');
  assert.deepEqual(r.required_fixes, ['parameterize the query']);
});

test('normalizeReview repairs missing / non-array list fields', () => {
  const r = normalizeReview({ verdict: 'CHANGES_REQUIRED', summary: 's' });
  assert.ok(Array.isArray(r.findings));
  assert.ok(Array.isArray(r.required_fixes));
  assert.ok(Array.isArray(r.suggested_tests));
  const r2 = normalizeReview({ verdict: 'APPROVED', summary: 's', findings: 'nope', required_fixes: null, suggested_tests: 7 });
  assert.deepEqual(r2.findings, []);
  assert.deepEqual(r2.required_fixes, []);
  assert.deepEqual(r2.suggested_tests, []);
  assert.equal(r2.verdict, 'APPROVED');
});

test('isBlockingFinding: severity vs explicit blocking flag', () => {
  assert.equal(isBlockingFinding({ severity: 'high' }), true);
  assert.equal(isBlockingFinding({ severity: 'critical' }), true);
  assert.equal(isBlockingFinding({ severity: 'medium' }), false);          // medium not auto-blocking
  assert.equal(isBlockingFinding({ severity: 'medium', blocking: true }), true);
  assert.equal(isBlockingFinding({ severity: 'high', blocking: false }), false); // explicit override wins
  assert.equal(isBlockingFinding({ severity: 'low' }), false);
  assert.equal(isBlockingFinding(null), false);
});

// --- finding identity / round comparison -----------------------------

test('fingerprint prefers the reviewer id, else derives file::category::rootcause', () => {
  assert.equal(fingerprint({ fingerprint: 'A::B::C' }), 'a::b::c');
  const fp = fingerprint({ file: 'app/Foo.php', category: 'Correctness', problem: 'Off-by-one in the loop bound' });
  assert.equal(fp, 'app/foo.php::correctness::off-by-one in the loop bound');
  // reworded same root cause with an explicit shared fingerprint => same id
  assert.equal(
    fingerprint({ fingerprint: 'app/foo.php::correctness::off-by-one', problem: 'totally different words' }),
    'app/foo.php::correctness::off-by-one',
  );
});

test('classifyFindings buckets NEW / STILL_OPEN / RESOLVED / NON_BLOCKING', () => {
  const prev = [
    { fingerprint: 'f1', severity: 'high', file: 'a', blocking: true },
    { fingerprint: 'f2', severity: 'medium', file: 'b', blocking: true },
  ];
  const curr = [
    { fingerprint: 'f2', severity: 'medium', file: 'b', blocking: true },          // carried over
    { fingerprint: 'f3', severity: 'high', file: 'c', blocking: true },            // new blocker
    { fingerprint: 'f4', severity: 'low', file: 'd', blocking: false, category: 'review-infrastructure' }, // note
  ];
  const c = classifyFindings(prev, curr, isBlockingFinding);
  assert.deepEqual(c.NEW.map((x) => x.fingerprint), ['f3']);
  assert.deepEqual(c.STILL_OPEN.map((x) => x.fingerprint), ['f2']);
  assert.deepEqual(c.RESOLVED.map((x) => x.fingerprint), ['f1']);
  assert.deepEqual(c.NON_BLOCKING.map((x) => x.fingerprint), ['f4']);
});
