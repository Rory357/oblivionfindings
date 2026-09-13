import {execFileSync} from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';
const root=process.cwd();
const folder=path.join(root,'docs/audits/2026-09-08-it-support/mockups/service-desk-overview/implementation/publish');
const manifest=JSON.parse(fs.readFileSync(path.join(folder,'manifest.json'),'utf8'));
const indexPath=path.join(folder,'overview.index');
if(fs.existsSync(indexPath)) throw new Error('Publication index already exists; inspect before retrying');
const env={...process.env,GIT_INDEX_FILE:indexPath};
const git=(args,input)=>execFileSync('git',args,{cwd:root,env,encoding:'utf8',input,maxBuffer:10*1024*1024});
git(['read-tree',manifest.base]);
for(const file of manifest.files) {
  const blob=git(['hash-object','-w','--stdin'],fs.readFileSync(path.join(folder,file))).trim();
  git(['update-index','--add','--cacheinfo',`100644,${blob},${file}`]);
}
git(['diff','--cached','--check',manifest.base]);
const changed=git(['diff','--cached','--name-only',manifest.base]).trim().split('\n').sort();
if(JSON.stringify(changed)!==JSON.stringify([...manifest.files].sort())) throw new Error('Publication scope mismatch');
const tree=git(['write-tree']).trim();
fs.writeFileSync(path.join(folder,'staged.json'),JSON.stringify({...manifest,tree,indexPath},null,2)+'\n');
fs.writeFileSync(path.join(folder,'overview.patch'),git(['diff','--cached',manifest.base]));
console.log(git(['diff','--cached','--stat',manifest.base]));
console.log(JSON.stringify({tree,files:changed.length,sharedIndexUntouched:true}));
