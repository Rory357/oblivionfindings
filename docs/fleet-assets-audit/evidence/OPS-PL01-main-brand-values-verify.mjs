import { readFileSync, writeFileSync } from 'node:fs';
import { createHash } from 'node:crypto';
import { execFileSync } from 'node:child_process';
import { createRequire } from 'node:module';
import assert from 'node:assert/strict';

const main = 'C:/Users/steph/Herd/oblivionfindings';
const root = 'C:/Users/steph/.codex/worktrees/people-locations-implementation/oblivionfindings';
const design = 'C:/Users/steph/.codex/worktrees/people-locations-design/oblivionfindings';
const candidate = '419e4c890c84a322ec031cd07cc06f61acf80d39';
const evidence = `${main}/docs/fleet-assets-audit/evidence`;
const packet = `${root}/docs/fleet-assets-audit/evidence/OPS-PL01-implementation`;
const json = (path) => JSON.parse(readFileSync(path, 'utf8').replace(/^\uFEFF/, ''));
const sha = (bytes) => createHash('sha256').update(bytes).digest('hex');
const git = (...args) => execFileSync('git', ['-c', `safe.directory=${root}`, '-C', root, ...args]);
const manifest = json(`${packet}/code-manifest.json`);
const mismatches = [];
for (const file of manifest.files) {
    if (sha(readFileSync(`${root}/${file.path}`)) !== file.sha256) mismatches.push(`working:${file.path}`);
    if (sha(git('show', `${candidate}:${file.path}`)) !== file.sha256) mismatches.push(`committed:${file.path}`);
}
const changed = git('diff', '--name-only', manifest.base, candidate).toString().trim().split(/\r?\n/).filter(Boolean);
const source = changed.filter((path) => !path.startsWith('docs/fleet-assets-audit/evidence/'));
const declared = new Set(manifest.files.map((file) => file.path));
const unmanifested = source.filter((path) => !declared.has(path));
const absent = [...declared].filter((path) => !source.includes(path));
const frozen = json(`${design}/docs/fleet-assets-audit/evidence/OPS-PL01-v5/freeze-manifest.json`);
for (const file of frozen.files) {
    if (sha(readFileSync(`${design}/${file.path}`)) !== file.sha256) mismatches.push(`frozen:${file.path}`);
}
const references = json(`${packet}/reference-hashes.json`);
for (const [path, expected] of Object.entries(references)) {
    if (sha(readFileSync(`${root}/${path}`)) !== expected) mismatches.push(`reference:${path}`);
}
const master = sha(readFileSync('C:/Users/steph/Downloads/oblivion-findings-fleet-assets-complete-astra-prompt-v10.md'));
assert.equal(master, 'c4837ab675f9dffdb6a8597636f49d5761da114e6c155dc08e6bb8a209d63fd0');
const head = git('rev-parse', 'HEAD').toString().trim();
const status = git('status', '--porcelain').toString().trim();
assert.equal(head, candidate);
assert.equal(status, '');
assert.deepEqual(mismatches, []);
assert.deepEqual(unmanifested, []);
assert.deepEqual(absent, []);
const verification = {
    candidate, parent: git('rev-parse', `${candidate}^`).toString().trim(),
    tree: git('rev-parse', `${candidate}^{tree}`).toString().trim(),
    sourceFiles: manifest.files.length, frozenFiles: frozen.files.length,
    referenceFiles: Object.keys(references).length, changedFiles: changed.length,
    mismatches, unmanifested, absent, status, masterSha256: master,
    originalProbeSha256: sha(readFileSync(`${evidence}/OPS-PL01-main-brand-token-probe.php`)),
};
writeFileSync(`${evidence}/OPS-PL01-main-brand-values-candidate-verification.json`, `${JSON.stringify(verification, null, 2)}\n`);

const requireCandidate = createRequire(`${root}/package.json`);
const { JSDOM } = requireCandidate('jsdom');
const probes = json(`${evidence}/OPS-PL01-main-brand-values-rendered.json`);
const results = probes.map((probe) => {
    const dom = new JSDOM(`<!doctype html><html><head><style>html:root{--status-warning:orange;--status-warning-foreground:black}</style>${probe.rendered}</head><body></body></html>`);
    const css = dom.window.getComputedStyle(dom.window.document.documentElement);
    const result = {
        case: probe.case,
        warning: css.getPropertyValue('--status-warning').trim(),
        warningForeground: css.getPropertyValue('--status-warning-foreground').trim(),
        primary: css.getPropertyValue('--primary').trim(),
    };
    assert.equal(result.warning, 'orange');
    assert.equal(result.warningForeground, 'black');
    assert.equal(result.primary, probe.case === 'ordinary' ? 'oklch(0.5 0.12 190)' : '');
    dom.window.close();
    return result;
});
writeFileSync(`${evidence}/OPS-PL01-main-brand-values-computed.json`, `${JSON.stringify(results, null, 2)}\n`);
console.log(JSON.stringify({ verification, computed: results }, null, 2));
