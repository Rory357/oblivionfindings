import { readFileSync, writeFileSync } from 'node:fs';
import { createHash } from 'node:crypto';
import { execFileSync } from 'node:child_process';
const main = 'C:/Users/steph/Herd/oblivionfindings';
const root = 'C:/Users/steph/.codex/worktrees/fleet-personal-reports/oblivionfindings';
const design = 'C:/Users/steph/.codex/worktrees/pkg09b-reports-design/oblivionfindings';
const packet = 'docs/fleet-assets-audit/reports/PKG-09B-REVIEW-PACKET';
const candidate = 'e4bb17b56405a748072bf83e8c30af323822e0bc';
const json = p => JSON.parse(readFileSync(p, 'utf8').replace(/^\uFEFF/, ''));
const sha = b => createHash('sha256').update(b).digest('hex');
const git = (...a) => execFileSync('git', ['-c',`safe.directory=${root}`,'-C',root,...a], {maxBuffer: 40000000});
const manifest = json(`${root}/${packet}/source-manifest.json`);
const mismatches = [], normalisedGit = [];
for (const item of manifest.files) {
  const bytes = readFileSync(`${root}/${item.path}`), blob = git('show',`${candidate}:${item.path}`);
  if (sha(bytes) !== item.worktreeSha256) mismatches.push(`worktree:${item.path}`);
  if (sha(blob) !== sha(bytes)) {
    if (blob.toString().replace(/\r\n/g,'\n') === bytes.toString().replace(/\r\n/g,'\n')) normalisedGit.push(item.path);
    else mismatches.push(`git:${item.path}`);
  }
}
let frozen = 0;
for (const version of ['v1','v2']) {
  const freeze = json(`${root}/${packet}/${version}-freeze-manifest.json`);
  for (const item of freeze.files) {
    frozen++;
    if (sha(readFileSync(`${design}/${item.path}`)) !== item.sha256.toLowerCase()) mismatches.push(`frozen:${version}:${item.path}`);
  }
}
const changed = git('diff','--name-only',manifest.base,candidate).toString().trim().split(/\r?\n/);
const source = changed.filter(p=>!p.startsWith('docs/'));
const declared = manifest.files.filter(p=>p.category==='application-or-test').map(p=>p.path);
const undeclared = source.filter(p=>!declared.includes(p));
const result = {candidate,base:manifest.base,workingHead:git('rev-parse','HEAD').toString().trim(),sourceFiles:source.length,payloadFiles:manifest.files.length,frozenFiles:frozen,normalisedGit,mismatches,undeclared,checkedAt:new Date().toISOString()};
writeFileSync(`${main}/docs/fleet-assets-audit/evidence/PKG-09B-main-source-verification.json`,JSON.stringify(result,null,2)+'\n');
console.log(JSON.stringify(result));
if (mismatches.length || undeclared.length || result.workingHead!==candidate) process.exitCode=1;
