import { readFileSync, writeFileSync } from 'node:fs';
import { createHash } from 'node:crypto';
import { execFileSync } from 'node:child_process';
import assert from 'node:assert/strict';

const main = 'C:/Users/steph/Herd/oblivionfindings';
const root = 'C:/Users/steph/.codex/worktrees/pkg08-settings-implementation/oblivionfindings';
const design = 'C:/Users/steph/.codex/worktrees/pkg08-settings-design/oblivionfindings/docs/fleet-assets-audit/previews/PKG-08';
const candidate = '38239d35506bf97c4b6f865a13aa10933b4cb439';
const reviewedParent = 'b07888e9b413696649ceceb03bbae3ee38edf166';
const packet = 'docs/fleet-assets-audit/evidence/PKG-08-implementation';
const json = (path) => JSON.parse(readFileSync(path, 'utf8').replace(/^\uFEFF/, ''));
const sha = (bytes) => createHash('sha256').update(bytes).digest('hex');
const git = (...args) => execFileSync('git', ['-c', `safe.directory=${root}`, '-C', root, ...args]);
const manifest = json(`${root}/${packet}/source-manifest.json`);
const mismatches = [], normalisedWorking = [];
for (const [path, expected] of Object.entries(manifest.files)) {
    const working = readFileSync(`${root}/${path}`);
    if (sha(working) !== expected) {
        if (sha(working.toString().replace(/\r\n/g, '\n')) === expected) normalisedWorking.push(path);
        else mismatches.push(`working:${path}`);
    }
    if (sha(git('show', `${candidate}:${path}`)) !== expected) mismatches.push(`committed:${path}`);
}
const changed = git('diff', '--name-only', manifest.base, candidate).toString().trim().split(/\r?\n/).filter(Boolean);
const source = changed.filter((path) => !path.startsWith(`${packet}/`));
const declared = new Set(Object.keys(manifest.files));
const unmanifested = source.filter((path) => !declared.has(path));
const unchangedReferences = [...declared].filter((path) => !source.includes(path));
const preserved = json(`${design}/v6/evidence/preservation-before.json`);
const frozen = json(`${design}/v6/freeze-manifest.json`);
preserved.v6 = frozen.files;
const previewCounts = {};
for (const [version, files] of Object.entries(preserved)) {
    previewCounts[version] = Object.keys(files).length;
    for (const [path, expected] of Object.entries(files)) {
        if (sha(readFileSync(`${design}/${version}/${path}`)) !== expected) mismatches.push(`preview:${version}/${path}`);
    }
}
const references = manifest.authorities;
assert.ok(references, 'Reference manifest field must be inspected');
for (const [path, hashes] of Object.entries(references)) {
    if (sha(readFileSync(`${root}/${path}`)) !== hashes.implementation) mismatches.push(`reference-implementation:${path}`);
    if (sha(readFileSync(`${main}/${path}`)) !== hashes.main) mismatches.push(`reference-main:${path}`);
}
for (const [where, hash] of Object.entries(manifest.protectedBlade)) {
    if (sha(readFileSync(`${where === 'main' ? main : root}/resources/views/app.blade.php`)) !== hash) mismatches.push(`blade:${where}`);
}
assert.equal(git('rev-parse', 'HEAD').toString().trim(), candidate);
assert.equal(git('rev-parse', 'HEAD^').toString().trim(), reviewedParent);
const correctionPaths = git('diff', '--name-only', reviewedParent, candidate).toString().trim().split(/\r?\n/).filter(Boolean);
assert.deepEqual(correctionPaths.filter((path) => !path.startsWith(`${packet}/`)), ['resources/js/pages/fleet-assets/settings/_notifications.tsx', 'resources/js/pages/fleet-assets/settings/index.tsx']);
assert.equal(sha(readFileSync(`${root}/${packet}/source-manifest.json`)), sha(git('show', `${candidate}:${packet}/source-manifest.json`)));
assert.deepEqual(mismatches, []);
assert.deepEqual(unmanifested, []);
assert.deepEqual(unchangedReferences, ['app/Http/Controllers/FleetAssets/GeofenceController.php']);
const result = {
    candidate, base: manifest.base, parent: git('rev-parse', 'HEAD^').toString().trim(), tree: git('rev-parse', `${candidate}^{tree}`).toString().trim(),
    correctionPaths,
    mainHead: execFileSync('git', ['-C', main, 'rev-parse', 'HEAD']).toString().trim(),
    sourceFiles: declared.size, changedSourceFiles: source.length, normalisedWorking,
    unchangedReferences, previewCounts, protectedReferences: Object.keys(references).length,
    mismatches, unmanifested, status: git('status', '--porcelain').toString().trim(),
    sourceManifestGitSha256: sha(git('show', `${candidate}:${packet}/source-manifest.json`)),
    frozenManifestSha256: sha(readFileSync(`${design}/v6/freeze-manifest.json`)),
    frozenBundleSha256: sha(readFileSync(`${design}/v6/dist/app.js`)),
};
writeFileSync(`${main}/docs/fleet-assets-audit/evidence/PKG-08-main-fidelity-verification.json`, `${JSON.stringify(result, null, 2)}\n`);
console.log(JSON.stringify(result, null, 2));
