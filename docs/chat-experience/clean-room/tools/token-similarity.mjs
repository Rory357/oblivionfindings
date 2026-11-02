// Clean-room similarity check: compares Oblivion's new files with the source of the outside code they
// replace, across languages (PHP, TypeScript, C and C++). Written by the C2.5 checker session, 23 Oct 2026.
// It contains no outside code; point it at outside sources downloaded to a scratch folder, never into the repo.
//
//   node docs/chat-experience/clean-room/tools/token-similarity.mjs [--show N] <ours...> -- <theirs...>
//
// Each file is read three ways:
//   structure  names become I, numbers N, strings S; types, modifiers, braces and semicolons are dropped,
//              so a port with renamed variables still lines up
//   literal    names lowercased without underscores, numbers as decimal values, string contents kept
//   comments   the words of every comment
// Shared runs are found by greedy string tiling (longest first, no overlaps), as JPlag does.
// Runs that only repeat a short pattern (lists of constants, number tables) or use fewer than seven
// different tokens are counted as "lists" and left out of the coverage score, but still listed by --show.
//
// Reading the result: compare each file's best coverage with a positive control (a deliberate port of one
// outside function, kept outside the repo) and with unrelated Oblivion files. In the C2.5 check a port of
// libopus's packet parser scored 31-42 % with 31-token runs; unrelated Chat services scored 0-5.5 %.

import fs from 'node:fs';
import path from 'node:path';

const MIN = { structure: 12, literal: 6, comments: 6 };

const DROP = new Set(`int unsigned signed char short long float double void bool boolean string number any
  uint8_t uint16_t uint32_t uint64_t int8_t int16_t int32_t int64_t size_t ssize_t off_t ptrdiff_t uintptr_t
  u8 u16 u32 u64 s8 s16 s32 s64 Bool const static inline extern register volatile restrict struct union enum
  typedef auto public private protected function final readonly abstract class interface trait namespace use
  new self parent array mixed iterable callable never let var export import from async await declare type
  implements extends mutable virtual override explicit constexpr noexcept`.split(/\s+/).filter(Boolean));

const KEYWORDS = new Map(Object.entries({
  if: 'if', else: 'else', elseif: 'else if', for: 'for', foreach: 'for', while: 'while', do: 'do',
  switch: 'switch', match: 'switch', case: 'case', default: 'default', break: 'break', continue: 'continue',
  return: 'return', throw: 'throw', try: 'try', catch: 'catch', finally: 'finally', goto: 'goto', sizeof: 'sizeof',
  true: 'K', false: 'K', null: 'K', NULL: 'K', nullptr: 'K', undefined: 'K', TRUE: 'K', FALSE: 'K',
  instanceof: 'instanceof', typeof: 'typeof', in: 'in', of: 'of', as: 'as', and: '&&', or: '||',
}));

const OPS = ['<<=', '>>=', '===', '!==', '**=', '??=', '...', '>>>', '->', '::', '=>', '==', '!=', '<=', '>=', '&&',
  '||', '++', '--', '+=', '-=', '*=', '/=', '%=', '&=', '|=', '^=', '.=', '<<', '>>', '??', '?.', '**'];

function lex(text, lang) {
  const tokens = [];
  const comments = [];
  let i = 0;
  let line = 1;
  const n = text.length;
  while (i < n) {
    const c = text[i];
    if (c === '\n') { line++; i++; continue; }
    if (/\s/.test(c)) { i++; continue; }
    if (lang === 'php' && text.startsWith('<?php', i)) { i += 5; continue; }
    if (text.startsWith('//', i) || (lang === 'php' && c === '#' && text[i + 1] !== '[')) {
      const start = i;
      while (i < n && text[i] !== '\n') i++;
      comments.push({ text: text.slice(start, i), line });
      continue;
    }
    if (text.startsWith('/*', i)) {
      const end = text.indexOf('*/', i + 2);
      const stop = end < 0 ? n : end + 2;
      comments.push({ text: text.slice(i + 2, stop - 2), line });
      for (let k = i; k < stop; k++) if (text[k] === '\n') line++;
      i = stop;
      continue;
    }
    if (lang === 'c' && c === '#') { // preprocessor: keep only the body of a #define
      let end = i;
      while (end < n && !(text[end] === '\n' && text[end - 1] !== '\\')) end++;
      const define = /^#\s*define\s+[A-Za-z_][A-Za-z0-9_]*(\([^)]*\))?/.exec(text.slice(i, end));
      if (define) { i += define[0].length; continue; }
      for (let k = i; k < end; k++) if (text[k] === '\n') line++;
      i = end;
      continue;
    }
    if (c === '"' || c === "'" || c === '`') {
      const at = line;
      let j = i + 1;
      let value = '';
      while (j < n && text[j] !== c) {
        if (text[j] === '\\' && j + 1 < n) { value += text[j] + text[j + 1]; j += 2; continue; }
        if (text[j] === '\n') line++;
        value += text[j++];
      }
      i = j + 1;
      tokens.push(lang === 'c' && c === "'" ? { kind: 'num', value: charValue(value), line: at } : { kind: 'str', value, line: at });
      continue;
    }
    const num = /^(0[xX][0-9a-fA-F_]+|0[bB][01_]+|\d[\d_]*(\.\d[\d_]*)?([eE][+-]?\d+)?)([uUlLfF]*)/.exec(text.slice(i, i + 40));
    if (num && /\d/.test(c)) { tokens.push({ kind: 'num', value: numValue(num[1]), line }); i += num[0].length; continue; }
    const name = /^\$?[A-Za-z_][A-Za-z0-9_]*/.exec(text.slice(i, i + 200));
    if (name) { tokens.push({ kind: 'name', value: name[0].replace(/^\$/, ''), line }); i += name[0].length; continue; }
    const op = OPS.find((o) => text.startsWith(o, i)) ?? c;
    tokens.push({ kind: 'op', value: op, line });
    i += op.length;
  }
  return { tokens, comments };
}

function charValue(v) {
  if (v.startsWith('\\x')) return String(parseInt(v.slice(2), 16));
  if (v === '\\0') return '0';
  if (v.startsWith('\\')) return String(({ n: 10, t: 9, r: 13, '\\': 92, "'": 39 })[v[1]] ?? v.charCodeAt(1));
  return String(v.charCodeAt(0));
}

function numValue(raw) {
  const s = raw.replace(/_/g, '');
  try { if (/^(0[xXbB][0-9a-fA-F]+|\d+)$/.test(s)) return BigInt(s).toString(); } catch { /* not an integer */ }
  return String(Number(s));
}

function views(file) {
  const lang = file.endsWith('.php') ? 'php' : file.endsWith('.ts') || file.endsWith('.js') || file.endsWith('.mjs') ? 'ts' : 'c';
  const text = fs.readFileSync(file, 'utf8');
  const lexed = lex(text, lang);
  const structure = [];
  const literal = [];
  for (const t of lexed.tokens) {
    let s;
    let l;
    if (t.kind === 'name') {
      if (DROP.has(t.value)) continue;
      const keyword = KEYWORDS.get(t.value);
      [s, l] = keyword ? [keyword, keyword] : ['I', t.value.toLowerCase().replace(/_/g, '')];
    } else if (t.kind === 'num') {
      [s, l] = ['N', '#' + t.value];
    } else if (t.kind === 'str') {
      [s, l] = ['S', '"' + t.value];
    } else {
      let v = t.value;
      if (v === '{' || v === '}' || v === ';') continue;
      v = ({ '===': '==', '!==': '!=', '->': '.', '::': '.', '?.': '.' })[v] ?? v;
      if (lang === 'php' && v === '.') v = '+'; // PHP joins strings with "."
      if (lang === 'php' && v === '.=') v = '+=';
      [s, l] = [v, v];
    }
    structure.push({ v: s, line: t.line });
    literal.push({ v: l, line: t.line });
  }
  const comments = [];
  for (const c of lexed.comments) {
    for (const w of c.text.toLowerCase().split(/[^a-z0-9]+/).filter(Boolean)) comments.push({ v: w, line: c.line });
  }
  return { file, structure, literal, comments };
}

// Greedy string tiling: maximal shared runs of at least `min` tokens, longest first, without overlaps.
function tiles(a, b, min) {
  if (a.length < min || b.length < min) return [];
  const key = (arr, i) => { let s = ''; for (let k = 0; k < min; k++) s += arr[i + k].v + '\u0001'; return s; };
  const index = new Map();
  for (let j = 0; j + min <= b.length; j++) {
    const k = key(b, j);
    const list = index.get(k) ?? [];
    if (list.length < 4000) list.push(j);
    index.set(k, list);
  }
  const found = [];
  for (let i = 0; i + min <= a.length; i++) {
    for (const j of index.get(key(a, i)) ?? []) {
      if (i > 0 && j > 0 && a[i - 1].v === b[j - 1].v) continue;
      let len = min;
      while (i + len < a.length && j + len < b.length && a[i + len].v === b[j + len].v) len++;
      found.push([i, j, len]);
    }
  }
  found.sort((x, y) => y[2] - x[2]);
  const usedA = new Uint8Array(a.length);
  const usedB = new Uint8Array(b.length);
  const out = [];
  for (const [i, j, len] of found) {
    let s = 0;
    while (s < len && (usedA[i + s] || usedB[j + s])) s++;
    let e = len;
    while (e > s && (usedA[i + e - 1] || usedB[j + e - 1])) e--;
    let free = e - s >= min;
    for (let k = s; free && k < e; k++) if (usedA[i + k] || usedB[j + k]) free = false;
    if (!free) continue;
    for (let k = s; k < e; k++) { usedA[i + k] = 1; usedB[j + k] = 1; }
    out.push({ len: e - s, oursLines: [a[i + s].line, a[i + e - 1].line], theirsLines: [b[j + s].line, b[j + e - 1].line],
      tokens: a.slice(i + s, i + e).map((x) => x.v) });
  }
  return out;
}

const isList = (tokens) => {
  if (new Set(tokens).size < 7) return true;
  for (let p = 1; p <= 4; p++) {
    let repeats = true;
    for (let k = p; k < tokens.length && repeats; k++) repeats = tokens[k] === tokens[k - p];
    if (repeats) return true;
  }
  return false;
};

const args = process.argv.slice(2);
let show = 0;
if (args[0] === '--show') { show = Number(args[1]); args.splice(0, 2); }
const sep = args.indexOf('--');
if (sep < 1 || sep === args.length - 1) {
  console.error('usage: node token-similarity.mjs [--show N] <ours...> -- <theirs...>');
  process.exit(2);
}
const ours = args.slice(0, sep).map(views);
const theirs = args.slice(sep + 1).map(views);
const short = (f) => f.split(/[\\/]/).slice(-3).join('/');
const shown = [];
for (const a of ours) {
  const best = { structure: [0, '-'], literal: [0, '-'], comments: [0, '-'] };
  const longest = { structure: 0, literal: 0, comments: 0 };
  let lists = 0;
  for (const b of theirs) {
    for (const view of ['structure', 'literal', 'comments']) {
      const found = tiles(a[view], b[view], MIN[view]);
      const kept = view === 'structure' ? found.filter((t) => !isList(t.tokens)) : found;
      lists += found.length - kept.length;
      const cover = a[view].length ? kept.reduce((sum, t) => sum + t.len, 0) / a[view].length : 0;
      if (cover > best[view][0]) best[view] = [cover, short(b.file)];
      for (const t of found) {
        if (!isList(t.tokens) || view !== 'structure') longest[view] = Math.max(longest[view], t.len);
        shown.push({ view, ours: a.file, theirs: b.file, list: view === 'structure' && isList(t.tokens), ...t });
      }
    }
  }
  const pct = (v) => `${(best[v][0] * 100).toFixed(1)}% vs ${best[v][1]}, longest ${longest[v]}`;
  console.log(`${path.basename(a.file)} (${a.structure.length} tokens)\n  structure ${pct('structure')} (${lists} list runs left out)\n  literal   ${pct('literal')}\n  comments  ${pct('comments')}`);
}
if (show > 0) {
  const lines = new Map();
  const read = (f) => { if (!lines.has(f)) lines.set(f, fs.readFileSync(f, 'utf8').split('\n')); return lines.get(f); };
  const excerpt = (f, [from, to]) => read(f).slice(from - 1, Math.min(to, from + 5)).map((l) => l.trim()).join(' / ');
  shown.sort((x, y) => y.len - x.len);
  console.log(`\nLongest ${show} shared runs (all views; "list" = left out of the score):`);
  for (const t of shown.slice(0, show)) {
    console.log(`\n[${t.view}${t.list ? ', list' : ''}] ${t.len} tokens  ${short(t.ours)}:${t.oursLines.join('-')}  <->  ${short(t.theirs)}:${t.theirsLines.join('-')}`);
    console.log(`  ours:   ${excerpt(t.ours, t.oursLines)}\n  theirs: ${excerpt(t.theirs, t.theirsLines)}`);
  }
}
