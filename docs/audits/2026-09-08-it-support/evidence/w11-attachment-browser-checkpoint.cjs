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
  'tests/Concurrency/It/ItInboundAttachmentStagingTest.php',
  'docs/audits/2026-09-08-it-support/evidence/w11-mailbox-browser-fixture.php',
  'docs/audits/2026-09-08-it-support/evidence/w11-browser-mailbox-reconcile.php',
  'resources/js/components/it/it-service-operations.tsx',
  'resources/js/components/it/__tests__/it-service-operations.test.tsx',
  'docs/runbooks/it-attachment-storage-recovery.md',
  'app/Domain/It/Exceptions/ItInboundAttachmentUnavailable.php',
  'app/Domain/It/Services/ItMailboxConnectionPresenter.php',
  'resources/js/components/settings/it-mailbox-provider.tsx',
  'tests/Feature/It/ItMailboxSettingsTest.php',
  'docs/audits/2026-09-08-it-support/evidence/w10-owned-browser-refresh-assets.ps1',
];
const baseline = JSON.parse(fs.readFileSync(path.join(__dirname, 'implementation-design-baseline.json'), 'utf8').replace(/^\uFEFF/, ''));
const protectedFiles = baseline.flatMap(entry => (Array.isArray(entry.path) ? entry.path : [entry.path]).map((name, i) => {
  assert.equal(hash(name).toUpperCase(), (Array.isArray(entry.sha256) ? entry.sha256[i] : entry.sha256).toUpperCase());
  return {path: name, unchanged: true};
}));
const tests = [
  ['it_b853404eee5740df', 'w11-cleanup-audit-failure-command-tests.txt'],
  ['it_106dc9d5e53d4710', 'w11-cleanup-audit-failure-staging-tests.txt'],
  ['it_4aa64d1ccb874a0b', 'w11-attachment-guidance-tests.txt'],
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
const read = name => JSON.parse(fs.readFileSync(path.join(__dirname, name), 'utf8').replace(/^\uFEFF/, ''));
const before = read('w11-attachment-browser-before-retry.json');
const after = read('w11-attachment-browser-final.json');
assert.equal(after.token, '724533b40323406b');
assert.equal(before.token, after.token);
assert.equal(after.synthetic_provider_only, true);
for (const [provider, connection] of [['microsoft', 1], ['google', 2]]) {
  assert.equal(before.inbound.find(x => x.it_mailbox_connection_id === connection && x.status === 'pending').records, 1);
  const rows = after.inbound.filter(x => x.it_mailbox_connection_id === connection);
  assert.equal(rows.length, 3);
  for (const [status, count] of [['processed', 31], ['duplicate', 2], ['quarantined', 10]]) {
    const row = rows.find(x => x.status === status);
    assert.equal(row.records, count);
    assert.equal(Number(row.acknowledged), count);
  }
  assert.deepEqual(after.provider_state.unread[provider], []);
  assert.equal(Object.keys(after.provider_state.reads[provider]).length, 43);
  for (const [id, count] of Object.entries(after.provider_state.reads[provider])) assert.equal(count, id === '39' ? 2 : 1);
  for (const [id, count] of Object.entries(after.provider_state.ack_attempts[provider])) assert.equal(count, provider === 'microsoft' && id === '1' ? 2 : 1);
}
assert.equal(after.synthetic_ticket_count, 58);
for (const [operation, count] of [['ticket.create', 58], ['ticket.comment', 4]]) {
  const row = after.canonical_commands.find(x => x.operation === operation);
  assert.equal(row.records, count);
  assert.equal(Number(row.committed), count);
}
const files = after.attachment_records;
assert.equal(files.length, 14);
assert.equal(files.filter(x => x.inbound_storage_state === 'deleted' && !x.object_exists && x.malware_scan_status === 'clean').length, 6);
assert.equal(files.filter(x => x.inbound_storage_state === 'ready' && x.object_exists && x.malware_scan_status === 'clean').length, 2);
assert.equal(files.filter(x => x.inbound_storage_state === 'rejected' && x.object_exists && x.malware_scan_status === 'infected').length, 2);
const canonical = files.filter(x => ['it_ticket', 'it_ticket_comment'].includes(x.attachable_type));
assert.equal(canonical.length, 4);
for (const file of canonical) {
  assert(file.object_exists && file.object_hash_matches && file.malware_scan_status === 'clean');
  const source = files.find(x => x.id === file.source_inbound_attachment_id);
  assert(source && source.inbound_storage_state === 'deleted' && source.inbound_content_hash === file.inbound_content_hash);
}
assert.equal(files.filter(x => x.object_exists).length, 8);
assert(files.filter(x => x.object_exists).every(x => x.object_hash_matches === true));
assert.equal(Object.values(after.scanner_fixture_state.attempts).reduce((a,b) => a+b, 0), 12);
const cleanup = read('w11-attachment-browser-postflight.json');
assert.equal(cleanup.token, after.token);
assert(cleanup.checks.schema_absent && cleanup.checks.owned_directory_absent);
const browserRecords = {token: after.token, providers: 2, messagesPerProvider: 43, canonicalTickets: 58,
  canonicalReplies: 4, canonicalFiles: 4, retainedQuarantineFiles: 4, removedTemporaryCopies: 6,
  remainingPrivateObjects: 8, exactRuntimeRemoved: true, browserDownloadCompletion: 'unverified_tool_error'};
const result = {sources: sources.map(name => ({path: name, sha256: hash(name)})), protectedFiles, tests, browserRecords,
  assetManifestSha256: hash('public/build/manifest.json')};
fs.writeFileSync(path.join(__dirname, 'w11-attachment-browser-source-hashes.json'), JSON.stringify(result, null, 2) + '\n');
console.log(JSON.stringify({tests, browserRecords, protectedFilesUnchanged: protectedFiles.length, sourceFiles: sources.length, assetManifestSha256: result.assetManifestSha256}, null, 2));
