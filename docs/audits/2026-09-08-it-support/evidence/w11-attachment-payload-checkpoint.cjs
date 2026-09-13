const fs = require('node:fs');
const path = require('node:path');
const crypto = require('node:crypto');
const assert = require('node:assert/strict');
const root = path.resolve(__dirname, '../../../..');
const hash = name => crypto.createHash('sha256').update(fs.readFileSync(path.join(root, name))).digest('hex');
const sources = ['app/Domain/It/Data/ItEmailAttachment.php', 'app/Domain/It/Services/ItEmailAttachments.php',
  'app/Domain/It/Services/ItEmailBase64.php', 'app/Domain/It/Services/ItEmailContent.php',
  'app/Services/GoogleGmailService.php', 'app/Services/MicrosoftGraphService.php',
  'tests/Unit/It/ItEmailAttachmentsTest.php','tests/Feature/It/ItEmailAttachmentProviderTest.php'];
const baseline = JSON.parse(fs.readFileSync(path.join(__dirname,'implementation-design-baseline.json'),'utf8').replace(/^\uFEFF/,''));
const protectedFiles = baseline.flatMap(entry => (Array.isArray(entry.path)?entry.path:[entry.path]).map((name,i)=>{
  assert.equal(hash(name).toUpperCase(),(Array.isArray(entry.sha256)?entry.sha256[i]:entry.sha256).toUpperCase());
  return {path:name,unchanged:true};
}));
const tests = ['it_36c0d4dd7ee1437f','it_71a45af357d54fe1','it_4efa466856204f7e'].map(token=>{
  const rows=fs.readFileSync(path.join(__dirname,token+'.diagnostic.jsonl'),'utf8').trim().split(/\r?\n/).map(x=>JSON.parse(x));
  const events=suffix=>rows.filter(x=>x.event==='PHPUnit\\Event\\'+suffix);
  const start=events('TestRunner\\ExecutionStarted')[0], end=events('TestRunner\\ExecutionFinished')[0];
  assert(start&&end,'Still running: '+token);
  return {token,passed:events('Test\\Passed').length,failed:events('Test\\Failed').length,errors:events('Test\\Errored').length,
    assertions:events('Test\\Finished').reduce((n,x)=>n+x.assertions,0),seconds:(Date.parse(end.utc)-Date.parse(start.utc))/1000};
});
assert.equal(tests[2].failed+tests[2].errors,0);
console.log(JSON.stringify({sources:sources.map(name=>({path:name,sha256:hash(name)})),protectedFiles,tests},null,2));
