import fs from 'node:fs/promises';
import path from 'node:path';
import crypto from 'node:crypto';
import {fileURLToPath} from 'node:url';
const here=path.dirname(fileURLToPath(import.meta.url)),hash=b=>crypto.createHash('sha256').update(b).digest('hex');
try{await fs.access(path.join(here,'FREEZE.txt'));throw Error('Already frozen. Create a new version for changes.')}catch(e){if(e.code!=='ENOENT')throw e}
const excluded=new Set(['artifact-manifest.json','FREEZE.txt','server.log','server-error.log','.playwright-cli']);
async function walk(dir){const list=[];for(const entry of await fs.readdir(dir,{withFileTypes:true})){if(excluded.has(entry.name))continue;const file=path.join(dir,entry.name);if(entry.isDirectory())list.push(...await walk(file));else list.push(file)}return list}
const files=[];for(const full of await walk(here)){const bytes=await fs.readFile(full);files.push({path:path.relative(here,full).replaceAll('\\','/'),bytes:bytes.length,sha256:hash(bytes)})}files.sort((a,b)=>a.path.localeCompare(b.path));
const verification=JSON.parse(await fs.readFile(path.join(here,'verification.json'),'utf8'));
const manifest={candidate:'PKG-05 v3',status:'Awaiting exact design approval',frozenAt:new Date().toISOString(),url:verification.url,baseline:verification.baseline,runtimeSha256:verification.runtimeSha256,fileSetSha256:hash(JSON.stringify(files)),algorithm:'SHA-256 over UTF-8 JSON.stringify of sorted file records (path,bytes,sha256)',browserAssertions:verification.browserAssertions,domainAssertions:verification.domainAssertions,typeErrors:verification.typeErrors,screenshots:files.filter(f=>f.path.endsWith('.png')).length,files};
const body=JSON.stringify(manifest,null,2)+'\n';await fs.writeFile(path.join(here,'artifact-manifest.json'),body);
await fs.writeFile(path.join(here,'FREEZE.txt'),`PKG-05 v3 · exact design review candidate\nFrozen: ${manifest.frozenAt}\nURL: ${manifest.url}\nRuntime SHA-256: ${manifest.runtimeSha256}\nFile-set SHA-256: ${manifest.fileSetSha256}\nManifest SHA-256: ${hash(body)}\nFiles: ${files.length}\nScreenshots: ${manifest.screenshots}\n77 browser assertions; 34 synthetic domain assertions; zero TypeScript errors.\nPrior 175 frozen files preserved. Actual browser zoom unverified.\nNo application implementation or operational effects. Preserve this version.\n`);
console.log(JSON.stringify({runtime:manifest.runtimeSha256,fileSet:manifest.fileSetSha256,manifest:hash(body),files:files.length,screenshots:manifest.screenshots},null,2));
