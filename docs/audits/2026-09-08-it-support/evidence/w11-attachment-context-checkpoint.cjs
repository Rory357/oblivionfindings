// Read-only source fingerprint and protected-design check for this bounded slice.
const fs = require('node:fs');
const crypto = require('node:crypto');
const path = require('node:path');
const assert = require('node:assert/strict');
const root = path.resolve(__dirname, '../../../..');
const hash = name => crypto.createHash('sha256').update(fs.readFileSync(path.join(root, name))).digest('hex');
const sources = [
  'app/Domain/It/Services/ItAttachmentWriteContext.php',
  'app/Domain/It/Services/ItTicketIntakeService.php',
  'app/Domain/It/Services/ItTicketInteractionService.php',
  'app/Domain/It/Services/ItMailboxInbox.php',
  'app/Domain/It/Services/ItMailboxPollState.php',
  'app/Domain/It/InboundEmailIngestor.php',
  'tests/Concurrency/It/ItInboundAttachmentTransactionTest.php',
];
const baseline = JSON.parse(fs.readFileSync(path.join(__dirname, 'implementation-design-baseline.json'), 'utf8').replace(/^\uFEFF/, ''));
const protectedFiles = baseline.flatMap(entry => (Array.isArray(entry.path) ? entry.path : [entry.path]).map((name, index) => {
  const expected = Array.isArray(entry.sha256) ? entry.sha256[index] : entry.sha256;
  assert.equal(hash(name).toUpperCase(), expected.toUpperCase(), name);
  return {path: name, unchanged: true};
}));
const tests = ['it_89049c80d61042b0', 'it_c14c88b649444330'].map(token => {
  const entries = fs.readFileSync(path.join(__dirname, token + '.diagnostic.jsonl'), 'utf8').trim().split(/\r?\n/).map(line => JSON.parse(line));
  const passed = entries.filter(x => x.event === 'PHPUnit\\Event\\Test\\Passed').length;
  const finished = entries.filter(x => x.event === 'PHPUnit\\Event\\Test\\Finished');
  assert.equal(passed, finished.length);
  const start = entries.find(x => x.event === 'PHPUnit\\Event\\TestRunner\\ExecutionStarted');
  const end = entries.find(x => x.event === 'PHPUnit\\Event\\TestRunner\\ExecutionFinished');
  assert(start && end);
  return {token, passed, assertions: finished.reduce((n, x) => n + x.assertions, 0), seconds: (Date.parse(end.utc) - Date.parse(start.utc)) / 1000};
});
console.log(JSON.stringify({sources: sources.map(name => ({path: name, sha256: hash(name)})), protectedFiles, tests}, null, 2));
