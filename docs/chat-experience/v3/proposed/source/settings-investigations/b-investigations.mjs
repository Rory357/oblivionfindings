// Board 2 — ChatV3-Investigations.html (M22: the investigator's own page and the controlled PDF export)
import {
    svg, av, btn, badge, cap, col, sec, note, callout, field, input, box, radio, link, ring,
    seg, lc, tally, rvr, dlg, sky, gbtn, wbtn, fchip, meter, settingsMenu, scene, settingsPage, crop, ground, writeBoard, lens,
} from './lib.mjs';

const ME = { you: 'MT', youName: 'Mere Tūhoe' };
const menu = settingsMenu('Chat investigations', { investigator: true });
const CASE = 'Complaint about shift-swap messages';
const REF = 'HR-2026-031';
const gl = (icon, s = '') => `<span class="c3-gl${s ? ' ' + s : ''}" aria-hidden="true">${svg(icon, s === 's32' ? 15 : 17)}</span>`;

// ---------- 1 · the cases list ----------
const listHeader = sky({
    icon: 'shieldcheck', title: 'Chat investigations',
    sub: 'Read-only, time-limited and logged · only people given investigator access see this page',
    actions: wbtn('Open a case', 'plus'),
    filters: fchip('My cases', true) + fchip('Open and ended'),
    tabs: [['Cases', 4], ['Preservation holds', 1], 'Activity log'], on: 0,
});
const caseRow = ({ title, ref, opened, people, convs, access, accessSub, tone = 'info', exports, status, sel = false }) =>
    `<tr${sel ? ' class="hov"' : ''}><td><b>${title}<span class="st-ref">${ref}</span></b><small>${opened}</small></td><td><span style="display: flex; gap: 8px; align-items: center"><span class="st-stack">${people.map((p) => av(p, 's28')).join('')}</span><span>${convs}</span></span></td><td><b style="font-weight: 550">${access}</b><small style="display: flex; gap: 6px; align-items: center">${ring(tone)}${accessSub}</small></td><td>${exports}</td><td>${status}</td><td style="width: 44px"><button class="oc-kebab" type="button" aria-label="Actions for ${title}">${svg('kebab', 16)}</button></td></tr>`;
const menuItem = (icon, label, o = {}) => `<div class="c3-mi${o.del ? ' del' : ''}" role="menuitem">${svg(icon, 16)}<span>${label}</span>${o.k ? `<span class="k">${o.k}</span>` : ''}</div>`;
const caseMenu = `<div class="c3-menu abs" role="menu" aria-label="Actions for ${CASE}" style="right: 30px; top: 108px">${menuItem('eye', 'Open case')}${menuItem('filedown', 'Export PDF')}${menuItem('plus', 'Add conversations')}${menuItem('lock', 'Place a preservation hold')}<div class="c3-ms" role="separator"></div>${menuItem('stop', 'End all access', { del: true })}</div>`;
const listBody = [
    note('<b>Your organisation lets investigators open access themselves.</b> Every search, view, export and hold is logged, and kept apart from the settings audit.', 'tint', 'shieldcheck'),
    tally([
        ['3', 'Conversations open to you', 'info', 'In 2 cases'],
        ['1', 'Access ends within a day', 'warn', 'Lost phone check · today, 6:40 pm'],
        ['1', 'On preservation hold', 'neutral', 'Ana Rēweti and Rohan Patel'],
        ['3', 'Exports this week', 'neutral', 'Each one logged with a check code'],
    ]),
    lc('Cases', '4 cases · newest first'),
    `<div style="position: relative"><section class="oc-card st-tblc"><table class="oc-table"><thead><tr><th>Case</th><th>Conversations</th><th>Access</th><th>Exports</th><th>Status</th><th></th></tr></thead><tbody>
${caseRow({ title: CASE, ref: REF, opened: 'Opened Thu 29 Oct, 2:15 pm, for 3 days', people: ['ana', 'rohan', 'hana'], convs: '3 · 2 open', access: 'Ends Sun 1 Nov, 2:15 pm', accessSub: 'In 2 days', exports: '2', status: badge('Open', 'info'), sel: true })}
${caseRow({ title: 'Lost phone: check what was shared', ref: 'HR-2026-029', opened: 'Opened today, 10:40 am, for 8 hours', people: ['hana'], convs: '1 · open', access: 'Ends today, 6:40 pm', accessSub: 'In 3 hours', tone: 'warn', exports: '0', status: badge('Open', 'info') })}
${caseRow({ title: 'Night shift messages after an incident', ref: 'IR-2026-114', opened: 'Opened Mon 19 Oct, 9:00 am, for 7 days', people: ['rohan', 'ana'], convs: '2 · none open', access: 'Ended Mon 26 Oct, 9:00 am', accessSub: 'The access period finished', tone: 'neutral', exports: '1', status: badge('Ended', 'neutral') })}
${caseRow({ title: 'Complaint withdrawn', ref: 'HR-2026-018', opened: 'Opened Wed 21 Oct, 3:30 pm, for 24 hours', people: ['hana'], convs: '1 · none open', access: 'Ended Thu 22 Oct, 11:05 am', accessSub: 'Ended early by Mere Tūhoe', tone: 'neutral', exports: '0', status: badge('Ended', 'neutral') })}
</tbody></table></section>${caseMenu}</div>`,
    `<p class="oc-hint" style="margin: 0">Right-click a case, or use its ⋯ button, for the same actions. Ended cases stay listed with their log; their conversations can’t be opened again without a new case.</p>`,
].join('');
const s1 = sec('The cases list', 'Full page at 1440 · Settings › Chat investigations · one row per case · the ⋯ and right-click menu',
    scene(settingsPage({ menu, header: listHeader, body: listBody }), { crumbsT: ['Home', 'Settings', 'Chat investigations'], h: 960, ...ME }));

const quiet = (icon, title, p, action = '') => `<div class="oc-card"><div class="oc-empty"><span class="ei">${svg(icon, 22)}</span><b>${title}</b><p>${p}</p>${action}</div></div>`;
const s1b = sec('Quiet states', 'No access · no cases yet · access that has ended',
    `<div class="c3-r3">${col('Someone without investigator access opens the page', quiet('lock', 'You don’t have investigator access', 'Only people given “Investigate Chat conversations” in Roles can open this page. Managing Chat settings doesn’t give it. Ask your privacy officer.'))}
${col('An investigator with no cases yet', quiet('shieldcheck', 'No cases yet', 'When you open a case, its conversations and its log show here.', btn('Open a case', 'p', { sm: true, icon: 'plus' })))}
${col('Opening a conversation after its access ended (refused and logged)', quiet('timer', 'This access has ended', 'Access to Hana Wilson and Rohan Patel ended on Thu 29 Oct at 4:02 pm. This attempt was logged. To look again, open a new case with a reason.', btn('Open a case', 'o', { sm: true })))}</div>`);

// ---------- 2 · one case, with the read-only conversation ----------
const caseHeader = sky({
    icon: 'briefcase', back: true, title: CASE, status: '<span class="oc-skychip">Open</span>',
    sub: `${REF} · opened by you on Thu 29 Oct at 2:15 pm for 3 days · reason recorded`,
    actions: wbtn('Export PDF', 'filedown') + gbtn('Add conversations', 'plus') + '<button class="oc-gbtn st-icon" type="button" aria-label="More: end all access, place a hold">' + svg('more', 16) + '</button>',
    meters: meter('Open to you', '2 of 3', '1 ended Thu, 4:02 pm') + meter('Access ends', 'Sun, 2:15 pm', 'In 2 days') + meter('Exports', '2', 'Last today, 3:12 pm') + meter('On hold', '1', 'Ana Rēweti and Rohan Patel'),
    tabs: ['Conversations', 'About this case', 'Access log', 'Exports'], on: 0,
});
const crow = ({ icon, name, tag, l2, on = false, end = false, flag = '' }) =>
    `<a class="c3-row${on ? ' on' : ''}" href="#c"${on ? ' aria-current="true"' : ''}${end ? ' style="opacity: 0.72"' : ''}>${gl(icon)}<span style="min-width: 0"><span class="l1"><b>${name}</b><span class="tg">${tag}</span>${flag ? `<time>${flag}</time>` : ''}</span><span class="l2"><span class="tx">${l2}</span></span></span></a>`;
const caseList = `<section class="oc-card st-grp"><div class="oc-card-h">${lens('chat')}<div><div class="oc-card-t st-gt">Conversations in this case</div><div class="oc-card-s">Each one has its own access and log</div></div></div>
<div class="st-cl">
${crow({ icon: 'chat', name: 'Ana Rēweti, Rohan Patel', tag: 'Direct', l2: 'Open · ends Sun 2:15 pm · on hold', on: true, flag: svg('lock', 13) })}
${crow({ icon: 'users', name: 'Kōwhai afternoon team', tag: 'Group', l2: 'Ana Rēweti, Hana Wilson, Rohan Patel · open' })}
${crow({ icon: 'chat', name: 'Hana Wilson, Rohan Patel', tag: 'Direct', l2: 'Access ended Thu 29 Oct, 4:02 pm', end: true })}
</div><div class="st-cl-f">${btn('Add conversations', 'o', { sm: true, icon: 'plus' })}</div></section>`;
const grp = ({ who, time, bubbles, own = false }) => `<article class="c3-g" aria-label="Messages from ${who === 'rohan' ? 'Rohan Patel' : 'Ana Rēweti'}">${av(who, 's32')}<div class="c3-gb"><div class="c3-gh"><b>${who === 'rohan' ? 'Rohan Patel' : 'Ana Rēweti'}</b><span class="rl">${who === 'rohan' ? 'Site lead' : 'Support worker'}</span><span>${time}</span></div>${bubbles.join('')}</div></article>`;
const bw = (html, cls = 's') => `<div class="c3-bw"><div class="c3-b ${cls}">${html}</div></div>`;
const viewer = `<section class="oc-card st-viewer" style="height: 810px">
<header class="c3-th-h">${gl('chat', 's40')}<div class="id"><h2>Ana Rēweti and Rohan Patel</h2><p>Direct chat · started Mon 12 Oct 2026 · 2 members</p></div><div class="acts" style="gap: 8px">${badge('On preservation hold', 'warn', false)}${btn('End access', 'o', { sm: true })}</div></header>
<div class="c3-bar st-tint">${svg('eye', 16)}<span><b>Investigation view · read-only.</b> <span class="m">You’re not in this conversation: nothing is marked read, nobody is told, and you can’t reply. Every view is logged.</span></span><span class="end m">Ends Sun 1 Nov, 2:15 pm</span></div>
<div class="c3-msgs top" role="log" aria-label="Messages" style="padding-top: 8px">
<div style="align-self: center">${btn('Load earlier messages', 'o', { xs: true })}</div>
<div class="c3-day">Tuesday 27 October</div>
${grp({ who: 'rohan', time: '2:14 pm', bubbles: [bw('Hi Ana, can you take Saturday’s sleepover at Kōwhai House? Hana can’t do it.')] })}
${grp({ who: 'ana', time: '2:20 pm', bubbles: [bw('I’ve already done three this fortnight. I’d rather not this time.')] })}
${grp({ who: 'rohan', time: '2:31 pm', bubbles: [
        bw(`<span class="lbl">${svg('trash', 12)}Removed by sender · 2:33 pm</span>If you say no again, I’ll remember it when the roster comes out.`, 'f st-removed'),
        bw('Sorry, that came out wrong. I’ll ask the others first.', ''),
        `<div class="c3-mt"><span>2:34 pm</span><span class="sp"></span><span class="ed">Edited 2:36 pm</span></div>`,
        `<div class="c3-pq st-earlier"><small>Earlier version · replaced at 2:36 pm</small>Fine. I’ll ask someone who actually wants the hours.</div>`,
        `<div class="c3-bw"><div class="oc-file" style="min-width: 300px"><span class="fi">${svg('filetext', 16)}</span><span><b>roster-week-44.pdf</b><small>PDF · 84 KB · listed by name only</small></span></div></div>`,
    ] })}
${grp({ who: 'ana', time: '2:52 pm', bubbles: [bw('Thanks Rohan.')] })}
</div>
<div class="st-foot">${svg('lock', 14)}You can’t reply here. Removed messages and earlier versions show only in investigation views.</div>
</section>`;
const caseBody = `<div class="st-split">${caseList}${viewer}</div>`;
const s2 = sec('One case · the read-only conversation', 'Full page · a record header with its meters · the case’s conversations beside the read-only view · removed and edited messages visible',
    scene(settingsPage({ menu, header: caseHeader, body: caseBody }), { crumbsT: ['Home', 'Settings', 'Chat investigations', CASE], h: 1280, ...ME }));

// ---------- 3 · finding conversations ----------
const res = (on, title, sub = '', b = '') => `<label class="ch-chk${on ? ' on' : ''}"><span class="oc-box${on ? ' on' : ''}" aria-hidden="true">${on ? svg('check', 12) : ''}</span><span>${title}${sub ? `<small>${sub}</small>` : ''}</span>${b}</label>`;
const picked = (t) => `<div><span>${t}</span><button class="ch-ib" type="button" aria-label="Remove ${t}" style="width: 26px; height: 26px">${svg('x', 14)}</button></div>`;
const openCase = dlg({
    size: 'md', icon: 'briefcase', title: 'Open a case', sub: 'Read-only access to private staff conversations, for a set time',
    body: `<div class="oc-two">${field('Case reference', input(REF))}<div></div></div>${field('Access ends after', seg(['1 hour', '8 hours', '24 hours', '3 days', '7 days'], '3 days'), 'Ends Sun 1 Nov at 2:15 pm. You can end it sooner.')}
${field('Reason', input('Ana Rēweti made a complaint on 27 October about messages she got about shift swaps. The general manager approved this review on 28 October.', 'st-ta'), 'At least 10 characters. Kept with the case and shown on every view.')}
<div class="oc-field"><span class="oc-lbl">Conversations</span><span class="oc-hint">Search by a staff member’s name and tick each conversation this case covers. Search again to add other people. You see who is in each one, never its messages.</span>
<div class="st-search">${input(`${svg('search', 15)}Rohan`, 'focus')}${btn('Find', 'o', { icon: 'search' })}</div>
<div class="st-res" role="group" aria-label="Matching conversations">
${res(true, 'Direct chat · Ana Rēweti, Rohan Patel')}
${res(true, 'Private group · Kōwhai afternoon team', 'Ana Rēweti, Hana Wilson, Rohan Patel')}
${res(true, 'Direct chat · Hana Wilson, Rohan Patel')}
${res(false, 'Team channel · Kōwhai House', '18 people')}
${res(false, 'Direct chat · Demo Admin, Rohan Patel', '', badge('On hold', 'warn'))}
</div>
<span class="oc-hint">Family conversations aren’t listed: they follow client record access.</span></div>
<div class="oc-field"><span class="oc-lbl">Chosen for this case · 3 of up to 20</span><div class="st-picked">${picked('Direct chat · Ana Rēweti, Rohan Patel')}${picked('Private group · Kōwhai afternoon team')}${picked('Direct chat · Hana Wilson, Rohan Patel')}</div></div>
${note('Your organisation lets investigators open access themselves. Your name, the reason and each conversation are logged.', 'tint', 'shieldcheck')}`,
    foot: btn('Cancel', 'o') + btn('Open read-only access', 'p'),
});
const addConv = dlg({
    size: 'sm', icon: 'plus', title: 'Add conversations to this case', sub: `${CASE} · ${REF}`,
    body: `<section class="oc-rvc">${rvr('Reason', 'Kept from the case')}${rvr('Access ends', 'With the case: Sun 1 Nov, 2:15 pm')}</section>
<div class="st-search">${input(`${svg('search', 15)}Hana`, 'focus')}${btn('Find', 'o', { icon: 'search' })}</div>
<div class="st-res">${res(true, 'Direct chat · Ana Rēweti, Hana Wilson')}${res(false, 'Private group · Kōwhai afternoon team', 'Already in this case', badge('In this case', 'info'))}</div>
<span class="oc-hint">Each added conversation gets its own access and its own log, under this case.</span>`,
    foot: btn('Cancel', 'o') + btn('Add 1 conversation', 'p'),
});
const finderStates = ground([
    col('Nothing found', note('No private staff conversations include “Mereana”. Check the spelling, or try another name.', '', 'search')),
    col('A family conversation slipped in (the whole request is refused)', note('<b>Nothing was opened.</b> Only private staff conversations can be opened here. Family conversations follow client record access. Untick it and try again.', 'crit', 'alert')),
    col('The case is full', note('20 chosen · the most one case can cover. Open another case for the rest.', 'warn', 'alert')),
    col('Opened', note(`<b>Read-only access opened for 3 conversations.</b> It ends Sun 1 Nov at 2:15 pm. While it’s open you can export a watermarked PDF; every export is logged.<div>${btn('Open the case', 'o', { sm: true, after: 'chevr' })}</div>`, 'tint', 'checkc')),
].join(''));
const s3 = sec('Finding conversations', 'Open a case (720) with the finder: tick several, search again to add people · adding to an open case (480) · finder states',
    `<div class="st-cols" style="grid-template-columns: 720px 480px 1fr">${col('Open a case · several conversations under one reason', openCase)}${col('Add conversations to an open case', addConv)}${col('Finder and opening states', finderStates)}</div>`);

// ---------- 4 · the access log and the case details ----------
const li = (time, tone, words, small = '') => `<div class="st-li"><time>${time}</time>${ring(tone)}<div>${words}${small ? `<small>${small}</small>` : ''}</div></div>`;
const hash = (h) => `<span class="st-hash">${h}</span>`;
const logCrop = crop(lc('Access log', 'Every search, view, export and hold · investigators only · kept apart from the settings audit', btn('Download the log', 'o', { xs: true, icon: 'filedown' })) +
    `<section class="oc-card st-log">
${li('Today, 3:12 pm', 'info', '<b>Mere Tūhoe</b> exported a watermarked PDF of 2 conversations', `Logged once for each conversation · check code ${hash('3b7e41d0…c91a')}`)}
${li('Today, 3:05 pm', 'neutral', '<b>Mere Tūhoe</b> opened Ana Rēweti, Rohan Patel', '42 messages shown')}
${li('Today, 2:58 pm', 'info', '<b>Mere Tūhoe</b> exported a watermarked PDF of 1 conversation', `Check code ${hash('9f2c0b7a…41d0')}`)}
${li('Today, 9:10 am', 'crit', '<b>Refused:</b> Mere Tūhoe tried to open Hana Wilson, Rohan Patel', 'Access to it had ended')}
${li('Thu 29 Oct, 4:02 pm', 'neutral', '<b>Mere Tūhoe</b> ended access to Hana Wilson, Rohan Patel')}
${li('Thu 29 Oct, 3:40 pm', 'warn', '<b>Mere Tūhoe</b> placed a preservation hold on Ana Rēweti, Rohan Patel', 'While it’s on, senders can’t edit or remove messages there')}
${li('Thu 29 Oct, 2:16 pm', 'neutral', '<b>Mere Tūhoe</b> opened Kōwhai afternoon team', '120 messages shown')}
${li('Thu 29 Oct, 2:15 pm', 'info', '<b>Mere Tūhoe</b> opened read-only access to 3 conversations for 3 days', 'Reason recorded · case HR-2026-031')}
${li('Thu 29 Oct, 2:12 pm', 'neutral', '<b>Mere Tūhoe</b> searched private conversations for “Rohan”', '5 conversations found · no messages shown')}
</section>`);
const about = `<section class="oc-rvc"><div class="oc-rvc-h">${svg('briefcase', 15)}About this case</div>
${rvr('Case reference', REF)}${rvr('Reason', '<span style="display: block; text-align: left; font-weight: 450">Ana Rēweti made a complaint on 27 October about messages she got about shift swaps. The general manager approved this review on 28 October.</span>')}
${rvr('Opened by', 'Mere Tūhoe · Thu 29 Oct, 2:15 pm')}${rvr('Access period', '3 days · ends Sun 1 Nov, 2:15 pm')}${rvr('Conversations', '3 · 2 open, 1 ended')}${rvr('Preservation hold', 'On for 1 conversation')}${rvr('Second person approval', 'Not needed · organisation setting')}</section>`;
const s4 = sec('Access log and case details', 'The case’s Access log tab (time first, then the ring, then the words) · About this case',
    `<div class="st-cols" style="grid-template-columns: 924px 480px">${logCrop}${col('About this case (tab)', about)}</div>`);

// ---------- 5 · the export pop-up ----------
const inc = `<ul class="oc-sig-list" style="font-size: 12.5px"><li>Every message, including ones the sender removed</li><li>Earlier versions of edited messages</li><li>Attachments listed by name only, never the files</li></ul>`;
const stamp = `<div class="c3-pq"><small>Every page says</small>Confidential — investigation record · ${REF} · Mere Tūhoe · 30 Oct 2026, 3:12 pm NZDT</div>`;
const exReady = dlg({
    icon: 'filedown', title: 'Export a watermarked PDF', sub: `Case ${REF} · only conversations open to you`,
    body: `<div class="oc-field"><span class="oc-lbl">What to export</span>${radio(true, 'This conversation', 'Ana Rēweti, Rohan Patel')}${radio(false, 'Both conversations still open in this case', 'Hana Wilson, Rohan Patel isn’t included: its access ended')}</div>${inc}${stamp}
<span class="oc-hint">The export is logged for each conversation, with a check code for the file. Up to 5 exports every 10 minutes.</span>`,
    foot: btn('Cancel', 'o') + btn('Export and log', 'p', { icon: 'filedown' }),
});
const exDone = dlg({
    icon: 'checkc', title: 'Exported and logged', sub: `Case ${REF} · 1 conversation`,
    body: `${field('File', `<div class="oc-file"><span class="fi">${svg('filetext', 16)}</span><span><b style="max-width: 360px">chat-investigation-hr-2026-031-20261030-151204.pdf</b><small>Saved to your downloads</small></span></div>`)}
${field('Check code (SHA-256)', `<div class="st-codebox"><span>3b7e41d09a5c27f18e60b4d2c3f9a7e15d8820c46b1f3e97a0d5c4b2e86fc91a</span><button class="ch-ib" type="button" aria-label="Copy the check code">${svg('copy', 15)}</button></div>`, 'The same code is in the access log. It shows whether a copy has been changed.')}`,
    foot: btn('Open the access log', 'g') + btn('Close', 'o'),
});
const exRate = dlg({
    icon: 'timer', tone: 'warn', title: 'Export a watermarked PDF', sub: `Case ${REF}`,
    body: `${note('<b>You’ve exported 5 times in the last 10 minutes.</b> You can export again at 3:24 pm. Nothing was exported or logged this time.', 'warn', 'timer')}${inc}`,
    foot: btn('Close', 'o') + btn('Export and log', 'p', { icon: 'filedown', dis: true }),
});
const exEnded = dlg({
    icon: 'stop', tone: 'crit', title: 'This access has ended', sub: `Case ${REF}`,
    body: note('Access to Ana Rēweti, Rohan Patel ended at 2:15 pm, so it can’t be exported. The attempt was logged. To look again, open a new case with a reason.', 'crit', 'alert'),
    foot: btn('Close', 'o') + btn('Open a case', 'o', { icon: 'plus' }),
});
const exBig = dlg({
    icon: 'alert', tone: 'warn', title: 'Export a watermarked PDF', sub: `Case ${REF} · both open conversations`,
    body: note('<b>This export would have more than 5,000 messages.</b> Export the conversations one at a time. Nothing was exported or logged.', 'warn', 'alert'),
    foot: btn('Back', 'o') + btn('Export this conversation', 'p', { icon: 'filedown' }),
});
const s5 = sec('Export pop-up', '480 · ready · done with its check code · too many exports · access ended · too many messages',
    `<div class="c3-r3">${col('Ready', exReady)}${col('Done · file name and check code', exDone)}${col('Rate limited (5 every 10 minutes)', exRate)}</div>
<div class="c3-r3">${col('Refused · access ended or expired', exEnded)}${col('Refused · too many messages for one file', exBig)}<div></div></div>`);

// ---------- 6 · a page of the PDF ----------
const pmsg = (who, at, text, o = {}) => `<div class="st-pmsg${o.rm ? ' rm' : ''}"><div class="st-pmeta"><strong>${who}</strong> · ${at}${o.ed ? ` · <span class="st-ptag">Edited</span> ${o.ed}` : ''}${o.rm ? ` · <span class="st-ptag">Removed by sender</span> ${o.rm}` : ''}</div><div class="st-pc">${text}</div>${o.file ? `<div class="st-pmeta">Attachment: ${o.file} — contents not included</div>` : ''}${o.ver ? `<div class="st-pver"><div class="st-pmeta">Earlier version 1 · replaced ${o.ed}</div><div class="st-pc">${o.ver}</div></div>` : ''}</div>`;
const stampLine = `Case ${REF} · Exported by Mere Tūhoe · 30 Oct 2026, 3:12 pm NZDT`;
const paperChrome = (page) => `<div class="st-wm">Confidential — investigation record<span>${REF} · Mere Tūhoe · 30 Oct 2026, 3:12 pm NZDT</span></div>
<div class="st-ph"><div class="lab">Confidential — investigation record</div><div class="stamp">${stampLine}</div></div>
<div class="st-pf"><span>Export reference 6f1c2a9e-4b7d-4c1a-9e02-8d5fb41d7c30. This record was exported under authorised, time-limited case access and the export is logged. Do not copy or share it beyond the case. Times are New Zealand time.</span><span class="pg">Page ${page} of 3</span></div>`;
const page1 = `<div class="st-paper" role="img" aria-label="Page 1 of the exported PDF">${paperChrome(1)}
<h4>Chat investigation record</h4>
<table class="st-psum"><tr><td>Case reference</td><td>${REF}</td></tr><tr><td>Exported by</td><td>Mere Tūhoe</td></tr><tr><td>Exported</td><td>30 Oct 2026, 3:12 pm NZDT</td></tr><tr><td>Case access ends</td><td>1 Nov 2026, 2:15 pm NZDT</td></tr><tr><td>Conversations</td><td>2</td></tr></table>
<div class="st-pnote">This is the complete record of each conversation below, including messages the sender removed and earlier versions of edited messages. Attachments are listed by name only; their contents are not included. 1 conversation in this case is not included because access to it has ended.</div>
<h5>Direct chat · Ana Rēweti, Rohan Patel</h5>
<div class="st-pmeta">Members: Ana Rēweti, Rohan Patel · Started 12 Oct 2026, 9:05 am · 42 messages · Preservation hold active</div>
${pmsg('Rohan Patel', '27 Oct 2026, 2:14 pm', 'Hi Ana, can you take Saturday’s sleepover at Kōwhai House? Hana can’t do it.')}
${pmsg('Ana Rēweti', '27 Oct 2026, 2:20 pm', 'I’ve already done three this fortnight. I’d rather not this time.')}
${pmsg('Rohan Patel', '27 Oct 2026, 2:31 pm', 'If you say no again, I’ll remember it when the roster comes out.', { rm: '27 Oct 2026, 2:33 pm' })}
${pmsg('Rohan Patel', '27 Oct 2026, 2:34 pm', 'Sorry, that came out wrong. I’ll ask the others first.', { ed: '27 Oct 2026, 2:36 pm', ver: 'Fine. I’ll ask someone who actually wants the hours.' })}
${pmsg('Rohan Patel', '27 Oct 2026, 2:40 pm', 'Here’s next week’s roster.', { file: 'roster-week-44.pdf (PDF, 84 KB)' })}
${pmsg('Ana Rēweti', '27 Oct 2026, 2:52 pm', 'Thanks Rohan.')}
</div>`;
const page2 = `<div class="st-paper" role="img" aria-label="Page 2 of the exported PDF">${paperChrome(2)}
${pmsg('Rohan Patel', '28 Oct 2026, 8:15 am', 'Morning Ana. Are you able to cover Saturday after all?')}
${pmsg('Ana Rēweti', '28 Oct 2026, 8:41 am', 'No, sorry. I said I can’t this week.')}
${pmsg('Rohan Patel', '28 Oct 2026, 8:44 am', 'No worries, it’s sorted.', { ed: '28 Oct 2026, 8:47 am', ver: 'Ok. Noted.' })}
<h5>Private group · Kōwhai afternoon team</h5>
<div class="st-pmeta">Members: Ana Rēweti, Hana Wilson, Rohan Patel · Started 3 Aug 2026, 1:00 pm · 120 messages</div>
${pmsg('Hana Wilson', '27 Oct 2026, 1:02 pm', 'The van’s booked for the 11 o’clock outing on Saturday.')}
${pmsg('Rohan Patel', '27 Oct 2026, 1:15 pm', 'Thanks Hana. I still need someone for Saturday’s sleepover.')}
${pmsg('Rohan Patel', '27 Oct 2026, 2:45 pm', 'Some people need to be more flexible.', { rm: '27 Oct 2026, 2:46 pm' })}
${pmsg('Hana Wilson', '27 Oct 2026, 3:10 pm', 'I can ask my cousin, she does relief work.')}
</div>`;
const pdfNotes = `<div class="ch-col">${cap('On every page')}<ul class="oc-sig-list"><li>The label at the top, then the case, who exported it and when (NZ time).</li><li>A large diagonal watermark with the same words.</li><li>The footer: the export reference and “Page 1 of 3”.</li></ul>${cap('In the record')}<ul class="oc-sig-list"><li>Removed messages: a red edge, “Removed by sender” and the time.</li><li>Edited messages: “Edited”, the time, and the earlier version in a dashed box.</li><li>Attachments: name, kind and size only.</li><li>Ended conversations are left out, and the summary says so.</li></ul>${cap('Not printed')}<ul class="oc-sig-list"><li>The file’s check code. It is logged for each conversation.</li></ul></div>`;
const s6 = sec('A page of the PDF', 'A4, always on white · watermarked with the case, the investigator, NZ export time and “Confidential — investigation record”',
    `<div class="st-cols" style="grid-template-columns: 620px 620px 1fr"><div class="oc t-d l b-journey" style="background: transparent">${page1}</div><div class="oc t-d l b-journey" style="background: transparent">${page2}</div>${pdfNotes}</div>`);

const len = writeBoard('ChatV3-Investigations.html', 'Chat v3 — investigations', 'Chat v3 · investigations',
    'The investigator’s own page (M22): cases, the read-only conversation, the access log and the controlled, watermarked PDF export. Only people given investigator access see it.',
    [s1, s1b, s2, s3, s4, s5, s6]);
console.log('ChatV3-Investigations.html', len);
