// Read-only assertions over recorded synthetic evidence and protected source hashes.
const fs = require('node:fs');
const crypto = require('node:crypto');
const path = require('node:path');
const assert = require('node:assert/strict');
const repo = path.resolve(__dirname, '../../../..');
const data = JSON.parse(fs.readFileSync(path.join(__dirname, 'w11-content-browser-reconciliation.json'), 'utf8').replace(/^\uFEFF/, ''));
assert.equal(data.token, '8e615cc094d948ef');
assert.equal(data.synthetic_ticket_count, 56);
assert.deepEqual(data.canonical_commands.map(x => [x.operation, Number(x.records), Number(x.committed)]), [['ticket.create', 56, 56], ['ticket.comment', 2, 2]]);
assert.deepEqual(data.canonical_email_audit.map(x => [x.action, Number(x.records)]), [['it.ticket.created', 56], ['it.ticket.comment.added', 2]]);
assert(data.connections.every(x => x.status === 'connected' && x.next_poll_at === null));
assert.equal(data.quarantine_reasons.reduce((n, x) => n + Number(x.records), 0), 16);
assert(data.quarantine_reasons.every(x => Number(x.retained_body_previews) === 0));
assert(data.inbound.every(x => Number(x.records) === Number(x.acknowledged)));
for (const provider of ['microsoft', 'google']) {
  const reads = Object.values(data.provider_state.reads[provider]);
  assert.equal(reads.length, 38);
  assert(reads.every(x => x === 1));
}
assert.equal(data.provider_state.external_body_reads, 1);
const baseline = JSON.parse(fs.readFileSync(path.join(__dirname, 'implementation-design-baseline.json'), 'utf8').replace(/^\uFEFF/, ''));
const design = baseline.flatMap(entry => (Array.isArray(entry.path) ? entry.path : [entry.path]).map((name, i) => {
  const expected = Array.isArray(entry.sha256) ? entry.sha256[i] : entry.sha256;
  const actual = crypto.createHash('sha256').update(fs.readFileSync(path.join(repo, name))).digest('hex');
  assert.equal(actual.toUpperCase(), expected.toUpperCase(), name);
  return { path: name, unchanged: true };
}));
console.log(JSON.stringify({ token: data.token, reconciliation_verified: true, tickets: 56, replies: 2, quarantined: 16, external_body_reads: 1, protected_design: design }, null, 2));
