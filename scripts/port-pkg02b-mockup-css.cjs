// Port the approved PKG-02B v13 stylesheets into one file scoped to .vehicle-studio.
// Usage: node scripts/port-pkg02b-mockup-css.cjs "$(pwd)"
const fs = require('fs');
const path = require('path');

const root = process.argv[2];
const src = path.join(root, 'docs/fleet-assets-audit/previews/PKG-02B/v13');
const out = path.join(root, 'resources/js/components/fleet-assets/vehicle-workspace/mockup-port.css');
const agentFiles = ['trips.css', 'finance.css'].map((f) =>
    path.join(root, 'resources/js/components/fleet-assets/vehicle-workspace', f),
);

// Sources in the design's cascade order (main.tsx imports).
const SOURCES = ['styles.css', 'studio.css', 'enhancements.css', 'telemetry.css', 'collection-view.css'];

// The design's own app shell and preview chrome: never ported.
const SHELL = [
    /^html\b/, /^body\b/, /^:root\b/, /^\*/, /^button\b/, /^select\b/, /^textarea\b/, /^input\b/,
    /^\.chrome\b/, /^\.chrome-/, /^\.sidebar\b/, /^\.sidebar-/, /^\.workspace\b/, /^\.main\b/,
    /^\.breadcrumbs\b/, /^\.wordmark\b/, /^\.avatar\b/, /^\.module-label\b/, /^\.nav-dot\b/,
    /^\.settings-link\b/, /^\.collapsed\b/, /^\.page-footer\b/, /^\.preview-bar\b/, /^\.preview-label\b/,
    /^\.op-toast\b/, /^\.denied\b/, /^\.fixture-note\b/, /^\.telemetry-preview-bar\b/, /^\.content$/,
    /^#/, /^\.preview-dialog\b/, /^\.modal-icon\b/, /^\.dialog-body\b/,
    // Element-level focus styling stays with the app; the design's page root is shell.
    /^a\b/, /^\.readable-records/,
];

// Hex colours in the design, replaced with semantic tokens.
const HEX = {
    '#0002': 'color-mix(in oklch, var(--foreground) 13%, transparent)',
    '#0003': 'color-mix(in oklch, var(--foreground) 19%, transparent)',
    '#00000015': 'color-mix(in oklch, var(--foreground) 8%, transparent)',
    '#99620a': 'var(--status-warning)',
    '#fef0cc': 'var(--status-warning-bg)',
    '#b4233a': 'var(--status-critical)',
    '#ffe5eb': 'var(--status-critical-bg)',
    '#0004': 'color-mix(in oklch, var(--foreground) 25%, transparent)',
    '#7555d9': 'var(--primary)',
    '#6342ca': 'var(--primary)',
    '#34216c': 'color-mix(in oklch, var(--primary) 70%, var(--foreground))',
    '#fff': 'var(--card)',
    '#ffffff': 'var(--card)',
    '#000': 'var(--foreground)',
};

function stripComments(css) {
    return css.replace(/\/\*[\s\S]*?\*\//g, '');
}

// Minimal block parser: returns [{ prelude, body }] where body is either a string
// (declarations) or nested rules for at-rules with blocks.
function parse(css) {
    const rules = [];
    let i = 0;
    while (i < css.length) {
        const open = css.indexOf('{', i);
        if (open === -1) break;
        const prelude = css.slice(i, open).trim();
        let depth = 1;
        let j = open + 1;
        while (j < css.length && depth > 0) {
            if (css[j] === '{') depth++;
            else if (css[j] === '}') depth--;
            j++;
        }
        const inner = css.slice(open + 1, j - 1);
        if (/^@(media|supports|container)\b/.test(prelude)) {
            rules.push({ prelude, children: parse(inner) });
        } else {
            rules.push({ prelude, body: inner.trim() });
        }
        i = j;
    }
    return rules;
}

function agentSelectors() {
    const set = new Set();
    for (const file of agentFiles) {
        if (!fs.existsSync(file)) continue;
        const walk = (rules) => rules.forEach((r) => {
            if (r.children) return walk(r.children);
            r.prelude.split(',').map((s) => s.trim()).forEach((s) => set.add(s));
        });
        walk(parse(stripComments(fs.readFileSync(file, 'utf8'))));
    }
    return set;
}

const taken = agentSelectors();

function scope(selector) {
    let s = selector.trim().replace(/\s+/g, ' ');
    // The design's page wrappers become the vehicle studio root.
    s = s.replace(/^\.readable-records\s+/, '').replace(/^\.content\s*>\s*/, '').replace(/^\.content\s+/, '');
    if (s === '.readable-records') return null;
    let dark = false;
    if (/^\.dark\s+/.test(s)) {
        dark = true;
        s = s.replace(/^\.dark\s+/, '');
    }
    if (SHELL.some((re) => re.test(s))) return null;
    const scoped = `.vehicle-studio ${s}`;
    if (taken.has(scoped)) return null;
    return dark ? `.dark ${scoped}` : scoped;
}

function tokens(body) {
    return body.replace(/#[0-9a-fA-F]{3,8}\b/g, (hex) => {
        const key = hex.toLowerCase();
        if (HEX[key]) return HEX[key];
        throw new Error(`Unmapped colour ${hex}`);
    });
}

function emit(rules, indent = '') {
    const outLines = [];
    for (const rule of rules) {
        if (rule.children) {
            const inner = emit(rule.children, indent + '    ');
            if (inner.trim()) outLines.push(`${indent}${rule.prelude} {\n${inner}${indent}}`);
            continue;
        }
        if (/^@keyframes\b/.test(rule.prelude)) {
            const name = rule.prelude.replace(/^@keyframes\s+/, '');
            outLines.push(`${indent}@keyframes vehicle-${name} {\n${indent}    ${tokens(rule.body).replace(/\n\s*/g, `\n${indent}    `)}\n${indent}}`);
            continue;
        }
        if (/^@/.test(rule.prelude)) continue;
        const selectors = rule.prelude.split(',').map(scope).filter(Boolean);
        if (!selectors.length) continue;
        const body = tokens(rule.body)
            .split(';')
            .map((d) => d.trim())
            .filter(Boolean)
            .map((d) => `${indent}    ${d};`)
            .join('\n');
        outLines.push(`${indent}${selectors.join(`,\n${indent}`)} {\n${body}\n${indent}}`);
    }
    return outLines.join('\n') + (outLines.length ? '\n' : '');
}

let keyframeNames = [];
let output = `/*
 * Generated port of the approved PKG-02B v13 design stylesheets
 * (docs/fleet-assets-audit/previews/PKG-02B/v13: ${SOURCES.join(', ')}),
 * scoped to .vehicle-studio. The design's own app shell is not ported, and
 * selectors already ported by trips.css and finance.css are left to them.
 * Colours are semantic tokens. Regenerate with scripts/port-pkg02b-mockup-css.cjs
 * rather than hand-editing; put
 * product adjustments in studio.css, which loads after this file.
 */
`;
for (const file of SOURCES) {
    const css = stripComments(fs.readFileSync(path.join(src, file), 'utf8'));
    const rules = parse(css);
    rules.forEach((r) => {
        if (/^@keyframes\b/.test(r.prelude)) keyframeNames.push(r.prelude.replace(/^@keyframes\s+/, ''));
    });
    output += `\n/* ── ${file} ── */\n` + emit(rules);
}
// Animations reference the renamed keyframes.
for (const name of keyframeNames) {
    output = output.replace(new RegExp(`(animation(?:-name)?\\s*:[^;]*?)\\b${name}\\b`, 'g'), `$1vehicle-${name}`);
}
fs.writeFileSync(out, output);
console.log(`wrote ${out}: ${output.split('\n').length} lines, keyframes: ${keyframeNames.join(', ') || 'none'}, skipped agent selectors: ${taken.size}`);
