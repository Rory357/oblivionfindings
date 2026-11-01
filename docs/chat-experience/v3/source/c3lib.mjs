// Chat v3 board helpers. Imports the design session's chat helpers read-only and writes only into chat3/.
// Run with cwd = the design scratchpad so lib.mjs can read canvas/project/*.dc.html.
import { writeFileSync, readFileSync } from 'node:fs';
const DS = 'file:///C:/Users/steph/AppData/Local/Temp/claude/C--Users-steph-Herd-oblivionfindings--claude-worktrees-oblivion-colors-design-5ff161/d677bdbc-7298-4571-8731-e52cdcdf60ea/scratchpad/';
const L = await import(DS + 'chat_work/lib.mjs');
export const OUT = 'C:/Users/steph/AppData/Local/Temp/claude/C--Users-steph-Herd-oblivionfindings--claude-worktrees-zealous-ellis-c573d4/18533ef8-533b-4719-967d-e5b48eb35162/scratchpad/chat3/';

export const { P, I, top, side, crumbs, familyLock, islands } = L;

// people who appear on the .com demo as well (avatar colours reuse lib.mjs's allowed hexes)
P.tane = ['TW', '#3e6aa8', 'Tane Wineera', 'Person supported · Kōwhai House'];
P.kiri = ['KW', '#8a6d2f', 'Kiri Wineera', 'Whānau · Mum'];
P.aroha = ['AN', '#3f7f6a', 'Aroha Ngata', 'Person supported · Kōwhai House'];
P.ana = ['AR', '#a0563f', 'Ana Rēweti', 'Support worker'];

// extra lucide-shaped glyphs (24×24, stroke 2)
Object.assign(I, {
    video: '<path d="m16 13 5.223 3.482a.5.5 0 0 0 .777-.416V7.87a.5.5 0 0 0-.752-.432L16 10.5"/><rect x="2" y="6" width="14" height="12" rx="2"/>',
    videooff: '<path d="M10.66 6H14a2 2 0 0 1 2 2v2.5l5.248-3.062A.5.5 0 0 1 22 7.87v8.196"/><path d="M16 16a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h2"/><path d="m2 2 20 20"/>',
    share: '<path d="M13 3H4a2 2 0 0 0-2 2v10a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-3"/><path d="M8 21h8M12 17v4"/><path d="m17 8 5-5M17 3h5v5"/>',
    hand: '<path d="M18 11V6a2 2 0 0 0-4 0"/><path d="M14 10V4a2 2 0 0 0-4 0v2"/><path d="M10 10.5V6a2 2 0 0 0-4 0v8"/><path d="M18 8a2 2 0 1 1 4 0v6a8 8 0 0 1-8 8h-2c-2.8 0-4.5-.86-5.99-2.34l-3.6-3.6a2 2 0 0 1 2.83-2.82L7 15"/>',
    bold: '<path d="M6 12h9a4 4 0 0 1 0 8H7a1 1 0 0 1-1-1V5a1 1 0 0 1 1-1h7a4 4 0 0 1 0 8"/>',
    italic: '<path d="M19 4h-9M14 20H5M15 4 9 20"/>',
    underline: '<path d="M6 4v6a6 6 0 0 0 12 0V4M4 20h16"/>',
    strike: '<path d="M16 4H9a3 3 0 0 0-2.83 4M14 12a4 4 0 0 1 0 8H6M4 12h16"/>',
    list: '<path d="M3 12h.01M3 18h.01M3 6h.01M8 12h13M8 18h13M8 6h13"/>',
    listord: '<path d="M10 12h11M10 18h11M10 6h11M4 10h2M4 6h1v4M6 18H4c0-1 2-2 2-3s-1-1.5-2-1"/>',
    quote: '<path d="M16 3a2 2 0 0 0-2 2v6a2 2 0 0 0 2 2 1 1 0 0 1 1 1v1a2 2 0 0 1-2 2 1 1 0 0 0-1 1v2a1 1 0 0 0 1 1 6 6 0 0 0 6-6V5a2 2 0 0 0-2-2z"/><path d="M5 3a2 2 0 0 0-2 2v6a2 2 0 0 0 2 2 1 1 0 0 1 1 1v1a2 2 0 0 1-2 2 1 1 0 0 0-1 1v2a1 1 0 0 0 1 1 6 6 0 0 0 6-6V5a2 2 0 0 0-2-2z"/>',
    code: '<path d="m16 18 6-6-6-6M8 6l-6 6 6 6"/>',
    camera: '<path d="M14.5 4h-5L7 7H4a2 2 0 0 0-2 2v9a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2V9a2 2 0 0 0-2-2h-3l-2.5-3z"/><circle cx="12" cy="13" r="3"/>',
    poll: '<path d="M3 3v16a2 2 0 0 0 2 2h16"/><path d="M18 17V9M13 17V5M8 17v-3"/>',
    sticker: '<path d="M15.5 3H5a2 2 0 0 0-2 2v14c0 1.1.9 2 2 2h14a2 2 0 0 0 2-2V8.5L15.5 3Z"/><path d="M15 3v4a2 2 0 0 0 2 2h4"/><path d="M8 13h.01M16 13h.01M10 16s.8 1 2 1c1.3 0 2-1 2-1"/>',
    aa: '<path d="M21 14h-5M16 16v-3.5a2.5 2.5 0 0 1 5 0V16M4.5 13h6M3 16l4.5-9 4.5 9"/>',
    leaf: '<path d="M11 20A7 7 0 0 1 9.8 6.1C15.5 5 17 4.48 19 2c1 2 2 4.18 2 8 0 5.5-4.78 10-10 10Z"/><path d="M2 21c0-3 1.85-5.36 5.08-6C9.5 14.52 12 13 13 12"/>',
    utensils: '<path d="M3 2v7c0 1.1.9 2 2 2h4a2 2 0 0 0 2-2V2M7 2v20M21 15V2a5 5 0 0 0-5 5v6c0 1.1.9 2 2 2h3Zm0 0v7"/>',
    car: '<path d="M19 17h2c.6 0 1-.4 1-1v-3c0-.9-.7-1.7-1.5-1.9C18.7 10.6 16 10 16 10s-1.3-1.4-2.2-2.3c-.5-.4-1.1-.7-1.8-.7H5c-.6 0-1.1.4-1.4.9l-1.4 2.9A3.7 3.7 0 0 0 2 12v4c0 .6.4 1 1 1h2"/><circle cx="7" cy="17" r="2"/><path d="M9 17h6"/><circle cx="17" cy="17" r="2"/>',
    bulb: '<path d="M15 14c.2-1 .7-1.7 1.5-2.5 1-.9 1.5-2.2 1.5-3.5A6 6 0 0 0 6 8c0 1 .2 2.2 1.5 3.5.7.7 1.3 1.5 1.5 2.5M9 18h6M10 22h4"/>',
    fwd: '<path d="m15 17 5-5-5-5"/><path d="M4 18v-2a4 4 0 0 1 4-4h12"/>',
    copy: '<rect width="14" height="14" x="8" y="8" rx="2"/><path d="M4 16c-1.1 0-2-.9-2-2V4c0-1.1.9-2 2-2h10c1.1 0 2 .9 2 2"/>',
    thread: '<path d="M14 9a2 2 0 0 1-2 2H6l-4 4V4a2 2 0 0 1 2-2h8a2 2 0 0 1 2 2z"/><path d="M18 9h2a2 2 0 0 1 2 2v11l-4-4h-6a2 2 0 0 1-2-2v-1"/>',
    smileplus: '<path d="M22 11v1a10 10 0 1 1-9-10"/><path d="M8 14s1.5 2 4 2 4-2 4-2M9 9h.01M15 9h.01M16 5h6M19 2v6"/>',
    turn: '<path d="m16 3 4 4-4 4M20 7H4M8 21l-4-4 4-4M4 17h16"/>',
    map: '<path d="M14.106 5.553a2 2 0 0 0 1.788 0l3.659-1.83A1 1 0 0 1 21 4.619v12.764a1 1 0 0 1-.553.894l-4.553 2.277a2 2 0 0 1-1.788 0l-4.212-2.106a2 2 0 0 0-1.788 0l-3.659 1.83A1 1 0 0 1 3 19.381V6.618a1 1 0 0 1 .553-.894l4.553-2.277a2 2 0 0 1 1.788 0z"/><path d="M15 5.764v15M9 3.236v15"/>',
    truck: '<path d="M14 18V6a2 2 0 0 0-2-2H4a2 2 0 0 0-2 2v11a1 1 0 0 0 1 1h2"/><path d="M15 18H9"/><path d="M19 18h2a1 1 0 0 0 1-1v-3.65a1 1 0 0 0-.22-.624l-3.48-4.35A1 1 0 0 0 17.52 8H14"/><circle cx="17" cy="18" r="2"/><circle cx="7" cy="18" r="2"/>',
    wrench: '<path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"/>',
    sun: '<circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2m-7.07-14.07 1.41 1.41m11.32 11.32 1.41 1.41M2 12h2m16 0h2M6.34 17.66l-1.41 1.41M19.07 4.93l-1.41 1.41"/>',
    usercheck: '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="m16 11 2 2 4-4"/>',
    calplus: '<path d="M8 2v4M16 2v4M21 13V6a2 2 0 0 0-2-2H5a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h8M3 10h18M16 19h6M19 16v6"/>',
    eyeoff: '<path d="M10.733 5.076a10.744 10.744 0 0 1 11.205 6.575 1 1 0 0 1 0 .696 10.747 10.747 0 0 1-1.444 2.49M14.084 14.158a3 3 0 0 1-4.242-4.242M17.479 17.499a10.75 10.75 0 0 1-15.417-5.151 1 1 0 0 1 0-.696 10.75 10.75 0 0 1 4.446-5.143M2 2l20 20"/>',
    checksq: '<rect x="3" y="3" width="18" height="18" rx="2"/><path d="m9 12 2 2 4-4"/>',
    arrdown: '<path d="M12 5v14M19 12l-7 7-7-7"/>',
    chevup: '<path d="m18 15-6-6-6 6"/>',
    chevl: '<path d="m15 18-6-6 6-6"/>',
    phoneout: '<path d="M16 2h6v6M22 2l-6 6"/><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.127.96.361 1.903.7 2.81a2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.907.339 1.85.573 2.81.7A2 2 0 0 1 22 16.92z"/>',
    door: '<path d="M18 20V6a2 2 0 0 0-2-2H8a2 2 0 0 0-2 2v14M2 20h20M14 12v.01"/>',
    shieldalert: '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><path d="M12 8v4M12 16h.01"/>',
    sparkle: '<path d="M12 3v3M12 18v3M3 12h3M18 12h3M5.6 5.6l2.1 2.1M16.3 16.3l2.1 2.1M5.6 18.4l2.1-2.1M16.3 7.7l2.1-2.1"/>',
    sendclock: '<path d="M22 2 11 13"/><path d="M22 2 15 22l-4-9-9-4Z"/><circle cx="18" cy="18" r="4" fill="var(--card)"/><path d="M18 16.5V18l1 1"/>',
    reply2: '<path d="m15 17 5-5-5-5"/><path d="M4 18v-2a4 4 0 0 1 4-4h12"/>',
    grid: '<rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/>',
});

export function svg(k, s = 16, cls = '') {
    if (!I[k]) throw new Error('no icon ' + k);
    const w = k === 'kebab' || k === 'more' ? 2.4 : 2;
    const fill = k === 'play' ? 'currentColor' : 'none';
    return `<svg${cls ? ` class="${cls}"` : ''} width="${s}" height="${s}" viewBox="0 0 24 24" fill="${fill}" stroke="currentColor" stroke-width="${w}" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">${I[k]}</svg>`;
}
export const nm = (who) => P[who][2];
export const first = (who) => P[who][2].split(' ')[0];
export const av = (who, size = '', state = '') => `<span class="ch-av${size ? ' ' + size : ''}${state ? ' ' + state : ''}" style="background: ${P[who][1]}" aria-hidden="true">${P[who][0]}</span>`;
export const gl = (icon, s = '', label = '') => `<span class="c3-gl${s ? ' ' + s : ''}"${label ? ` aria-label="${label}"` : ' aria-hidden="true"'}>${svg(icon, s === 's32' ? 15 : 17)}</span>`;
export const btn = (label, kind = 'o', o = {}) =>
    `<button class="oc-btn ${kind}${o.sm ? ' sm' : ''}${o.xs ? ' xs' : ''}${o.dis ? ' dis' : ''}${o.cls ? ' ' + o.cls : ''}" type="button"${o.aria ? ` aria-label="${o.aria}"` : ''}${o.style ? ` style="${o.style}"` : ''}>${o.icon ? svg(o.icon, o.sm || o.xs ? 14 : 15) : ''}${label}${o.after ? svg(o.after, 14) : ''}</button>`;
export const ib = (icon, label, cls = '', s = 17) => `<button class="ch-ib${cls ? ' ' + cls : ''}" type="button" aria-label="${label}">${svg(icon, s)}</button>`;
export const lens = (icon, cls = '', s = 15) => `<span class="ch-lens${cls ? ' ' + cls : ''}">${svg(icon, s)}</span>`;
export const badge = (t, tone = 'neutral') => `<span class="oc-badge ${tone}">${t}</span>`;
export const e = (ch, cls = '') => `<span class="c3-e${cls ? ' ' + cls : ''}">${ch}</span>`;
export const cap = (t) => `<span class="ch-cap">${t}</span>`;
export const col = (label, inner, style = '') => `<div class="ch-col"${style ? ` style="${style}"` : ''}>${cap(label)}${inner}</div>`;
export const sec = (title, sub, inner) => `<section class="ch-sec"><h2>${title}<small>${sub}</small></h2>${inner}</section>`;
export const note = (text, tone = '', icon = 'info') => `<div class="ch-note${tone ? ' ' + tone : ''}">${svg(icon, 15)}<div>${text}</div></div>`;
export const field = (label, inner, hint = '') => `<div class="oc-field"><span class="oc-lbl">${label}</span>${inner}${hint ? `<span class="oc-hint">${hint}</span>` : ''}</div>`;
export const input = (val, cls = '') => `<div class="oc-input${cls ? ' ' + cls : ''}">${val}</div>`;
export const sel = (val, cls = '') => `<button class="ch-sel${cls ? ' ' + cls : ''}" type="button" aria-haspopup="listbox">${val}${svg('chev', 15, 'chev')}</button>`;
export const box = (on = false, label = '', sub = '') => `<label class="ch-chk"><span class="oc-box${on ? ' on' : ''}" aria-hidden="true">${on ? svg('check', 12) : ''}</span><span>${label}${sub ? `<small>${sub}</small>` : ''}</span></label>`;

// ---------- delivery rings + reactions ----------
const DR = { sending: 'Sending', sent: 'Sent', dlv: 'Delivered', read: 'Read', fail: 'Couldn’t send' };
export const dr = (st, label = DR[st]) => `<span class="c3-dr ${st}" aria-hidden="true"></span><span class="${st === 'read' ? 'read' : st === 'fail' ? 'fail' : ''}">${label}</span>`;
export const rc = (emoji, n, me = false, who = '') => `<button class="c3-rc${me ? ' me' : ''}" type="button" aria-label="${n} reacted ${emoji}${who ? ': ' + who : ''}${me ? ', including you' : ''}">${e(emoji)}${n}</button>`;
export const rx = (...list) => `<div class="c3-rx">${list.join('')}<button class="c3-rc add" type="button" aria-label="Add a reaction">${svg('smileplus', 14)}</button></div>`;

// ---------- message groups ----------
// bubble(s): array of strings (html) or {html, cls}; first/last/single classes assigned automatically
export function group({ who, own = false, time = '', role = '', bubbles = [], after = '', meta = '', hbar = '', avatar = true, icon = '', name = '' }) {
    const n = bubbles.length;
    const bs = bubbles.map((b, i) => {
        const o = typeof b === 'string' ? { html: b } : b;
        const pos = n === 1 ? 's' : i === 0 ? 'f' : i === n - 1 ? 'l' : '';
        if (o.raw) return `<div class="c3-bw">${o.raw}${i === 0 ? hbar : ''}</div>`;
        return `<div class="c3-bw"><div class="c3-b${pos ? ' ' + pos : ''}${o.cls ? ' ' + o.cls : ''}">${o.html}</div>${i === 0 ? hbar : ''}</div>`;
    }).join('');
    const head = own ? '' : `<div class="c3-gh"><b>${name || nm(who)}</b>${role ? `<span class="rl">${role}</span>` : ''}<span>${time}</span></div>`;
    const lead = own || !avatar ? '' : icon ? gl(icon, 's32') : av(who, 's32');
    const m = meta || (own && time ? `<div class="c3-mt"><span>${time}</span></div>` : '');
    return `<article class="c3-g${own ? ' own' : ''}" aria-label="Messages from ${own ? 'you' : name || nm(who)}">${lead}<div class="c3-gb">${head}${bs}${after}${m}</div></article>`;
}
export const ownMeta = (time, st, extra = '') => `<div class="c3-mt"><span>${time}</span><span class="sp"></span>${dr(st)}${extra}</div>`;
export const day = (t) => `<div class="c3-day">${t}</div>`;
export const unreadLine = (t) => `<div class="c3-new">${t}</div>`;
export const typing = (who) => `<div class="c3-typing">${av(who, 's28')}<span class="c3-dots" aria-hidden="true"><i></i><i></i><i></i></span><span>${first(who)} is typing…</span></div>`;
export const jump = (n) => `<div class="c3-pillrow"><button class="c3-jump" type="button">${svg('arrdown', 14)}Jump to latest<span class="ch-n">${n}</span></button></div>`;

// gallery / media
export const gallery = (cap = '', more = 2) => `<div class="c3-gal" role="group" aria-label="6 photos">${['p1', 'p2', 'p3', 'p4'].map((p, i) => `<div class="c3-ph ${p}" role="img" aria-label="${['Garden beds', 'Lunch Tane made', 'Kitchen bench', 'Painting'][i]}">${i === 3 && more ? `<span class="more">+${more}</span>` : ''}</div>`).join('')}${cap ? `<div class="cap">${cap}</div>` : ''}</div>`;
export const wave = (n = 34, lit = 12, seed = 3) => `<span class="ch-wave" aria-hidden="true">${Array.from({ length: n }, (_, i) => { const h = 5 + Math.round(Math.abs(Math.sin(i * 1.7 + seed) * 15 + Math.sin(i * 0.6) * 4)); return `<i${i < lit ? ' class="lit"' : ''} style="height: ${Math.min(24, h)}px"></i>`; }).join('')}</span>`;
export const voice = (t = '0:12 / 0:42', lit = 10) => `<div class="ch-voice"><button class="pl" type="button" aria-label="Play voice note">${svg('play', 14)}</button>${wave(32, lit)}<span class="tm">${t}</span><button class="spd" type="button" aria-label="Playback speed 1×">1×</button></div>`;
export const link = (t) => `<a class="c3-a" href="#link">${t}</a>`;
// outside link preview card (on by default); own = sender sees “Remove preview”
export const preview = ({ site, title, desc, ph = 'p1', own = false }) => `<a class="c3-lp" href="#link" aria-label="Link preview: ${title}"><span style="min-width: 0"><span class="s">${site}</span><b>${title}</b><small>${desc}</small></span><span class="c3-ph ${ph}" aria-hidden="true"></span>${own ? `<button class="rm" type="button" aria-label="Remove preview">${svg('x', 12)}</button>` : ''}</a>`;

// care card
export function card({ icon, md, title, refn = '', facts, badgeT = '', tone = 'neutral', action = 'Open', extra = '', lock = false, m = '', style = '' }) {
    return `<div class="c3-cc${lock ? ' lock' : ''}"${style ? ` style="${style}"` : ''}>${lens(icon, lock ? 'mut' : '', 17)}<div class="hd"><span class="md">${md}</span>${badgeT ? badge(badgeT, tone) : ''}</div><div><b>${title}${refn ? `<span class="ref">${refn}</span>` : ''}</b><small>${facts}</small>${extra}</div>${action || m ? `<div class="ft">${action ? btn(action, 'o', { xs: true, after: lock ? '' : 'chevr' }) : ''}${m ? `<span class="m">${m}</span>` : ''}</div>` : ''}</div>`;
}

// ---------- composer ----------
export function composer({ text = '', ph = 'Write a message…', focus = false, hint = true, aud = 'Only conversation members', above = '', mode = 'auto', replace = '', send = '' } = {}) {
    const has = !!text;
    const right = mode === 'none' ? '' : send || (has ? `<button class="c3-round p" type="button" aria-label="Send">${svg('send', 18)}</button>` : `<button class="c3-round o" type="button" aria-label="Record a voice note">${svg('mic', 19)}</button>`);
    const fieldHtml = replace || `<div class="c3-field${focus ? ' focus' : ''}"><div class="tx${has ? ' has' : ''}" role="textbox" aria-multiline="true" aria-label="Write a message">${text || ph}${focus && has ? '<span class="caret"></span>' : ''}</div>${ib('smile', 'Emoji', '', 18)}${ib('sticker', 'Stickers and GIFs', '', 18)}${ib('aa', 'Show formatting', '', 18)}</div>`;
    return `<div class="c3-comp">${above}<div class="c3-cin"><button class="c3-round o" type="button" aria-label="Attach">${svg('plus', 19)}</button>${fieldHtml}${right}</div>${hint ? `<div class="c3-hint"><span>Enter to send · Shift + Enter for a new line</span><span class="r">${svg('lock', 12)}${aud}</span></div>` : ''}</div>`;
}

// ---------- conversation rail ----------
export function crow({ who, icon, name, tag = '', time, prev, unread = 0, on = false, hov = false, state = '', mention = false, muted = false, draft = '', pinned = false }) {
    const lead = who ? av(who, '', state) : gl(icon);
    const tx = draft ? `<em>Draft:</em> ${draft}` : prev;
    const fl = [pinned ? svg('pin', 13) : '', muted ? `<span aria-label="Muted">${svg('belloff', 13)}</span>` : '', mention ? `<span class="c3-at" aria-label="You were mentioned">${svg('at', 11)}</span>` : '', unread ? `<span class="ch-n" aria-label="${unread} unread">${unread}</span>` : ''].join('');
    return `<a class="c3-row${on ? ' on' : ''}${hov ? ' hov' : ''}${unread ? ' unread' : ''}" href="#c"${on ? ' aria-current="true"' : ''}>${lead}<span style="min-width: 0"><span class="l1"><b>${name}</b>${tag ? `<span class="tg">${tag}</span>` : ''}<time>${time}</time></span><span class="l2"><span class="tx">${tx}</span>${fl ? `<span class="fl">${fl}</span>` : ''}</span></span></a>`;
}
export const csh = (t, c = '') => `<div class="c3-sh">${svg('chev', 12)}${t}${c ? `<span class="c">${c}</span>` : ''}</div>`;
export function rail({ chips = ['All', 'Unread 4', 'Mentions 1', 'Groups', 'Channels', 'Family'], on = 'All', body, foot = true } = {}) {
    const chip = (c) => { const [t, n] = c.split(/ (?=\d)/); return `<button type="button" role="tab" aria-selected="${t === on}"${t === on ? ' class="on"' : ''}>${t}${n ? `<span class="n">${n}</span>` : ''}</button>`; };
    return `<aside class="c3-rail" aria-label="Conversations">
<div class="c3-rail-h"><h1>Chat</h1>${ib('sliders', 'Filter and sort', '', 17)}${ib('pencil', 'New conversation', 'b', 16)}</div>
<div class="ch-find">${svg('search', 15)}Search chats and people<span class="oc-kbd" style="margin-left: auto">Ctrl K</span></div>
<div class="ch-filt" role="tablist" aria-label="Show">${chips.map(chip).join('')}</div>
<div class="c3-list">${body}</div>
${foot ? `<nav class="c3-rail-f" aria-label="Chat shortcuts"><a href="#family" class="w" aria-label="Family inbox, 3 waiting">${svg('inbox', 15)}Family<span class="ch-n">3</span></a><a href="#calls">${svg('phone', 15)}Calls</a><a href="#saved">${svg('bookmark', 15)}Saved</a></nav>` : ''}
</aside>`;
}
export const STAFF_LIST = () => [
    csh('Pinned'),
    crow({ icon: 'hash', name: 'Kōwhai House', tag: 'Channel', time: '11:02', prev: 'Rohan: Fire door on level 1 is fixed', unread: 2, pinned: true }),
    csh('Favourites'),
    crow({ who: 'alex', name: 'Alex Morgan', time: '10:42', prev: 'You: Absolutely, I’ll check it before lunch.', on: true, state: 'on' }),
    crow({ icon: 'users', name: 'Kōwhai afternoon team', time: '10:36', prev: 'Hana: The updated plan is pinned', unread: 1, mention: true }),
    csh('Chats'),
    crow({ who: 'rohan', name: 'Rohan Patel', time: '09:58', prev: '', draft: 'Can we swap Thursday’s…', state: 'on' }),
    crow({ who: 'hana', name: 'Hana Wilson', time: 'Yesterday', prev: 'Thanks, see you Friday', state: 'leave' }),
    crow({ icon: 'users', name: 'Night team', time: 'Mon', prev: 'You: Handover notes are in', muted: true }),
    csh('Family', '3 waiting'),
    crow({ who: 'kiri', name: 'Kiri Wineera', tag: 'Family', time: '11:10', prev: 'About Tane · Can we visit on Saturday?', unread: 1 }),
    crow({ who: 'jo', name: 'Jo Taylor', tag: 'Family', time: '09:40', prev: 'About Sam · Friday afternoon suits us', state: '' }),
].join('');

// ---------- thread ----------
export function threadHead({ who, icon, state = 'on', name, sub, tabs = ['Chat', 'Files', 'Pinned 2', 'Notes'], on = 'Chat', video = true }) {
    const lead = who ? av(who, 's40', state) : gl(icon, 's40');
    const tab = (t) => { const [x, n] = t.split(/ (?=\d)/); return `<a href="#${x.toLowerCase()}"${x === on ? ' class="on" aria-current="page"' : ''}>${x}${n ? `<span class="n">${n}</span>` : ''}</a>`; };
    return `<header class="c3-th-h">${lead}<div class="id"><h2>${name}</h2><p>${sub}</p></div><nav class="c3-tabs" aria-label="Conversation views">${tabs.map(tab).join('')}</nav><div class="acts">${ib('phone', 'Voice call', '', 18)}${video ? ib('video', 'Video call', '', 19) : ''}<span class="vr" aria-hidden="true"></span>${ib('search', 'Search in this chat', '', 18)}${ib('users', 'People and details', '', 18)}${ib('more', 'More', '', 18)}</div></header>`;
}
export const thread = ({ head, bar = '', msgs, comp, cls = '' }) => `<section class="c3-th" aria-label="Conversation">${head}${bar}<div class="c3-msgs${cls ? ' ' + cls : ''}" role="log" aria-label="Messages">${msgs}</div>${comp}</section>`;

// ---------- menus ----------
export const mi = (icon, label, o = {}) => `<div class="c3-mi${o.on ? ' on' : ''}${o.del ? ' del' : ''}" role="menuitem">${icon ? (icon.startsWith('<') ? icon : svg(icon, 16)) : ''}${o.sub ? `<div><b>${label}</b><small>${o.sub}</small></div>` : `<span>${label}</span>`}${o.k ? `<span class="k">${o.k}</span>` : ''}${o.arrow ? `<span class="k ar">${svg('chevr', 14)}</span>` : ''}</div>`;
export const ms = () => '<div class="c3-ms" role="separator"></div>';
export const mc = (t) => `<div class="c3-mc">${t}</div>`;
export const menu = (items, cls = '', style = '', label = 'Menu') => `<div class="c3-menu${cls ? ' ' + cls : ''}" role="menu" aria-label="${label}"${style ? ` style="${style}"` : ''}>${items.join('')}</div>`;

// ---------- dialogs and panels ----------
export function dlg({ size = 'sm', icon, tone = '', title, sub = '', body, foot = '', style = '' }) {
    return `<section class="oc-dlg ${size}" role="dialog" aria-label="${title.replace(/<[^>]+>/g, '').replace(/"/g, '&quot;')}"${style ? ` style="${style}"` : ''}>
<div class="oc-mh">${icon ? `<span class="ic${tone ? ' ' + tone : ''}">${svg(icon, 20)}</span>` : ''}<div><h3>${title}</h3>${sub ? `<p>${sub}</p>` : ''}</div><button class="oc-x" type="button" aria-label="Close">${svg('x', 18)}</button></div>
<div class="oc-mb">${body}</div>
${foot ? `<div class="oc-mf">${foot}</div>` : ''}
</section>`;
}
export function panel({ icon, title, sub = '', body, foot = '', inset = false, tabs = '', style = '' }) {
    return `<section class="c3-panel${inset ? ' in' : ''}" role="dialog" aria-label="${title.replace(/<[^>]+>/g, '')}"${style ? ` style="${style}"` : ''}><div class="oc-sh"><span class="ic">${svg(icon, 19)}</span><div style="min-width: 0; flex: 1"><h3>${title}</h3>${sub ? `<p>${sub}</p>` : ''}${tabs}</div><button class="oc-x" type="button" aria-label="Close">${svg('x', 18)}</button></div><div class="c3-pb">${body}</div>${foot ? `<div class="c3-pf">${foot}</div>` : ''}</section>`;
}
export const pp = (who, title, sub, right = '', state = 'on') => `<div class="c3-pp">${av(who, 's32', state)}<div><b>${title || nm(who)}</b>${sub ? `<small>${sub}</small>` : ''}</div>${right}</div>`;

// ---------- page and board writer ----------
const MODE = `<script>(function(){function m(){var d=location.hash==='#d';document.querySelectorAll('.oc.pp-board').forEach(function(e){e.classList.toggle('d',d);e.classList.toggle('l',!d);});document.querySelectorAll('.pp-head .mode').forEach(function(e){e.textContent=d?'Dark':'Light';});}m();addEventListener('hashchange',m);})();</script>`;
export const scene = (inner, { active = 'chat', crumbsT = ['Home', 'Chat'], h = 1000 } = {}) =>
    `<div class="ch-frame" style="width: 1440px"><div class="oc oc-app pp-board t-d l b-journey lg-journey"${h !== 1000 ? ` style="height: ${h}px"` : ''}>
${top()}
${side(active)}
<main class="oc-main">
${L.crumbs(...crumbsT)}
${inner}
</main>
</div></div>`;
export function writeBoard(file, title, h1, desc, sections, { measure = false } = {}) {
    const body = `<div class="oc oc-kit pp-board t-d l b-journey lg-journey" style="width: 1600px; padding-left: 40px; padding-right: 40px">
<div class="oc-kit-h pp-head"><h1>${h1}</h1><p>${desc}</p><span class="mode">Light</span></div>
${sections.join('\n')}
</div>`;
    const html = `<!doctype html>
<html lang="en-NZ">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>${title}</title>
<link href="https://fonts.googleapis.com/css2?family=Instrument+Sans:wght@400;500;600;700&amp;display=swap" rel="stylesheet">
<link rel="stylesheet" href="./oc.css">
<link rel="stylesheet" href="./oc-chat.css">
<link rel="stylesheet" href="./oc-chat3.css">
<style>html,body{margin:0;background:transparent}</style>
</head>
<body>
${body}
${MODE}
</body>
</html>
`;
    let out = html;
    const blobs = JSON.parse(readFileSync('brand/assets.json', 'utf8'));
    for (const [n, u] of Object.entries(blobs)) out = out.split(u).join('brand/' + n + '.svg');
    if (/\/_blob\/[0-9a-f]{32}/.test(out)) throw new Error('unmapped blob in ' + file);
    if (/\uFFFD/.test(out)) throw new Error('U+FFFD in ' + file);
    writeFileSync(OUT + 'b/' + file, out);
    return out.length;
}
