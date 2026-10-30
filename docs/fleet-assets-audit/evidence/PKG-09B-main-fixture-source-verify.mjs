import { readFileSync, writeFileSync } from 'node:fs';
import process from 'node:process';
import { createHash } from 'node:crypto';
import { execFileSync } from 'node:child_process';
const main = 'C:/Users/steph/Herd/oblivionfindings';
const root = 'C:/Users/steph/.codex/worktrees/fleet-personal-reports/oblivionfindings';
const candidate = '50716b9cca0473ee4082e329ce6ff1347833f714';
const packet = `${root}/docs/fleet-assets-audit/reports/PKG-09B-REVIEW-PACKET`;
const manifest = JSON.parse(readFileSync(`${packet}/correction-manifest.json`, 'utf8').replace(/^\uFEFF/, ''));
const hash = data => createHash('sha256').update(data).digest('hex');
const git = (...args) => execFileSync('git', ['-c', `safe.directory=${root}`, '-C', root, ...args], {maxBuffer:40000000});
const mismatches = [];
const files = manifest.completeApplicationAndTestFiles;
for (const file of files) {
  const bytes = readFileSync(`${root}/${file.path}`);
  const blob = git('show', `${candidate}:${file.path}`);
  if (hash(bytes) !== file.worktreeSha256) mismatches.push(`worktree:${file.path}`);
  if (hash(blob.toString().replace(/\r\n/g, '\n')) !== file.normalizedLfSha256) mismatches.push(`commit:${file.path}`);
}
const changed = git('diff', '--name-only', manifest.base, candidate).toString().trim().split(/\r?\n/).filter(p=>!p.startsWith('docs/'));
const declared = new Set(files.map(f=>f.path));
const undeclared = changed.filter(p=>!declared.has(p));
const result = {candidate, base:manifest.base, actualHead:git('rev-parse','HEAD').toString().trim(), sourceFiles:files.length, mismatches, undeclared, checkedAt:new Date().toISOString()};
writeFileSync(`${main}/docs/fleet-assets-audit/evidence/PKG-09B-main-fixture-source-verification.json`, JSON.stringify(result,null,2)+'\n');
console.log(JSON.stringify(result));
if (mismatches.length || undeclared.length || result.actualHead!==candidate) process.exitCode=1;
