import { readFileSync, writeFileSync } from 'node:fs';
import { createHash } from 'node:crypto';
import { execFileSync } from 'node:child_process';
import assert from 'node:assert/strict';

const main = 'C:/Users/steph/Herd/oblivionfindings';
const root = 'C:/Users/steph/.codex/worktrees/people-locations-implementation/oblivionfindings';
const design = 'C:/Users/steph/.codex/worktrees/people-locations-design/oblivionfindings';
const candidate = '352ce7006098b227b945a47c4d55400c9aeff429';
const parent = '419e4c890c84a322ec031cd07cc06f61acf80d39';
const packet = 'docs/fleet-assets-audit/evidence/OPS-PL01-implementation';
const json = (path) => JSON.parse(readFileSync(path, 'utf8').replace(/^\uFEFF/, ''));
const sha = (bytes) => createHash('sha256').update(bytes).digest('hex');
const git = (...args) => execFileSync('git', ['-c', `safe.directory=${root}`, '-C', root, ...args]);
const manifest = json(`${root}/${packet}/code-manifest.json`);
const mismatches = [];
for (const file of manifest.files) {
    if (sha(readFileSync(`${root}/${file.path}`)) !== file.sha256) mismatches.push(`working:${file.path}`);
    if (sha(git('show', `${candidate}:${file.path}`)) !== file.sha256) mismatches.push(`committed:${file.path}`);
}
const paths = (base) => git('diff', '--name-only', base, candidate).toString().trim().split(/\r?\n/).filter(Boolean);
const sourceOnly = (files) => files.filter((path) => !path.startsWith('docs/fleet-assets-audit/evidence/'));
assert.deepEqual(sourceOnly(paths(manifest.base)), manifest.files.map((file) => file.path).sort());
const visualSource = sourceOnly(paths(parent));
assert.deepEqual(visualSource, [
    'resources/js/components/people-locations/history-panel.tsx',
    'resources/js/components/people-locations/record-picker.tsx',
    'resources/js/components/people-locations/workspace.css',
    'resources/js/pages/operations/people-locations/index.tsx',
]);
const frozen = json(`${design}/docs/fleet-assets-audit/evidence/OPS-PL01-v5/freeze-manifest.json`);
for (const file of frozen.files) {
    if (sha(readFileSync(`${design}/${file.path}`)) !== file.sha256) mismatches.push(`frozen:${file.path}`);
}
const references = json(`${root}/${packet}/reference-hashes.json`);
for (const [path, expected] of Object.entries(references)) {
    for (const [label, checkout] of [['candidate', root], ['main', main]]) {
        if (sha(readFileSync(`${checkout}/${path}`)) !== expected) mismatches.push(`${label}-reference:${path}`);
    }
}
const headerGuide = 'design_styles/PAGE_HEADER_STYLE_GUIDE.md';
const headerHash = sha(git('show', `${parent}:${headerGuide}`));
assert.equal(headerHash, '33aeeb0e4434d3bb4d3023e3ce95ffee12e95ba1fe62c02f36601f8219827faa');
assert.equal(sha(readFileSync(`${root}/${headerGuide}`)), headerHash);
assert.equal(sha(readFileSync(`${main}/${headerGuide}`)), headerHash);
const master = sha(readFileSync('C:/Users/steph/Downloads/oblivion-findings-fleet-assets-complete-astra-prompt-v10.md'));
assert.equal(master, 'c4837ab675f9dffdb6a8597636f49d5761da114e6c155dc08e6bb8a209d63fd0');
assert.equal(git('rev-parse', 'HEAD').toString().trim(), candidate);
assert.equal(git('rev-parse', 'HEAD^').toString().trim(), parent);
assert.equal(git('status', '--porcelain').toString().trim(), '');
assert.deepEqual(mismatches, []);
const result = {
    candidate, parent, base: manifest.base,
    tree: git('rev-parse', `${candidate}^{tree}`).toString().trim(),
    mainHead: execFileSync('git', ['-C', main, 'rev-parse', 'HEAD']).toString().trim(),
    sourceFiles: manifest.files.length, reportedSourceDigest: manifest.digest,
    sourceManifestGitSha256: sha(git('show', `${candidate}:${packet}/code-manifest.json`)),
    frozenFiles: frozen.files.length, protectedReferences: Object.keys(references).length,
    pageHeaderGuideSha256: headerHash, masterSha256: master,
    visualSource, mismatches, cleanCandidate: true,
};
writeFileSync(`${main}/docs/fleet-assets-audit/evidence/OPS-PL01-main-visual-verification.json`, `${JSON.stringify(result, null, 2)}\n`);
console.log(JSON.stringify(result, null, 2));
