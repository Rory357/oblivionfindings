import { readFileSync, writeFileSync } from 'node:fs';
import { execFileSync } from 'node:child_process';
import { createHash } from 'node:crypto';
import process from 'node:process';

const root = 'C:/Users/steph/.codex/worktrees/fleet-personal-reports/oblivionfindings';
const main = 'C:/Users/steph/Herd/oblivionfindings';
const packet = process.argv[2];
if (!packet) throw new Error('Pass the exact frozen packet directory.');
const readJson = path => JSON.parse(readFileSync(path, 'utf8').replace(/^\uFEFF/, ''));
const manifest = readJson(`${packet}/source-manifest.json`);
const git = (...args) => execFileSync('git', ['-c', `safe.directory=${root}`, '-C', root, ...args], { maxBuffer: 50000000 });
const tree = revision => new Map(git('ls-tree', '-r', '-z', revision).toString().split('\0').filter(Boolean).map(row => {
  const tab = row.indexOf('\t');
  return [row.slice(tab + 1), row.slice(0, tab).split(' ')[2]];
}));
const finalTree = tree(manifest.codeCommit);
const inputs = Object.fromEntries(Object.entries(manifest.inputCommits).map(([name, revision]) => [name, tree(revision)]));
const failures = [];
for (const file of manifest.paths) {
  if ((finalTree.get(file.path) ?? null) !== file.finalBlob) failures.push(`final:${file.path}`);
  if ((inputs.Main.get(file.path) ?? null) !== file.mainBlob) failures.push(`base:${file.path}`);
  for (const name of file.matchingInputRefs) {
    if ((inputs[name]?.get(file.path) ?? null) !== file.finalBlob) failures.push(`input:${name}:${file.path}`);
  }
}
const actualChanges = git('diff', '--name-only', '-z', manifest.inputCommits.Main, manifest.codeCommit).toString().split('\0').filter(Boolean);
const declared = new Set(manifest.paths.map(file => file.path));
for (const path of actualChanges) if (!declared.has(path)) failures.push(`undeclared:${path}`);
for (const path of declared) if (!actualChanges.includes(path)) failures.push(`unchanged-declared:${path}`);
const protectedPaths = readFileSync(`${packet}/main-programme-paths.txt`, 'utf8').replace(/^\uFEFF/, '').trim().split(/\r?\n/);
for (const path of protectedPaths) if (inputs.Main.get(path) !== finalTree.get(path)) failures.push(`protected:${path}`);
for (const revision of Object.values(manifest.inputCommits)) git('merge-base', '--is-ancestor', revision, manifest.codeCommit);
const sourceDelta = git('diff', '--name-only', manifest.codeCommit, '--', 'app', 'config', 'routes', 'resources', 'database', 'tests', 'composer.json', 'composer.lock', 'package.json', 'package-lock.json').toString().trim();
if (sourceDelta) failures.push(`working-source:${sourceDelta}`);
const packetCommit = process.argv[3];
let packetFiles = 0;
if (packetCommit) {
  const packetManifest = readJson(`${packet}/packet-manifest.json`);
  const sha256 = bytes => createHash('sha256').update(bytes).digest('hex');
  for (const entry of packetManifest.files) {
    const bytes = readFileSync(`${packet}/${entry.path}`);
    const committed = git('show', `${packetCommit}:${packetManifest.packetDirectory}/${entry.path}`);
    if (sha256(bytes) !== entry.sha256 || bytes.length !== entry.bytes) failures.push(`packet-worktree:${entry.path}`);
    if (sha256(committed) !== entry.sha256 || committed.length !== entry.bytes) failures.push(`packet-commit:${entry.path}`);
  }
  const sidecarHash = readFileSync(`${packet}/packet-manifest.sha256`, 'utf8').trim().split(/\s+/)[0];
  if (sha256(readFileSync(`${packet}/packet-manifest.json`)) !== sidecarHash) failures.push('packet-manifest-checksum');
  const packetChanges = git('diff', '--name-only', manifest.codeCommit, packetCommit).toString().trim().split(/\r?\n/).filter(Boolean);
  for (const path of packetChanges) if (!path.startsWith(`${packetManifest.packetDirectory}/`)) failures.push(`unexpected-packet-change:${path}`);
  packetFiles = packetManifest.files.length;
}
const result = { codeCommit: manifest.codeCommit, packetCommit, packetFiles, base: manifest.inputCommits.Main, sourcePaths: actualChanges.length, protectedPaths: protectedPaths.length, allInputsIncluded: true, failures, checkedAt: new Date().toISOString() };
writeFileSync(`${main}/docs/fleet-assets-audit/evidence/MAIN-combined-final-source-verification.json`, `${JSON.stringify(result, null, 2)}\n`);
console.log(JSON.stringify(result));
if (failures.length) process.exitCode = 1;
