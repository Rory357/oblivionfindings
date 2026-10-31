// Chat v3 board helpers for the three new boards (notification row, side sheet, pop-up kit).
// Rebuilt from docs/chat-experience/v3/source/c3lib.mjs (its lib.mjs import is gone).
// The top bar, side nav and dark-mode script are copied from the approved ChatV3-Workspace board.
import { readFileSync, writeFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

const HERE = dirname(fileURLToPath(import.meta.url));
export const OUT = join(HERE, '..');
const mined = JSON.parse(readFileSync(join(HERE, 'icons-mined.json'), 'utf8'));
const OK_ICON = new RegExp('^(?:<(?:path|circle|rect|line|polyline|ellipse|polygon) [^<>]*/>)+$');
const M = (k) => { if (!mined[k]) throw new Error('no mined icon ' + k); if (!OK_ICON.test(mined[k])) throw new Error('bad mined icon ' + k); return mined[k]; };

// people (fictional demo data only; avatar colours are the approved boards' four avatar colours)
export const P = {
    rohan: ['RP', '#3e6aa8', 'Rohan Patel', 'Site lead · Kōwhai House'],
    ana: ['AR', '#a0563f', 'Ana Rēweti', 'Support worker · Kōwhai House'],
    tane: ['TW', '#3e6aa8', 'Tane Wineera', 'Person supported · Kōwhai House'],
    kiri: ['KW', '#8a6d2f', 'Kiri Wineera', 'Whānau · Mum'],
    aroha: ['AN', '#3f7f6a', 'Aroha Ngata', 'Person supported · Kōwhai House'],
    admin: ['DA', '#7a5aa8', 'Demo Admin', 'Administrator'],
};

// lucide-shaped icons, 24 × 24, stroke 2 (mined from the approved boards where they exist)
export const I = {
    search: '<circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/>',
    x: '<path d="M18 6 6 18M6 6l12 12"/>',
    check: '<path d="M20 6 9 17l-5-5"/>',
    checks: '<path d="M18 6 7 17l-5-5M22 10l-7.5 7.5L13 16"/>',
    chev: '<path d="m6 9 6 6 6-6"/>',
    chevr: '<path d="m9 18 6-6-6-6"/>',
    chevl: '<path d="m15 18-6-6 6-6"/>',
    chevup: '<path d="m18 15-6-6-6 6"/>',
    plus: '<path d="M5 12h14M12 5v14"/>',
    send: M('Send'),
    mic: M('Record a voice note'),
    smile: M('Emoji'),
    sticker: M('Stickers and GIFs'),
    aa: M('Show formatting'),
    phone: M('Voice call'),
    video: M('Video call'),
    users: M('People and details'),
    more: M('More'),
    pin: M('mi:Pin'),
    bookmark: M('mi:Save for me'),
    belloff: M('mi:Mute'),
    at: M('You were mentioned'),
    unread: M('mi:Mark as unread'),
    archive: '<rect width="20" height="5" x="2" y="3" rx="1"/><path d="M4 8v11a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8M10 12h4"/>',
    leave: M('mi:Leave group'),
    reply: '<path d="m9 17-5-5 5-5"/><path d="M20 18v-2a4 4 0 0 0-4-4H4"/>',
    thread: M('mi:Reply in thread'),
    fwd: M('mi:Share to another chat'),
    copy: M('mi:Copy text'),
    turn: M('mi:Turn into'),
    info: '<circle cx="12" cy="12" r="10"/><path d="M12 16v-4M12 8h.01"/>',
    flag: M('mi:Report a concern'),
    eyeoff: M('mi:Delete for me'),
    tasks: M('mi:Task'),
    alert: M('mi:Incident report'),
    clipboard: M('mi:Shift note'),
    heart: M('mi:Family note'),
    monitor: M('mi:IT request'),
    pencil: M('mi:Edit'),
    trash: M('mi:Delete for everyone'),
    bell: M('Notifications, alerts waiting'),
    msg: M('Chat, 6 unread'),
    inbox: M('Family inbox, 3 waiting'),
    sliders: M('Filter and sort'),
    newchat: M('New conversation'),
    play: M('Play voice note'),
    hash: '<path d="M4 9h16M4 15h16M10 3 8 21M16 3l-2 18"/>',
    lock: '<rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/>',
    shield: '<path d="M20 13c0 5-3.5 7.5-7.66 8.95a1 1 0 0 1-.67-.01C7.5 20.5 4 18 4 13V6a1 1 0 0 1 1-1c2 0 4.5-1.2 6.24-2.72a1.17 1.17 0 0 1 1.52 0C14.51 3.81 17 5 19 5a1 1 0 0 1 1 1z"/>',
    shieldalert: '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><path d="M12 8v4M12 16h.01"/>',
    alertc: '<circle cx="12" cy="12" r="10"/><path d="M12 8v4M12 16h.01"/>',
    history: '<path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/><path d="M3 3v5h5M12 7v5l4 2"/>',
    phonemissed: '<path d="m16 2 6 6M22 2l-6 6"/><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.127.96.361 1.903.7 2.81a2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.907.339 1.85.573 2.81.7A2 2 0 0 1 22 16.92z"/>',
    phonein: '<path d="M16 2v6h6M22 2l-6 6"/><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.127.96.361 1.903.7 2.81a2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.907.339 1.85.573 2.81.7A2 2 0 0 1 22 16.92z"/>',
    home: '<path d="M15 21v-8a1 1 0 0 0-1-1h-4a1 1 0 0 0-1 1v8"/><path d="M3 10a2 2 0 0 1 .709-1.528l7-5.999a2 2 0 0 1 2.582 0l7 5.999A2 2 0 0 1 21 10v9a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/>',
    calendar: '<path d="M8 2v4M16 2v4"/><rect width="18" height="18" x="3" y="4" rx="2"/><path d="M3 10h18"/>',
    clock: '<circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/>',
    pill: '<path d="m10.5 20.5 10-10a4.95 4.95 0 1 0-7-7l-10 10a4.95 4.95 0 1 0 7 7Z"/><path d="m8.5 8.5 7 7"/>',
    image: '<rect width="18" height="18" x="3" y="3" rx="2" ry="2"/><circle cx="9" cy="9" r="2"/><path d="m21 15-3.086-3.086a2 2 0 0 0-2.828 0L6 21"/>',
    file: '<path d="M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7Z"/><path d="M14 2v4a2 2 0 0 0 2 2h4"/>',
    expand: '<path d="M15 3h6v6M9 21H3v-6M21 3l-7 7M3 21l7-7"/>',
    panelr: '<rect width="18" height="18" x="3" y="3" rx="2"/><path d="M15 3v18M10 15l-3-3 3-3"/>',
    userplus: '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M19 8v6M22 11h-6"/>',
    userminus: '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 11h-6"/>',
    user: '<path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>',
    usercheck: '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="m16 11 2 2 4-4"/>',
    refresh: '<path d="M21 12a9 9 0 1 1-9-9c2.52 0 4.93 1 6.74 2.74L21 8"/><path d="M21 3v5h-5"/>',
    eye: '<path d="M2.062 12.348a1 1 0 0 1 0-.696 10.75 10.75 0 0 1 19.876 0 1 1 0 0 1 0 .696 10.75 10.75 0 0 1-19.876 0"/><circle cx="12" cy="12" r="3"/>',
    undo: '<path d="M9 14 4 9l5-5"/><path d="M4 9h10.5a5.5 5.5 0 0 1 5.5 5.5 5.5 5.5 0 0 1-5.5 5.5H11"/>',
    building: '<rect width="16" height="20" x="4" y="2" rx="2" ry="2"/><path d="M9 22v-4h6v4M8 6h.01M16 6h.01M12 6h.01M12 10h.01M12 14h.01M16 10h.01M16 14h.01M8 10h.01M8 14h.01"/>',
    layers: '<path d="m12.83 2.18a2 2 0 0 0-1.66 0L2.6 6.08a1 1 0 0 0 0 1.83l8.58 3.91a2 2 0 0 0 1.66 0l8.58-3.9a1 1 0 0 0 0-1.83Z"/><path d="m22 17.65-9.17 4.16a2 2 0 0 1-1.66 0L2 17.65"/><path d="m22 12.65-9.17 4.16a2 2 0 0 1-1.66 0L2 12.65"/>',
    ban: '<circle cx="12" cy="12" r="10"/><path d="m4.9 4.9 14.2 14.2"/>',
    pause: '<rect x="14" y="4" width="4" height="16" rx="1"/><rect x="6" y="4" width="4" height="16" rx="1"/>',
    moon: '<path d="M12 3a6 6 0 0 0 9 9 9 9 0 1 1-9-9Z"/>',
    circlecheck: '<circle cx="12" cy="12" r="10"/><path d="m9 12 2 2 4-4"/>',
    listchecks: '<path d="m3 17 2 2 4-4M3 7l2 2 4-4M13 6h8M13 12h8M13 18h8"/>',
    bellring: '<path d="M10.268 21a2 2 0 0 0 3.464 0"/><path d="M22 8c0-2.3-.8-4.3-2-6M3.262 15.326A1 1 0 0 0 4 17h16a1 1 0 0 0 .74-1.673C19.41 13.956 18 12.499 18 8A6 6 0 0 0 6 8c0 4.499-1.411 5.956-2.738 7.326M4 2C2.8 3.7 2 5.7 2 8"/>',
    calplus: '<path d="M8 2v4M16 2v4M21 13V6a2 2 0 0 0-2-2H5a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h8M3 10h18M16 19h6M19 16v6"/>',
    wrench: '<path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"/>',
    arrdown: '<path d="M12 5v14M19 12l-7 7-7-7"/>',
    smileplus: '<path d="M22 11v1a10 10 0 1 1-9-10"/><path d="M8 14s1.5 2 4 2 4-2 4-2M9 9h.01M15 9h.01M16 5h6M19 2v6"/>',
    star: '<path d="M11.525 2.295a.53.53 0 0 1 .95 0l2.31 4.679a2.12 2.12 0 0 0 1.595 1.16l5.166.756a.53.53 0 0 1 .294.904l-3.736 3.638a2.12 2.12 0 0 0-.611 1.878l.882 5.14a.53.53 0 0 1-.771.56l-4.618-2.428a2.12 2.12 0 0 0-1.973 0L6.396 21.01a.53.53 0 0 1-.77-.56l.881-5.139a2.12 2.12 0 0 0-.611-1.879L2.16 9.795a.53.53 0 0 1 .294-.906l5.165-.755a2.12 2.12 0 0 0 1.597-1.16z"/>',
    mail: '<rect width="20" height="16" x="2" y="4" rx="2"/><path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7"/>',
    globe: '<circle cx="12" cy="12" r="10"/><path d="M12 2a14.5 14.5 0 0 0 0 20 14.5 14.5 0 0 0 0-20M2 12h20"/>',
};

export function svg(k, s = 16, cls = '') {
    if (!I[k]) throw new Error('no icon ' + k);
    const fill = k === 'play' ? 'currentColor' : 'none';
    const w = k === 'more' ? 2.4 : 2;
    return `<svg${cls ? ` class="${cls}"` : ''} width="${s}" height="${s}" viewBox="0 0 24 24" fill="${fill}" stroke="currentColor" stroke-width="${w}" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">${I[k]}</svg>`;
}
export const nm = (who) => P[who][2];
export const first = (who) => P[who][2].split(' ')[0];
export const av = (who, size = '', state = '', style = '') => `<span class="ch-av${size ? ' ' + size : ''}${state ? ' ' + state : ''}" style="background: ${P[who][1]}${style ? '; ' + style : ''}" aria-hidden="true">${P[who][0]}</span>`;
export const gl = (icon, s = '', label = '') => `<span class="c3-gl${s ? ' ' + s : ''}"${label ? ` aria-label="${label}"` : ' aria-hidden="true"'}>${svg(icon, s === 's32' ? 15 : 17)}</span>`;
export const btn = (label, kind = 'o', o = {}) =>
    `<button class="oc-btn ${kind}${o.sm ? ' sm' : ''}${o.xs ? ' xs' : ''}${o.dis ? ' dis' : ''}${o.cls ? ' ' + o.cls : ''}" type="button"${o.aria ? ` aria-label="${o.aria}"` : ''}${o.style ? ` style="${o.style}"` : ''}>${o.spin ? '<span class="oc-spin" aria-hidden="true"></span>' : ''}${o.icon ? svg(o.icon, o.sm || o.xs ? 14 : 15) : ''}${label}${o.after ? svg(o.after, 14) : ''}</button>`;
export const ib = (icon, label, cls = '', s = 17) => `<button class="ch-ib${cls ? ' ' + cls : ''}" type="button" aria-label="${label}">${svg(icon, s)}</button>`;
export const lens = (icon, cls = '', s = 15) => `<span class="ch-lens${cls ? ' ' + cls : ''}">${svg(icon, s)}</span>`;
export const badge = (t, tone = 'neutral', cls = '') => `<span class="oc-badge ${tone}${cls ? ' ' + cls : ''}">${t}</span>`;
export const e = (ch, cls = '') => `<span class="c3-e${cls ? ' ' + cls : ''}">${ch}</span>`;
export const cap = (t) => `<span class="ch-cap">${t}</span>`;
export const col = (label, inner, style = '') => `<div class="ch-col"${style ? ` style="${style}"` : ''}>${cap(label)}${inner}</div>`;
export const sec = (title, sub, inner) => `<section class="ch-sec"><h2>${title}<small>${sub}</small></h2>${inner}</section>`;
export const note = (text, tone = '', icon = 'info') => `<div class="ch-note${tone ? ' ' + tone : ''}">${svg(icon, 15)}<div>${text}</div></div>`;
export const field = (label, inner, hint = '', err = '') => `<div class="oc-field"><span class="oc-lbl">${label}</span>${inner}${err ? `<span class="oc-err">${svg('alertc', 13)}${err}</span>` : ''}${hint ? `<span class="oc-hint">${hint}</span>` : ''}</div>`;
export const input = (val, cls = '') => `<div class="oc-input${cls ? ' ' + cls : ''}">${val}</div>`;
export const area = (val, cls = '', h = 76) => `<div class="oc-input oc-area${cls ? ' ' + cls : ''}" style="height: ${h}px; align-items: flex-start; padding-top: 9px; line-height: 1.45">${val}</div>`;
export const sel = (val, cls = '') => `<button class="ch-sel${cls ? ' ' + cls : ''}" type="button" aria-haspopup="listbox">${val}${svg('chev', 15, 'chev')}</button>`;
export const box = (on = false, label = '', sub = '', dis = false) => `<label class="ch-chk"><span class="oc-box${on ? ' on' : ''}${dis ? ' dis' : ''}" aria-hidden="true">${on ? svg('check', 12) : ''}</span><span>${label}${sub ? `<small>${sub}</small>` : ''}</span></label>`;
export const radio = (on = false, label = '', sub = '') => `<label class="ch-chk"><span class="oc-radio${on ? ' on' : ''}" aria-hidden="true"></span><span>${label}${sub ? `<small>${sub}</small>` : ''}</span></label>`;
export const sw = (on, label, sub = '') => `<div class="pk-swrow"><span><b>${label}</b>${sub ? `<small>${sub}</small>` : ''}</span><span class="oc-sw${on ? '' : ' off'}" role="switch" aria-checked="${on}" aria-label="${label}"></span></div>`;
export const callout = (text, tone = '', icon = 'info') => `<div class="oc-callout${tone ? ' ' + tone : ''}"${tone === 'crit' ? ' role="alert"' : ''}>${svg(icon, 16)}<div>${text}</div></div>`;
export const skel = (w = '100%', h = 10) => `<span class="oc-skel" style="width: ${w}; height: ${h}px"></span>`;

// delivery rings + reactions (c3lib)
const DR = { sending: 'Sending', sent: 'Sent', dlv: 'Delivered', read: 'Read', fail: 'Couldn’t send' };
export const dr = (st, label = DR[st]) => `<span class="c3-dr ${st}" aria-hidden="true"></span><span class="${st === 'read' ? 'read' : st === 'fail' ? 'fail' : ''}">${label}</span>`;
export const rc = (emoji, n, me = false, who = '') => `<button class="c3-rc${me ? ' me' : ''}" type="button" aria-label="${n} reacted ${emoji}${who ? ': ' + who : ''}${me ? ', including you' : ''}">${e(emoji)}${n}</button>`;
export const rx = (...list) => `<div class="c3-rx">${list.join('')}<button class="c3-rc add" type="button" aria-label="Add a reaction">${svg('smileplus', 14)}</button></div>`;

// message groups (c3lib)
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
export const link = (t) => `<a class="c3-a" href="#link">${t}</a>`;
export const wave = (n = 34, lit = 12, seed = 3) => `<span class="ch-wave" aria-hidden="true">${Array.from({ length: n }, (_, i) => { const h = 5 + Math.round(Math.abs(Math.sin(i * 1.7 + seed) * 15 + Math.sin(i * 0.6) * 4)); return `<i${i < lit ? ' class="lit"' : ''} style="height: ${Math.min(24, h)}px"></i>`; }).join('')}</span>`;
export const voice = (t = '0:12 / 0:42', lit = 10) => `<div class="ch-voice"><button class="pl" type="button" aria-label="Play voice note">${svg('play', 14)}</button>${wave(32, lit)}<span class="tm">${t}</span><button class="spd" type="button" aria-label="Playback speed 1×">1×</button></div>`;
export function card({ icon, md, title, refn = '', facts, badgeT = '', tone = 'neutral', action = 'Open', extra = '', lock = false, m = '', style = '', actionIcon = 'chevr', actionKind = 'o' }) {
    return `<div class="c3-cc${lock ? ' lock' : ''}"${style ? ` style="${style}"` : ''}>${lens(icon, lock ? 'mut' : '', 17)}<div class="hd"><span class="md">${md}</span>${badgeT ? badge(badgeT, tone) : ''}</div><div><b>${title}${refn ? `<span class="ref">${refn}</span>` : ''}</b><small>${facts}</small>${extra}</div>${action || m ? `<div class="ft">${action ? btn(action, actionKind, { xs: true, after: lock ? '' : actionIcon }) : ''}${m ? `<span class="m">${m}</span>` : ''}</div>` : ''}</div>`;
}
export function composer({ text = '', ph = 'Write a message…', focus = false, hint = true, aud = 'Only conversation members', above = '', replace = '', send = '', style = '' } = {}) {
    const has = !!text;
    const right = send || (has ? `<button class="c3-round p" type="button" aria-label="Send">${svg('send', 18)}</button>` : `<button class="c3-round o" type="button" aria-label="Record a voice note">${svg('mic', 19)}</button>`);
    const fieldHtml = replace || `<div class="c3-field${focus ? ' focus' : ''}"><div class="tx${has ? ' has' : ''}" role="textbox" aria-multiline="true" aria-label="Write a message">${text || ph}${focus && has ? '<span class="caret"></span>' : ''}</div>${ib('smile', 'Emoji', '', 18)}${ib('sticker', 'Stickers and GIFs', '', 18)}${ib('aa', 'Show formatting', '', 18)}</div>`;
    return `<div class="c3-comp"${style ? ` style="${style}"` : ''}>${above}<div class="c3-cin"><button class="c3-round o" type="button" aria-label="Attach">${svg('plus', 19)}</button>${fieldHtml}${right}</div>${hint ? `<div class="c3-hint"><span>Enter to send · Shift + Enter for a new line</span><span class="r">${svg('lock', 12)}${aud}</span></div>` : ''}</div>`;
}
// conversation rail row (c3lib crow)
export function crow({ who, icon, name, tag = '', time, prev, unread = 0, on = false, hov = false, state = '', mention = false, muted = false }) {
    const lead = who ? av(who, '', state) : gl(icon);
    const fl = [muted ? `<span aria-label="Muted">${svg('belloff', 13)}</span>` : '', mention ? `<span class="c3-at" aria-label="You were mentioned">${svg('at', 11)}</span>` : '', unread ? `<span class="ch-n" aria-label="${unread} unread">${unread}</span>` : ''].join('');
    return `<a class="c3-row${on ? ' on' : ''}${hov ? ' hov' : ''}${unread ? ' unread' : ''}" href="#c">${lead}<span style="min-width: 0"><span class="l1"><b>${name}</b>${tag ? `<span class="tg">${tag}</span>` : ''}<time>${time}</time></span><span class="l2"><span class="tx">${prev}</span>${fl ? `<span class="fl">${fl}</span>` : ''}</span></span></a>`;
}
export const crowLite = (icon, who, name, time, prev, unread = 0, mention = false, hov = false, tag = '') => crow({ icon, who, name, time, prev, unread, mention, hov, tag, state: who === 'ana' ? 'on' : '' });
export const mi = (icon, label, o = {}) => `<div class="c3-mi${o.on ? ' on' : ''}${o.del ? ' del' : ''}" role="menuitem">${icon ? (icon.startsWith('<') ? icon : svg(icon, 16)) : ''}${o.sub ? `<div><b>${label}</b><small>${o.sub}</small></div>` : `<span>${label}</span>`}${o.k ? `<span class="k">${o.k}</span>` : ''}${o.arrow ? `<span class="k ar">${svg('chevr', 14)}</span>` : ''}</div>`;
export const ms = () => '<div class="c3-ms" role="separator"></div>';
export const mc = (t) => `<div class="c3-mc">${t}</div>`;
export const menu = (items, cls = '', style = '', label = 'Menu') => `<div class="c3-menu${cls ? ' ' + cls : ''}" role="menu" aria-label="${label}"${style ? ` style="${style}"` : ''}>${items.join('')}</div>`;

// pop-up kit frames (POPUP_STYLE_GUIDE: Form 480/720/900, Confirm 480, Viewer, Side panel 480/640)
const strip = (t) => t.replace(/<[^>]+>/g, '').replace(/"/g, '&quot;');
export function dlg({ size = 'sm', icon, tone = '', title, sub = '', body, foot = '', style = '', x = true }) {
    return `<section class="oc-dlg ${size}" role="dialog" aria-label="${strip(title)}"${style ? ` style="${style}"` : ''}>
<div class="oc-mh">${icon ? `<span class="ic${tone ? ' ' + tone : ''}">${svg(icon, 20)}</span>` : ''}<div><h3>${title}</h3>${sub ? `<p>${sub}</p>` : ''}</div>${x ? `<button class="oc-x" type="button" aria-label="Close">${svg('x', 18)}</button>` : ''}</div>
<div class="oc-mb">${body}</div>
${foot ? `<div class="oc-mf">${foot}</div>` : ''}
</section>`;
}
// Confirm: lens on top, the question, one line, then the footer (PopupConfirm; no ✕, Esc and Cancel close it)
export function confirm({ icon = 'info', tone = '', title, text = '', body = '', foot, style = '' }) {
    return `<section class="oc-dlg sm pk-cf" role="alertdialog" aria-label="${strip(title)}"${style ? ` style="${style}"` : ''}>
<div class="oc-mb pk-cfb"><span class="pk-lens${tone ? ' ' + tone : ''}">${svg(icon, 20)}</span><h3>${title}</h3>${text ? `<p>${text}</p>` : ''}${body}</div>
<div class="oc-mf">${foot}</div>
</section>`;
}
export function panel({ icon, title, sub = '', body, foot = '', style = '' }) {
    return `<section class="c3-panel" role="dialog" aria-label="${strip(title)}"${style ? ` style="${style}"` : ''}><div class="oc-sh"><span class="ic">${svg(icon, 19)}</span><div style="min-width: 0; flex: 1"><h3>${title}</h3>${sub ? `<p>${sub}</p>` : ''}</div><button class="oc-x" type="button" aria-label="Close">${svg('x', 18)}</button></div><div class="c3-pb">${body}</div>${foot ? `<div class="c3-pf">${foot}</div>` : ''}</section>`;
}
// the locked record a pop-up came from (PopupContext): a plain line between hairlines
export const ctx = (icon, title, tag = '', noteT = '') => `<div class="pk-ctx"><span class="ch-lens s22">${svg(icon, 13)}</span><div><b>${title}${tag ? `<span class="tg">${tag}</span>` : ''}</b>${noteT ? `<small>${noteT}</small>` : ''}</div></div>`;
export const pq = (who, text) => `<div class="c3-pq"><small>${who}</small>${text}</div>`;
export const pp = (who, title, sub, right = '', state = 'on') => `<div class="c3-pp">${av(who, 's32', state)}<div><b>${title || nm(who)}</b>${sub ? `<small>${sub}</small>` : ''}</div>${right}</div>`;
export const toast = (text, { icon = 'check', crit = false, action = '' } = {}) => `<div class="oc-toast static" role="status"><span class="ti${crit ? ' crit' : ''}">${svg(icon, 15)}</span><div style="flex: 1; min-width: 0">${text}</div>${action}</div>`;

// app shell copied from the approved Workspace board; the signed-in person is Rohan Patel
const TOP = readFileSync(join(HERE, 'shell-top.html'), 'utf8')
    .replace('aria-label="Your account: Mere Thompson">MT<', 'aria-label="Your account: Rohan Patel">RP<');
const SIDE = readFileSync(join(HERE, 'shell-side.html'), 'utf8');
export const top = ({ bellOpen = false, chatOpen = false, bellCount = 0 } = {}) => {
    let t = TOP;
    if (bellOpen || bellCount) t = t.replace('<button class="oc-iconbtn" type="button" aria-label="Notifications, alerts waiting">', `<button class="oc-iconbtn${bellOpen ? ' pk-open' : ''}" type="button" aria-label="Notifications, ${bellCount} unread"${bellOpen ? ' aria-expanded="true"' : ''}>`).replace('<span class="oc-dot"></span>', bellCount ? `<span class="oc-count">${bellCount}</span>` : '<span class="oc-dot"></span>');
    if (chatOpen) t = t.replace('<button class="oc-iconbtn" type="button" aria-label="Chat, 6 unread">', '<button class="oc-iconbtn pk-open" type="button" aria-label="Chat, 6 unread" aria-expanded="true">');
    return t;
};
const OPS_SUB = '<div class="oc-sub"><a href="#ops-dash">Dashboard</a><a class="on" href="#clients" aria-current="page">Clients</a><a href="#plans">Care plans</a><a href="#notes">Shift notes</a></div>';
export const side = (active = 'chat') => {
    if (active === 'chat') return SIDE;
    let s = SIDE.replace('class="oc-nav on" href="#chat" aria-current="page"', 'class="oc-nav" href="#chat"');
    if (active === 'clients') {
        s = s.replace(/<a class="oc-nav grp" href="#ops" aria-expanded="false">([\s\S]*?)<\/a>/, (m, inner) =>
            `<a class="oc-nav grp on" href="#ops" aria-expanded="true">${inner.replace('<path d="m9 18 6-6-6-6"/>', '<path d="m6 9 6 6 6-6"/>')}</a>${OPS_SUB}`);
    } else if (active !== 'none') {
        s = s.replace(`class="oc-nav" href="#${active}"`, `class="oc-nav on" href="#${active}" aria-current="page"`);
    }
    return s;
};
const crumbs = (...c) => `<div class="oc-crumbs">${c.map((t, i) => (i === c.length - 1 ? `<b>${t}</b>` : `<span>${t}</span><span aria-hidden="true">›</span>`)).join('')}</div>`;
export const scene = (inner, { active = 'chat', crumbsT = ['Home', 'Chat'], h = 1000, topO = {}, over = '', style = '' } = {}) =>
    `<div class="ch-frame" style="width: 1440px"><div class="oc oc-app pp-board t-d l b-journey lg-journey" style="position: relative${h !== 1000 ? `; height: ${h}px` : ''}${style ? '; ' + style : ''}">
${top(topO)}
${side(active)}
<main class="oc-main">
${crumbs(...crumbsT)}
${inner}
</main>
${over}
</div></div>`;

// board writer: same structure, fonts, stylesheets and dark-mode script as the approved boards
const MODE = readFileSync(join(HERE, 'mode-script.html'), 'utf8');
export function writeBoard(file, title, h1, desc, sections, style = '') {
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
<style>
/* New parts for this board only. Tokens from the three stylesheets; no new colours. */
${KIT_CSS}${style}
</style>
</head>
<body>
<div class="oc oc-kit pp-board t-d l b-journey lg-journey" style="width: 1600px; padding-left: 40px; padding-right: 40px">
<div class="oc-kit-h pp-head"><h1>${h1}</h1><p>${desc}</p><span class="mode">Light</span></div>
${sections.join('\n')}
</div>
${MODE}
</body>
</html>
`;
    if (/�/.test(html)) throw new Error('U+FFFD in ' + file);
    if (/#[0-9a-fA-F]{3,8}\b/.test((KIT_CSS + style))) throw new Error('raw hex in the board style block of ' + file);
    writeFileSync(join(OUT, file), html);
    return html.length;
}

// shared small parts used by all three boards
const KIT_CSS = `
.pk-open { background: var(--chrome-active); color: var(--chrome-active-fg); }
.pk-cf .pk-cfb { padding-top: 22px; gap: 10px; }
.pk-cf h3 { margin: 4px 0 0; font-size: 17px; font-weight: 650; letter-spacing: -0.015em; }
.pk-cf p { margin: 0; font-size: 13px; color: var(--mfg); line-height: 1.5; }
.pk-lens { width: 44px; height: 44px; border-radius: 50%; display: grid; place-items: center; color: var(--pt); background: var(--lens, var(--tint)); box-shadow: inset 0 0 0 1.5px color-mix(in oklch, currentColor 36%, transparent), 0 0 0 5px color-mix(in oklch, currentColor 7%, transparent); }
.pk-lens.crit, .oc-mh .ic.crit { color: var(--crit); background: var(--crit-bg); }
.pk-lens.warn, .oc-mh .ic.warn { color: var(--warn); background: var(--warn-bg); }
.pk-ctx { display: flex; gap: 10px; align-items: flex-start; padding: 10px 0; border-top: 1px solid var(--border); border-bottom: 1px solid var(--border); }
.pk-ctx b { display: flex; align-items: center; gap: 8px; font-size: 13px; font-weight: 650; }
.pk-ctx .tg { font-size: 11px; font-weight: 600; color: var(--mfg); border: 1px solid var(--border); border-radius: 6px; padding: 0 6px; line-height: 18px; }
.pk-ctx small { display: block; font-size: 12px; color: var(--mfg); margin-top: 1px; }
.pk-swrow { display: flex; align-items: center; justify-content: space-between; gap: 14px; padding: 10px 12px; border-radius: 12px; background: var(--muted); border: 1px solid var(--border); }
.pk-swrow b { display: block; font-size: 13px; font-weight: 650; }
.pk-swrow small { display: block; font-size: 12px; color: var(--mfg); }
.pk-swrow .oc-sw { flex: none; }
.oc-mf .l { margin-right: auto; }
.oc-btn .oc-spin { width: 13px; height: 13px; border-width: 2px; }
.oc-area { white-space: normal; }
.c3-note-row .c3-meas { align-content: start; }
`;
