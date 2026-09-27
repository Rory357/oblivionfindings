import fs from 'node:fs/promises';
import {createReadStream} from 'node:fs';
import readline from 'node:readline';
import path from 'node:path';
import crypto from 'node:crypto';
import {fileURLToPath} from 'node:url';
import {execFileSync} from 'node:child_process';
const here=path.dirname(fileURLToPath(import.meta.url)),root=path.resolve(here,'../../../../..');
const hash=x=>crypto.createHash('sha256').update(x).digest('hex');
const read=async p=>JSON.parse(await fs.readFile(p,'utf8'));
const save=async(n,data)=>fs.writeFile(path.join(here,n),JSON.stringify(data,null,2)+'\n');
const prior=await read(path.join(here,'../v2/context-manifest.json'));
const session='C:/Users/steph/.codex/sessions/2026/09/26/rollout-2026-09-26T22-26-32-01a0dd40-952e-7ab0-ab5f-2c2ee8f9f734.jsonl';
let actual;
for await(const line of readline.createInterface({input:createReadStream(session),crlfDelay:Infinity})){if(line.includes('"type":"turn_context"')){const event=JSON.parse(line);actual={timestamp:event.timestamp,model:event.payload.model,effort:event.payload.effort,cwd:event.payload.cwd}}}
if(actual?.model!=='gpt-6-astra'||actual?.effort!=='xhigh')throw Error('Unexpected effective model metadata');
const references=[];
for(const reference of prior.references){const bytes=await fs.readFile(reference.path);references.push({...reference,sha256:hash(bytes),unchangedFromV2:hash(bytes).toLowerCase()===reference.sha256.toLowerCase()})}
const context={recorded_at:new Date().toISOString(),thread_id:prior.thread_id,actual_model:actual.model,actual_effort:actual.effort,verified_turn_timestamp:actual.timestamp,metadata_source:session+' turn_context',checkout:root,head:execFileSync('git',['rev-parse','HEAD'],{cwd:root,encoding:'utf8'}).trim(),pin:'Existing pinned chat preserved; no new task or worker',scope:'PKG-05 isolated synthetic desktop design candidate only',canonical_master:prior.canonical_master,canonical_sha256:hash(await fs.readFile(prior.canonical_master)),references,published_context:'Read-only Main register revision 100 reports f7d517359da6ffdf90de2f259111fe5e8a1133f2. Same published commit evaluated in v2; no fresh remote verification, fetch, rebase or application changes.'};
await save('context-manifest.json',context);
await save('reference-verification.json',{checkedAt:new Date().toISOString(),references});
const canonical=await read(path.join(here,'../v2/canonical-source-hashes.json'));
for(const file of canonical.files){const bytes=await fs.readFile(path.join(root,file.path));file.unchangedFromV2=hash(bytes)===file.sha256;file.sha256=hash(bytes);delete file.unchangedFromV1}
await save('canonical-source-hashes.json',canonical);
const preservation=[];
for(const version of ['v1','v2']){const dir=path.join(here,'..',version),manifest=await read(path.join(dir,'artifact-manifest.json'));const changed=[];for(const file of manifest.files){if(hash(await fs.readFile(path.join(dir,file.path)))!==file.sha256)changed.push(file.path)}preservation.push({version,filesChecked:manifest.files.length,mismatches:changed});}
const audit=await read(path.join(here,'../audit-v1/audit-integrity.json'));const changed=[];for(const file of audit.auditFiles){if(hash(await fs.readFile(path.join(here,'../audit-v1',file.path)))!==file.sha256)changed.push(file.path)}preservation.push({version:'audit-v1',filesChecked:audit.auditFiles.length,mismatches:changed});
await save('preservation.json',preservation);if(preservation.some(v=>v.mismatches.length))throw Error('A prior frozen artifact changed');
const suites=[];
for(const file of ['verify-interactions','verify-returns','verify-final','verify-flow']){const log=await fs.readFile(path.join(here,file+'.log'),'utf8');const match=log.match(/### Result\s*\r?\n([^\r\n]+)/);if(!match)throw Error('Missing successful result '+file);const data=JSON.parse(match[1]);if(data.results.some(v=>!v.pass))throw Error('Failed test '+file);suites.push({script:file+'.js',log:file+'.log',...data})}
const domain=await read(path.join(here,'qa-domain.json')),types=await read(path.join(here,'qa-types.json'));
const response=await fetch('http://127.0.0.1:4397/dist/app.js'),served=Buffer.from(await response.arrayBuffer());
const runtime=hash(await fs.readFile(path.join(here,'dist/app.js')));if(hash(served)!==runtime)throw Error('Served runtime differs');
const verification={candidate:'PKG-05 v3',checkedAt:new Date().toISOString(),url:'http://127.0.0.1:4397/#/fleet-assets/transports/journeys/J-609?from=journeys',baseline:context.head,runtimeSha256:runtime,servedSha256:hash(served),versionHeader:response.headers.get('x-pkg05-version'),browserAssertions:suites.reduce((n,s)=>n+s.results.length,0),domainAssertions:domain.results.length,typeErrors:types.scopedErrors.length+types.importedSourceErrors.length,suites,domain,types,preservation,limitations:['Synthetic browser state only. No API, database, uploads, notifications or clinical record was changed.','Scoped staff checkout/return authority and canonical record-link/projection contracts still need implementation approval.','Resource availability is a controlled source simulation, not a live reservation engine.','Actual browser zoom remains unverified; desktop resizing checked at 1024, 1280, 1440 and 1920 pixels.','Whole-application/backend security and repository CI were not exercised by these preview tests.']};
await save('verification.json',verification);
console.log(JSON.stringify({browserAssertions:verification.browserAssertions,domainAssertions:verification.domainAssertions,typeErrors:verification.typeErrors,runtimeSha256:runtime,priorArtifacts:preservation,changedReferences:references.filter(r=>!r.unchangedFromV2).map(r=>r.path)},null,2));
