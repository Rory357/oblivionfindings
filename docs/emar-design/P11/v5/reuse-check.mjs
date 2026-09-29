// P11 v5 self-checks. Usage (from the repository root): node docs/emar-design/P11/v5/reuse-check.mjs
// 1. P00 v5 wording is reused verbatim: every string in P00’s rule, PIN and witness declarations
//    (pinned commit ff3bff860) appears unchanged in src/data.ts.
// 2. The preview imports the app’s real primitives (Fleet preview method) and no shared file changed.
// 3. Tokens and honesty: no hex colours, raw palette classes or dark: pairs; no prompt/confirm/alert;
//    no network calls (synthetic data only).
import { execFileSync } from 'node:child_process';
import { readFileSync, readdirSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const here = path.dirname(fileURLToPath(import.meta.url));
const git = (...a) => execFileSync('git', a, { encoding: 'utf8', maxBuffer: 64 * 1024 * 1024 });
const p00 = git('show', 'ff3bff860:docs/emar-design/P00/v5/mockup.js');
const data = readFileSync(path.join(here, 'src/data.ts'), 'utf8');
const src = Object.fromEntries(readdirSync(path.join(here, 'src')).filter((f) => /\.(tsx?|css)$/.test(f)).map((f) => [f, readFileSync(path.join(here, 'src', f), 'utf8')]));
let fails = 0;
const check = (name, ok, detail = '') => { if (!ok) fails += 1; console.log(`${ok ? 'PASS' : 'FAIL'}  ${name}${detail ? ` — ${detail}` : ''}`); };

// 1. P00 wording
function decl(name) {
    const lines = p00.split('\n');
    const i = lines.findIndex((l) => new RegExp(`^    const ${name} = `).test(l));
    if (i < 0) return '';
    let j = i + 1;
    while (j < lines.length && !/^    (const|function|let) |^    \/\*/.test(lines[j])) j += 1;
    return lines.slice(i, j).join('\n');
}
// The one P00 field v5 doesn’t show as text: SAFETY_RULES.restricted.rec (shown as the “Agreed next step” badge).
const OMIT = new Set(['Agreed with Stephan (29 Sep 2026): Block now — the dialog shows who on shift can give it — then Co-signer with witness PIN once the PIN is built. Never a co-signer by login password.']);
for (const name of ['SAFETY_RULES', 'PIN_RULES', 'CDW_OPTS', 'CDW_LABEL', 'MATCH', 'ROUTES', 'CLASSES', 'NZULM', 'OBS', 'RULES', 'MEDLIST']) {
    const block = decl(name);
    const strings = [...block.matchAll(/'((?:[^'\\]|\\.)*)'/g)].map((x) => x[1]).filter((s) => s.length > 2 && !OMIT.has(s) && !/^(i|icon)$/.test(s));
    const icons = new Set(['pill', 'activity', 'file-text', 'layers', 'shield']); // P00 icon names; v5 uses lucide components
    const missing = strings.filter((s) => !icons.has(s) && !data.includes(`'${s}'`));
    check(`P00 v5 ${name}: wording reused verbatim`, block.length > 0 && missing.length === 0, missing.length ? `missing: ${missing.slice(0, 3).join(' | ')}` : `${strings.length} strings`);
}
const pinsBlock = p00.slice(p00.indexOf('const STAFF_PINS = ['), p00.indexOf('];', p00.indexOf('const STAFF_PINS = [')));
const pinNames = [...pinsBlock.matchAll(/name: '([^']+)'[^}]*pin: '([^']+)'/g)].map((x) => [x[1], x[2]]);
check('P00 v5 STAFF_PINS: people and PIN states unchanged (P11 adds four)', pinNames.length === 6 && pinNames.every(([n, st]) => new RegExp(`name: '${n}'[^}]*pin: '${st}'`).test(data)), `${pinNames.length} people`);

// 2. Real components, no shared-file edits
const imports = new Set(Object.values(src).flatMap((s) => [...s.matchAll(/from '(@\/[^']+)'/g)].map((x) => x[1])));
const REQUIRED = ['@/components/page/page-header', '@/components/ui/switch', '@/components/wizard/shell', '@/components/wizard/primitives', '@/components/confirm-dialog', '@/components/lists/entity-table', '@/components/lists/entity-menu', '@/components/lists/list-caption', '@/components/ui/status-badge', '@/components/ui/empty-state', '@/components/ui/skeleton-table', '@/components/ui/popover', '@/components/ui/command', '@/components/fleet-assets/maintenance/date-picker', '@/components/fleet-assets/maintenance/time-picker', '@/components/governance/DiscardDraftDialog', '@/pages/fleet-assets/settings/_ui'];
const missingImp = REQUIRED.filter((r) => !imports.has(r));
check('imports the app’s real primitives', missingImp.length === 0, missingImp.length ? `missing ${missingImp.join(', ')}` : `${imports.size} app modules imported`);
const changed = git('status', '--porcelain', '--', 'resources', 'DESIGN.md', 'design_styles').trim();
const committed = git('diff', '--name-only', 'origin/main...HEAD', '--', 'resources', 'DESIGN.md', 'design_styles').trim();
check('no shared component, DESIGN.md or design_styles change', !changed && !committed, changed || committed);

// 3. Tokens and honesty
const tsx = Object.entries(src).filter(([f]) => f.endsWith('.tsx') || f.endsWith('.ts'));
const hits = (re) => tsx.flatMap(([f, s]) => [...s.matchAll(re)].map((x) => `${f}: ${x[0]}`));
const hex = hits(/(?:className|style)=[^>]*#[0-9a-fA-F]{3,8}\b/g);
check('no hex colours in markup', hex.length === 0, hex.slice(0, 3).join(' | '));
const palette = hits(/\b(?:bg|text|border|ring|fill|stroke|from|to|via)-(?:red|green|blue|amber|yellow|gray|slate|zinc|neutral|stone|orange|emerald|teal|sky|indigo|violet|purple|pink|rose|lime|cyan|fuchsia|white|black)(?:-\d{2,3})?\b/g);
check('no raw palette classes', palette.length === 0, palette.slice(0, 3).join(' | '));
const dark = hits(/\bdark:[a-z]/g);
check('no dark: pairs', dark.length === 0, dark.slice(0, 3).join(' | '));
const css = src['styles.css'];
check('preview CSS uses tokens only', !/#[0-9a-fA-F]{3,8}\b|rgb\(|hsl\(/.test(css));
const dialogs = hits(/\bwindow\.(prompt|confirm|alert)\(|(?<![.\w])(prompt|confirm|alert)\(/g);
check('never a browser prompt/confirm/alert', dialogs.length === 0, dialogs.slice(0, 3).join(' | '));
const net = hits(/\bfetch\(|axios|XMLHttpRequest|router\.(visit|get|post)/g);
check('no network calls (synthetic only)', net.length === 0, net.slice(0, 3).join(' | '));
// design_styles/DESIGN_TOKENS.md:104-110 allows default, outline, ghost and link (Fleet uses link inside ReviewCards and
// ghost in section footers). Buttons are never restyled: only spacing classes may be added.
const variants = hits(/variant="(secondary)"/g);
check('buttons use default / outline / destructive / ghost / link only', variants.length === 0, variants.slice(0, 3).join(' | '));
const restyled = hits(/<Button[^>]*className="([^"]*)"/g).filter((h) => h.replace(/^.*className="|"$/g, '').split(/\s+/).some((c) => c && !/^-?(m|p)[trblxy]?-|^gap-|^self-|^shrink|^w-full$/.test(c)));
check('buttons are never restyled (spacing classes only)', restyled.length === 0, restyled.slice(0, 3).join(' | '));

console.log(fails ? `\n${fails} check(s) failed` : '\nAll checks passed');
process.exit(fails ? 1 : 0);
