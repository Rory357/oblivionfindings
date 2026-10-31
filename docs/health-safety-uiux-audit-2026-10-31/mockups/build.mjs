// Builds the one-page artifact from the audit docs + hand-written sections + mockup boards.
import fs from 'node:fs';
import path from 'node:path';

const HERE = path.dirname(new URL(import.meta.url).pathname).replace(/^\/([A-Z]:)/, '$1');
const DOCS = 'C:/Users/steph/Herd/oblivionfindings/.claude/worktrees/eager-mahavira-c57cd4/docs/health-safety-uiux-audit-2026-10-31';
const src = (f) => fs.readFileSync(path.join(HERE, 'src', f), 'utf8');

const SHOTS = Object.fromEntries(
  fs.readdirSync(path.join(DOCS, 'shots')).map((f) => [f.replace(/\.(png|jpg)$/, '').split('-')[0], f]),
);

function esc(s) { return s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;'); }

function inline(s) {
  s = esc(s);
  // screenshot references like `a07` or `a07b`
  s = s.replace(/`([amst]\d{2}b?)`/g, (m, id) => SHOTS[id]
    ? `<a class="shot-ref" href="#shot-${id}" data-shot="${id}">${id}</a>` : `<code>${id}</code>`);
  s = s.replace(/`([^`]+)`/g, '<code>$1</code>');
  s = s.replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>');
  s = s.replace(/(^|[\s(])\*([^*]+)\*/g, '$1<em>$2</em>');
  s = s.replace(/\[([^\]]+)\]\(([^)]+)\)/g, '<a href="$2">$1</a>');
  return s;
}

// Small Markdown → HTML: headings, paragraphs, nested bullet lists, numbered lists, tables, rules.
function md(text, idPrefix = '') {
  const lines = text.replace(/\r/g, '').split('\n');
  let out = '', i = 0;
  const slug = (t) => idPrefix + t.toLowerCase().replace(/<[^>]+>/g, '').replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '').slice(0, 48);
  while (i < lines.length) {
    let l = lines[i];
    if (/^\s*$/.test(l)) { i++; continue; }
    if (/^---+\s*$/.test(l)) { out += '<hr>'; i++; continue; }
    const h = l.match(/^(#{1,4})\s+(.*)$/);
    if (h) { const lv = h[1].length; const t = inline(h[2]); out += `<h${lv + 1} id="${slug(h[2])}">${t}</h${lv + 1}>`; i++; continue; }
    if (/^\|/.test(l)) {
      const rows = []; while (i < lines.length && /^\|/.test(lines[i])) rows.push(lines[i++]);
      const cells = (r) => r.replace(/^\||\|$/g, '').split(/(?<!\\)\|/).map((c) => c.trim());
      const head = cells(rows[0]); const body = rows.slice(2).map(cells);
      out += '<div class="tbl"><table><thead><tr>' + head.map((c) => `<th>${inline(c)}</th>`).join('') + '</tr></thead><tbody>'
        + body.map((r) => '<tr>' + r.map((c) => `<td>${inline(c)}</td>`).join('') + '</tr>').join('') + '</tbody></table></div>';
      continue;
    }
    if (/^\s*([-*]|\d+\.)\s+/.test(l)) {
      // list block (nested by indent)
      const block = []; while (i < lines.length && (/^\s*([-*]|\d+\.)\s+/.test(lines[i]) || /^\s{2,}\S/.test(lines[i]))) block.push(lines[i++]);
      out += listHtml(block);
      continue;
    }
    // paragraph
    const para = []; while (i < lines.length && !/^\s*$/.test(lines[i]) && !/^(#|\||---|\s*([-*]|\d+\.)\s)/.test(lines[i])) para.push(lines[i++].trim());
    out += `<p>${inline(para.join(' '))}</p>`;
  }
  return out;
}
function listHtml(block) {
  const items = []; // {indent, ordered, text}
  for (const l of block) {
    const m = l.match(/^(\s*)([-*]|\d+\.)\s+(.*)$/);
    if (m) items.push({ indent: m[1].length, ordered: /\d/.test(m[2]), text: m[3] });
    else if (items.length) items[items.length - 1].text += ' ' + l.trim();
  }
  let html = '', stack = [];
  for (const it of items) {
    while (stack.length && it.indent < stack[stack.length - 1].indent) { html += `</li></${stack.pop().tag}>`; }
    if (!stack.length || it.indent > stack[stack.length - 1].indent) { const tag = it.ordered ? 'ol' : 'ul'; stack.push({ indent: it.indent, tag }); html += `<${tag}><li>`; }
    else html += '</li><li>';
    html += inline(it.text);
  }
  while (stack.length) html += `</li></${stack.pop().tag}>`;
  return html;
}

const doc = (f) => fs.existsSync(path.join(DOCS, f)) ? fs.readFileSync(path.join(DOCS, f), 'utf8') : '';
const stripTitle = (t) => t.replace(/^# .*\n/, '');

let page = src('00-head.html');
page += src('10-summary.html');
page += `<section id="problems" class="doc"><div class="wrap">${src('20-problems-intro.html')}${md(stripTitle(doc('REPORT.md')).split(/\n## The detail, ranked\n/)[1].split(/\n## Workflow map/)[0], 'p-')}</div></section>`;
page += `<section id="workflows" class="doc"><div class="wrap"><p class="eyebrow">3 · Workflows</p><h2 class="sec">Each role, each core job</h2>${md(doc('REPORT.md').split(/\n## Workflow map — each role, each core job\n/)[1], 'w-')}</div></section>`;
page += `<section id="plan" class="doc"><div class="wrap"><p class="eyebrow">4 · Plan</p><h2 class="sec">The fix plan</h2>${md(stripTitle(doc('PLAN.md')), 'pl-')}</div></section>`;
page += `<section id="audit" class="doc"><div class="wrap"><p class="eyebrow">5 · Audit log</p><h2 class="sec">How the plan was audited</h2>${md(stripTitle(doc('AUDIT-LOG.md')), 'au-')}</div></section>`;
page += src('60-mockups.html');

const I = (n) => `<svg class="i"><use href="#i-${n}"/></svg>`;
const TOP = (who = 'DA') => `<div class="topbar"><div class="wordmark">Oblivion <span>Living</span></div><div class="datebit"><b>Saturday</b> 31 October 2026</div><div class="cmd">${I('search')} Search or jump to…</div><div class="tb-actions">${who === 'PN' ? '' : `<span class="tb-btn brand">${I('alert')} Report incident</span>`}<span class="tb-btn">${I('clock')} Clock in/out</span><span class="avatar">${who}</span></div></div>`;
const HS_SUBS = { full: ['Overview', 'Incidents and safety reviews', 'Injuries and care', 'Risks and prevention', 'People and participation', 'Reports'], sw: ['Incidents and safety reviews', 'Risks and prevention'] };
const SIDE = (variant, active) => {
  const top = ['Overview', 'Company Hub', variant === 'sw' ? 'My Day' : 'My Day', 'Chat', 'My Calendar', 'All Tasks'];
  const grps = variant === 'sw' ? ['Sites & Locations', 'Operations', 'Workforce', 'Meds today'] : ['Sites & Locations', 'Operations', 'Workforce', 'Medication', 'Health & Clinical'];
  let h = '<aside class="side">';
  for (const t of top) h += `<div class="it${active === t ? ' on' : ''}">${I(t === 'All Tasks' ? 'list' : t === 'My Day' ? 'check' : t === 'Overview' ? 'grid' : t === 'My Calendar' ? 'cal' : 'home')} ${t}${t === 'All Tasks' ? '<span class="count">9+</span>' : ''}</div>`;
  h += '<hr>';
  for (const g of grps) h += `<div class="it grp">${I('right')} ${g}</div>`;
  h += `<div class="it grp${active && HS_SUBS[variant === 'sw' ? 'sw' : 'full'].includes(active) ? '' : ''}">${I('shield')} Health &amp; Safety</div>`;
  for (const s of HS_SUBS[variant === 'sw' ? 'sw' : 'full']) h += `<div class="it sub${active === 'hs:' + s ? ' on' : ''}">${s}</div>`;
  for (const g of ['Fleet & Assets', 'IT & Support']) h += `<div class="it grp">${I('right')} ${g}</div>`;
  h += '</aside>';
  return h;
};
const tpl = (html) => html
  .replace(/\{\{TOP(?: (\w+))?\}\}/g, (m, w) => TOP(w))
  .replace(/\{\{SIDE (\w+) ([^}]+)\}\}/g, (m, v, a) => SIDE(v, a.trim()))
  .replace(/\{\{I (\w+)\}\}/g, (m, n) => I(n));
for (const f of fs.readdirSync(path.join(HERE, 'src', 'boards')).sort()) page += tpl(fs.readFileSync(path.join(HERE, 'src', 'boards', f), 'utf8'));
page += src('70-shots.html').replace('%%SHOTS%%', Object.entries(SHOTS).map(([id, f]) =>
  `<figure id="shot-${id}" class="shot"><img loading="lazy" src="shots/${f}" alt="Screenshot ${id}"><figcaption>${id} · ${f.replace(/^[a-z0-9]+-/, '').replace(/\.(png|jpg)$/, '').replace(/-/g, ' ')}</figcaption></figure>`).join(''));
page += src('90-tail.html');

fs.writeFileSync(path.join(HERE, 'index.html'), page);
fs.mkdirSync(path.join(HERE, 'shots'), { recursive: true });
for (const f of Object.values(SHOTS)) fs.copyFileSync(path.join(DOCS, 'shots', f), path.join(HERE, 'shots', f));
console.log('built', (page.length / 1024).toFixed(0) + ' KB', Object.keys(SHOTS).length, 'shots');

// Keep the repo copy of the mockup sources in step with what was built.
const MOCK = path.join(DOCS, 'mockups');
fs.mkdirSync(path.join(MOCK, 'src', 'boards'), { recursive: true });
for (const f of fs.readdirSync(path.join(HERE, 'src'))) if (f.endsWith('.html')) fs.copyFileSync(path.join(HERE, 'src', f), path.join(MOCK, 'src', f));
for (const f of fs.readdirSync(path.join(MOCK, 'src', 'boards'))) if (!fs.existsSync(path.join(HERE, 'src', 'boards', f))) fs.unlinkSync(path.join(MOCK, 'src', 'boards', f));
for (const f of fs.readdirSync(path.join(HERE, 'src', 'boards'))) fs.copyFileSync(path.join(HERE, 'src', 'boards', f), path.join(MOCK, 'src', 'boards', f));
for (const f of ['build.mjs', 'preview.sh', 'rep.mjs']) fs.copyFileSync(path.join(HERE, f), path.join(MOCK, f));
console.log('synced sources to', MOCK);
