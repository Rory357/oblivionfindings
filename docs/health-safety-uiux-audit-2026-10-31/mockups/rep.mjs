// Exact find-and-replace in a board: node rep.mjs <file> <json array of [old, new] pairs>
import fs from 'node:fs';
const [file, pairsJson] = process.argv.slice(2);
let t = fs.readFileSync(file, 'utf8');
const pairs = JSON.parse(fs.readFileSync(pairsJson, 'utf8'));
let n = 0;
for (const [a, b] of pairs) {
  if (!t.includes(a)) { console.error('NOT FOUND in ' + file + ':\n  ' + a.slice(0, 120)); process.exitCode = 1; continue; }
  t = t.split(a).join(b); n++;
}
fs.writeFileSync(file, t);
console.log(file.split('/').pop(), 'replaced', n, 'of', pairs.length);
