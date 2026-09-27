import fs from 'node:fs/promises';
import path from 'node:path';
import crypto from 'node:crypto';
import {fileURLToPath} from 'node:url';
const here=path.dirname(fileURLToPath(import.meta.url)),repo=path.resolve(here,'../../../../..');
const hash=bytes=>crypto.createHash('sha256').update(bytes).digest('hex');
const readJson=async file=>JSON.parse((await fs.readFile(file,'utf8')).replace(/^\uFEFF/,''));
const writeJson=async(file,value)=>fs.writeFile(path.join(here,file),JSON.stringify(value,null,2)+'\n');
const v1=await readJson(path.join(here,'../v1/artifact-manifest.json'));
const preservation=[];
for(const file of v1.files){const actual=hash(await fs.readFile(path.join(here,'../v1',file.path)));preservation.push({path:file.path,expected:file.sha256,actual,unchanged:actual===file.sha256})}
if(preservation.some(v=>!v.unchanged))throw Error('A frozen v1 artifact changed');
const originalSources=await readJson(path.join(here,'../v1/canonical-source-hashes.json'));
const canonical=[];for(const file of originalSources.files){const sha256=hash(await fs.readFile(path.join(repo,file.path)));canonical.push({...file,sha256,unchangedFromV1:sha256===file.sha256})}
await writeJson('canonical-source-hashes.json',{baseline:originalSources.baseline,files:canonical});
const context=await readJson(path.join(here,'context-manifest.json')),references=[];
for(const ref of context.references){const current=hash(await fs.readFile(ref.path));references.push({path:ref.path,atDesignStart:ref.sha256.toLowerCase(),atFreeze:current,unchanged:current===ref.sha256.toLowerCase()})}
await writeJson('reference-verification.json',{checkedAt:new Date().toISOString(),note:'Read-only references. Main-owned programme register changes are recorded separately from protected guide or canonical application changes.',references});
const suites=[];
for(const file of ['qa-normal-flow.log','iterations/01-workflow/browser-results.log','iterations/02-recovery/browser-results.log','iterations/03-usability/browser-results.log','qa-final-browser.log']){
 const output=await fs.readFile(path.join(here,file),'utf8'),line=output.split(/\r?\n/).find(v=>v.startsWith('{"'));
 if(!line)throw Error('Missing completed result in '+file);
 const result=JSON.parse(line);if(result.results.some(v=>v.pass===false))throw Error('Failed browser result in '+file);
 suites.push({file,passed:result.results.filter(v=>v.pass===true).length,result});
}
const inputs=await readJson(path.join(here,'build-inputs.json'));
const inputCounts={total:inputs.inputs.length,previewSource:inputs.inputs.filter(v=>path.resolve(v.path).startsWith(here)).length,repositoryCss:inputs.inputs.filter(v=>v.path.endsWith('.css')).length};
inputCounts.repositoryJsTs=inputCounts.total-inputCounts.previewSource-inputCounts.repositoryCss;
const http=await fetch('http://127.0.0.1:4396/dist/app.js'),served=new Uint8Array(await http.arrayBuffer()),disk=await fs.readFile(path.join(here,'dist/app.js'));
if(hash(served)!==hash(disk))throw Error('Served runtime differs from candidate');
const v1Http=await fetch('http://127.0.0.1:4395/');
const integrity={checkedAt:new Date().toISOString(),runtimeSha256:hash(disk),servedRuntimeMatches:true,v1ServerStatus:v1Http.status,v1Preserved:preservation.every(v=>v.unchanged),v1Files:preservation.length,preservation,inputCounts,canonicalUnchanged:canonical.every(v=>v.unchangedFromV1),referenceChanges:references.filter(v=>!v.unchanged),browserAssertions:suites.reduce((n,s)=>n+s.passed,0),suites,domain:await readJson(path.join(here,'qa-domain.json')),types:await readJson(path.join(here,'qa-types.json'))};
await writeJson('verification.json',integrity);
await writeJson('iterations/03-usability/after-hashes.json',await Promise.all(['app.tsx','forms.tsx','domain.ts','preview.css','dist/app.js'].map(async name=>({path:name,sha256:hash(await fs.readFile(path.join(here,name)))}))));
console.log(JSON.stringify({runtime:integrity.runtimeSha256,inputCounts,browserAssertions:integrity.browserAssertions,domainAssertions:integrity.domain.results.length,v1Preserved:integrity.v1Preserved,canonicalUnchanged:integrity.canonicalUnchanged,referenceChanges:integrity.referenceChanges.map(v=>v.path)},null,2));
