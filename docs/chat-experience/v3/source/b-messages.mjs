import * as C from './c3lib.mjs';
const { svg, av, gl, btn, ib, lens, badge, e, col, sec, note, group, ownMeta, day, unreadLine, composer, mi, ms, mc, menu, rc, rx, dr, dlg, panel, pp, card, link, preview, input, box, writeBoard, nm } = C;

const mini = (inner, style = '') => `<div class="ch-mini"${style ? ` style="${style}"` : ''}>${inner}</div>`;
const msgs = (inner, style = '') => `<div class="c3-msgs top" style="flex: none; padding-bottom: 14px${style ? '; ' + style : ''}">${inner}</div>`;

// 1 · right-click / kebab menu with "Turn into" open
const ctxMenu = menu([
    `<div class="c3-mrx" aria-label="React">${C.e ? ['👍', '❤️', '😂', '😮', '😢', '🙏'].map((c) => `<span>${e(c)}</span>`).join('') : ''}<span class="add">${svg('smileplus', 17)}</span></div>`,
    mi('reply', 'Reply', { k: 'R' }),
    mi('thread', 'Reply in thread', { k: 'T' }),
    mi('fwd', 'Share to another chat'),
    mi('copy', 'Copy text', { k: 'Ctrl C' }),
    ms(),
    mi('pin', 'Pin'),
    mi('bookmark', 'Save for me'),
    mi('turn', 'Turn into', { arrow: true, on: true }),
    mi('info', 'Message info'),
    ms(),
    mi('flag', 'Report a concern'),
    mi('eyeoff', 'Delete for me'),
], '', 'position: absolute; left: 236px; top: 20px', 'Message actions');
const turnMenu = menu([mc('Turn this message into'),
    mi('tasks', 'Task', { sub: 'Assign it with a due date' }),
    mi('alert', 'Incident report', { sub: 'Opens Report incident, chat linked' }),
    mi('clipboard', 'Shift note', { sub: 'Adds to Tane Wineera’s notes' }),
    mi('heart', 'Family note', { sub: 'For Tane’s whānau to see' }),
    mi('wrench', 'Maintenance job', { sub: 'Kōwhai House' }),
    mi('monitor', 'IT request'),
], '', 'position: absolute; left: 492px; top: 214px; width: 236px', 'Turn into');
const ownMenu = menu([mc('On your own messages, also'), mi('pencil', 'Edit', { sub: 'For 15 minutes after sending' }), mi('trash', 'Delete for everyone', { del: true, sub: 'For 1 hour after sending' })], '', 'position: absolute; left: 20px; top: 330px; width: 232px', 'Own message actions');
const ctxThread = mini(msgs([
    day('Today · Monday 19 October'),
    group({ who: 'rohan', time: '10:42 am', role: 'Site lead', bubbles: [{ html: 'The extractor fan in the downstairs bathroom is making a grinding noise again. Can someone log it?', cls: 'hl' }] }),
    group({ who: 'mere', own: true, bubbles: ['I’ll turn it into a maintenance job now.'], meta: ownMeta('10:44 am', 'sent') }),
].join('')) + '<div style="height: 330px"></div>', 'position: relative');
const f1 = col('Right-click, the ⋯ button, or Shift + F10 — one menu everywhere', `<div style="position: relative">${ctxThread}${ctxMenu}${turnMenu}${ownMenu}</div>`);

// 2 · message info
const info = panel({ icon: 'info', title: 'Message info', sub: 'Sent to Kōwhai afternoon team · 10:31 am', body: `
<div class="c3-pq"><small>Your message</small>The updated plan is pinned. Please read it before 2 pm.</div>
<span class="ch-cap">Read by 3</span>
<div class="c3-ppl">${pp('alex', '', 'Support worker', `<span class="t">${dr('read', '10:33 am')}</span>`)}${pp('rohan', '', 'Site lead', `<span class="t">${dr('read', '10:40 am')}</span>`)}${pp('hana', '', 'Support worker', `<span class="t">${dr('read', '11:02 am')}</span>`, 'on')}</div>
<span class="ch-cap">Delivered, not read yet 1</span>
<div class="c3-ppl">${pp('ana', '', 'Off shift · will see it next shift', `<span class="t">${dr('dlv', '10:31 am')}</span>`, '')}</div>
${note('Read times are shown only in staff conversations and only to the sender. Family see “Seen by care team”.', 'tint')}` });
const f2 = col('Message info · who has read it, with rings', `<div style="display: flex; gap: 20px; align-items: flex-start">${info}</div>`);

// 3 · share to another chat
const share = dlg({ size: 'sm', icon: 'fwd', title: 'Share to another chat', sub: 'From Rohan Patel · 10:42 am', body: `
<div class="c3-pq"><small>Rohan Patel</small>The extractor fan in the downstairs bathroom is making a grinding noise again.</div>
<div class="ch-find">${svg('search', 15)}Search chats and people</div>
<div style="display: grid; gap: 2px">${[['hash', 'Kōwhai House', 'Site channel · 14 people', true], ['users', 'Maintenance team', 'Private group · 4 people', true], ['', 'Alex Morgan', 'Direct', false]].map(([i, n, s, on]) => `<div class="c3-pp" style="grid-template-columns: 20px 32px minmax(0,1fr)"><span class="oc-box${on ? ' on' : ''}" aria-hidden="true">${on ? svg('check', 12) : ''}</span>${i ? gl(i, 's32') : av('alex', 's32', 'on')}<div><b>${n}</b><small>${s}</small></div></div>`).join('')}</div>
${C.field('Add a note (optional)', input('Shared from the afternoon team', 'has'))}
${note('Family conversations can’t receive staff messages. A shared message shows who first sent it.', '', 'shield')}`, foot: `${btn('Cancel')}${btn('Share to 2 chats', 'p', { icon: 'fwd' })}` });
const f3 = col('Share to another chat · 480 form, family chats excluded', share);

// 4 · thread side panel
const thr = panel({ icon: 'thread', title: 'Thread', sub: 'Kōwhai afternoon team · 4 replies', tabs: '', body: `
<div class="c3-rep">${group({ who: 'hana', time: '9:15 am', bubbles: ['Who can take Tane to his dentist appointment on Thursday at 2 pm?'] })}
<div class="c3-rep-n">4 replies</div>
${group({ who: 'alex', time: '9:20 am', bubbles: ['I can if I swap my break.'] })}
${group({ who: 'rohan', time: '9:31 am', role: 'Site lead', bubbles: ['Thanks Alex — the van is free. I’ll book it.'], after: rx(rc('👍', 2, true)) })}
${group({ who: 'mere', own: true, bubbles: ['I’ll let his mum know.'], meta: ownMeta('9:40 am', 'read') })}</div>`, foot: `<div style="flex: 1; display: grid; gap: 8px">${composer({ ph: 'Reply in thread…', hint: false }).replace('class="c3-comp"', 'class="c3-comp" style="border: 0; padding: 0; background: transparent"')}<div style="display: flex; align-items: center; gap: 12px">${btn('Following', 'o', { xs: true, icon: 'bell' })}${C.box(false, 'Also send to the conversation')}</div></div>` });
const f4 = col('Reply in thread · Teams-style side panel', thr);

// 5 · pinned bar + thread summary line
const pinned = mini(`<div class="c3-pin">${lens('pin', '', 12)}<span style="min-width: 0; display: grid"><b>Pinned · Rohan Patel</b><span class="tx">Fire drill moved to Thursday, 2 pm. Meet at the letterbox.</span></span><span class="r">1 of 2 ${ib('chevup', 'Previous pinned', '', 14)}${ib('chev', 'Next pinned', '', 14)}</span></div>` + msgs([
    group({ who: 'hana', time: '9:15 am', bubbles: ['Who can take Tane to his dentist appointment on Thursday at 2 pm?'], after: `<a class="c3-thr" href="#thread"><span class="stk">${av('alex', 's28')}${av('rohan', 's28')}</span><span>4 replies</span> <small>· last 9:40 am</small></a>` }),
    group({ who: 'mere', own: true, bubbles: ['Thanks everyone!'], meta: ownMeta('9:42 am', 'dlv') }),
].join('')));
const f5 = col('Pinned messages stay at the top · thread replies fold into one line', pinned);

// 6 · priority messages
const imp = { html: `<span class="lbl">${svg('alertc', 13)}Important</span>Tane’s new medication chart starts tonight. Please read it in eMAR before your shift.`, cls: 'imp' };
const urg = { html: `<span class="lbl">${svg('alert', 13)}Urgent · read and confirm</span>The fire door on level 1 is broken. Use the back exit until it’s fixed.<div class="c3-conf"><span class="c3-ring" style="--p: 60%">3/5</span><div><b>3 of 5 on shift have confirmed</b><small>Hana and Alex haven’t yet · goes to the house lead at 2:30 pm</small></div>${btn('Chase 2', 'o', { xs: true, icon: 'bell' })}</div>`, cls: 'urg' };
const prio = mini(msgs([
    group({ who: 'rohan', time: '1:05 pm', role: 'Site lead', bubbles: [imp] }),
    group({ who: 'mere', own: true, bubbles: [urg], meta: ownMeta('2:10 pm', 'dlv') }),
].join('')) + `<div class="c3-comp"><div style="display: flex; gap: 8px; align-items: center; flex-wrap: wrap"><span class="oc-hint">Send as</span>${['Normal', 'Important', 'Urgent'].map((t, i) => `<button class="c3-qr${i === 2 ? ' warn' : ''}" type="button" aria-pressed="${i === 2}">${i === 1 ? svg('alertc', 13) : i === 2 ? svg('alert', 13) : ''}${t}</button>`).join('')}<span style="margin-left: auto">${C.box(true, 'Ask to confirm')}</span></div></div>`);
const f6 = col('Important and urgent · urgent asks on-shift staff to confirm', prio);

// 7 · poll
const poll = mini(msgs(group({ who: 'rohan', time: '11:20 am', role: 'Site lead', bubbles: [{ html: `<div class="c3-poll"><h4>Which day suits the team barbecue?</h4>${[['Friday 30 October', 4, 67, true], ['Saturday 31 October', 2, 33, false], ['Sunday 1 November', 0, 0, false]].map(([t, v, p, me]) => `<div class="c3-opt${me ? ' me' : ''}"><i style="width: ${p}%"></i><span class="ck">${me ? svg('check', 14) : ''}</span><span>${t}</span><span class="v">${v}</span></div>`).join('')}<div class="f"><span>6 of 9 voted · you chose Friday</span><span>Closes 3 pm</span></div></div>` }] })));
const f7 = col('Poll · results update as people vote', poll);

// 8 · links: internal → care card, outside → plain link
const links = mini(msgs([
    group({ who: 'alex', time: '12:02 pm', bubbles: [`Here’s the open shift: ${link('oblivioncare.nz/rostering/shifts/8812')}`, { raw: card({ icon: 'calplus', md: 'Open shift', title: 'Saturday 24 October · 3–11 pm', facts: 'Kōwhai House · Support worker · needs cover', badgeT: 'Needs cover', tone: 'warn', action: 'Offer to cover' }) }] }),
    group({ who: 'mere', own: true, bubbles: [`Saturday’s bus times: ${link('kowhaibus.nz/route-52')}`], after: preview({ site: 'Kōwhai Buses', title: 'Route 52 timetable · Kōwhai to the city', desc: 'Weekday and weekend times, stops and live departures.', ph: 'p1', own: true }) + `<span class="oc-hint">Outside links show a preview; an admin can switch previews off</span>`, meta: ownMeta('12:05 pm', 'sent') }),
].join('')));
const f8 = col('Links · Oblivion links become care cards; outside links show a preview', links);

// 9 · edited and deleted
const states = mini(msgs([
    group({ who: 'alex', time: '1:10 pm', bubbles: ['Shift starts at 3:30 pm, not 3 pm.'], after: '<div class="c3-mt"><span class="ed">Edited</span></div>' }),
    group({ who: 'hana', time: '1:12 pm', bubbles: [{ html: `${svg('trash', 13)}Hana deleted this message`, cls: 'gone' }] }),
    group({ who: 'mere', own: true, bubbles: [{ html: `${svg('eyeoff', 13)}You deleted this for yourself`, cls: 'gone' }], meta: '' }),
    group({ who: 'mere', own: true, bubbles: ['Got it, see you at 3:30.'], meta: ownMeta('1:14 pm', 'fail', ' <button class="ch-lnk" type="button" style="font-size: 11px">Try again</button>') }),
].join('')) + note('You can edit a message for 15 minutes and delete it for everyone for 1 hour. Earlier versions are kept for records; a hold stops edits and deletions.', '', 'history'));
const f9 = col('Edited, deleted and failed · always in plain words', states);

// 10 · multi-select
const sel = (on) => `<span class="c3-sel${on ? ' on' : ''}" aria-hidden="true">${on ? svg('check', 12) : ''}</span>`;
const multi = mini(msgs([
    `<div class="c3-selrow">${sel(true)}${group({ who: 'kiri', time: '4:58 pm', bubbles: ['Tane said his tooth has been sore since Sunday.'] })}</div>`,
    `<div class="c3-selrow">${sel(true)}${group({ who: 'mere', own: true, bubbles: ['Thanks Kiri, we’ll book the dentist tomorrow morning.'], meta: ownMeta('5:02 pm', 'read') }).replace('class="c3-g own"', 'class="c3-g own" style="margin-left: auto"')}</div>`,
    `<div class="c3-selrow">${sel(false)}${group({ who: 'kiri', time: '5:04 pm', bubbles: ['Thank you ' + e('🙏')] })}</div>`,
].join('')) + `<div class="c3-msel" role="toolbar" aria-label="2 messages selected"><b>2 selected</b>${btn('Save to Tane’s notes', 'o', { xs: true, icon: 'clipboard' })}${btn('Copy', 'o', { xs: true, icon: 'copy' })}${btn('Report a concern', 'o', { xs: true, icon: 'flag' })}${ib('x', 'Cancel selection', 'x', 16)}</div>`);
const f10 = col('Select several · save to the person’s notes, copy or report', multi);

const sections = [
    sec('Acting on a message', 'Hover, right-click and keyboard open the same actions; nothing opens as a pop-up first', `<div class="c3-r2">${f1}${f2}</div>`),
    sec('Sharing, threads and pins', 'Share within the rules, reply without losing the main conversation', `<div class="c3-r3">${f3}${f4}${f5}</div>`),
    sec('Priority, polls and links', 'Urgent messages track who has confirmed; Oblivion links open as live cards', `<div class="c3-r3">${f6}${f7}${f8}</div>`),
    sec('States and selection', 'Plain words for every state; selected messages can go to the care record', `<div class="c3-r2">${f9}${f10}</div>`),
];
export const size = writeBoard('ChatV3-Messages.html', 'Chat v3 — message actions', 'Chat v3 · message actions', 'Reactions, menus, message info, sharing, threads, pins, priority, polls, links and message states.', sections);
console.log('ChatV3-Messages.html', size);
