const fs = require('node:fs');
const path = require('node:path');
const crypto = require('node:crypto');
const assert = require('node:assert/strict');
const root = path.resolve(__dirname, '../../../..');
const hash = name => crypto.createHash('sha256').update(fs.readFileSync(path.join(root, name))).digest('hex');
const sources = [
  'app/Domain/It/Services/ItAttachmentStorageIntentService.php',
  'app/Domain/It/Services/ItAttachmentStorageService.php',
  'app/Domain/It/Services/ItInboundAttachmentStaging.php',
  'app/Domain/It/Services/ItAttachmentCleanupReadService.php',
  'app/Console/Commands/RetryItAttachmentCleanup.php',
  'tests/Unit/It/RetryItAttachmentCleanupTest.php',
  'tests/Feature/It/ItAttachmentCleanupOperationsTest.php',
  'tests/Concurrency/It/ItAttachmentCleanupCommandTest.php',
  'resources/js/components/it/it-service-operations.tsx',
  'resources/js/components/it/__tests__/it-service-operations.test.tsx',
  'docs/runbooks/it-attachment-storage-recovery.md',
];
const baseline = JSON.parse(fs.readFileSync(path.join(__dirname, 'implementation-design-baseline.json'), 'utf8').replace(/^\uFEFF/, ''));
const protectedFiles = baseline.flatMap(entry => (Array.isArray(entry.path) ? entry.path : [entry.path]).map((name, i) => {
  assert.equal(hash(name).toUpperCase(), (Array.isArray(entry.sha256) ? entry.sha256[i] : entry.sha256).toUpperCase());
  return {path: name, unchanged: true};
}));
const tests = [
  ['it_6df6172b13b54fbd', 'w11-inbound-cleanup-command-tests.txt'],
  ['it_74c89cfc00db45b8', 'w11-inbound-cleanup-regression-tests.txt'],
].map(([token, log]) => {
  const rows = fs.readFileSync(path.join(__dirname, token + '.diagnostic.jsonl'), 'utf8').trim().split(/\r?\n/).map(JSON.parse);
  const events = suffix => rows.filter(x => x.event === 'PHPUnit\\Event\\' + suffix);
  const start = events('TestRunner\\ExecutionStarted')[0], end = events('TestRunner\\ExecutionFinished')[0];
  assert(start && end);
  assert.equal(events('Test\\Failed').length + events('Test\\Errored').length, 0);
  const output = fs.readFileSync(path.join(__dirname, log), 'utf8');
  const offset = output.lastIndexOf('"isolation_checks"');
  assert(offset > output.indexOf('"isolation_checks"'));
  const postflight = output.slice(offset);
  assert.equal((postflight.match(/: true/g) || []).length, 14);
  assert(postflight.includes('"isolated_schema_does_not_exist": true'));
  assert(postflight.includes('oblivion_it_support_test_' + token));
  return {token, log, passed: events('Test\\Passed').length, failed: 0, errors: 0,
    assertions: events('Test\\Finished').reduce((n, x) => n + x.assertions, 0),
    seconds: (Date.parse(end.utc) - Date.parse(start.utc)) / 1000,
    postflightChecks: 14, isolatedSchemaAbsent: true};
});
const result = {sources: sources.map(name => ({path: name, sha256: hash(name)})), protectedFiles, tests,
  assetManifestSha256: hash('public/build/manifest.json')};
fs.writeFileSync(path.join(__dirname, 'w11-inbound-cleanup-source-hashes.json'), JSON.stringify(result, null, 2) + '\n');
console.log(JSON.stringify({tests, protectedFilesUnchanged: protectedFiles.length, sourceFiles: sources.length, assetManifestSha256: result.assetManifestSha256}, null, 2));
