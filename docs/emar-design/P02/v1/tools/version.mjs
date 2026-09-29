// Writes VERSION.txt: the SHA-256 of every file that makes up P02 v1 (approval
// applies to these hashes only). Screenshots are evidence, not part of the
// version; the harness profile and Vite cache are excluded (.gitignore).
//   node docs/emar-design/P02/v1/tools/version.mjs
import { createHash } from 'node:crypto';
import { readFileSync, readdirSync, statSync, writeFileSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const root = path.join(path.dirname(fileURLToPath(import.meta.url)), '..');
const skip = new Set(['screenshots', '.chrome-profile', '.vite', 'VERSION.txt']);
const files = [];
const walk = (dir) => {
    for (const name of readdirSync(dir).sort()) {
        if (skip.has(name)) continue;
        const full = path.join(dir, name);
        if (statSync(full).isDirectory()) walk(full);
        else files.push(path.relative(root, full).split(path.sep).join('/'));
    }
};
walk(root);
const sha = (p) => createHash('sha256').update(readFileSync(path.join(root, p))).digest('hex');
const shots = readdirSync(path.join(root, 'screenshots')).filter((f) => f.endsWith('.png')).length;
const report = sha('screenshots/report.json');
const lines = [
    'eMAR P02 v1 — Person medication record',
    'Branch claude/interesting-lalande-efa3cf · base origin/main ddb8d3af4 · 30 September 2026 (NZDT)',
    'SHA-256 of every file that makes up this version (approval applies to these hashes only).',
    `Screenshots are evidence, not part of the approved version: ${shots} PNG files; report.json sha256 ${report}.`,
    'src/p01/{clock,contract,data}.ts and {dialogs,doses,record-dialog,store,ui,inertia-shim}.tsx are P01 v1 (3ac640485) byte for byte — compare with P01 v1 VERSION.txt src/*.',
    '',
    ...files.map((f) => `${sha(f)} *${f}`),
    '',
];
writeFileSync(path.join(root, 'VERSION.txt'), lines.join('\n'));
process.stdout.write(`VERSION.txt: ${files.length} files\n`);
