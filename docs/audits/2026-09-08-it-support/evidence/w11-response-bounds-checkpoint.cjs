const fs = require('node:fs');
const path = require('node:path');
const crypto = require('node:crypto');
const assert = require('node:assert/strict');
const root = path.resolve(__dirname, '../../../..');
const hash = name => crypto.createHash('sha256').update(fs.readFileSync(path.join(root, name))).digest('hex');
const sources = ['app/Services/Integration/MailboxResponseBody.php', 'app/Services/Integration/MailboxProviderHttp.php',
  'app/Services/Integration/Exceptions/MailboxProviderFailure.php', 'tests/Unit/It/MailboxResponseBodyTest.php',
  'tests/Support/It/mailbox-response-server.php', 'tests/Feature/It/ItMailboxProviderFailureTest.php', 'tests/Feature/It/ItMailboxPollTest.php'];
const baseline = JSON.parse(fs.readFileSync(path.join(__dirname, 'implementation-design-baseline.json'), 'utf8').replace(/^\uFEFF/, ''));
const protectedFiles = baseline.flatMap(entry => (Array.isArray(entry.path) ? entry.path : [entry.path]).map((name, i) => {
  assert.equal(hash(name).toUpperCase(), (Array.isArray(entry.sha256) ? entry.sha256[i] : entry.sha256).toUpperCase());
  return {path:name, unchanged:true};
}));
const tests = ['it_ef4a86ec12ec42e0','it_723d2bcbe4cc4659','it_980a3a5360e14b78'].map(token => {
  const rows = fs.readFileSync(path.join(__dirname, token+'.diagnostic.jsonl'), 'utf8').trim().split(/\r?\n/).map(x=>JSON.parse(x));
  const events = suffix => rows.filter(x=>x.event === 'PHPUnit\\Event\\'+suffix);
  const start = events('TestRunner\\ExecutionStarted')[0];
  const end = events('TestRunner\\ExecutionFinished')[0];
  assert(start && end, 'Run has not finished: '+token);
  return {token, passed:events('Test\\Passed').length, failed:events('Test\\Failed').length, errors:events('Test\\Errored').length,
    assertions:events('Test\\Finished').reduce((n,x)=>n+x.assertions,0), seconds:(Date.parse(end.utc)-Date.parse(start.utc))/1000};
});
assert.equal(tests[2].failed + tests[2].errors, 0);
console.log(JSON.stringify({sources:sources.map(name=>({path:name,sha256:hash(name)})), protectedFiles, tests},null,2));
