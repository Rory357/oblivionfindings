// ChatV3-Popups.html — C1.0e, every Chat pop-up and its states on the app's pop-up kit
import * as C from './lib.mjs';
const { svg, av, gl, btn, ib, lens, badge, col, sec, note, field, input, area, sel, box, radio, sw, callout, skel, dlg, confirm, panel, ctx, pq, pp, toast, mi, ms, mc, menu, dr, crow } = C;

const STYLE = `
.pk-col { display: grid; gap: 16px; align-content: start; }
.pk-chips { min-height: 38px; border-radius: 10px; border: 1px solid var(--border); background: var(--field); display: flex; flex-wrap: wrap; gap: 6px; align-items: center; padding: 5px 8px; font-size: 13.5px; color: var(--fg); }
.pk-chips.focus { border-color: var(--primary); box-shadow: var(--halo); }
.pk-chips .caret { display: inline-block; width: 1.5px; height: 17px; background: var(--primary); vertical-align: -3px; margin-left: 1px; }
.pk-chips .ph { color: var(--mfg); }
.pk-chips .oc-xchip .ch-av { width: 20px; height: 20px; font-size: 9px; margin-left: -6px; }
.pk-res { border: 1px solid var(--border); border-radius: 12px; overflow: hidden; background: var(--card); }
.pk-res .g { padding: 7px 12px 4px; font-size: 11px; font-weight: 700; letter-spacing: 0.05em; text-transform: uppercase; color: var(--mfg); background: var(--muted); border-bottom: 1px solid var(--border); }
.pk-res .c3-pp { grid-template-columns: 20px 32px minmax(0, 1fr); padding: 7px 12px; }
.pk-res .c3-pp + .c3-pp { border-top: 1px solid var(--border); }
.pk-res .c3-pp.on { background: color-mix(in oklch, var(--primary) 6%, var(--card)); }
.pk-res .c3-pp.dis b { color: var(--mfg); }
.pk-res .c3-pp.dis .ch-av { opacity: 0.55; }
.pk-res .c3-pp small.why { color: var(--warn-fg); display: flex; gap: 5px; align-items: center; }
.pk-res .c3-pp small.why svg { color: var(--warn); flex: none; }
.pk-res .oc-empty { padding: 18px 16px; }
.pk-res .oc-empty .ei { width: 40px; height: 40px; }
.pk-res .oc-empty b { font-size: 13.5px; }
.pk-res .sk { display: grid; grid-template-columns: 20px 32px minmax(0, 1fr); gap: 10px; align-items: center; padding: 9px 12px; }
.pk-res .sk + .sk { border-top: 1px solid var(--border); }
.pk-res .sk .c { width: 32px; height: 32px; border-radius: 50%; background: var(--muted); }
.pk-res .sk .b { width: 18px; height: 18px; border-radius: 5px; background: var(--muted); }
.pk-res .sk > div { display: grid; gap: 6px; }
.pk-tiles { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; }
.pk-tiles .oc-tile { font-size: 12.5px; }
.pk-tiles .oc-tile .ti { width: 28px; height: 28px; }
.pk-seg { display: flex; }
.pk-seg .oc-tgl { width: 100%; }
.pk-seg .oc-tgl span { flex: 1; justify-content: center; }
.pk-ro { display: grid; gap: 4px; }
.pk-ro .ch-chk { padding: 8px 10px; border-radius: 10px; border: 1px solid var(--border); }
.pk-ro .ch-chk.on { border-color: color-mix(in oklch, var(--primary) 45%, var(--border)); background: color-mix(in oklch, var(--primary) 6%, var(--card)); }
.oc-dlg .oc-mf .oc-btn.dis { pointer-events: none; }
.oc-dlg.busy .oc-mb { opacity: 0.6; }
.oc-mh .oc-x.dis { opacity: 0.4; }
.pk-mute { position: relative; height: 330px; }
.pk-mute .ch-mini { width: 300px; padding: 8px; }
.pk-toasts { display: grid; gap: 10px; }
.pk-toasts .oc-toast { width: 100%; }
.pk-tbl .oc-table td { vertical-align: top; font-size: 12.5px; line-height: 1.45; }
.pk-tbl .oc-table td b { font-weight: 650; }
.pk-tbl .oc-table code { font-family: ui-monospace, 'Cascadia Code', Consolas, monospace; font-size: 12px; color: var(--pt); }
.pk-tbl .oc-table td small { display: block; color: var(--mfg); font-size: 12px; }
.pk-kit { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 14px; }
.pk-kit .c3-meas b { display: flex; gap: 8px; align-items: center; }
.pk-kit .c3-meas b .ch-lens { width: 24px; height: 24px; }
`;

// ---------- people picker parts ----------
const xchip = (who, t) => `<span class="oc-xchip">${av(who, '', '', 'margin-left: -6px')}${t}<button type="button" aria-label="Remove ${t}">${svg('x', 12)}</button></span>`;
const prow = (who, title, sub, { on = false, dis = false, why = '', icon = '' } = {}) => `<div class="c3-pp${on ? ' on' : ''}${dis ? ' dis' : ''}"><span class="oc-box${on ? ' on' : ''}${dis ? ' dis' : ''}" aria-hidden="true">${on ? svg('check', 12) : ''}</span>${icon ? gl(icon, 's32') : av(who, 's32', who === 'ana' ? 'on' : '')}<div><b>${title}</b>${why ? `<small class="why">${svg('alert', 12)}${why}</small>` : `<small>${sub}</small>`}</div></div>`;
const skRow = () => `<div class="sk"><span class="b"></span><span class="c"></span><div>${skel('45%', 9)}${skel('70%', 8)}</div></div>`;

// ---------- 1 · starting conversations ----------
const newConv = dlg({ size: 'sm', icon: 'newchat', title: 'New conversation', sub: 'Chat with one person, or start a group', body: `
<div class="pk-seg"><span class="oc-tgl" role="radiogroup" aria-label="Kind"><span>${svg('user', 14)}One person</span><span class="on">${svg('users', 14)}Group</span><span>${svg('hash', 14)}Site channel</span></span></div>
${field('People', `<div class="pk-chips focus">${xchip('ana', 'Ana Rēweti')}${xchip('admin', 'Demo Admin')}<span>a<span class="caret"></span></span></div>`)}
<div class="pk-res" role="listbox" aria-label="People you can add"><div class="g">People you can see</div>
${prow('ana', 'Ana Rēweti', 'Support worker · Kōwhai House', { on: true })}
${prow('admin', 'Demo Admin', 'Administrator · every Site', { on: true })}
</div>
${field('Group name', input('Tane’s dentist plan', 'has'), 'Everyone in the group sees the name.')}
${note('You can only add people you can see. Everyone in a group must be able to see each other’s Site.', '', 'shield')}`, foot: `${btn('Cancel')}${btn('Start group', 'p')}` });

const picker = (q, inner) => `<div class="pk-col" style="gap: 8px"><div class="pk-chips focus"><span>${q}<span class="caret"></span></span></div><div class="pk-res" role="listbox">${inner}</div></div>`;
const pickerStates = `<div class="pk-col">
${C.cap('Loading')}${picker('an', skRow() + skRow() + skRow())}
${C.cap('No match')}${picker('Hemi', `<div class="oc-empty"><span class="ei">${svg('search', 18)}</span><b>No one called “Hemi” that you can see</b><p>People at Sites you can’t see don’t show here. Ask your site lead if someone is missing.</p></div>`)}
${C.cap('Can’t add · would see a Site the others can’t')}${picker('Ana', `${prow('ana', 'Ana Rēweti', '', { dis: true, why: 'Can’t add: 2 people in this group can’t see Tōtara service' })}`)}
${C.cap('Couldn’t load')}${picker('an', `<div class="oc-empty"><span class="ei" style="color: var(--crit); background: var(--crit-bg)">${svg('alertc', 18)}</span><b>Couldn’t load people</b><p>Check your connection. What you’ve chosen is kept.</p>${btn('Try again', 'o', { xs: true, icon: 'refresh' })}</div>`)}
</div>`;

const addPeople = dlg({ size: 'sm', icon: 'userplus', title: 'Add people', sub: 'They join the group straight away', body: `
${ctx('users', 'Kōwhai afternoon team', 'Private group · 5 people')}
${field('People', `<div class="pk-chips">${xchip('ana', 'Ana Rēweti')}<span class="ph">Search people…</span></div>`)}
${field('What can they see?', `<div class="pk-ro">${radio(false, 'New messages only')}${'<label class="ch-chk on"><span class="oc-radio on" aria-hidden="true"></span><span>The last 7 days<small>From Monday 12 October</small></span></label>'}${radio(false, 'Everything since the group started')}</div>`)}
<span class="oc-hint">Everyone in the group sees “Rohan Patel added Ana Rēweti”.</span>
${note('In a family conversation this lists only Tane’s approved family contacts, and a new relative accepts an invitation before they see anything.', 'tint', 'heart')}`, foot: `${btn('Cancel')}${btn('Add 1 person', 'p')}` });

// ---------- 2 · leaving, removing, deleting ----------
const leave = confirm({ icon: 'leave', title: 'Leave Kōwhai afternoon team?', text: 'You’ll stop getting its messages. What you sent stays in the conversation for records.', foot: `${btn('Cancel')}${btn('Leave group', 'p')}` });
const leaveOwner = confirm({ icon: 'leave', title: 'Leave Kōwhai afternoon team?', text: 'You’re its only owner. Choose who looks after it before you go.', body: field('New owner', sel(`${av('ana', 's28')}Ana Rēweti`)), foot: `${btn('Cancel')}${btn('Make Ana owner and leave', 'p')}` });
const remove = confirm({ icon: 'userminus', title: 'Remove Ana Rēweti from Kōwhai afternoon team?', text: 'Ana stops seeing new messages. What she sent stays, and everyone sees “Rohan Patel removed Ana Rēweti”. You can add her back later.', foot: `${btn('Cancel')}${btn('Remove Ana', 'p')}` });
const removeNoPerm = confirm({ icon: 'lock', title: 'You can’t remove people any more', text: 'Your role changed while this was open. Only the group’s owners can remove people.', foot: btn('Close', 'p') });
const delQuote = pq('Your message · 10:44 am', 'Tane’s dentist is Thursday at 3 pm.');
const del = confirm({ icon: 'trash', tone: 'crit', title: 'Delete this message for everyone?', text: 'It disappears for everyone in Kōwhai afternoon team. A copy stays in the encrypted history for records.', body: delQuote + `<span class="oc-hint">You can delete for everyone until 11:44 am.</span>`, foot: `${btn('Cancel')}${btn('Delete for everyone', 'x')}` });
const delToasts = `<div class="pk-toasts">${toast('<b>Message deleted for everyone</b>', { icon: 'trash', action: btn('Undo', 'g', { xs: true, icon: 'undo' }) })}${toast('<b>Deleted for you</b> · others still see it', { icon: 'eyeoff', action: btn('Undo', 'g', { xs: true, icon: 'undo' }) })}</div>`;

// ---------- 3 · delete, every state ----------
const delSaving = confirm({ icon: 'trash', tone: 'crit', title: 'Delete this message for everyone?', text: 'It disappears for everyone in Kōwhai afternoon team.', body: delQuote, foot: `${btn('Cancel', 'o', { dis: true })}${btn('Deleting…', 'x', { spin: true, dis: true })}` }).replace('pk-cf"', 'pk-cf busy"');
const delFailed = confirm({ icon: 'trash', tone: 'crit', title: 'Delete this message for everyone?', text: 'It disappears for everyone in Kōwhai afternoon team.', body: callout('Couldn’t delete it. The message is unchanged. Check your connection and try again.', 'crit', 'alertc') + delQuote, foot: `${btn('Cancel')}${btn('Try again', 'x', { icon: 'refresh' })}` });
const delUnknown = confirm({ icon: 'trash', tone: 'crit', title: 'Result not confirmed', text: 'The connection dropped before Oblivion answered. The message may already be deleted.', body: callout('Check the message before you try again, so nothing happens twice.', 'warn', 'alert'), foot: `${btn('Close')}${btn('Check the message', 'p', { icon: 'refresh' })}` });
const delLate = confirm({ icon: 'clock', title: 'You can’t delete this for everyone now', text: 'Messages can be deleted for everyone in the first hour. You can still delete it for yourself.', foot: `${btn('Cancel')}${btn('Delete for me', 'p')}` });
const delHold = confirm({ icon: 'lock', title: 'This conversation is on hold', text: 'Messages can’t be edited or deleted while it’s kept for an investigation. Ask your site lead if something needs fixing.', foot: btn('Close', 'p') });

// ---------- 4 · sharing and turning into work ----------
const share = dlg({ size: 'sm', icon: 'fwd', title: 'Share to another chat', sub: 'From Ana Rēweti · 11:41 am', body: `
${pq('Ana Rēweti', 'Can you check the plan before 2 pm? Kiri asked about Saturday too.')}
<div class="ch-find">${svg('search', 15)}Search chats and people</div>
<div class="pk-res">${[['hash', 'Kōwhai House', 'Site channel · 14 people', true, ''], ['users', 'Kōwhai afternoon team', 'Private group · 5 people', true, ''], ['', 'Kiri Wineera · About Tane', '', false, 'Family conversations can’t receive staff messages']].map(([i, n, s, on, why]) => `<div class="c3-pp${on ? ' on' : ''}${why ? ' dis' : ''}"><span class="oc-box${on ? ' on' : ''}${why ? ' dis' : ''}" aria-hidden="true">${on ? svg('check', 12) : ''}</span>${i ? gl(i, 's32') : av('kiri', 's32')}<div><b>${n}</b>${why ? `<small class="why">${svg('shield', 12)}${why}</small>` : `<small>${s}</small>`}</div></div>`).join('')}</div>
${field('Add a note (optional)', input('For the afternoon team', 'has'))}`, foot: `${btn('Cancel')}${btn('Share to 2 chats', 'p', { icon: 'fwd' })}` });
const sharePart = dlg({ size: 'sm', icon: 'fwd', title: 'Share to another chat', sub: 'From Ana Rēweti · 11:41 am', body: `
${callout('<b>Shared to Kōwhai House.</b> Couldn’t share to Kōwhai afternoon team. It’s still ticked, with your note.', 'crit', 'alertc')}
<div class="pk-res">${`<div class="c3-pp on"><span class="oc-box on" aria-hidden="true">${svg('check', 12)}</span>${gl('users', 's32')}<div><b>Kōwhai afternoon team</b><small>Not shared yet</small></div></div>`}</div>`, foot: `${btn('Close')}${btn('Try again', 'p', { icon: 'refresh' })}` });

const taskErr = dlg({ size: 'sm', icon: 'tasks', title: 'Add a task', sub: 'From Ana Rēweti’s message · 11:41 am', body: `
${pq('Ana Rēweti', 'Can you check the plan before 2 pm?')}
${field('Task <i>*</i>', input('', 'err'), '', 'Say what needs doing.')}
<div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px">${field('Assign to', sel(`${av('rohan', 's28')}Rohan Patel (you)`))}${field('Due', `<button class="ch-sel" type="button" style="border-color: var(--crit)">${svg('calendar', 15)}Sun 18 Oct${svg('chev', 15, 'chev')}</button>`, '', 'That date has passed.')}</div>
${field('Where', sel(`${gl('home', 's32').replace('c3-gl s32', 'c3-gl s32" style="width: 22px; height: 22px')}Kōwhai House`))}
${box(true, 'Post a link to the task in this conversation', 'Everyone here sees the task card; only people who can see tasks can open it')}`, foot: `${btn('Cancel')}${btn('Add task', 'p')}` });
const taskNoPerm = dlg({ size: 'sm', icon: 'tasks', title: 'Add a task', sub: 'From Kiri Wineera’s message · 11:10 am', body: `
${callout('<b>You can no longer add tasks at Kōwhai House.</b> Your access changed while this was open. What you typed is kept, so you can copy it or ask your site lead.', 'warn', 'lock')}
${field('Task', input('Call Kiri about Saturday’s visit', 'dis'))}`, foot: `${btn('Close')}${btn('Copy what I typed', 'o', { icon: 'copy' })}` });

const report = dlg({ size: 'sm', icon: 'flag', title: 'Report a concern', sub: 'Goes to the site lead, not to Kiri', body: `
${pq('Kiri Wineera · About Tane · 9:02 am', 'Here’s the photo from the garden this morning. <span style="color: var(--mfg); display: inline-flex; gap: 4px; align-items: center">' + svg('image', 13) + ' 1 photo</span>')}
${field('What’s the concern?', `<div class="pk-tiles">${[['shieldalert', 'Unsafe or abusive', false], ['eyeoff', 'Private details shared', true], ['flag', 'Bullying or harassment', false], ['more', 'Something else', false]].map(([i, t, on]) => `<span class="oc-tile${on ? ' on' : ''}" role="radio" aria-checked="${on}"><span class="ti">${svg(i, 15)}</span>${t}</span>`).join('')}</div>`)}
${field('Tell us more (optional)', area('The photo shows Aroha in the background.', 'has', 64))}
${ctx('shield', 'Rohan Patel and the safeguarding lead', 'Site lead', 'They see the message, even if it is later deleted. Kiri isn’t told who reported it.')}`, foot: `${btn('Cancel')}${btn('Send concern', 'p', { icon: 'flag' })}` });

// ---------- 5 · looking things up and recording ----------
const infoLoading = panel({ icon: 'info', title: 'Message info', sub: 'Sent to Kōwhai afternoon team · 10:31 am', body: `
<div class="c3-pq"><small>Your message</small>The updated plan is pinned. Please read it before 2 pm.</div>
${C.cap('Read by')}<div class="pk-res" style="border: 0">${skRow().replace('<span class="b"></span>', '<span></span>')}${skRow().replace('<span class="b"></span>', '<span></span>')}</div>` });
const infoFamily = panel({ icon: 'info', title: 'Message info', sub: 'Sent to Kiri Wineera · About Tane · 5:20 pm', body: `
<div class="c3-pq"><small>Your message</small>He had a great day at cooking group and made his own lunch.</div>
${C.cap('Seen')}<div class="c3-ppl">${pp('kiri', '', 'Whānau · Mum', `<span class="t">${dr('read', '5:22 pm')}</span>`, '')}</div>
${note('Kiri sees “Seen by care team” on her messages, never which worker read them.', 'tint', 'eye')}` });
const callRec = dlg({ size: 'sm', icon: 'clipboard', title: 'Add a call record', sub: 'Voice call with Kiri Wineera · 12 min · 4:05 pm', body: `
${ctx('user', 'Tane Wineera’s notes', 'Staff only', 'Only staff who can see Tane’s notes can read this.')}
${field('What you talked about <i>*</i>', area('Kiri will visit on Saturday at 2 pm with Tane’s nana. She asked about his tooth; the dentist is booked for Thursday.', 'has', 82))}
${box(true, 'Add a task to follow up', 'Call Kiri after the dentist on Thursday')}
${sw(false, 'Also send Kiri a short note', 'She sees only what you write for her, never this record')}`, foot: `${btn('Not needed')}${btn('Save call record', 'p')}` });
const callFail = dlg({ size: 'sm', icon: 'clipboard', title: 'Add a call record', sub: 'Voice call with Kiri Wineera · 12 min · 4:05 pm', body: `
${callout('Couldn’t save the call record. What you wrote is kept here. Try again.', 'crit', 'alertc')}
${field('What you talked about <i>*</i>', area('Kiri will visit on Saturday at 2 pm with Tane’s nana…', 'has', 56))}`, foot: `${btn('Cancel')}${btn('Try again', 'p', { icon: 'refresh' })}` });

const mute = `<div class="pk-mute">
<div class="ch-mini">${crow({ icon: 'users', name: 'Kōwhai afternoon team', time: '10:36', prev: 'Ana: The updated plan is pinned', unread: 1, hov: true })}</div>
${menu([mi('pin', 'Pin to top'), mi('star', 'Add to favourites'), mi('unread', 'Mark as unread'), mi('belloff', 'Mute', { arrow: true, on: true }), ms(), mi('archive', 'Archive'), mi('leave', 'Leave group', { del: true })], 'abs', 'left: 20px; top: 62px', 'Conversation actions')}
<div class="c3-menu abs" role="menu" aria-label="Mute" style="left: 236px; top: 168px; width: 220px">${['For 1 hour', 'For 8 hours', 'Until tomorrow 7 am', 'Until I turn it back on'].map((t, i) => mi('', t, { on: i === 2 })).join('')}</div>
</div>
<div class="pk-toasts">${toast('<b>Muted until 7:00 am tomorrow</b>', { icon: 'belloff', action: btn('Undo', 'g', { xs: true, icon: 'undo' }) })}</div>
${note('Mute is a menu, not a pop-up: one click and an Undo. Urgent messages and mentions still reach you.', '', 'belloff')}`;

// ---------- 6 · safety and privacy ----------
const pause = confirm({ icon: 'pause', tone: 'warn', title: 'Pause messages from Kiri Wineera?', text: 'Kiri can’t send messages to Tane’s care team until you turn this back on. She can still read earlier messages and phone the house.', body: `${field('Why are you pausing them? <i>*</i>', area('Kiri sent several upsetting messages to Ana late at night. Talking with her on Tuesday.', 'has', 64), 'Kept with the record. Kiri sees “Messages are paused. Please phone Kōwhai House.”')}`, foot: `${btn('Cancel')}${btn('Pause messages', 'p', { icon: 'pause' })}` });
const stopChase = confirm({ icon: 'alert', tone: 'crit', title: 'Stop asking for confirmations?', text: '2 people on shift haven’t confirmed the fire door message. They won’t be reminded, and the house lead won’t be told at 2:30 pm.', body: field('Why? <i>*</i>', input('', 'err'), '', 'Say why before you stop asking.'), foot: `${btn('Keep asking')}${btn('Stop asking', 'x')}` });
const sendAnyway = confirm({ icon: 'shieldalert', tone: 'crit', title: 'Send to Kiri without changes?', text: 'Your message names Aroha Ngata, who also lives at Kōwhai House. Families only hear about their own person.', body: `${pq('Your message to Kiri', 'Tane had a good lunch with Aroha today.')}${field('Why does it need to go as it is? <i>*</i>', area('Kiri already knows Aroha; they met at the open day.', 'has', 56), 'Kept with the message for records.')}`, foot: `${btn('Edit message')}${btn('Send anyway', 'x')}` });

// ---------- 7 · states every pop-up has (Add people as the example) ----------
const ap = (body, foot, o = {}) => dlg({ size: 'sm', icon: 'userplus', title: 'Add people', sub: 'To Kōwhai afternoon team', body, foot, ...o });
const stLoading = ap(`${field('People', `<div class="pk-chips"><span class="ph">Search people…</span></div>`)}<div class="pk-res" aria-busy="true">${skRow()}${skRow()}${skRow()}</div>`, `${btn('Cancel')}${btn('Add people', 'p', { dis: true })}`);
const stEmpty = ap(`<div class="pk-res"><div class="oc-empty"><span class="ei">${svg('usercheck', 18)}</span><b>Everyone you can see is already here</b><p>People at Sites you can’t see don’t show. Ask your site lead to add them.</p></div></div>`, `${btn('Close', 'p')}`);
const stNoAccess = ap(`<div class="pk-res"><div class="oc-empty"><span class="ei">${svg('lock', 18)}</span><b>You can’t add people to this group</b><p>Only its owners can add people. Demo Admin owns this group.</p></div></div>`, `${btn('Close', 'p')}`);
const stInvalid = ap(`${field('People <i>*</i>', `<div class="pk-chips" style="border-color: var(--crit)"><span class="ph">Search people…</span></div>`, '', 'Choose at least one person.')}${field('What can they see?', sel('The last 7 days'))}`, `${btn('Cancel')}${btn('Add people', 'p')}`);
const stSaving = ap(`${field('People', `<div class="pk-chips">${xchip('ana', 'Ana Rēweti')}</div>`)}${field('What can they see?', sel('The last 7 days'))}`, `${btn('Cancel', 'o', { dis: true })}${btn('Adding…', 'p', { spin: true, dis: true })}`).replace('class="oc-dlg sm"', 'class="oc-dlg sm busy"').replace('<button class="oc-x"', '<button class="oc-x dis"');
const stFailed = ap(`${callout('Couldn’t add Ana. Your choices are kept. Check your connection and try again.', 'crit', 'alertc')}${field('People', `<div class="pk-chips">${xchip('ana', 'Ana Rēweti')}</div>`)}`, `${btn('Cancel')}${btn('Try again', 'p', { icon: 'refresh' })}`);
const stUnknown = ap(`${callout('<b>Result not confirmed.</b> Ana may already be in the group. Check the member list before trying again.', 'warn', 'alert')}`, `${btn('Close')}${btn('Check members', 'p', { icon: 'users' })}`);
const stCouldnt = ap(`${callout('Couldn’t load people. Check your connection and try again.', 'crit', 'alertc')}`, `${btn('Cancel')}${btn('Try again', 'o', { icon: 'refresh' })}`);
const discard = confirm({ icon: 'trash', tone: 'crit', title: 'Discard this draft?', text: 'What you wrote in Report a concern will be lost.', foot: `${btn('Keep editing')}${btn('Discard draft', 'x')}` });

// ---------- 8 · what each replaces ----------
const T = [
    ['chat-thread.tsx', '“Message actions”: a 480 dialog of outline buttons, with “Acknowledge” as the only reaction', 'Yes, its own dialog', 'No pop-up. The hover bar and one menu', 'Messages board'],
    ['chat-message-removal.tsx', '“Remove your message?”', 'Yes', 'Delete this message for everyone? Delete for me needs no pop-up, just Undo', 'Leaving, removing and deleting; Deleting · every state'],
    ['chat-voice-recorder.tsx', '“Record a voice message”', 'Yes', 'No pop-up. Records in the composer', 'Compose board'],
    ['chat-audience-review.tsx', '“Before your first message”, “Review the updated audience”', 'Yes', 'The “who can read this” bar, and the privacy check before family', 'Workspace, Family and Oblivion boards'],
    ['chat-callbacks.tsx', '“Callbacks”, “Request callback”', 'Yes', 'The callback notice in the one notice slot; the family request form', 'Side sheet and Family boards'],
    ['chat-urgent-contact.tsx', '“Need urgent help?”', 'Yes', 'Kept, on the Family board look', 'Family board'],
    ['chat-family-work.tsx', '“Take ownership”, “Hand over family work”, “Staff-only coordination”', 'Yes', 'Not drawn yet', 'Open question'],
    ['chat-composer-recovery.tsx', '“Saved elsewhere” draft comparison', 'Yes, inline', 'Not drawn yet', 'Open question'],
    ['chat-create-conversation.tsx', '“Start a chat”, “Create a private group”, “Create a managed channel”, “Message the care team”', 'No', 'New conversation, one form with One person · Group · Site channel', 'Starting a conversation'],
    ['chat-people-invite.tsx', '“Invite eligible colleagues”, “Invite approved relatives”', 'No', 'Add people', 'Starting a conversation; States every pop-up has'],
    ['chat-management.tsx', '“Manage conversation”, “Membership and ownership”, “Leave conversation”, “Transfer and leave”, Mute', 'No', 'People panel; Leave group and Remove confirms; Mute in the menu', 'Leaving, removing and deleting; Looking things up'],
    ['call-summary.tsx', '“Add a call summary” (Communication Record and Family Note)', 'No', 'Add a call record, with an optional note for Kiri', 'Looking things up and recording'],
    ['chat-family-followup.tsx', '“Create follow-up”', 'No', 'Turn into › Family note', 'Messages board menu'],
    ['—', 'New', '—', 'Share to another chat · Add a task · Report a concern · Pause messages · Stop asking for confirmations', 'Sharing and turning into work; Safety and privacy'],
];
const table = `<div class="oc-card pk-tbl" style="overflow: hidden"><table class="oc-table"><thead><tr><th style="width: 210px">Today’s file</th><th>Today’s pop-up</th><th style="width: 150px">Opened from chat-thread.tsx</th><th>In Chat v3</th><th style="width: 220px">Drawn on</th></tr></thead><tbody>${T.map(([f, t, th, r, w]) => `<tr><td><code>${f}</code></td><td>${t}</td><td>${th}</td><td><b>${r}</b></td><td>${w}</td></tr>`).join('')}</tbody></table></div>`;

const kit = `<div class="pk-kit">
<div class="c3-meas"><b>${lens('newchat', '', 13)}Form · 480</b><p>Lens, title, one line, ✕. The record it came from as a plain line. Grey footer: Cancel first, the main button last and named for what it does.</p></div>
<div class="c3-meas"><b>${lens('trash', 'crit', 13)}Confirm · 480</b><p>A question that names the thing. No ✕; Esc and Cancel close it. Red only when something is deleted or a safety check is loosened.</p></div>
<div class="c3-meas"><b>${lens('info', '', 13)}Side panel · 480</b><p>Message info and threads, on the sky head, beside the conversation. Read-only parts never become a pop-up.</p></div>
<div class="c3-meas"><b>${lens('more', '', 13)}Menus and toasts</b><p>Message and conversation actions, mute and “Delete for me” are menus with an Undo toast. A pop-up only when there is a form to fill.</p></div>
</div>`;

const sections = [
    sec('The kit Chat uses', 'POPUP_STYLE_GUIDE.md · Form, Confirm, Side panel, menus and toasts · widths 480, 720, 900 only', kit),
    sec('Starting a conversation', 'You can only add people you can see · the people picker and its states', `<div class="c3-r3">${col('New conversation · Form 480', newConv)}${col('People picker states', pickerStates)}${col('Add people · Form 480', addPeople)}</div>`),
    sec('Leaving, removing and deleting', '“Remove” keeps history and stays plain; “Delete” is red and offers Undo', `<div class="c3-r3">${col('Leave group · Confirm', `<div class="pk-col">${leave}${leaveOwner}</div>`)}${col('Remove a person · Confirm', `<div class="pk-col">${remove}${removeNoPerm}</div>`)}${col('Delete for everyone · Confirm, destructive', `<div class="pk-col">${del}${delToasts}</div>`)}</div>`),
    sec('Deleting · every state', 'Deleting, failed with the message kept, result not confirmed, too late, and on hold', `<div class="c3-r3">${col('Deleting · buttons wait', delSaving)}${col('Failed · nothing changed', delFailed)}${col('Result not confirmed · never send twice', delUnknown)}${col('Too late for everyone', delLate)}${col('On hold for an investigation', delHold)}${col('The rule behind every state (D9)', note('You can edit a message for 15 minutes and delete it for everyone for 1 hour. Earlier versions are kept for records; a hold stops edits and deletions.', 'tint', 'history'))}</div>`),
    sec('Sharing and turning into work', 'Approved forms from the Messages and Oblivion boards, with their error and access states', `<div class="c3-r3">${col('Share · family chats can’t receive staff messages', `<div class="pk-col">${share}${sharePart}</div>`)}${col('Add a task · validation and lost access', `<div class="pk-col">${taskErr}${taskNoPerm}</div>`)}${col('Report a concern · as Ana Rēweti', report)}</div>`),
    sec('Looking things up and recording', 'Message info stays a side panel; the call record replaces the two-step call summary; mute stays a menu', `<div class="c3-r3">${col('Message info · loading, and in a family conversation', `<div class="pk-col">${infoLoading}${infoFamily}</div>`)}${col('Add a call record · after a family call', `<div class="pk-col">${callRec}${callFail}</div>`)}${col('Mute · a menu and an Undo, no pop-up', mute)}</div>`),
    sec('Safety and privacy', 'Pausing a family contact, loosening a safety check, and sending past the privacy check', `<div class="c3-r3">${col('Pause messages · site leads only · warning', `<div class="pk-col">${pause}${note('Staff can’t block each other: mute the chat and report a concern instead.', '', 'ban')}</div>`)}${col('Stop asking for confirmations · destructive, reason required', stopChase)}${col('Send past the privacy check · destructive, reason required', sendAnyway)}</div>`),
    sec('States every pop-up has', 'Shown on Add people · loading, couldn’t load, nothing to add, no access, check your entry, saving, failed with work kept, result not confirmed, discard', `<div class="c3-r3">${col('Loading', stLoading)}${col('Couldn’t load', stCouldnt)}${col('Nothing to add', stEmpty)}${col('No access', stNoAccess)}${col('Check your entry', stInvalid)}${col('Saving · ✕ and Cancel wait', stSaving)}${col('Failed · choices kept', stFailed)}${col('Result not confirmed', stUnknown)}${col('Discard this draft? · the one guard', discard)}</div>`),
    sec('What each pop-up replaces', 'Today’s chat dialogs, the ones chat-thread.tsx opens, and where each one goes in Chat v3', table),
];
const size = C.writeBoard('ChatV3-Popups.html', 'Chat v3 — pop-ups', 'Chat v3 · pop-up kit', 'Every Chat pop-up on the app’s pop-up kit: one frame, the kit widths, the same states. Message actions stay a hover bar and one menu.', sections, STYLE);
console.log('ChatV3-Popups.html', size);
