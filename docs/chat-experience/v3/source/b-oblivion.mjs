import * as C from './c3lib.mjs';
const { svg, av, gl, btn, ib, lens, badge, e, col, sec, note, group, ownMeta, day, composer, mi, ms, mc, menu, dr, dlg, panel, pp, card, field, input, sel, box, writeBoard } = C;

const mini = (inner, style = '') => `<div class="ch-mini"${style ? ` style="${style}"` : ''}>${inner}</div>`;
const msgs = (inner) => `<div class="c3-msgs top" style="flex: none; padding-bottom: 14px">${inner}</div>`;
const stackc = (...cards) => `<div style="display: grid; gap: 12px">${cards.join('')}</div>`;
const trail = `<div class="oc-trail" aria-hidden="true"><span class="dot lit"></span><span class="seg lit"></span><span class="dot now"></span><span class="seg"></span><span class="dot"></span></div>`;
const nums = (...b) => `<div class="nums">${b.join('')}</div>`;
const dtb = (b, s) => `<button class="oc-dt" type="button"><span class="ti">${svg('cal', 16)}</span><span><b>${b}</b>${s ? `<small>${s}</small>` : ''}</span>${svg('chev', 15, 'chev')}</button>`;

// ---------- 1 · care cards ----------
const W = 'width: 100%';
const cards1 = stackc(
    card({ icon: 'user', md: 'Person supported', title: 'Tane Wineera', facts: 'Kōwhai House · Room 3 · Key worker Mere Thompson', extra: nums(badge('Allergy: penicillin', 'crit'), badge('Whānau visiting Saturday', 'info')), action: 'Open profile', style: W }),
    card({ icon: 'alert', md: 'Incident', title: 'Slip in the downstairs bathroom', refn: 'INC-0142', facts: 'Tane Wineera · Kōwhai House · Reported 9:12 am', badgeT: 'Open', tone: 'warn', action: 'Open incident', style: W }),
    card({ icon: 'clock', md: 'Shift', title: 'Mere Thompson · 7:00 am–3:00 pm', facts: 'Kōwhai House · Started 6:58 am · 2 h 40 min left', badgeT: 'On shift', tone: 'live', extra: trail, action: 'Open shift', style: W }),
    card({ icon: 'pill', md: 'Medication round', title: 'Lunchtime round · 12:00 pm', facts: 'Kōwhai House · 6 people', extra: nums(badge('1 overdue', 'crit'), badge('4 due', 'warn'), badge('9 given', 'ok')), action: 'Open round', m: 'For staff who give medicines', style: W }),
);
const cards2 = stackc(
    card({ icon: 'tasks', md: 'Task', title: 'Book the dentist for Tane', facts: 'Due Friday 23 October · Assigned to Hana Wilson', badgeT: 'To do', tone: 'info', action: 'Open task', style: W }),
    card({ icon: 'calplus', md: 'Open shift', title: 'Saturday 24 October · 3–11 pm', facts: 'Kōwhai House · Support worker', badgeT: 'Needs cover', tone: 'warn', action: 'Offer to cover', style: W }),
    card({ icon: 'sun', md: 'Leave request', title: 'Hana Wilson · Annual leave', facts: '4–8 November · 4 working days', badgeT: 'Waiting for approval', tone: 'neutral', action: 'Review', m: 'You can approve this', style: W }),
    card({ icon: 'truck', md: 'Vehicle booking', title: 'Van 2 · Thursday 10 am–1 pm', facts: 'Outing to the library · Driver Alex Morgan', badgeT: 'Booked', tone: 'ok', action: 'Open booking', style: W }),
);
const cards3 = stackc(
    card({ icon: 'wrench', md: 'Maintenance job', title: 'Bathroom extractor fan', facts: 'Kōwhai House · downstairs · reported by Rohan Patel', badgeT: 'Booked Monday', tone: 'info', action: 'Open job', style: W }),
    card({ icon: 'monitor', md: 'IT request', title: 'Office laptop won’t charge', refn: 'IT-0412', facts: 'Kōwhai House office · IT support', badgeT: 'In progress', tone: 'info', action: 'Open request', style: W }),
    card({ icon: 'lock', md: 'Record', title: 'You can’t open this record', facts: 'Shared by Rohan Patel. Ask Rohan or your manager if you need it.', action: '', lock: true, style: W }),
    note('<b>Every card checks access when it is shown.</b> Each person sees only what their role and Sites allow, and the card updates when the record changes. Pasting an Oblivion link turns it into a card.', 'tint', 'shield'),
);

// ---------- 2 · from a message to a record ----------
const task = dlg({ size: 'sm', icon: 'tasks', title: 'Add a task', sub: 'From Rohan Patel’s message · 10:42 am', body: `
<div class="c3-pq"><small>Rohan Patel</small>The extractor fan in the downstairs bathroom is making a grinding noise again. Can someone log it?</div>
${field('Task', input('Log the bathroom extractor fan with maintenance', 'has'))}
<div class="oc-two" style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px">${field('Assign to', sel(`${av('hana', 's28')}Hana Wilson`))}${field('Due', dtb('Tue 20 Oct', 'Tomorrow'))}</div>
${field('Where', sel(`${gl('home', 's32').replace('c3-gl s32', 'c3-gl s32" style="width: 22px; height: 22px')}Kōwhai House`))}
${box(true, 'Post a link to the task in this conversation', 'Everyone here sees the task card; only people who can see tasks can open it')}`, foot: `${btn('Cancel')}${btn('Add task', 'p')}` });
const f4 = col('Turn into → task · prefilled, with a link back', task);

const guard = dlg({ size: 'sm', icon: 'shieldalert', tone: 'warn', title: 'Check before sending to Kiri', sub: 'Kiri Wineera and Tane’s care team can read this', body: `
<div class="c3-pq"><small>Your message</small>Tane had a good lunch with <mark style="background: color-mix(in oklch, var(--warn) 22%, transparent); border-radius: 4px; padding: 0 3px">Aroha</mark> today. He’s on <mark style="background: color-mix(in oklch, var(--warn) 22%, transparent); border-radius: 4px; padding: 0 3px">antibiotics</mark> for his tooth. ${svg('image', 13)} 1 photo</div>
<div class="c3-find crit">${lens('users', 'crit', 13)}<div><b>Names another person supported</b><small>Aroha Ngata also lives at Kōwhai House. Families only hear about their own person.</small></div></div>
<div class="c3-find">${lens('image', 'warn', 13)}<div><b>The photo may show Tane</b><small>Tane’s consent says no photos shared with whānau online.</small></div></div>
<div class="c3-find">${lens('pill', 'warn', 13)}<div><b>Medication detail</b><small>Kiri’s portal doesn’t show medication. A phone call may be better.</small></div></div>
${field('If you still need to send it, say why', `<div class="oc-input oc-area" style="height: 58px; align-items: flex-start; padding-top: 9px; color: var(--mfg)">Kept with the message for records</div>`)}`, foot: `<span style="margin-right: auto">${btn('Send anyway', 'x', { icon: 'send' })}</span>${btn('Edit message', 'p', { icon: 'pencil' })}` });
const f5 = col('Privacy check before sending to family · NZ Privacy Act and HIPC', guard);

const reach = `<div class="c3-reach"><span class="stk">${av('mere', 's28', '')}${av('alex', 's28', '')}${av('ana', 's28', '')}</span><div><b>Reaches 3 people on shift now</b> <span class="m">· Mere, Alex and Ana until 3 pm</span></div><button class="ch-lnk" type="button">Change</button></div>`;
const reachF = mini(msgs(group({ who: 'rohan', time: '1:58 pm', role: 'Site lead', icon: '', bubbles: ['Fire door is being fixed at 2 pm.'] })) + composer({ text: '<span class="ch-at">@on-shift Kōwhai House</span> The fire door is fixed, the back exit is open again', focus: true, above: reach, hint: false }));
const quick = mini(msgs(group({ who: 'alex', time: '7:42 am', bubbles: ['Are you nearly here? Tane’s keen to get going.'] })) + composer({ hint: false, above: `<div class="c3-chips">${['On my way', 'Running 10 minutes late', 'Medication given', 'Call me when you can'].map((t) => `<button class="c3-qr" type="button">${t}</button>`).join('')}<button class="c3-qr tint" type="button">${svg('pencil', 12)}Edit</button></div>` }));
const f6 = `<div style="display: grid; gap: 20px">${col('@on-shift · shows who it reaches before you send', reachF)}${col('Quick replies · set by your organisation, edited by you', quick)}</div>`;

// ---------- 3 · shift-aware ----------
const dig = `<div class="c3-dig" style="width: 100%"><div class="hd">${lens('history', 's36', 17)}<div><b>Since your last shift</b><small>Kōwhai House · Sunday 3:00 pm to now</small></div>${ib('x', 'Dismiss', '', 15)}</div>
${[['msg', '14 messages in Kōwhai House', '2 mention you', 'Read'], ['heart', '2 family messages waiting for you', 'Kiri Wineera, Jo Taylor', 'Reply'], ['alert', '1 incident reported', 'Slip in the downstairs bathroom · Open', 'Open'], ['pill', 'Lunchtime round has 1 overdue dose', 'For staff who give medicines', 'Open'], ['tasks', '3 tasks due today', 'Book the dentist for Tane and 2 more', 'Open']].map(([i, b, s, a]) => `<div class="it">${lens(i, '', 13)}<div><b>${b}</b><small>${s}</small></div>${btn(a, 'o', { xs: true })}</div>`).join('')}
<div class="ft">${btn('Start my handover notes', 'o', { sm: true, icon: 'clipboard' })}<span class="m">Counted from the records you can see</span></div></div>`;
const digF = mini(`<div class="c3-pin" style="border-bottom: 0">${gl('hash', 's32')}<span style="display: grid"><b>Kōwhai House</b><span class="tx">Site channel · 14 people</span></span><span class="r">${badge('You clocked in 6:58 am', 'live')}</span></div><div style="padding: 0 16px 16px">${dig}</div>`);
const f7 = col('Since your last shift · the first thing you see after clocking in', digF);

const conf = C.panel({ icon: 'usercheck', title: 'Read and confirm', sub: 'Fire door on level 1 · you sent it at 2:10 pm', body: `
<div style="display: flex; gap: 14px; align-items: center">${'<span class="c3-ring lg" style="--p: 60%">3/5</span>'}<div><b style="font-size: 14px">3 of 5 on shift have confirmed</b><small class="oc-muted" style="display: block; font-size: 12.5px">The house lead is told at 2:30 pm if anyone hasn’t</small></div></div>
<span class="ch-cap">Confirmed 3</span>
<div class="c3-ppl">${pp('rohan', '', 'Site lead', `<span class="t">${dr('read', '2:12 pm')}</span>`)}${pp('ana', '', 'Support worker', `<span class="t">${dr('read', '2:14 pm')}</span>`)}${pp('mere', 'Mere Thompson (you)', 'Support worker', `<span class="t">${dr('read', '2:10 pm')}</span>`)}</div>
<span class="ch-cap">Not yet 2</span>
<div class="c3-ppl">${pp('alex', '', 'On an outing with Tane · seen 2:20 pm', `<span class="t">${dr('dlv', 'Seen')}</span>`)}${pp('hana', '', 'On a break until 2:30 pm', `<span class="t">${dr('sent', 'Not seen')}</span>`)}</div>
${C.note('Staff who are off shift see it when their next shift starts.', '', 'info')}`, foot: `${btn('Close')}${btn('Chase 2 people', 'p', { icon: 'bell' })}` });
const f8 = col('Read and confirm · who still needs to see a safety message', conf);

const later = menu([mc('Send later'),
    mi('send', 'Send now', { k: 'Enter' }),
    mi('clock', 'At the start of my next shift', { sub: 'Thursday 22 October, 7:00 am', on: true }),
    mi('sun', 'Tomorrow morning', { sub: 'Tuesday 20 October, 9:00 am' }),
    mi('cal', 'Choose a date and time…'),
], 'w300', 'position: absolute; right: 14px; bottom: 64px', 'Send later');
const laterF = `<div style="position: relative">${mini(msgs(group({ who: 'hana', time: '9:02 pm', bubbles: ['No rush — reply when you’re back on.'] })) + '<div style="height: 236px"></div>' + composer({ text: 'Can you check Tane’s new shoes fit before the outing?', send: `<span class="c3-split"><button class="c3-round p" type="button" aria-label="Send">${svg('send', 18)}</button><button class="c3-round p" type="button" aria-label="Send later" aria-expanded="true">${svg('chev', 15)}</button></span>`, hint: false }))}${later}</div>`;
const loc = mini(msgs(group({ who: 'mere', own: true, bubbles: [{ raw: `<div class="c3-cc" style="width: 360px"><div class="c3-map" aria-label="Map of the outing"><svg class="rt" viewBox="0 0 360 118" preserveAspectRatio="none" aria-hidden="true"><path d="M40 92 C 90 92, 120 46, 180 46 S 260 30, 300 24"/></svg><span class="home" style="left: 28px; top: 80px">${svg('home', 12)}</span><span class="me" style="left: 292px; top: 16px"></span></div>${lens('mappin', '', 16)}<div class="hd"><span class="md">Live location</span>${badge('Live · 1 min ago', 'live')}</div><div><b>Outing to the library with Tane</b><small>Van 2 · shared with Kōwhai House on-shift staff</small></div><div class="ft">${btn('I’m back', 'p', { xs: true, icon: 'check' })}<span class="m">Stops by itself at 1:30 pm</span></div></div>` }], meta: ownMeta('10:05 am', 'read') })));
const f9 = `<div style="display: grid; gap: 20px">${col('Send later · at the start of your next shift', laterF)}${col('Live location for lone work · stops when you’re back', loc)}</div>`;

const sections = [
    sec('Care cards', 'Live records inside a conversation — client, incident, shift, medication round, task, open shift, leave, van, maintenance, IT', `<div class="c3-r3">${col('People, incidents, shifts, medicines', cards1)}${col('Tasks, cover, leave, vehicles', cards2)}${col('Maintenance, IT and no access', cards3)}</div>`),
    sec('From a conversation to the right record', 'Turn a message into work, check privacy before family see it, reach whoever is on shift', `<div class="c3-r3">${f4}${f5}${f6}</div>`),
    sec('Shift-aware', 'Chat knows the roster: what changed since your shift, who has confirmed a safety message, when to send', `<div class="c3-r3">${f7}${f8}${f9}</div>`),
];
export const size = writeBoard('ChatV3-Oblivion.html', 'Chat v3 — Oblivion only', 'Chat v3 · only in Oblivion', 'What Teams and WhatsApp can’t do: care records, the roster, privacy rules and NZ care settings built into the conversation.', sections);
console.log('ChatV3-Oblivion.html', size);
