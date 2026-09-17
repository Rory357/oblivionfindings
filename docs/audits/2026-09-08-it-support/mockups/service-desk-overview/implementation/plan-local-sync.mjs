import {execFileSync} from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';
import {createHash} from 'node:crypto';
const root=process.cwd();
const output=path.join(root,'docs/audits/2026-09-08-it-support/mockups/service-desk-overview/implementation/local-sync');
fs.mkdirSync(output,{recursive:true});
const git=(args,options={})=>execFileSync('git',args,{cwd:root,encoding:'utf8',maxBuffer:12*1024*1024,...options});
const base=git(['rev-parse','HEAD']).trim();
const target=git(['rev-parse','origin/main']).trim();
const published=JSON.parse(fs.readFileSync(path.join(output,'../publish/commit.json'),'utf8'));
git(['merge-base','--is-ancestor',base,target]);
const hash=bytes=>bytes===null?null:createHash('sha256').update(bytes).digest('hex');
const normalize=bytes=>bytes===null?null:bytes.toString('utf8').replace(/\r\n/g,'\n');
const paths=git(['diff','--name-only','-z',base,target]).split('\0').filter(Boolean);
const entries=[];
for(const file of paths) {
  const absolute=path.resolve(root,file);
  if(!absolute.toLowerCase().startsWith((root+path.sep).toLowerCase())) throw new Error('Path escaped workspace');
  const spec=ref=>{try{return git(['show',`${ref}:${file}`],{encoding:null,stdio:['pipe','pipe','pipe']});}catch{return null;}};
  const before=spec(base), incoming=spec(target), working=fs.existsSync(absolute)?fs.readFileSync(absolute):null;
  let desired=working,kind='already-present';
  if(normalize(working)!==normalize(incoming)) {
    const shared=['resources/js/pages/it/index.tsx','app/Http/Controllers/It/ItProvisioningController.php'].includes(file);
    if(shared && target===published.Commit && git(['rev-parse',`${base}:${file}`])===git(['rev-parse',`${published.Base}:${file}`])) {
      const reference=normalize(incoming), live=normalize(working);
      const fragments=file.endsWith('.tsx')
        ? [reference.match(/            <TicketDrawer\n[\s\S]*?            \/>/)?.[0],reference.match(/                            <ItOverview\n[\s\S]*?                            \/>/)?.[0]]
        : ["            'workboard' => app(\\App\\Domain\\It\\Presenters\\ItOverviewWorkboardPresenter::class)->present($user),"];
      if(!fragments.every(fragment=>fragment && live.includes(fragment)))throw new Error(`Shared integration changed: ${file}`);
      kind='retained-shared-integration';
    }
    else if(normalize(working)===normalize(before)){desired=incoming;kind=incoming===null?'delete-clean':'update-clean';}
    else {
      if(before===null || incoming===null || working===null) throw new Error(`Manual integration required: ${file}`);
      const temp=path.join(output,'merge',file);
      fs.mkdirSync(path.dirname(temp),{recursive:true});
      for(const [suffix,bytes] of [['base',before],['incoming',incoming],['working',working]])fs.writeFileSync(temp+'.'+suffix,normalize(bytes));
      let merged;
      try{merged=git(['merge-file','-p',temp+'.working',temp+'.base',temp+'.incoming']);}
      catch(error){throw new Error(`Unresolved merge in ${file}: ${error.status}`);}
      desired=Buffer.from(merged);kind='merge-dirty';
    }
  }
  const backup=path.join(output,'before',file), after=path.join(output,'after',file);
  fs.mkdirSync(path.dirname(backup),{recursive:true});fs.mkdirSync(path.dirname(after),{recursive:true});
  if(working!==null)fs.writeFileSync(backup,working);
  if(desired!==null)fs.writeFileSync(after,desired);
  entries.push({file,kind,before:hash(working),after:hash(desired),write:hash(working)!==hash(desired)});
}
const indexFile=path.join(root,'.git/index');
const plan={base,target,indexHash:hash(fs.readFileSync(indexFile)),entries};
fs.writeFileSync(path.join(output,'plan.json'),JSON.stringify(plan,null,2)+'\n');
console.log(JSON.stringify({base,target,paths:entries.length,writes:entries.filter(e=>e.write).length,kinds:entries.reduce((sum,e)=>(sum[e.kind]=(sum[e.kind]??0)+1,sum),{}),dirtyMerges:entries.filter(e=>e.kind==='merge-dirty').map(e=>e.file)},null,2));
