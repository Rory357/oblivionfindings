// Writes VERSION.txt: the SHA-256 of every file that makes up this version
// (sources, build, tools, docs). Screenshots are evidence, not part of the
// version: they are counted, and report.json's hash is recorded.
//   node docs/emar-design/P07b/v1/tools/version.mjs
import { createHash } from 'node:crypto';
import { readdirSync, readFileSync, statSync, writeFileSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const root = path.join(path.dirname(fileURLToPath(import.meta.url)), '..');
const skip = new Set(['screenshots', '.chrome-profile', '.vite', 'node_modules', 'VERSION.txt']);
const files = [];
(function walk(dir) {
    for (const name of readdirSync(dir).sort()) {
        if (skip.has(name)) continue;
        const full = path.join(dir, name);
        if (statSync(full).isDirectory()) walk(full);
        else files.push(path.relative(root, full).split(path.sep).join('/'));
    }
})(root);
const sha = (p) => createHash('sha256').update(readFileSync(p)).digest('hex');
const shots = readdirSync(path.join(root, 'screenshots')).filter((f) => f.endsWith('.png'));
const lines = [
    'eMAR P07b v1.1 — Controlled register, loss & destruction',
    'Branch claude/emar-p07b · base origin/main 21bfb4ce4 · 30 September 2026 (NZDT)',
    'SHA-256 of every file that makes up this version (approval applies to these hashes only).',
    `Screenshots are evidence, not part of the approved version: ${shots.length} PNG files; report.json sha256 ${sha(path.join(root, 'screenshots', 'report.json'))}.`,
    '',
    ...files.map((f) => `${sha(path.join(root, f))} *${f}`),
    '',
];
writeFileSync(path.join(root, 'VERSION.txt'), lines.join('\n'));
process.stdout.write(`VERSION.txt: ${files.length} files, ${shots.length} screenshots\n`);
