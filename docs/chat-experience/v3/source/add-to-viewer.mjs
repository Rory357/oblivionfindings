// Insert the Chat v3 page into a copy of the design viewer's index.html, right after the "talk" page.
// Usage: node add-to-viewer.mjs <source index.html> <manifest-entry.json> <out index.html>
import { readFileSync, writeFileSync } from 'node:fs';

const [src, entryFile, out] = process.argv.slice(2);
let html = readFileSync(src, 'utf8');
const entry = JSON.parse(readFileSync(entryFile, 'utf8'));

// Remove an earlier copy of this page so the script can run again.
const idKey = `{"id":"${entry.id}",`;
const existing = html.indexOf(idKey);
if (existing !== -1) {
    const end = findObjectEnd(html, existing);
    const before = html[existing - 1] === ',' ? existing - 1 : existing;
    html = html.slice(0, before) + html.slice(end + (before === existing && html[end] === ',' ? 1 : 0));
}

const anchor = html.indexOf('{"id":"talk",');
if (anchor === -1) throw new Error('talk page not found');
const end = findObjectEnd(html, anchor);
html = html.slice(0, end) + ',' + JSON.stringify(entry) + html.slice(end);
writeFileSync(out, html);
console.log('inserted', entry.id, 'with', entry.items.length, 'boards');

function findObjectEnd(s, start) {
    let depth = 0, inStr = false, esc = false;
    for (let i = start; i < s.length; i++) {
        const c = s[i];
        if (inStr) { if (esc) esc = false; else if (c === '\\') esc = true; else if (c === '"') inStr = false; continue; }
        if (c === '"') inStr = true;
        else if (c === '{' || c === '[') depth++;
        else if (c === '}' || c === ']') { depth--; if (depth === 0) return i + 1; }
    }
    throw new Error('unbalanced');
}
