import {execFileSync} from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';
import {createHash} from 'node:crypto';
if(process.argv[2]!=='--runtime-cleanup-confirmed')throw new Error('Explicit runtime cleanup release required');
const root=process.cwd();
const folder=path.join(root,'docs/audits/2026-09-08-it-support/mockups/service-desk-overview/implementation/local-sync');
const plan=JSON.parse(fs.readFileSync(path.join(folder,'plan.json'),'utf8'));
const hash=data=>data===null?null:createHash('sha256').update(data).digest('hex');
const bytes=file=>fs.existsSync(file)?fs.readFileSync(file):null;
const git=(...args)=>execFileSync('git',args,{cwd:root,encoding:'utf8',maxBuffer:12*1024*1024}).trim();
const indexFile=path.join(root,'.git/index'), lockFile=indexFile+'.lock';
if(git('symbolic-ref','HEAD')!=='refs/heads/main'||git('rev-parse','HEAD')!==plan.base)throw new Error('Local HEAD changed');
if(hash(bytes(indexFile))!==plan.indexHash)throw new Error('Shared index changed');
git('diff','--cached','--quiet');
git('merge-base','--is-ancestor',plan.base,plan.target);
for(const entry of plan.entries){
  const absolute=path.resolve(root,entry.file);
  if(!absolute.toLowerCase().startsWith((root+path.sep).toLowerCase()))throw new Error('Target escaped workspace');
  if(hash(bytes(absolute))!==entry.before)throw new Error(`Source changed: ${entry.file}`);
  if(hash(bytes(path.join(folder,'after',entry.file)))!==entry.after)throw new Error(`Prepared bytes changed: ${entry.file}`);
}
const dirty=git('diff','--name-only','-z').split('\0').filter(Boolean);
const preserved=dirty.filter(file=>!plan.entries.some(entry=>entry.file===file&&entry.write)).map(file=>({file,hash:hash(bytes(path.join(root,file)))}));
fs.writeFileSync(path.join(folder,'index.before'),bytes(indexFile));
const staging=JSON.parse(fs.readFileSync(path.join(folder,'../publish/staged.json'),'utf8'));
const tempIndex=bytes(staging.indexPath);
if(execFileSync('git',['write-tree'],{cwd:root,env:{...process.env,GIT_INDEX_FILE:staging.indexPath},encoding:'utf8'}).trim()!==git('rev-parse',`${plan.target}^{tree}`))throw new Error('Incoming index tree mismatch');
const lock=fs.openSync(lockFile,'wx');
try{fs.writeFileSync(lock,tempIndex);}finally{fs.closeSync(lock);}
fs.writeFileSync(path.join(folder,'apply-started.json'),JSON.stringify({base:plan.base,target:plan.target,preserved},null,2)+'\n');
for(const entry of plan.entries.filter(entry=>entry.write)){
  const absolute=path.resolve(root,entry.file);
  if(entry.after===null){fs.unlinkSync(absolute);}
  else{fs.mkdirSync(path.dirname(absolute),{recursive:true});fs.writeFileSync(absolute,bytes(path.join(folder,'after',entry.file)));}
}
git('update-ref','-m','Fast-forward local main after scoped Overview publication','refs/heads/main',plan.target,plan.base);
fs.renameSync(lockFile,indexFile);
for(const entry of plan.entries)if(hash(bytes(path.join(root,entry.file)))!==entry.after)throw new Error(`Integrated source mismatch: ${entry.file}`);
for(const entry of preserved)if(hash(bytes(path.join(root,entry.file)))!==entry.hash)throw new Error(`Unrelated source changed: ${entry.file}`);
git('diff','--cached','--quiet');
const result={head:git('rev-parse','HEAD'),branch:git('branch','--show-current'),incomingPaths:plan.entries.length,sourceWrites:plan.entries.filter(entry=>entry.write).length,unrelatedDirtyPathsPreserved:preserved.length,sharedIndexMatchesHead:true,headerLocalDelta:git('diff','--numstat','--','resources/js/components/page/page-header.tsx')};
fs.writeFileSync(path.join(folder,'result.json'),JSON.stringify(result,null,2)+'\n');
console.log(JSON.stringify(result,null,2));
