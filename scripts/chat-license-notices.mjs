import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

// Preserve the installed client dependency licences with the browser distribution.
// This is a reproducible notice inventory, not legal certification of the product.
const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const visited = new Set();
const entries = [];
function locate(name, from) {
    for (let current = from; ; current = path.dirname(current)) {
        const candidate = path.join(
            current,
            'node_modules',
            name,
            'package.json',
        );
        if (fs.existsSync(candidate)) return candidate;
        if (current === path.dirname(current))
            throw new Error(`Missing installed dependency: ${name}`);
    }
}
function collect(name, from) {
    const packageFile = locate(name, from);
    if (visited.has(packageFile)) return;
    visited.add(packageFile);
    const directory = path.dirname(packageFile);
    const pkg = JSON.parse(fs.readFileSync(packageFile, 'utf8'));
    const files = fs
        .readdirSync(directory)
        .filter(
            (file) =>
                /^(licen[cs]e|notice|copying|copyright)(\.|$|-)/i.test(file) &&
                fs.statSync(path.join(directory, file)).isFile(),
        )
        .sort()
        .map((file) => ({
            name: file,
            content: fs.readFileSync(path.join(directory, file), 'utf8').trim(),
        }));
    if (pkg.name === '@bufbuild/protobuf' && pkg.version === '1.10.1') {
        for (const file of [
            'bufbuild-protobuf-1.10.1-APACHE.txt',
            'bufbuild-protobuf-1.10.1-GOOGLE-NOTICE.txt',
        ]) {
            files.push({
                name: file,
                content: fs
                    .readFileSync(
                        path.join(root, 'deploy/chat-calling/licenses', file),
                        'utf8',
                    )
                    .trim(),
            });
        }
    }
    if (!files.length || !pkg.license)
        throw new Error(
            `Review required: missing licence information for ${pkg.name}@${pkg.version}`,
        );
    entries.push({
        name: pkg.name,
        version: pkg.version,
        licence: pkg.license,
        files,
    });
    for (const dependency of Object.keys(pkg.dependencies ?? {}).sort())
        collect(dependency, directory);
}
collect('livekit-client', root);
collect('pusher-js', root);

// Code copied inside a package's built file rather than installed as its own package, so it
// ships no licence file. The marker must still appear in the built file, or the entry needs review.
const MIT_BODY = `Permission is hereby granted, free of charge, to any person obtaining a copy
of this software and associated documentation files (the "Software"), to deal
in the Software without restriction, including without limitation the rights
to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
copies of the Software, and to permit persons to whom the Software is
furnished to do so, subject to the following conditions:

The above copyright notice and this permission notice shall be included in all
copies or substantial portions of the Software.

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
SOFTWARE.`;
const embedded = [
    { name: 'ts-debounce', host: 'livekit-client', file: 'dist/livekit-client.esm.mjs', marker: 'Originally from ts-debounce', holder: '2017 Jakub Chodorowicz' },
    { name: '@stablelib/base64', host: 'pusher-js', file: 'dist/web/pusher.js', marker: 'Copyright (C) 2016 Dmitry Chestnykh', holder: '2016 Dmitry Chestnykh' },
    { name: '@stablelib/utf8', host: 'pusher-js', file: 'dist/web/pusher.js', marker: 'Package utf8 implements UTF-8 encoding', holder: '2016 Dmitry Chestnykh' },
];
for (const part of embedded) {
    const hostPackage = locate(part.host, root);
    const built = path.join(path.dirname(hostPackage), part.file);
    if (!fs.existsSync(built) || !fs.readFileSync(built, 'utf8').includes(part.marker))
        throw new Error(
            `Review required: ${part.name} is no longer found inside ${part.host}/${part.file}`,
        );
    const host = JSON.parse(fs.readFileSync(hostPackage, 'utf8'));
    entries.push({
        name: part.name,
        version: `bundled inside ${host.name}@${host.version}`,
        licence: 'MIT',
        files: [{ name: 'LICENSE (from the built file header)', content: `MIT License\n\nCopyright (c) ${part.holder}\n\n${MIT_BODY}` }],
    });
}

entries.sort(
    (a, b) =>
        a.name.localeCompare(b.name) || a.version.localeCompare(b.version),
);
const text = [
    'OBLIVION CHAT — BROWSER THIRD-PARTY NOTICES',
    'Generated from installed runtime packages by scripts/chat-license-notices.mjs.',
    'These components remain the property of their respective copyright holders.',
    'This inventory covers livekit-client, pusher-js and their declared runtime dependency trees, not the entire application or server image.',
    ...entries.map(
        (entry) =>
            `\n${'='.repeat(72)}\n${entry.name}@${entry.version}\nDeclared licence: ${entry.licence}\n${entry.files.map((file) => `\n--- ${file.name} ---\n${file.content}\n`).join('')}`,
    ),
].join('\n');
const output = path.join(
    root,
    'public',
    'legal',
    'chat-third-party-notices.txt',
);
fs.mkdirSync(path.dirname(output), { recursive: true });
// Some packages ship CRLF licence files; write LF only so a build never leaves
// the deploy checkout modified (the deploy refuses a dirty checkout).
fs.writeFileSync(output, `${text}\n`.replace(/\r\n?/g, '\n'));
console.log(
    `Preserved licences/notices for ${entries.length} installed runtime packages in public/legal/chat-third-party-notices.txt`,
);
for (const entry of entries)
    console.log(`${entry.name}@${entry.version}: ${entry.licence}`);
