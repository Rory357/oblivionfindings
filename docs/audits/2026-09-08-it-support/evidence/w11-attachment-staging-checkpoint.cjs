const fs = require('node:fs');
const path = require('node:path');
const crypto = require('node:crypto');
const assert = require('node:assert/strict');
const root = path.resolve(__dirname, '../../../..');
const hash = name => crypto.createHash('sha256').update(fs.readFileSync(path.join(root, name))).digest('hex');
const sources = ['app/Domain/It/Services/ItInboundAttachmentStaging.php',
  'app/Domain/It/Exceptions/ItInboundAttachmentUnavailable.php', 'app/Domain/It/Services/ItEmailAttachments.php',
  'app/Models/ItAttachment.php', 'app/Models/ItInboundEmail.php', 'app/Providers/AppServiceProvider.php', 'config/it.php',
  'database/migrations/2026_09_11_000019_add_it_inbound_attachment_staging.php',
  'tests/Concurrency/It/ItInboundAttachmentStagingTest.php', 'tests/Feature/It/ItTicketDraftAttachmentTest.php'];
const baseline = JSON.parse(fs.readFileSync(path.join(__dirname, 'implementation-design-baseline.json'), 'utf8').replace(/^\uFEFF/, ''));
const protectedFiles = baseline.flatMap(entry => (Array.isArray(entry.path) ? entry.path : [entry.path]).map((name, i) => {
  assert.equal(hash(name).toUpperCase(), (Array.isArray(entry.sha256) ? entry.sha256[i] : entry.sha256).toUpperCase());
  return {path: name, unchanged: true};
}));
const runs = [
  ['it_4ee5db76818f43ca', 'w11-attachment-staging-tests.txt'],
  ['it_65b77c8395cc483d', 'w11-attachment-staging-tests-final.txt'],
  ['it_7df9dfca9b2b490b', 'w11-attachment-staging-regression.txt'],
  ['it_0f639d2f476c4f90', 'w11-attachment-staging-regression-retry.txt'],
];
const tests = runs.map(([token, log]) => {
  const rows = fs.readFileSync(path.join(__dirname, token + '.diagnostic.jsonl'), 'utf8').trim().split(/\r?\n/).map(JSON.parse);
  const events = suffix => rows.filter(x => x.event === 'PHPUnit\\Event\\' + suffix);
  const start = events('TestRunner\\ExecutionStarted')[0], end = events('TestRunner\\ExecutionFinished')[0];
  assert(start && end, 'Still running: ' + token);
  assert.equal(events('Test\\Failed').length, token === 'it_7df9dfca9b2b490b' ? 2 : 0);
  assert.equal(events('Test\\Errored').length, 0);
  const output = fs.readFileSync(path.join(__dirname, log), 'utf8');
  const postflightOffset = output.lastIndexOf('"isolation_checks"');
  assert(postflightOffset > output.indexOf('"isolation_checks"'), 'Missing postflight');
  const postflight = output.slice(postflightOffset);
  assert.equal((postflight.match(/: true/g) || []).length, 14);
  assert(postflight.includes('"isolated_schema_does_not_exist": true'));
  assert(postflight.includes('oblivion_it_support_test_' + token));
  return {token, log, passed: events('Test\\Passed').length,
    failed: events('Test\\Failed').length, errors: events('Test\\Errored').length,
    assertions: events('Test\\Finished').reduce((n, x) => n + x.assertions, 0),
    seconds: (Date.parse(end.utc) - Date.parse(start.utc)) / 1000, postflightChecks: 14, isolatedSchemaAbsent: true};
});
const result = {sources: sources.map(name => ({path: name, sha256: hash(name)})), protectedFiles, tests};
fs.writeFileSync(path.join(__dirname, 'w11-attachment-staging-source-hashes.json'), JSON.stringify(result, null, 2) + '\n');
console.log(JSON.stringify({tests, protectedFilesUnchanged: protectedFiles.length, sourceFiles: sources.length}, null, 2));
