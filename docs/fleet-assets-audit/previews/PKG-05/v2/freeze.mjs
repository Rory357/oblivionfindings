import fs from 'node:fs/promises';
import path from 'node:path';
import crypto from 'node:crypto';
import {fileURLToPath} from 'node:url';
const here=path.dirname(fileURLToPath(import.meta.url));
const hash=bytes=>crypto.createHash('sha256').update(bytes).digest('hex');
const excluded=new Set(['artifact-manifest.json','FREEZE.txt','server.stdout.log','server.stderr.log']);
try{await fs.access(path.join(here,'FREEZE.txt'));throw Error('Already frozen. Create a new candidate instead of replacing this freeze.')}catch(error){if(error.code!=='ENOENT')throw error}
async function walk(dir){let files=[];for(const item of await fs.readdir(dir,{withFileTypes:true})){if(item.name==='.playwright-cli'||excluded.has(item.name))continue;const full=path.join(dir,item.name);if(item.isDirectory())files.push(...await walk(full));else files.push(full)}return files}
const records=[];for(const full of await walk(here)){const bytes=await fs.readFile(full);records.push({path:path.relative(here,full).replaceAll('\\','/'),bytes:bytes.length,sha256:hash(bytes)})}records.sort((a,b)=>a.path.localeCompare(b.path));
const manifest={candidate:'PKG-05 v2',status:'Awaiting exact design approval',frozenAt:new Date().toISOString(),url:'http://127.0.0.1:4396/#/fleet-assets/transports/overview',baseline:'4ea64c547ed85a5b7504e59599db351f6eba7deb',runtimeSha256:hash(await fs.readFile(path.join(here,'dist/app.js'))),fileSetSha256:hash(JSON.stringify(records)),algorithm:'SHA-256 over UTF-8 JSON.stringify of sorted file records (path,bytes,sha256)',browserAssertions:74,domainAssertions:31,additionalAuditRounds:3,screenshots:records.filter(v=>v.path.endsWith('.png')).length,files:records};
const manifestText=JSON.stringify(manifest,null,2)+'\n';await fs.writeFile(path.join(here,'artifact-manifest.json'),manifestText);const manifestSha=hash(manifestText);
await fs.writeFile(path.join(here,'FREEZE.txt'),`PKG-05 v2 · exact design review candidate\nFrozen: ${manifest.frozenAt}\nURL: ${manifest.url}\nRuntime SHA-256: ${manifest.runtimeSha256}\nFile set SHA-256: ${manifest.fileSetSha256}\nManifest SHA-256: ${manifestSha}\nFrozen files: ${records.length}\nScreenshots: ${manifest.screenshots}\nThree additional audit/improvement rounds completed.\n74 browser assertions; 31 synthetic transition assertions. Actual browser zoom unverified.\nNo application implementation or production effects. Preserve this version; make later corrections in a new candidate.\n`);
console.log(JSON.stringify({runtime:manifest.runtimeSha256,fileSet:manifest.fileSetSha256,manifest:manifestSha,files:records.length,screenshots:manifest.screenshots},null,2));
