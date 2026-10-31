// Builds the G2 Overview board: Stephan's header image as the reference, then M3 and M1 redrawn with that header.
const fs = require('fs');
const path = require('path');
const HERE = __dirname;
const SRC = 'C:/Users/steph/.claude/projects/C--Users-steph-Herd-oblivionfindings--claude-worktrees-objective-mendel-a6cccd/dd00b656-07db-4781-930c-f8bd2ee955e0/tool-results/artifact-2b446619-1793411681-44b6.html';
const M1 = 'C:/Users/steph/Herd/oblivionfindings/.claude/worktrees/objective-mendel-a6cccd/docs/health-safety-uiux-audit-2026-10-31/mockups/src/boards/01-m1.html';
const IMG = path.join(HERE, '..', 'stephan-overview.webp');

const src = fs.readFileSync(SRC, 'utf8').split('\n');
const css = fs.readFileSync(path.join(HERE, 'header-parts.html'), 'utf8').split('<!--CSS-->')[1].split('<!--/CSS-->')[0];
// Head: title, fonts, styles and icon sprite (lines 2..365 of the approved M3 board page).
let head = src.slice(1, 365).join('\n').replace('<title>M3 redraw for Q4</title>', '<title>H&amp;S Overview G2</title>');
head = head.replace('</style>', css + '\n</style>');
head = head.replace('</svg>', [
  '<symbol id="i-userclock" viewBox="0 0 24 24"><circle cx="9" cy="8" r="4"/><path d="M15 21a6 6 0 0 0-12 0"/><path d="M18 7v4l2 1"/></symbol>',
  '<symbol id="i-doc" viewBox="0 0 24 24"><rect x="5" y="3" width="14" height="18" rx="2"/><path d="M9 8h6M9 12h6M9 16h4"/></symbol>',
  '</svg>'].join('\n'));
head = head.replace(/^<body>.*$/m, '');
const script = src.join('\n').split('<dialog class="lightbox"')[1].split('<script>')[1].split('</script>')[0];

const I = (n) => `<svg class="i"><use href="#i-${n}"/></svg>`;
const spark = (hs, red) => `<div class="spark${red ? ' red' : ''}">${hs.map((h, i) => `<i${i === hs.length - 1 ? ' class="now"' : ''} style="height:${h}px"></i>`).join('')}</div>`;
const tile = ({ v, l, d, none, icon, alert, hs, red }) =>
  `<div class="kt${alert ? ' alert' : ''}"><span class="kv">${v}</span><div><div class="kl">${l}</div><div class="kd${none ? ' none' : ''}">${d}</div></div>${spark(hs, red)}<span class="kic">${I(icon)}</span></div>`;
const bars = (hs, cls = '', hot = []) => `<div class="bars ${cls}">${hs.map((h, i) => `<i${hot.includes(i) ? ' class="hot"' : ''} style="height:${h}%"></i>`).join('')}</div>`;
const SEV = [['Critical', 'oklch(0.66 0.21 25)'], ['High', 'oklch(0.74 0.16 55)'], ['Medium', 'oklch(0.84 0.15 90)'], ['Low', 'oklch(0.75 0.11 293)']];
const TYPES = ['oklch(0.75 0.11 293)', 'oklch(0.7 0.18 40)', 'oklch(0.84 0.15 90)', 'oklch(0.6 0.08 280)', 'oklch(0.8 0.12 70)'];
function charts({ sev, scope, overTime, overBars, due, dueBars, dueHot, types }) {
  const total = sev.reduce((a, b) => a + b, 0);
  const tt = types.reduce((a, [, n]) => a + n, 0);
  let acc = 0;
  const cone = types.map(([, n], i) => { const a = acc; acc += n / tt * 360; return `${TYPES[i]} ${a}deg ${acc}deg`; }).join(', ');
  return `<div class="c4">
    <div class="ct"><div class="chh">Incident severity <span>open · ${scope}</span></div>
      <div class="sev">${sev.map((n, i) => `<b style="flex:${n};background:${SEV[i][1]}"></b>`).join('')}</div>
      <div class="lg">${sev.map((n, i) => `<span><i style="background:${SEV[i][1]}"></i>${n} ${SEV[i][0]}</span>`).join('')}</div></div>
    <div class="ct"><div class="chh">Incidents over time <span>reported each day</span></div>
      <div class="two">${bars(overBars)}<div class="big">${overTime[0]}<small>reported in the<br>last 30 days</small><em>↓ ${overTime[1]}% on the month before</em></div></div></div>
    <div class="ct"><div class="chh">Actions due soon <span>each day ahead</span></div>
      <div class="two">${bars(dueBars, 'due', dueHot)}<div class="big">${due}<small>due in the<br>next 30 days</small></div></div></div>
    <div class="ct"><div class="chh">Top incident types <span>open</span></div>
      <div class="types"><div class="donut" style="background:conic-gradient(${cone})"></div><ul>${types.map(([t, n], i) => `<li><i style="background:${TYPES[i]}"></i>${t}<b>${n}</b></li>`).join('')}</ul></div></div>
  </div>`;
}
const rail = (sites, prev) => `<div class="rail2"><span class="tab on">Overview</span><span class="tab">Prevention <span class="cnt">${prev}</span></span><span class="tab">Harm and injuries</span><span class="tab">Legal duties</span>
  <div class="right"><span class="fchip">${I('cal')} Period: <b>last 30 days</b> ${I('down')}</span><span class="fchip">${sites} ${I('down')}</span><span class="find">${I('search')} Find</span></div></div>`;

// ── M3 (provider manager): the approved Q4 redraw's body, with Stephan's header ──
let m3 = src.join('\n');
m3 = m3.slice(m3.indexOf('<section class="board" id="m3">'));
m3 = m3.slice(0, m3.indexOf('</div></section>') + '</div></section>'.length);
const m3Header = [
  '<div class="k4">',
  tile({ v: 4, l: 'Incidents need an owner', d: '↓ 2 since 1 Oct', icon: 'userplus', alert: true, hs: [10, 14, 8, 18, 12, 22, 16, 12] }),
  tile({ v: 14, l: 'Open incidents', d: '↑ 3 since 1 Oct', icon: 'doc', hs: [12, 16, 10, 20, 24, 18, 26, 22] }),
  tile({ v: 5, l: 'Actions overdue', d: '↑ 1 since 1 Oct', icon: 'clock', alert: true, red: true, hs: [8, 14, 22, 10, 26, 18, 12, 16] }),
  tile({ v: 2, l: 'Open hazards', d: 'No change since 1 Oct', icon: 'alert', hs: [12, 18, 14, 22, 26, 16, 20, 18] }),
  '</div>',
  charts({ sev: [2, 4, 5, 3], scope: 'both sites', overTime: [11, 27], overBars: [0, 20, 0, 40, 20, 0, 0, 60, 20, 0, 40, 0, 20, 80, 0, 20, 0, 40, 20, 0, 100, 20, 0, 40], due: 8, dueBars: [20, 20, 40, 10, 10, 10, 90, 20, 30, 10, 40, 30], dueHot: [0, 1, 2, 6, 7, 8], types: [['Falls', 5], ['Medication', 4], ['Behaviour', 2], ['Environment', 1], ['Other', 2]] }),
  rail('Both sites', '3 due'),
].join('\n');
m3 = m3.replace(/<div class="nums">[\s\S]*?<div class="rail">[\s\S]*?<\/div>/, m3Header);
m3 = m3.replace('<span class="tag">M3 · package B2 · redrawn for Q4</span>', '<span class="tag">M3 · package B2 · G2 · your header</span>');
m3 = m3.replace(/<div class="board-notes">[\s\S]*?<\/div>\s*<div class="stage-frame" id="f-m3">/, `<div class="board-notes">
    <ul>
      <li><b>Header, as you drew it.</b> Four boxes: Incidents need an owner · Open incidents · Actions overdue · Open hazards, each with how it moved since 1 October and eight small bars (the same count at the end of each of the last 8 weeks). Under them, four charts: severity of open incidents, incidents reported each day, actions due each day for the next 30 days, and the top incident types.</li>
      <li>Every box and every chart counts only records Rāwiri can open at Kauri House and Rimu House. The restraint review at Kauri House isn't counted anywhere. Each box and each chart opens its filtered list.</li>
    </ul>
    <ul>
      <li>The page appears straight away with the queue; the boxes and charts fill in a moment later ("Counting…" bars while they load), so a slow chart never holds up the page.</li>
      <li>A box only shows a change when the records' own dates can show what the number was on 1 October. Where they can't yet, it says "No trend yet" instead of guessing.</li>
      <li>Body unchanged from the Q4 redraw: his working queue (WorkSafe decision, reviews that need an owner, incidents to assign, actions to check) with the buttons the server allows him.</li>
    </ul>
  </div>
  <div class="stage-frame" id="f-m3">`);
m3 = m3.replace('Provider manager (Rāwiri Ngata) · after Q4', 'Provider manager (Rāwiri Ngata) · after Q4 · G2 header');

// ── M1 (H&S officer): the approved board with the same header ──
let m1 = fs.readFileSync(M1, 'utf8');
const topM3 = src.join('\n').match(/<div class="topbar">[\s\S]*?<\/div><\/div>\n/)[0];
const sideM3 = src.join('\n').match(/<aside class="side">[\s\S]*?<\/aside>/)[0];
m1 = m1.replace('{{TOP DH}}', topM3.replace('<span class="avatar">RN</span>', '<span class="avatar">DH</span>'));
m1 = m1.replace('{{SIDE full hs:Overview}}', sideM3);
m1 = m1.replace(/\{\{I (\w+)\}\}/g, (_, n) => I(n));
const m1Header = [
  '<div class="k4">',
  tile({ v: 4, l: 'Need an owner', d: '↑ 1 since 1 Oct', icon: 'userplus', alert: true, hs: [12, 10, 14, 18, 12, 16, 20, 22] }),
  tile({ v: 3, l: 'WorkSafe: decide or tell', d: 'No trend yet', none: true, icon: 'flag', alert: true, red: true, hs: [4, 4, 4, 4, 4, 4, 4, 18] }),
  tile({ v: 8, l: 'Actions overdue', d: '↓ 3 since 1 Oct', icon: 'clock', alert: true, red: true, hs: [26, 22, 24, 18, 20, 16, 14, 14] }),
  tile({ v: 3, l: 'Actions to check', d: '↑ 2 since 1 Oct', icon: 'check', hs: [6, 8, 4, 10, 6, 8, 12, 16] }),
  '</div>',
  charts({ sev: [4, 9, 14, 11], scope: 'all sites', overTime: [9, 40], overBars: [20, 0, 40, 0, 0, 20, 60, 0, 20, 0, 0, 40, 20, 0, 0, 100, 0, 20, 0, 40, 0, 0, 20, 40], due: 11, dueBars: [30, 20, 10, 60, 10, 20, 40, 10, 90, 20, 30, 10], dueHot: [0, 1, 3, 8], types: [['Falls', 12], ['Medication', 10], ['Behaviour', 7], ['Environment', 5], ['Other', 4]] }),
  rail('All sites · 12', '7 due'),
].join('\n');
m1 = m1.replace(/<div class="nums">[\s\S]*?<div class="rail">[\s\S]*?<\/div>/, m1Header);
// The monthly bars card moves into the header's "Incidents over time".
m1 = m1.replace(/\s*<section class="surface">\s*<div class="sh"><h4>Incidents each month[\s\S]*?<\/section>/, '');
m1 = m1.replace('<span class="tag">M1 · package B2</span>', '<span class="tag">M1 · package B2 · G2 · your header</span>');
m1 = m1.replace(/<div class="board-notes">[\s\S]*?<\/div>\s*<div class="stage-frame" id="f-m1">/, `<div class="board-notes">
    <ul>
      <li><b>Same header for every role.</b> Dana's four boxes stay the approved queue numbers (Need an owner · WorkSafe: decide or tell · Actions overdue · Actions to check), drawn your way: big number, how it moved since 1 October, eight weekly bars, icon. The four charts are the same as on M3, across all 12 sites.</li>
      <li>"WorkSafe: decide or tell" shows <i>No trend yet</i>: the app only started keeping dated WorkSafe decisions recently, so it can't say what the number was on 1 October. The bars show only the weeks it does know.</li>
    </ul>
    <ul>
      <li>The "Incidents each month" card leaves the right column: "Incidents over time" in the header replaces it.</li>
      <li>Everything else is the approved M1: the queue blocks with WorkSafe first, "Where safety reviews are up to", days since a lost-time injury, checks due and sites.</li>
    </ul>
  </div>
  <div class="stage-frame" id="f-m1">`);

const img = 'data:image/webp;base64,' + fs.readFileSync(IMG).toString('base64');
const intro = `<section class="doc"><div class="wrap">
<p class="eyebrow">Health and safety · G2 (package B2)</p>
<h2 class="sec">Your Overview header, on every role's Overview</h2>
<p>You sent this header and said it feels right. G2 builds it. The two boards below show it on the provider manager's Overview (M3, the page you drew) and on the health and safety officer's (M1). Every role gets the same header with its own four numbers; nothing is built until you approve.</p>
<figure class="shot" style="margin-top:16px"><img src="${img}" alt="Stephan's Overview header: four count boxes with trends and small bars, four charts, tabs with Period and site filters"><figcaption>Your design, 31 October</figcaption></figure>
<div class="tbl"><table><thead><tr><th>Who</th><th>Their four boxes</th><th>Charts</th></tr></thead><tbody>
<tr><td>Admin, health and safety officer</td><td>Need an owner · WorkSafe: decide or tell · Actions overdue · Actions to check</td><td>The four incident charts, all sites</td></tr>
<tr><td>Team lead</td><td>The same four, at their sites</td><td>The four incident charts, their sites</td></tr>
<tr><td>Provider manager</td><td>Incidents need an owner · Open incidents · Actions overdue · Open hazards (as you drew it)</td><td>The four incident charts, their sites</td></tr>
<tr><td>Compliance lead</td><td>Close-anyway requests · WorkSafe: decide or tell · Actions overdue · Open safety reviews</td><td>The four incident charts, all sites</td></tr>
<tr><td>Maintenance coordinator</td><td>Open hazards · Checks due · Actions overdue · Drills due</td><td>She can't open incidents, so she gets hazard and check charts instead: open hazards by risk, checks due each day, hazards added each day, open hazards by kind</td></tr>
</tbody></table></div>
<p><strong>Rules for every number:</strong> it counts only records the person can open (the same checks as the lists, so a box never hints at something hidden); the boxes and charts load just after the page; a change is shown only when the records' own dates can prove it; every box and chart opens its list.</p>
</div></section>`;

const html = head + '\n' + intro + '\n' + m3 + '\n' + m1 + '\n<dialog class="lightbox" id="lb"><img alt=""><p></p></dialog>\n<script>' + script + '</script>\n';
fs.writeFileSync(path.join(HERE, 'hs-overview-g2.html'), html);
console.log('ok', html.length);
