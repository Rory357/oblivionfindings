// ChatV3-Notifications.html — C7.7, the Chat notification row (with C1.4 grouping rules)
import * as C from './lib.mjs';
const { svg, av, gl, btn, ib, lens, badge, col, sec, note, mi, ms, mc, menu, scene, writeBoard } = C;

const STYLE = `
.nr { position: relative; display: grid; grid-template-columns: 36px minmax(0, 1fr) 10px; gap: 10px; padding: 10px 14px 11px; border-bottom: 1px solid var(--border); font-size: 13px; align-items: start; background: var(--card); --ring-gap: var(--card); }
.nr:last-child { border-bottom: 0; }
.nr.unread { background: color-mix(in oklch, var(--primary) 5%, var(--card)); --ring-gap: color-mix(in oklch, var(--primary) 5%, var(--card)); }
.nr.hov { background: var(--muted); --ring-gap: var(--muted); }
.nr .ld { position: relative; width: 36px; height: 36px; }
.nr .ld > .ch-lens { width: 36px; height: 36px; }
.nr .kd { position: absolute; right: -5px; bottom: -4px; width: 19px; height: 19px; border-radius: 50%; display: grid; place-items: center; background: var(--card); color: var(--pt); box-shadow: 0 0 0 1.5px var(--border); }
.nr .kd.crit { color: var(--crit); background: var(--crit-bg); }
.nr .kd.warn { color: var(--warn); background: var(--warn-bg); }
.nr .kd.at { background: var(--pfill); color: var(--band); box-shadow: 0 0 0 2px var(--ring-gap); }
.nr .tx { min-width: 0; display: grid; gap: 1px; }
.nr .l1 { display: flex; align-items: center; gap: 6px; min-width: 0; }
.nr .l1 b { font-size: 13.5px; font-weight: 650; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; min-width: 0; }
.nr .l1 .tg { flex: none; font-size: 10.5px; font-weight: 700; color: var(--mfg); border: 1px solid var(--border); border-radius: 5px; padding: 0 5px; line-height: 16px; }
.nr .l1 time { margin-left: auto; flex: none; font-size: 11.5px; color: var(--mfg); font-variant-numeric: tabular-nums; }
.nr.unread .l1 time { color: var(--pt); font-weight: 650; }
.nr .wh { font-size: 12.5px; font-weight: 600; color: var(--fg); display: flex; gap: 6px; align-items: center; min-width: 0; }
.nr .wh svg { flex: none; }
.nr .wh.crit { color: var(--crit-fg); }
.nr .wh.crit svg { color: var(--crit); }
.nr .wh .ch-at { font-size: 12px; }
.nr .pv { font-size: 12.5px; color: var(--mfg); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.nr .pv.two { white-space: normal; }
.nr .pv i { font-style: normal; color: var(--fg); font-weight: 550; }
.nr .tags { display: flex; gap: 6px; align-items: center; flex-wrap: wrap; margin-top: 6px; }
.nr .ud { width: 8px; height: 8px; border-radius: 50%; background: var(--primary); margin-top: 6px; }
.nr:not(.unread) .l1 b { font-weight: 550; }
.nr:not(.unread) .wh { color: var(--mfg); font-weight: 500; }
.nr.gone .l1 b, .nr.gone .wh { color: var(--mfg); }
.nr .c3-hbar { top: 8px; right: 10px; }
.nr.wide { grid-template-columns: 40px minmax(0, 1fr) auto 10px; gap: 12px; padding: 12px 18px; }
.nr.wide .ld, .nr.wide .ld > .ch-lens, .nr.wide .ld > .c3-gl, .nr.wide .ld > .ch-av { width: 40px; height: 40px; }
.nr.wide .acts { display: flex; gap: 6px; align-self: center; }
.nr.wide .l1 time { margin-left: 8px; }
.nr.wide .mod { font-size: 11.5px; color: var(--mfg); }
.nl { width: 384px; }
.nl .c3-sh { padding: 10px 14px 4px; background: var(--card); border-bottom: 1px solid var(--border); }
.nl.w480 { width: 480px; }
.oc-app .oc-notif.bellm { position: absolute; top: 50px; right: 62px; z-index: 8; }
.oc-notif .oc-notif-h { gap: 10px; }
.oc-notif .ft { display: flex; justify-content: center; padding: 9px; border-top: 1px solid var(--border); }
.oc-notif .ft a { font-size: 13px; font-weight: 600; color: var(--pt); text-decoration: none; display: inline-flex; gap: 6px; align-items: center; }
.nfilt { display: flex; gap: 10px; align-items: center; padding: 10px 12px; }
.nfilt .ch-find { width: 280px; height: 32px; margin-left: auto; }
.pg-cards { display: grid; grid-template-columns: minmax(0, 1.5fr) minmax(0, 1fr); gap: 20px; align-items: start; }
.pg-cards .oc-card-h b { font-size: 15px; font-weight: 650; }
.pn { width: 100%; max-width: 400px; border-radius: 14px; background: var(--card); border: 1px solid var(--border); box-shadow: var(--shadow-lg); padding: 12px 14px 13px; display: grid; grid-template-columns: 36px minmax(0, 1fr) auto; gap: 2px 12px; align-items: start; }
.pn .ic { width: 36px; height: 36px; border-radius: 50%; background: var(--pfill); display: grid; place-items: center; grid-row: span 3; }
.pn .ic img { width: 26px; height: 26px; display: block; }
.pn .ap { font-size: 11.5px; color: var(--mfg); font-weight: 600; }
.pn b { font-size: 13.5px; font-weight: 650; grid-column: 2; }
.pn small { font-size: 12.5px; color: var(--mfg); grid-column: 2; }
.pn .x { grid-column: 3; grid-row: 1; color: var(--mfg); }
.pn-k { display: grid; gap: 4px; }
.pn-k .lab { font-size: 11.5px; font-weight: 650; color: var(--mfg); display: flex; gap: 6px; align-items: center; }
.pn-stack { display: grid; gap: 14px; }
.pn-lock { border-radius: 16px; background: var(--chrome); border: 1px solid var(--chrome-border); padding: 22px 18px 18px; display: grid; gap: 12px; justify-items: center; color: var(--chrome-hi); }
.pn-lock .tm { font-size: 44px; font-weight: 600; letter-spacing: -0.03em; line-height: 1; font-variant-numeric: tabular-nums; }
.pn-lock .dt { font-size: 13px; color: var(--chrome-fg); margin-top: -4px; }
.pn-lock .pn { background: var(--chrome-active); border-color: var(--chrome-border); box-shadow: none; --mfg: var(--chrome-fg); color: var(--chrome-hi); }
.never { display: grid; grid-template-columns: 1fr 1fr; gap: 6px 14px; font-size: 12.5px; }
.never span { display: inline-flex; gap: 7px; align-items: center; }
.never svg { color: var(--crit); flex: none; }
.grp-seq { display: grid; gap: 8px; margin-top: 4px; }
.grp-seq .st { display: grid; grid-template-columns: 22px minmax(0, 1fr); gap: 8px; align-items: start; font-size: 12.5px; }
.grp-seq .st .n { width: 22px; height: 22px; border-radius: 50%; display: grid; place-items: center; font-size: 11px; font-weight: 700; color: var(--pt); background: var(--tint); }
.grp-seq .st b { font-weight: 650; }
.grp-seq .st small { display: block; color: var(--mfg); font-size: 12px; }
.today-list { display: grid; gap: 4px; margin-top: 4px; }
.today-list span { display: flex; gap: 8px; align-items: center; font-size: 12px; color: var(--mfg); padding: 5px 8px; border-radius: 8px; background: var(--muted); border: 1px solid var(--border); }
.today-list span b { color: var(--fg); font-weight: 600; }
`;

// ---------- the notification row (the app's row, Chat content) ----------
// lead: who/icon · kind: corner glyph · name · tag · time · what happened · preview · tags · unread dot
function nrow({ who, icon, lensT = '', kind = '', kindTone = '', name, tag = '', time, what = '', whatTone = '', whatIcon = '', pv = '', pvTwo = false, tags = '', unread = false, hov = false, hbar = '', wide = false, acts = '', cls = '', mod = '' }) {
    const lead = who ? av(who, '') : lensT ? lens(icon, lensT, 17) : icon ? (icon === 'hash' || icon === 'users' ? gl(icon) : lens(icon, '', 17)) : '';
    const k = kind ? `<span class="kd${kindTone ? ' ' + kindTone : ''}" aria-hidden="true">${svg(kind, 11)}</span>` : '';
    return `<div class="nr${unread ? ' unread' : ''}${hov ? ' hov' : ''}${wide ? ' wide' : ''}${cls ? ' ' + cls : ''}" role="listitem" aria-label="${(name + (what ? ', ' + what : '') + (unread ? ', unread' : '')).replace(/<[^>]+>/g, '')}">
<span class="ld">${lead}${k}</span>
<span class="tx"><span class="l1"><b>${name}</b>${tag ? `<span class="tg">${tag}</span>` : ''}${mod ? `<span class="mod">${mod}</span>` : ''}<time>${time}</time></span>${what ? `<span class="wh${whatTone ? ' ' + whatTone : ''}">${whatIcon ? svg(whatIcon, 13) : ''}${what}</span>` : ''}${pv ? `<span class="pv${pvTwo ? ' two' : ''}">${pv}</span>` : ''}${tags ? `<span class="tags">${tags}</span>` : ''}</span>
${wide ? `<span class="acts">${acts}</span>` : ''}${unread ? '<span class="ud" aria-hidden="true"></span>' : '<span></span>'}${hbar}</div>`;
}
const hbar = (urgent = false) => `<div class="c3-hbar" role="toolbar" aria-label="Notification actions"><button type="button" aria-label="Open conversation">${svg('chevr', 16)}</button>${urgent ? '' : `<button type="button" aria-label="Mark as read">${svg('check', 16)}</button>`}<button type="button" aria-label="Mute conversation">${svg('belloff', 16)}</button><span class="vr" aria-hidden="true"></span><button type="button" class="on" aria-label="More actions">${svg('more', 16)}</button></div>`;
const shead = (t, c = '') => `<div class="c3-sh">${t}${c ? `<span class="c">${c}</span>` : ''}</div>`;

// the rows (viewer: Rohan Patel, site lead, Monday 19 October 2026)
const R = {
    urgent: (o = {}) => nrow({ icon: 'hash', kind: 'alert', kindTone: 'crit', name: 'Kōwhai House', tag: 'Channel', time: '11:58', what: 'Urgent from Demo Admin · read and confirm', whatTone: 'crit', pv: 'The fire door on level 1 is broken. Use the back exit until it’s fixed.', tags: badge('Waiting for you to confirm', 'crit', 'sm'), unread: true, ...o }),
    mention: (o = {}) => nrow({ icon: 'users', kind: 'at', kindTone: 'at', name: 'Kōwhai afternoon team', time: '11:40', what: 'Ana Rēweti mentioned you', pv: '<span class="ch-at">@Rohan Patel</span> can you check Tane’s new plan before 2 pm?', tags: badge('and 2 more messages', 'neutral', 'sm nodot'), unread: true, ...o }),
    family: (o = {}) => nrow({ who: 'kiri', kind: 'phonein', kindTone: 'warn', name: 'Kiri Wineera · About Tane', tag: 'Family', time: '11:10', what: 'Asked for a call back · 2 new messages', pv: 'Can we visit on Saturday afternoon? His nana is coming up from Taupō.', tags: badge('Reply due by 3:00 pm', 'warn', 'sm'), unread: true, ...o }),
    missed: (o = {}) => nrow({ who: 'ana', kind: 'phonemissed', kindTone: 'crit', name: 'Ana Rēweti', time: '10:52', what: 'Missed voice call', pv: 'And a message: Ring me when you’re free.', tags: btn('Call back', 'o', { xs: true, icon: 'phone' }), unread: true, ...o }),
    grouped: (o = {}) => nrow({ icon: 'hash', name: 'Tōtara service', tag: 'Channel', time: '10:31', what: '3 new messages', pv: 'Demo Admin: The van is booked for Thursday.', unread: true, ...o }),
    other: (o = {}) => nrow({ icon: 'calendar', name: 'Fire extinguisher check', time: '9:15', what: 'Kōwhai House · due tomorrow', pv: 'Inspections and maintenance', ...o }),
    summary: (o = {}) => nrow({ icon: 'history', name: 'Since your last shift', time: '6:58', what: '14 messages in 5 conversations · 2 mention you', pv: 'Held while you were off shift. Sent when you clocked in.', tags: btn('Open summary', 'o', { xs: true }), unread: true, ...o }),
    night: (o = {}) => nrow({ icon: 'users', name: 'Night team', time: 'Yesterday', what: '5 messages', pv: 'Ana Rēweti: Handover notes are in.', ...o }),
};

// ---------- 1 · the bell, in place ----------
const bell = `<div class="oc-notif bellm" role="dialog" aria-label="Notifications">
<div class="oc-notif-h">Notifications<span class="ch-filt" style="margin-left: 6px"><button type="button" class="on">All</button><button type="button">Chat<span class="n" style="font-size: 11px">5</span></button></span><a href="#read-all">Mark all read</a></div>
<div class="nl" style="width: auto" role="list">
${shead('Today', '6 new')}
${R.urgent()}${R.mention()}${R.family()}${R.missed()}${R.grouped()}${R.other()}
${shead('Before your shift')}
${R.summary({ unread: false, tags: btn('Open summary', 'o', { xs: true }) })}
${R.night()}
</div>
<div class="ft"><a href="#notifications">See all notifications${svg('chevr', 14)}</a></div>
</div>`;
const myDay = `<div class="oc-sky"><div class="oc-sky-top"><span class="oc-mark" aria-hidden="true">${svg('circlecheck', 20)}</span><div><div class="oc-title">My day</div><div class="oc-sub-line">Monday 19 October · Kōwhai House · on shift 7:00 am to 3:00 pm</div></div><div class="oc-sky-actions"><button class="oc-gbtn" type="button">${svg('clipboard', 15)}Handover notes</button><button class="oc-wbtn" type="button">${svg('clock', 15)}Clock out</button></div></div><div style="height: 18px"></div></div>
<div class="pg-cards">
<div class="oc-card"><div class="oc-card-h"><b>Today at Kōwhai House</b><span class="oc-card-s" style="margin-left: auto">4 things</span></div><div class="oc-list">
<div class="oc-li">${lens('pill', '', 14)}<div><b>Lunchtime medicines</b><small>12:00 pm · Tane Wineera and Aroha Ngata</small></div></div>
<div class="oc-li">${lens('calendar', '', 14)}<div><b>Tane’s dentist</b><small>Thursday 2:00 pm · van booked</small></div></div>
<div class="oc-li">${lens('tasks', '', 14)}<div><b>Check Tane’s new plan</b><small>Due 2:00 pm · asked by Ana Rēweti</small></div></div>
<div class="oc-li">${lens('wrench', '', 14)}<div><b>Fire door on level 1</b><small>Being fixed at 2:00 pm</small></div></div>
</div></div>
<div class="oc-card"><div class="oc-card-h"><b>On shift with you</b></div><div class="oc-list">
<div class="oc-li">${av('ana', 's32', 'on')}<div><b>Ana Rēweti</b><small>Support worker · until 3:00 pm</small></div></div>
<div class="oc-li">${av('admin', 's32', '')}<div><b>Demo Admin</b><small>Administrator · office</small></div></div>
</div></div>
</div>`;
const bellScene = scene(myDay, { active: 'myday', crumbsT: ['Home', 'My day'], h: 1000, topO: { bellOpen: true, bellCount: 6 }, over: bell });

const bellNotes = `<div class="c3-note-row">
<div class="c3-meas"><b>The app’s row, with Chat’s words</b><p>Chat rows use the same notification row as every module: who or what, what happened, the newest line, the time and an unread dot. A small mark on the photo says what kind it is: @ for a mention, a red phone for a missed call, a red triangle for urgent.</p></div>
<div class="c3-meas"><b>One row per conversation</b><p>Six new messages in one chat are one row that says “6 new messages”, not six rows. The row moves to the top and its time updates. Opening the conversation anywhere clears it: the Chat page, a side sheet or another tab.</p></div>
<div class="c3-meas"><b>What needs you stays visible</b><p>A mention stays named in its row (“Ana Rēweti mentioned you · and 2 more messages”). Urgent messages stay until you confirm. A family row keeps its reply promise until someone in the care team replies.</p></div>
</div>`;

// ---------- 2 · the Notifications page with the Chat filter ----------
const pageRows = [
    shead('Today', '5 Chat'),
    R.urgent({ wide: true, pv: 'The fire door on level 1 is broken. Use the back exit until it’s fixed.', tags: '', mod: 'Chat', acts: btn('Read and confirm', 'p', { sm: true, icon: 'usercheck' }) }),
    R.mention({ wide: true, mod: 'Chat', tags: '', what: 'Ana Rēweti mentioned you · and 2 more messages', acts: btn('Open', 'o', { sm: true }) }),
    R.family({ wide: true, mod: 'Chat', tags: '', what: 'Asked for a call back · 2 new messages · reply due by 3:00 pm', acts: `${btn('Call Kiri', 'o', { sm: true, icon: 'phone' })}${btn('Reply', 'o', { sm: true, icon: 'reply' })}` }),
    R.missed({ wide: true, mod: 'Chat', tags: '', hov: true, acts: btn('Call back', 'o', { sm: true, icon: 'phone' }), hbar: '' }),
    R.grouped({ wide: true, mod: 'Chat', acts: btn('Open', 'o', { sm: true }) }),
    shead('Before your shift'),
    R.summary({ wide: true, unread: false, mod: 'Chat', tags: '', acts: btn('Open summary', 'o', { sm: true }) }),
    shead('Earlier'),
    R.night({ wide: true, mod: 'Chat', acts: '' }),
].join('');
const rightMenu = menu([
    mi('chevr', 'Open conversation', { k: 'Enter' }),
    mi('panelr', 'Open beside this page', { sub: 'In a side sheet' }),
    mi('phone', 'Call Ana back'),
    ms(),
    mi('check', 'Mark as read'),
    mi('belloff', 'Mute conversation', { arrow: true, on: true }),
    ms(),
    mi('sliders', 'Chat notification settings'),
], 'abs w300', 'right: 250px; top: 596px', 'Notification actions');
const muteMenu = `<div class="c3-menu abs" role="menu" aria-label="Mute" style="right: 34px; top: 724px; width: 212px">${['For 1 hour', 'For 8 hours', 'Until tomorrow 7 am', 'Until I turn it back on'].map((t, i) => mi('', t, { on: i === 1 })).join('')}${ms()}<div class="c3-mi" style="font-size: 12px; color: var(--mfg)"><span>Urgent messages and mentions still reach you</span></div></div>`;
const page = `<div class="oc-sky"><div class="oc-sky-top"><span class="oc-mark" aria-hidden="true">${svg('bell', 20)}</span><div><div class="oc-title">Notifications</div><div class="oc-sub-line">What needs you, newest first · Chat shows one row per conversation</div></div><div class="oc-sky-actions"><button class="oc-gbtn" type="button">${svg('sliders', 15)}Notification settings</button><button class="oc-wbtn" type="button">${svg('checks', 15)}Mark all read</button></div></div>
<div style="display: flex; gap: 2px; margin-top: 16px"><span class="oc-tab on">Notifications<span class="n">9</span></span><span class="oc-tab">Announcements<span class="n">1</span></span></div></div>
<div class="oc-card" style="margin-top: -6px"><div class="nfilt"><span class="ch-filt"><button type="button">All</button><button type="button">Unread<span class="n" style="font-size: 11px">6</span></button><button type="button" class="on">Chat<span class="n" style="font-size: 11px">5</span></button><button type="button">Needs action<span class="n" style="font-size: 11px">3</span></button><button type="button">Mentions<span class="n" style="font-size: 11px">1</span></button></span>${C.sel('All Sites', 'sm')}<span class="ch-find">${svg('search', 14)}Search notifications</span></div>
<div class="nl" style="width: auto; border-top: 1px solid var(--border)" role="list">${pageRows}</div></div>`;
const pageScene = scene(page, { active: 'none', crumbsT: ['Home', 'Notifications'], h: 1000, over: rightMenu + muteMenu });

// ---------- 3 · row parts ----------
const kinds = `<div class="oc-notif nl" role="list" aria-label="Kinds of Chat rows">
${nrow({ who: 'ana', name: 'Ana Rēweti', time: '11:45', what: '1 new message', pv: 'Are you coming to the 2 pm meeting?', unread: true })}
${R.grouped()}
${R.mention()}
${R.urgent()}
${R.missed()}
${nrow({ who: 'kiri', kind: 'phonein', kindTone: 'warn', name: 'Kiri Wineera · About Tane', tag: 'Family', time: '11:05', what: 'Asked for a call back', pv: 'Any time after 4 pm · on her mobile', tags: btn('Call Kiri', 'o', { xs: true, icon: 'phone' }) + btn('Mark done', 'g', { xs: true }), unread: true })}
${nrow({ who: 'kiri', kind: 'heart', name: 'Kiri Wineera · About Tane', tag: 'Family', time: '11:10', what: 'Family message waiting', pv: 'Can we visit on Saturday afternoon?', tags: badge('Reply due by 3:00 pm', 'warn', 'sm') + `<span class="oc-hint">Owner: you</span>`, unread: true })}
${nrow({ who: 'kiri', kind: 'heart', kindTone: 'crit', name: 'Kiri Wineera · About Tane', tag: 'Family', time: '11:10', what: 'Family message waiting', pv: 'Can we visit on Saturday afternoon?', tags: badge('Reply overdue · was due 3:00 pm', 'crit', 'sm'), unread: true })}
${R.summary()}
</div>`;
const states = `<div style="display: grid; gap: 14px">
<div class="oc-notif nl" role="list" aria-label="Read and unread">
${shead('Unread, then read')}
${R.grouped()}
${R.grouped({ unread: false, what: '3 messages', time: '10:31' })}
${shead('Previews off · My Chat preferences')}
${nrow({ icon: 'users', kind: 'at', kindTone: 'at', name: 'Kōwhai afternoon team', time: '11:40', what: 'Ana Rēweti mentioned you', pv: 'Open Chat to read it.', unread: true })}
${nrow({ who: 'kiri', kind: 'heart', name: 'Kiri Wineera · About Tane', tag: 'Family', time: '11:10', what: '2 new messages', pv: 'Open Chat to read them.', tags: badge('Reply due by 3:00 pm', 'warn', 'sm'), unread: true })}
${shead('Muted conversation')}
${nrow({ icon: 'hash', kind: 'belloff', name: 'Tōtara service', tag: 'Channel', time: '10:20', what: 'Muted · only your mention comes through', pv: '<span class="ch-at">@Rohan Patel</span> is the van free on Thursday?', unread: true })}
${shead('No longer allowed')}
${nrow({ icon: 'lock', lensT: 'mut', name: 'Conversation not available', time: '9:02', what: 'You’re no longer a member, or it’s at a Site you can’t see.', pv: '', cls: 'gone' })}
</div>
${note('Previews are on unless you switch them off in My Chat preferences. With them off, a row says who and how many, never the words. A row you can no longer open loses its name and preview at once.', 'tint', 'eye')}
</div>`;
const actRow = `<div style="position: relative; height: 470px">
<div class="oc-notif nl" role="list" style="position: absolute; left: 0; top: 0">${R.mention({ hov: true, hbar: hbar(false) })}${R.urgent({ hbar: '' })}</div>
${menu([
    mi('chevr', 'Open conversation', { k: 'Enter' }),
    mi('panelr', 'Open beside this page', { sub: 'In a side sheet' }),
    ms(),
    mi('check', 'Mark as read', { sub: 'Clears this row and the Chat count' }),
    mi('belloff', 'Mute conversation', { arrow: true, on: true }),
    ms(),
    mi('sliders', 'Chat notification settings'),
], 'abs w300', 'left: 60px; top: 70px', 'Notification actions')}
<div class="c3-menu abs" role="menu" aria-label="Mute" style="left: 268px; top: 194px; width: 210px">${['For 1 hour', 'For 8 hours', 'Until tomorrow 7 am', 'Until I turn it back on'].map((t, i) => mi('', t, { on: i === 0 })).join('')}</div>
</div>
${note('Hover shows Open, Mark as read and Mute. Right-click, the ⋯ button or Shift + F10 open the same list. Nothing opens a pop-up.', '', 'info')}
${note('Urgent rows have no Mark as read. They leave the bell when you confirm in the conversation.', 'crit', 'alert')}`;

// ---------- 4 · grouping rules ----------
const rules = `<div class="c3-note-row">
<div class="c3-meas"><b>Today: one entry per message</b><p>The bell gets a new entry for every message, each called “New Chat message”, with no name and no conversation. Six messages make six identical rows.</p><div class="today-list">${Array.from({ length: 4 }, (_, i) => `<span>${svg('msg', 13)}<b>New Chat message</b><span style="margin-left: auto; border: 0; background: none; padding: 0">${[2, 4, 9, 12][i]}m</span></span>`).join('')}</div></div>
<div class="c3-meas"><b>Proposed: one row that keeps count</b><div class="grp-seq">
<div class="st"><span class="n">1</span><div><b>A message arrives</b><small>Kōwhai afternoon team gets a row: “1 new message”.</small></div></div>
<div class="st"><span class="n">2</span><div><b>Two more arrive, one mentions you</b><small>The same row now says “Ana Rēweti mentioned you · and 2 more messages”.</small></div></div>
<div class="st"><span class="n">3</span><div><b>You open the conversation</b><small>The row turns read and the counts clear, on every tab and device.</small></div></div>
</div></div>
<div class="c3-meas"><b>Off shift: one notice at clock-in</b><div class="grp-seq">
<div class="st"><span class="n">1</span><div><b>You clock out</b><small>Chat stops sending you rows, sounds and browser notifications.</small></div></div>
<div class="st"><span class="n">2</span><div><b>Messages wait</b><small>Urgent messages for your Site still reach you. Everything else waits.</small></div></div>
<div class="st"><span class="n">3</span><div><b>You clock in</b><small>One row, “Since your last shift”, opens the summary in Chat. Mentions are listed first.</small></div></div>
</div></div>
</div>`;

// ---------- 5 · browser notifications ----------
const pn = (title, body, { time = 'now', site = '' } = {}) => `<div class="pn" role="img" aria-label="Browser notification: ${title}. ${body}"><span class="ic"><img src="brand/favicon-white.svg" alt=""></span><span class="ap">Oblivion Care · ${time}</span><span class="x">${svg('x', 14)}</span><b>${title}</b><small>${body}${site ? ` · ${site}` : ''}</small></div>`;
const pk = (label, inner, icon = 'msg') => `<div class="pn-k"><span class="lab">${svg(icon, 13)}${label}</span>${inner}</div>`;
const pushStaff = `<div class="pn-stack">
${pk('A message', pn('Ana Rēweti', 'New message in Kōwhai afternoon team'))}
${pk('A mention', pn('Ana Rēweti mentioned you', 'In Kōwhai afternoon team'), 'at')}
${pk('More messages in the same chat · replaces the last one', pn('3 new messages', 'In Tōtara service'), 'layers')}
${pk('Urgent · read and confirm', pn('Urgent message from Demo Admin', 'Open Oblivion Care to read and confirm', { site: 'Kōwhai House' }), 'alert')}
${pk('Missed call', pn('Missed call from Ana Rēweti', 'Open Oblivion Care to call back'), 'phonemissed')}
</div>`;
const pushFamily = `<div class="pn-stack">
${pk('Family message waiting', pn('New family message', 'A reply is due by 3:00 pm', { site: 'Kōwhai House' }), 'heart')}
${pk('Callback request', pn('A family member asked for a call back', 'Open Oblivion Care to see who', { site: 'Kōwhai House' }), 'phonein')}
${pk('Since your last shift', pn('Since your last shift', '14 messages in 5 conversations'), 'history')}
<div class="c3-meas" style="gap: 10px"><b>Never in a browser notification</b><div class="never"><span>${svg('x', 13)}What the message says</span><span>${svg('x', 13)}The person supported</span><span>${svg('x', 13)}Family names</span><span>${svg('x', 13)}Photos and files</span><span>${svg('x', 13)}Care or health details</span><span>${svg('x', 13)}Record numbers</span></div></div>
</div>`;
const pushLock = `<div class="pn-stack">
${pk('When details are hidden · locked or shared computers', `<div class="pn-lock"><span class="tm">11:58</span><span class="dt">Monday 19 October</span>${pn('Oblivion Care', 'You have 4 new notifications')}</div>`, 'lock')}
${note('<b>Hide details in browser notifications</b> is a switch in My Chat preferences. With it on, every notification looks like this one. Family details are hidden either way.', 'tint', 'eyeoff')}
${note('Signing out removes Chat notifications already on this computer. A notification only opens Chat after Oblivion checks you can still see that conversation.', '', 'shield')}
${note('<b>Notifications blocked in this browser.</b> New messages still show in the bell and the Chat count.', 'warn', 'belloff')}
</div>`;

const sections = [
    sec('In the bell', 'One row per conversation · mentions, urgent, missed calls, callbacks and family replies in the app’s notification row', `<div class="c3-stage">${bellScene}</div>`),
    sec('Why it works this way', 'C7.7 and C1.4 · built from the plan, decided 22 October', bellNotes),
    sec('On the Notifications page', 'Full page at 1440 × 1000 · the Chat filter, wider rows with their main action, right-click and the mute choices', `<div class="c3-stage">${pageScene}</div>`),
    sec('Row parts', 'Every kind of Chat row, read and unread, previews off, muted, and the hover and right-click actions', `<div class="c3-r3">${col('Every kind of Chat row', kinds)}${col('Read, unread, previews off, muted', states)}${col('Hover, right-click and Mute', actRow)}</div>`),
    sec('How rows group', 'Today the bell gets one row per message; the rebuild keeps one per conversation', rules),
    sec('Browser notifications', 'Say who wrote, never what · the brand icon · family details hidden', `<div class="c3-r3">${col('Staff conversations · who wrote, never what', pushStaff)}${col('Family conversations · no names, no words', pushFamily)}${col('Locked screens, shared computers, blocked', pushLock)}</div>`),
];
const size = writeBoard('ChatV3-Notifications.html', 'Chat v3 — notifications', 'Chat v3 · notifications', 'How Chat tells you something happened: one row per conversation in the bell and on the Notifications page, and browser notifications that never show the message.', sections, STYLE);
console.log('ChatV3-Notifications.html', size);
