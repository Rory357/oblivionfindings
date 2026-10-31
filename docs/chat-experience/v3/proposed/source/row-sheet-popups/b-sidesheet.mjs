// ChatV3-SideSheet.html — C7.3, one conversation in a right-hand side sheet over another page
import * as C from './lib.mjs';
const { svg, av, gl, btn, ib, lens, badge, e, col, sec, note, group, ownMeta, day, unreadLine, typing, card, composer, rc, rx, mi, ms, menu, scene, writeBoard, skel } = C;

const STYLE = `
.oc-app .oc-sheet.ss { top: 56px; width: 640px; z-index: 6; }
.ss-box { position: relative; width: 640px; display: flex; flex-direction: column; background: var(--card); border: 1px solid var(--border); border-radius: 16px; box-shadow: var(--shadow-lg); overflow: hidden; }
.t-d .ss-box::after { content: ''; position: absolute; top: 0; bottom: 0; left: -1px; width: 2px; background: var(--horizon-v); pointer-events: none; z-index: 4; }
.oc-sh.ss-h { padding: 12px 12px 0 18px; display: grid; gap: 8px; flex: none; }
.ss-h .r1 { display: flex; gap: 12px; align-items: center; min-width: 0; }
.ss-h .id { min-width: 0; }
.ss-h h3 { margin: 0; font-size: 16px; font-weight: 650; letter-spacing: -0.01em; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.ss-h .id p { margin: 1px 0 0; font-size: 12.5px; color: var(--mfg); white-space: nowrap; }
.ss-h .acts { margin-left: auto; display: flex; gap: 2px; align-items: center; flex: none; }
.ss-h .ch-ib { color: var(--band); }
.ss-h .acts .vr { width: 1px; height: 20px; background: color-mix(in oklch, var(--band) 26%, transparent); margin: 0 4px; }
.ss-h .oc-x { margin-left: 2px; }
.ss-h .ch-av, .ss-h .c3-gl { --ring-gap: var(--sky-ink); }
.ss-h .c3-gl { color: var(--band); background: color-mix(in oklch, var(--band) 12%, transparent); box-shadow: inset 0 0 0 2px var(--rim); }
.ss-h .r2 { display: flex; align-items: flex-end; gap: 10px; }
.ss-h .oc-stabs { margin-top: 0; }
.ss-h .oc-skychip { margin: 0 0 9px auto; }
.ss-h .oc-stab .n { font-size: 11px; font-weight: 700; margin-left: 4px; opacity: 0.85; }
.ss .c3-msgs, .ss-box .c3-msgs { padding: 6px 20px 0; }
.ss .c3-g, .ss-box .c3-g { max-width: 86%; }
.ss .c3-cc, .ss-box .c3-cc { width: 348px; }
.ss .c3-hint, .ss-box .c3-hint { padding: 0 52px 0 54px; }
.ss .c3-bar, .ss-box .c3-bar { padding-left: 16px; }
.ss-ro { display: flex; align-items: center; gap: 10px; padding: 12px 16px; border-top: 1px solid var(--border); background: var(--muted); font-size: 12.5px; }
.ss-ro b { font-weight: 650; }
.ss-ro .oc-btn { margin-left: auto; flex: none; }
.ss-mid { flex: 1; display: grid; place-items: center; padding: 24px; }
.ss-mid .oc-empty { max-width: 380px; }
.ss-mid .oc-empty p { max-width: 360px; }
.ss-sk { display: grid; gap: 14px; padding: 18px 20px; flex: 1; align-content: end; }
.ss-sk .b { display: grid; grid-template-columns: 32px minmax(0, 1fr); gap: 10px; align-items: start; }
.ss-sk .b .c { width: 32px; height: 32px; border-radius: 50%; background: var(--muted); }
.ss-sk .b > div { display: grid; gap: 7px; }
.ss-sk .own { justify-self: end; width: 60%; display: grid; gap: 7px; justify-items: end; }
.pg-grid { display: grid; grid-template-columns: 524px minmax(0, 1fr); gap: 20px; align-items: start; }
.pg-grid .oc-card-h b { font-size: 15px; font-weight: 650; }
.pg-grid .oc-glance dd .ch-lnk { margin-left: 6px; }
.trig { display: grid; gap: 12px; padding: 14px 16px; border-radius: 14px; border: 1px solid var(--border); background: var(--card); box-shadow: var(--shadow); }
.trig .hd { display: flex; gap: 10px; align-items: center; }
.trig .hd b { display: block; font-size: 13.5px; font-weight: 650; }
.trig .hd small { display: block; font-size: 12px; color: var(--mfg); }
.trig .row { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; }
.trig .arrow { display: flex; gap: 8px; align-items: center; font-size: 12px; color: var(--mfg); border-top: 1px dashed var(--border); padding-top: 10px; }
.trig .arrow svg { color: var(--pt); flex: none; }
.trig .oc-btn .ch-n { height: 18px; min-width: 18px; font-size: 10.5px; margin-left: 2px; }
.rc-pop { width: 340px; }
.rc-pop .hd { display: flex; align-items: center; justify-content: space-between; padding: 12px 14px 6px; font-weight: 650; font-size: 13.5px; }
.rc-pop .c3-list { padding: 0 6px 6px; }
.rc-pop .ft { gap: 12px; display: flex; justify-content: space-between; align-items: center; padding: 9px 14px; border-top: 1px solid var(--border); background: var(--muted); font-size: 12px; color: var(--mfg); }
.rc-pop .ft a { font-size: 13px; font-weight: 600; color: var(--pt); text-decoration: none; display: inline-flex; gap: 6px; align-items: center; }
.stackd { position: relative; height: 520px; border-radius: 14px; overflow: hidden; border: 1px solid var(--border); background: var(--bg); box-shadow: var(--shadow); }
.stackd .t { position: absolute; left: 0; right: 0; top: 0; height: 28px; background: var(--chrome); }
.stackd .n { position: absolute; left: 0; top: 28px; bottom: 0; width: 118px; background: var(--chrome); border-top: 1px solid var(--chrome-border); }
.stackd .pg { position: absolute; left: 128px; top: 40px; right: 330px; bottom: 12px; border-radius: 10px; background: var(--card); border: 1px solid var(--border); padding: 10px; display: grid; align-content: start; gap: 6px; }
.stackd .pg i { display: block; height: 6px; border-radius: 3px; background: color-mix(in oklch, var(--mfg) 28%, transparent); }
.stackd .pg i.h { height: 34px; border-radius: 8px; background: var(--sky); }
.stackd .sh { position: absolute; right: 0; top: 28px; bottom: 0; width: 320px; background: var(--card); border-left: 1px solid var(--border); box-shadow: var(--shadow-lg); display: grid; grid-template-rows: 46px 1fr 40px; }
.stackd .sh .h { background: var(--sky); }
.stackd .sh .m { padding: 10px; display: grid; gap: 6px; align-content: end; }
.stackd .sh .m i { display: block; height: 14px; border-radius: 8px; background: var(--muted); border: 1px solid var(--border); width: 70%; }
.stackd .sh .m i.o { justify-self: end; width: 55%; background: color-mix(in oklch, var(--primary) 11%, var(--card)); }
.stackd .sh .c { border-top: 1px solid var(--border); margin: 0 8px; }
.stackd .dk { position: absolute; right: 336px; bottom: 12px; width: 182px; height: 70px; border-radius: 10px; background: var(--chrome); border: 1px solid var(--chrome-border); box-shadow: var(--shadow-lg); }
.stackd .ic { position: absolute; right: 8px; top: 36px; width: 182px; height: 54px; border-radius: 10px; background: var(--card); border: 1px solid var(--border); box-shadow: var(--shadow-lg); }
.stackd .lb { position: absolute; font-size: 11px; font-weight: 700; color: var(--fg); background: var(--card); border: 1px solid var(--border); border-radius: 6px; padding: 1px 6px; white-space: nowrap; box-shadow: var(--shadow); }
.stackd .lb.ch { color: var(--chrome-hi); background: var(--chrome-active); border-color: var(--chrome-border); }
.stackd .ms { position: absolute; display: flex; align-items: center; gap: 4px; font-size: 10.5px; font-weight: 700; color: var(--pt); }
.stackd .ms::before, .stackd .ms::after { content: ''; height: 1px; flex: 1; background: var(--primary); }
.layers { display: grid; gap: 6px; }
.layers .l { display: grid; grid-template-columns: 26px minmax(0, 1fr); gap: 10px; align-items: center; font-size: 12.5px; padding: 7px 10px; border-radius: 10px; border: 1px solid var(--border); background: var(--card); }
.layers .l b { font-weight: 650; }
.layers .l small { display: block; color: var(--mfg); font-size: 12px; }
.layers .l .k { width: 26px; height: 26px; border-radius: 50%; display: grid; place-items: center; font-size: 11.5px; font-weight: 750; color: var(--pt); background: var(--tint); }
`;

// ---------- the sheet ----------
function sheetHead({ who, icon, state = 'on', name, sub, chip = '', tabs = ['Chat', 'Files', 'Pinned 1'], calls = true, back = false, sk = false }) {
    const lead = sk ? `<span class="c3-gl s40"></span>` : who ? av(who, 's40', state) : gl(icon, 's40');
    const tab = (t, i) => { const [x, n] = t.split(/ (?=\d)/); return `<span class="oc-stab${i === 0 ? ' on' : ''}">${x}${n ? `<span class="n">${n}</span>` : ''}</span>`; };
    return `<div class="oc-sh ss-h"><div class="r1">${back ? ib('chevl', 'Back to the previous conversation', '', 18) : ''}${lead}<div class="id">${sk ? `<h3>${skel('150px', 12)}</h3><p style="margin-top: 7px">${skel('220px', 9)}</p>` : `<h3>${name}</h3><p>${sub}</p>`}</div><div class="acts">${calls ? ib('phone', 'Voice call', '', 18) + ib('video', 'Video call', '', 19) + '<span class="vr" aria-hidden="true"></span>' : ''}<button class="oc-gbtn sm" type="button">${svg('expand', 14)}Open in Chat</button>${ib('more', 'More', '', 18)}<button class="oc-x" type="button" aria-label="Close">${svg('x', 18)}</button></div></div>
<div class="r2">${tabs.length ? `<div class="oc-stabs" role="tablist">${tabs.map(tab).join('')}</div>` : '<div style="height: 10px"></div>'}${chip ? `<span class="oc-skychip">${chip}</span>` : ''}</div></div>`;
}
const msgs = (inner, cls = '') => `<div class="c3-msgs${cls ? ' ' + cls : ''}" role="log" aria-label="Messages">${inner}</div>`;

// ---------- 1 · full scene: Tane's profile with a chat open beside it ----------
const carePlanCard = card({ icon: 'clipboard', md: 'Care plan', title: 'Tane Wineera · eating and drinking', facts: 'Updated today 8:50 am by Ana Rēweti · Soft food until the dentist', badgeT: 'Changed today', tone: 'info', action: 'Show on this page', actionIcon: 'arrdown' });
const incCard = card({ icon: 'alert', md: 'Incident', title: 'Slip in the downstairs bathroom', refn: 'INC-0142', facts: 'Tane Wineera · Kōwhai House · 9:12 am', badgeT: 'Open', tone: 'warn', action: 'Open incident', m: 'Opens under this chat' });
const mainMsgs = [
    day('Yesterday'),
    group({ who: 'ana', time: '4:40 pm', bubbles: ['Tane didn’t want his lunch today. He said his tooth hurts again.'] }),
    group({ who: 'rohan', own: true, bubbles: ['Thanks Ana. I’ll book the dentist for Thursday.'], meta: ownMeta('4:52 pm', 'read') }),
    day('Today · Monday 19 October'),
    group({ who: 'ana', time: '9:20 am', bubbles: [{ raw: incCard }, 'He’s fine, just a bruised knee. I’ve changed his plan to soft food until Thursday.', { raw: carePlanCard }], after: rx(rc('🙏', 1, true)) }),
    unreadLine('1 new message'),
    group({ who: 'ana', time: '11:41 am', bubbles: ['Can you check the plan before 2 pm? Kiri asked about Saturday too. I said you’d call her.'] }),
].join('');
const sheet = `<aside class="oc-sheet ss" role="dialog" aria-modal="false" aria-label="Chat with Ana Rēweti">
${sheetHead({ who: 'ana', name: 'Ana Rēweti', sub: 'Support worker · on shift at Kōwhai House until 3 pm', chip: `${svg('user', 13)}From Tane Wineera’s profile` })}
${msgs(mainMsgs)}
${composer({ text: 'Checking it now. I’ll call Kiri after lunch.', focus: true, aud: 'Only you and Ana' })}
</aside>`;
const profile = `<div class="oc-sky"><div class="oc-sky-top">${av('tane', 's52', '', 'box-shadow: 0 0 0 3px color-mix(in oklch, var(--rim) 70%, transparent)')}<div><div class="oc-title">Tane Wineera<span class="oc-skychip">${svg('home', 13)}Kōwhai House</span></div><div class="oc-sub-line">Person supported · key worker Ana Rēweti · plan reviewed 2 October</div></div><div class="oc-sky-actions"><button class="oc-gbtn" type="button">${svg('heart', 15)}Family chat<span class="ch-n" style="height: 18px; min-width: 18px; font-size: 10.5px">2</span></button><button class="oc-wbtn" type="button">${svg('plus', 15)}Add note</button></div></div>
<div style="display: flex; gap: 2px; margin-top: 16px"><span class="oc-tab on">Overview</span><span class="oc-tab">Care plan</span><span class="oc-tab">Notes</span><span class="oc-tab">Medication</span><span class="oc-tab">Family<span class="n">2</span></span><span class="oc-tab">Documents</span></div></div>
<div class="pg-grid">
<div style="display: grid; gap: 20px">
<div class="oc-card"><div class="oc-card-h"><b>At a glance</b></div><div style="padding: 14px 16px"><dl class="oc-glance">
<dt>Lives at</dt><dd>Kōwhai House · Tōtara service</dd>
<dt>Key worker</dt><dd>${av('ana', 's28', 'on')}Ana Rēweti<button class="ch-lnk" type="button">Message</button></dd>
<dt>Site lead</dt><dd>Rohan Patel (you)</dd>
<dt>Family</dt><dd>Kiri Wineera, Mum<button class="ch-lnk" type="button">Family chat</button></dd>
<dt>Next review</dt><dd>2 April 2027</dd>
</dl></div></div>
<div class="oc-card"><div class="oc-card-h"><b>Today</b><span class="oc-card-s" style="margin-left: auto">3 notes</span></div><div class="oc-list">
<div class="oc-li">${lens('alert', 'warn', 14)}<div><b>Slip in the downstairs bathroom</b><small>9:12 am · INC-0142 · bruised knee, no treatment needed</small></div></div>
<div class="oc-li">${lens('clipboard', '', 14)}<div><b>Care plan changed</b><small>8:50 am · soft food until Thursday · Ana Rēweti</small></div></div>
<div class="oc-li">${lens('pill', '', 14)}<div><b>Morning medicines given</b><small>8:05 am · Ana Rēweti</small></div></div>
</div></div>
</div>
<div style="display: grid; gap: 20px">
<div class="oc-card"><div class="oc-card-h"><b>Care plan</b></div><div style="padding: 14px 16px; display: grid; gap: 8px">${skel('80%')}${skel('60%')}${skel('70%')}</div></div>
<div class="oc-card"><div class="oc-card-h"><b>Family</b></div><div style="padding: 14px 16px; display: grid; gap: 8px">${skel('50%')}${skel('65%')}</div></div>
</div>
</div>`;
const mainScene = scene(profile, { active: 'clients', crumbsT: ['Home', 'Operations', 'Clients', 'Tane Wineera'], over: sheet });

const why = `<div class="c3-note-row">
<div class="c3-meas" id="ss-meas"><b>Messages keep the sheet</b><p>Below the header and above the one-line composer, the messages get 762 of the sheet’s 944 px (81%). Today’s 720 px sheet squeezes the whole two-pane workspace, list and all, into the same space.</p><div class="bar" aria-hidden="true"><i class="m" style="width: 81%"></i></div><div class="lg"><span class="m">Messages</span><span>Header and composer</span></div></div>
<div class="c3-meas"><b>One conversation, one width</b><p>The kit’s 640 px side panel, with the same grouped bubbles, care cards, reactions and composer as the Chat page. The page keeps 564 px beside it. No conversation list: “Open in Chat” goes to the full page with the same conversation, place and draft.</p></div>
<div class="c3-meas"><b>The page underneath still works</b><p>No dark overlay. You can scroll and read the profile while you chat. A care card for the page you are on says “Show on this page” and scrolls to it. Other cards open their page under the sheet, and the sheet stays open.</p></div>
</div>`;

// ---------- 2 · where it opens from ----------
const trig = (icon, title, sub, inner, opens) => `<div class="trig"><div class="hd">${lens(icon, '', 15)}<div><b>${title}</b><small>${sub}</small></div></div><div class="row">${inner}</div><div class="arrow">${svg('panelr', 14)}<span>${opens}</span></div></div>`;
const recent = `<div class="c3-pop rc-pop" role="dialog" aria-label="Recent chats"><div class="hd">Recent chats</div><div class="c3-list">
${C.crowLite('users', '', 'Kōwhai afternoon team', '11:40', 'Ana: @Rohan Patel can you check…', 1, true)}
${C.crowLite('', 'ana', 'Ana Rēweti', '11:41', 'Can you check the plan before 2 pm?', 1, false, true)}
${C.crowLite('', 'kiri', 'Kiri Wineera', '11:10', 'About Tane · Can we visit on Saturday?', 2, false, false, 'Family')}
${C.crowLite('hash', '', 'Kōwhai House', '11:58', 'Demo Admin: The fire door on level 1…', 1)}
</div><div class="ft"><span>Opens beside this page</span><a href="#chat">Open Chat${svg('chevr', 13)}</a></div></div>`;
const triggers = `<div class="c3-r4">
${col('A person’s profile', trig('user', 'Tane Wineera', 'Overview tab', `${btn('Family chat', 'o', { sm: true, icon: 'heart' }).replace('Family chat</button>', 'Family chat<span class="ch-n">2</span></button>')}${btn('Message key worker', 'o', { sm: true, icon: 'msg' })}`, 'Kiri’s family conversation about Tane, or a chat with Ana. The unread count is on the button.'))}
${col('A shift', trig('calendar', 'Saturday 24 October · 3–11 pm', 'Kōwhai House · Ana Rēweti', `${btn('Message Ana', 'o', { sm: true, icon: 'msg' })}${btn('Swap shift', 'g', { sm: true })}`, 'The chat with Ana, with “From Saturday’s shift” on the header. The shift isn’t sent unless you add it as a card.'))}
${col('An incident', trig('alert', 'INC-0142 · Slip in the bathroom', 'Tane Wineera · Kōwhai House', `${btn('Discuss in Chat', 'o', { sm: true, icon: 'msg' })}`, 'Kōwhai House channel with the incident card ready in the composer. You choose whether to send it.'))}
${col('The header Chat button', recent)}
</div>
${note('The same sheet opens from eMAR “Message lead” and from Control Room. Nothing from the page is shared on its own: a card is only sent when you send it.', 'tint', 'shield')}`;

// ---------- 3 · states ----------
const famHead = sheetHead({ who: 'kiri', state: '', name: 'Kiri Wineera · About Tane', sub: 'Whānau · Tane Wineera · Kōwhai House', chip: `${svg('user', 13)}From Tane Wineera’s profile`, tabs: ['Chat', 'Files', 'Visits 1'] });
const famBar = `<div class="c3-bar fam"><span class="c3-aud">${lens('shield', '', 11)}Family + care team can read this</span><span class="sep" aria-hidden="true"></span><span><span class="m">Owner</span> <b>Rohan Patel</b></span><span class="sep" aria-hidden="true"></span><span class="c3-due">${badge('Reply due by 3:00 pm', 'warn')}</span></div>`;
const famSlot = `<div class="c3-pin">${lens('phonein', 'warn', 12)}<span style="min-width: 0; display: grid"><b>Kiri asked for a call back</b><span class="tx">Any time after 4 pm · on her mobile</span></span><span class="r">${btn('Call Kiri', 'o', { xs: true, icon: 'phone' })}${btn('Done', 'g', { xs: true })}</span></div>`;
const famMsgs = msgs([
    day('Today · Monday 19 October'),
    group({ who: 'kiri', time: '11:05 am', bubbles: ['Kia ora, could someone ring me this afternoon about Tane’s tooth?'] }),
    unreadLine('1 new message'),
    group({ who: 'kiri', time: '11:10 am', bubbles: ['Can we visit on Saturday afternoon? His nana is coming up from Taupō.'] }),
].join(''));
const famSheet = `<div class="ss-box" style="height: 640px" role="dialog" aria-label="Kiri Wineera, about Tane">${famHead}${famBar}${famSlot}${famMsgs}${composer({ ph: 'Message Kiri and the care team…', aud: 'Kiri and Tane’s care team' })}</div>`;

const firstSheet = `<div class="ss-box" style="height: 640px" role="dialog" aria-label="Chat with Ana Rēweti">${sheetHead({ who: 'ana', name: 'Ana Rēweti', sub: 'Support worker · on shift at Kōwhai House until 3 pm', chip: `${svg('user', 13)}From Tane Wineera’s profile`, tabs: ['Chat', 'Files'] })}
<div class="ss-mid"><div class="oc-empty">${av('ana', 's64', 'on')}<b>This is the start of your chat with Ana</b><p>Only you and Ana can read it. Nothing from Tane’s profile is shared unless you add it.</p>${btn('Add Tane’s profile as a card', 'o', { sm: true, icon: 'plus' })}</div></div>
${composer({ text: 'Hi Ana, has Tane eaten anything since lunch?', focus: true, aud: 'Only you and Ana' })}</div>`;

const closedSheet = `<div class="ss-box" style="height: 520px" role="dialog" aria-label="Kōwhai night team, closed">${sheetHead({ icon: 'users', name: 'Kōwhai night team', sub: 'Private group · 6 people · closed', calls: false, tabs: ['Chat', 'Files', 'Pinned 2'] })}
${msgs([day('Wednesday 28 October'), group({ who: 'ana', time: '6:40 am', bubbles: ['Handover notes are in. Tane slept well.'] }), group({ who: 'rohan', own: true, bubbles: ['Thanks all. This group closes today. Night shifts use Kōwhai House from now on.'], meta: ownMeta('7:02 am', 'read') })].join(''))}
<div class="ss-ro">${lens('lock', 'mut', 13)}<span><b>Rohan Patel closed this group on 28 October.</b> You can read it, but not reply.</span>${btn('Go to Kōwhai House', 'o', { sm: true })}</div></div>`;

const noAccess = `<div class="ss-box" style="height: 520px" role="dialog" aria-label="Conversation not available">${sheetHead({ icon: 'lock', name: 'Conversation not available', sub: 'Chat', calls: false, tabs: [] }).replace('<button class="oc-gbtn sm" type="button">' + svg('expand', 14) + 'Open in Chat</button>', '')}
<div class="ss-mid"><div class="oc-empty"><span class="ei">${svg('lock', 22)}</span><b>You can’t open this conversation</b><p>You’re not a member, or it belongs to a Site you can’t see. Ask your site lead if you need it.</p>${btn('Close', 'o', { sm: true })}</div></div>
<div style="padding: 0 18px 18px">${note('If you were removed: “Rohan Patel removed you from Kōwhai afternoon team on 29 October.” Earlier messages stay hidden.', '', 'info')}</div></div>`;

const loading = `<div class="ss-box" style="height: 440px" role="dialog" aria-label="Loading conversation" aria-busy="true">${sheetHead({ sk: true, calls: false, tabs: ['Chat', 'Files'] })}
<div class="ss-sk"><div class="b"><span class="c"></span><div>${skel('40%', 9)}${skel('75%', 30)}</div></div><div class="own">${skel('100%', 30)}</div><div class="b"><span class="c"></span><div>${skel('30%', 9)}${skel('60%', 30)}</div></div></div>
${composer({ ph: 'Loading…', hint: false }).replace('class="c3-comp"', 'class="c3-comp" style="opacity: 0.55"')}</div>`;
const failed = `<div class="ss-box" style="height: 440px" role="dialog" aria-label="Couldn’t load conversation">${sheetHead({ who: 'ana', name: 'Ana Rēweti', sub: 'Support worker', calls: false, tabs: ['Chat', 'Files'] })}
<div class="ss-mid"><div class="oc-empty"><span class="ei" style="color: var(--crit); background: var(--crit-bg)">${svg('alertc', 22)}</span><b>Couldn’t load this conversation</b><p>Check your connection and try again. Your unsent message is kept.</p>${btn('Try again', 'o', { sm: true, icon: 'refresh' })}</div></div></div>`;

const states = `<div class="c3-r2">
${col('Family conversation · the slim bar and one notice slot', famSheet)}
${col('First message from a profile · nothing shared on its own', firstSheet)}
${col('Closed by its owner · read only', closedSheet + note('Archived for you is different: the sheet looks normal, and a new message brings the chat back to your list.', '', 'archive'))}
${col('No access · the name is never shown', noAccess)}
${col('Loading', loading)}
${col('Couldn’t load', failed)}
</div>`;

// ---------- 4 · width and stacking ----------
const diagram = `<div class="stackd" aria-label="Diagram of the screen at 1440 px">
<span class="t"></span><span class="n"></span>
<div class="pg"><i class="h"></i><i style="width: 70%"></i><i style="width: 85%"></i><i style="width: 60%"></i><i style="width: 75%"></i></div>
<div class="sh"><span class="h"></span><div class="m"><i></i><i class="o"></i><i></i><i class="o"></i></div><span class="c"></span></div>
<span class="dk"></span><span class="ic"></span>
<span class="lb ch" style="left: 10px; top: 60px">Nav 236</span>
<span class="lb" style="left: 150px; top: 230px">Page keeps 564</span>
<span class="lb" style="right: 110px; top: 250px">Chat sheet 640</span>
<span class="lb ch" style="right: 352px; bottom: 50px">Call dock 364</span>
<span class="lb" style="right: 16px; top: 98px">Incoming call 364</span>
<span class="ms" style="right: 0; width: 320px; top: 496px">640 px</span>
</div>`;
const layers = `<div class="layers">
<div class="l"><span class="k">5</span><div><b>Toasts</b><small>“Message sent”, Undo. Bottom left, above everything.</small></div></div>
<div class="l"><span class="k">4</span><div><b>Pop-ups, with the dark overlay</b><small>Share, turn into a task, report a concern. Centred over the page and the sheet; focus goes back to the sheet after.</small></div></div>
<div class="l"><span class="k">3</span><div><b>Calls</b><small>The incoming call card sits top right over the sheet header. The call dock moves to the left of the sheet so it never covers the composer.</small></div></div>
<div class="l"><span class="k">2</span><div><b>The chat sheet</b><small>One at a time. Opening another conversation replaces it, with a back arrow to the one before.</small></div></div>
<div class="l"><span class="k">1</span><div><b>The page</b><small>Still scrolls and works. No overlay.</small></div></div>
</div>`;
const rules = `<div style="display: grid; gap: 12px">
${note('<b>Esc or ✕ closes the sheet.</b> Your unsent message stays with the conversation, so closing never asks “Discard this draft?”.', '', 'x')}
${note('<b>It stays open when the page changes</b> through a care card. Moving to another part of Oblivion from the main nav closes it.', '', 'panelr')}
${note('<b>A record’s own side panel</b> (for example Edit care plan) and the chat sheet never sit side by side. Chat opens over it; closing Chat shows the panel again with its typing kept.', 'warn', 'layers')}
${note('<b>The sidebar Chat link</b> still opens the full Chat page. The header Chat button lists recent chats; choosing one opens this sheet.', '', 'msg')}
</div>`;
const stacking = `<div style="display: grid; grid-template-columns: 720px minmax(0, 1fr) minmax(0, 1fr); gap: 20px; align-items: start">${col('The screen at 1440 px, drawn at half size', diagram)}${col('What sits on top of what', layers)}${col('Rules', rules)}</div>`;

const sections = [
    sec('A conversation beside your work', 'Full page at 1440 × 1000 · Tane Wineera’s profile with a chat open in the 640 px side sheet', `<div class="c3-stage">${mainScene}</div>`),
    sec('Why it works this way', 'C7.3 · one conversation, never the two-pane workspace squeezed into 720 px', why),
    sec('Where it opens from', 'Profiles, shifts, incidents, eMAR, Control Room and the header Chat button all open the same sheet', triggers),
    sec('States in the sheet', 'Family, a first message, closed, no access, loading and couldn’t load · drawn at the real 640 px width', states),
    sec('Width and stacking', 'How the sheet shares a 1440 px screen with the page, calls, pop-ups and toasts', stacking),
];
const size = writeBoard('ChatV3-SideSheet.html', 'Chat v3 — side sheet', 'Chat v3 · side sheet', 'One conversation in a right-hand sheet over another page, so you can chat without leaving what you are doing.', sections, STYLE);
console.log('ChatV3-SideSheet.html', size);
