const fs = require('node:fs');
const path = require('node:path');
const crypto = require('node:crypto');
const assert = require('node:assert/strict');
const root = path.resolve(__dirname, '../../../..');
const read = name => JSON.parse(fs.readFileSync(path.join(__dirname, name), 'utf8').replace(/^\uFEFF/, ''));
const hash = name => crypto.createHash('sha256').update(fs.readFileSync(path.join(root, name))).digest('hex');
const sources = [
  'database/migrations/2026_09_11_000020_add_inbound_quarantine_review.php',
  'app/Models/ItInboundEmail.php', 'app/Domain/It/InboundEmailIngestor.php',
  'app/Domain/It/Services/ItInboundQuarantineReview.php',
  'app/Http/Controllers/Settings/ItInboundQuarantineController.php', 'routes/settings.php',
  'resources/js/pages/settings/it-mailbox.tsx',
  'resources/js/components/settings/it-mailbox-quarantine-contract.ts',
  'resources/js/components/settings/it-mailbox-quarantine.tsx',
  'resources/js/components/settings/__tests__/it-mailbox-quarantine.test.tsx',
  'tests/Feature/It/ItInboundQuarantineReviewTest.php',
  'docs/runbooks/it-mailbox-quarantine-recovery.md',
  'docs/audits/2026-09-08-it-support/evidence/w11-browser-mailbox-reconcile.php',
];
const protectedFiles = read('implementation-design-baseline.json').flatMap(entry =>
  (Array.isArray(entry.path) ? entry.path : [entry.path]).map((name, index) => {
    assert.equal(hash(name).toUpperCase(), (Array.isArray(entry.sha256) ? entry.sha256[index] : entry.sha256).toUpperCase());
    return {path: name, unchanged: true};
  }));
const token = 'it_61c130cd79004a0f';
const events = fs.readFileSync(path.join(__dirname, token + '.diagnostic.jsonl'), 'utf8').trim().split(/\r?\n/).map(JSON.parse);
const match = suffix => events.filter(row => row.event === 'PHPUnit\\Event\\' + suffix);
assert.equal(match('Test\\Failed').length + match('Test\\Errored').length, 0);
assert.equal(match('Test\\Passed').length, 72);
assert.equal(match('Test\\Finished').reduce((sum, row) => sum + row.assertions, 0), 619);
const testLog = fs.readFileSync(path.join(__dirname, 'w11-quarantine-review-tests.txt'), 'utf8');
const postflight = testLog.slice(testLog.lastIndexOf('"isolation_checks"'));
assert.equal((postflight.match(/: true/g) || []).length, 14);
assert(postflight.includes('"isolated_schema_does_not_exist": true'));
assert(testLog.includes('"exit_code":0'));
assert(fs.readFileSync(path.join(__dirname, 'w11-quarantine-focus-ui-tests.txt'), 'utf8').includes('15 passed'));
const before = read('w11-quarantine-browser-before.json');
const cancelled = read('w11-quarantine-browser-after-cancel.json');
const final = read('w11-quarantine-browser-final.json');
assert.equal(final.token, '7574d29be8994d0d');
assert.equal(before.token, final.token);
assert.equal(final.synthetic_provider_only, true);
assert.equal(cancelled.quarantine_reviews.length, 0);
assert.equal(cancelled.quarantine_review_audit.length, 0);
assert.deepEqual(final.quarantine_reviews.map(row => Number(row.id)), [42, 85]);
for (const row of final.quarantine_reviews) {
  assert.equal(row.status, 'quarantined');
  assert.equal(row.quarantine_reason, 'sender_unknown');
  assert.equal(Number(row.quarantine_review_version), 2);
  assert.equal(row.quarantine_retry_requested_at, null);
  assert(row.acknowledged_at);
}
assert.equal(final.quarantine_review_audit.length, 4);
for (const id of [42, 85]) {
  const audit = final.quarantine_review_audit.filter(row => Number(row.auditable_id) === id);
  assert.deepEqual(audit.map(row => row.action), ['settings.it_mailbox.quarantine_retry_requested', 'it.inbound_email.quarantine_retry_completed']);
  assert(audit[0].user_id);
  const file = final.attachment_records.find(row => row.attachable_type === 'it_inbound_email' && Number(row.attachable_id) === id);
  assert(file && file.object_exists && file.object_hash_matches);
  assert.equal(file.inbound_storage_state, 'ready');
  assert.equal(file.malware_scan_status, 'clean');
  const original = before.attachment_records.find(row => Number(row.id) === Number(file.id));
  assert.equal(file.inbound_content_hash, original.inbound_content_hash);
}
assert.equal(final.synthetic_ticket_count, 58);
assert.equal(final.inbound.reduce((sum, row) => sum + Number(row.records), 0), 86);
assert.equal(final.inbound.reduce((sum, row) => sum + Number(row.acknowledged), 0), 86);
assert.equal(Number(final.canonical_commands.find(row => row.operation === 'ticket.create').records), 58);
assert.equal(Number(final.canonical_commands.find(row => row.operation === 'ticket.comment').records), 4);
assert.equal(final.attachment_records.length, 14);
assert.equal(final.attachment_records.filter(row => row.object_exists).length, 8);
assert(final.attachment_records.filter(row => row.object_exists).every(row => row.object_hash_matches));
for (const provider of ['microsoft', 'google']) {
  assert.equal(final.provider_state.reads[provider]['42'], 2);
  assert.equal(final.provider_state.ack_attempts[provider]['42'], 2);
  assert.equal(final.provider_state.unread[provider].length, 0);
}
assert(final.quarantine_reasons.every(row => Number(row.retained_body_previews) === 0));
const cleanup = read('w11-quarantine-browser-postflight.json');
assert.equal(cleanup.token, final.token);
assert.equal(cleanup.checks.schema_absent, true);
assert.equal(cleanup.checks.owned_directory_absent, true);
assert.equal(hash('public/build/manifest.json'), '914586e1e6900cc46fdefa469e5088eb16ee2a0417b6cbdcfb456462895920ac');
const result = {
  capturedAt: new Date().toISOString(), sourceFiles: sources.map(name => ({path: name, sha256: hash(name)})),
  protectedFiles, backend: {token, tests: 72, assertions: 619, postflightChecks: 14}, uiTests: 15,
  browser: {token: final.token, retries: 2, auditEvents: 4, unchangedAccessStayedQuarantined: true, privateObjects: 8, hashesMatch: true, cleanup},
  manifest: hash('public/build/manifest.json'), fullW11Accepted: false, fullGoalComplete: false,
};
fs.writeFileSync(path.join(__dirname, 'w11-quarantine-review-source-hashes.json'), JSON.stringify(result, null, 2) + '\n');
console.log(JSON.stringify({sourceFiles: sources.length, protectedFiles: protectedFiles.length, backendTests: 72, assertions: 619, uiTests: 15, browserRetries: 2, exactRuntimeRemoved: true}));
