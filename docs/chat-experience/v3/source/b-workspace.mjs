import * as C from './c3lib.mjs';
const { svg, av, gl, btn, ib, lens, badge, e, col, sec, note, group, ownMeta, day, unreadLine, typing, jump, gallery, voice, link, card, composer, rail, crow, csh, STAFF_LIST, threadHead, thread, mi, ms, menu, rc, rx, dr, scene, writeBoard } = C;

// ---------- 1 · staff thread, full page ----------
const staffMsgs = [
    day('Today · Monday 19 October'),
    group({ who: 'mere', own: true, bubbles: [`<button class="ch-quote" type="button"><b>Alex Morgan</b>The afternoon handover is ready. Could you check it before lunch?</button>Absolutely, I’ll check it before lunch. Saturday’s bus: ${link('kowhaibus.nz/route-52')}`], after: C.preview({ site: 'Kōwhai Buses', title: 'Route 52 timetable · Kōwhai to the city', desc: 'Weekday and weekend times, stops and live departures.', ph: 'p1', own: true }), meta: ownMeta('10:31 am', 'read') }),
    group({ who: 'alex', time: '10:36 am', bubbles: [{ raw: gallery('') }], after: rx(rc('❤️', 3, true, 'You, Rohan, Hana'), rc('👏', 2, false, 'Rohan, Hana')) }),
    unreadLine('2 new messages'),
    group({ who: 'rohan', time: '10:42 am', role: 'Site lead', bubbles: [{ raw: C.card({ icon: 'alert', md: 'Incident', title: 'Slip in the downstairs bathroom', refn: 'INC-0142', facts: 'Tane Wineera · Kōwhai House · 9:12 am', badgeT: 'Open', tone: 'warn', action: 'Open incident' }) }, { html: `${voice('0:08 / 0:31', 9)}` }] }),
    typing('hana'),
].join('');
const staffThread = thread({
    head: threadHead({ who: 'alex', name: 'Alex Morgan', sub: 'Support worker · On shift at Kōwhai House until 3 pm' }),
    msgs: staffMsgs,
    comp: composer({}),
});
const staffScene = scene(`<div class="c3-ws">${rail({ body: STAFF_LIST() })}${staffThread}</div>`);

// ---------- 2 · family thread seen by staff ----------
const famBar = `<div class="c3-bar fam"><span class="c3-aud">${lens('shield', '', 11)}Family + care team can read this</span><span class="sep" aria-hidden="true"></span><span><span class="m">Owner</span> <b>Mere Thompson</b></span><span class="sep" aria-hidden="true"></span><span class="c3-due">${C.badge('Reply due by 3:00 pm', 'warn')}</span><span class="end">${btn('Callbacks 1', 'o', { xs: true, icon: 'phonein' })}${btn('Hand over', 'o', { xs: true, after: 'chev' })}</span></div>`;
const famMsgs = [
    day('Yesterday'),
    group({ who: 'kiri', time: '4:58 pm', bubbles: ['Kia ora, how was Tane’s day? He sounded tired on the phone.'] }),
    group({ who: 'mere', own: true, bubbles: ['Kia ora Kiri — he had a great day at cooking group and made his own lunch. A bit tired after the walk, so he’s having a quiet evening.', { raw: `<div class="c3-ph one p2" role="img" aria-label="Tane’s lunch"></div>` }], after: `<span class="c3-saved">${svg('check', 12)}Saved to Tane’s photos</span>`, meta: ownMeta('5:20 pm', 'read', '<span class="sp"></span><span>Seen by Kiri</span>') }),
    group({ who: 'kiri', time: '5:24 pm', bubbles: ['That’s lovely to hear, thank you! ' + e('🥰')], after: rx(rc('❤️', 1, true, 'You')) }),
    day('Today · Monday 19 October'),
    unreadLine('1 new message'),
    group({ who: 'kiri', time: '11:10 am', bubbles: ['Can we visit on Saturday afternoon? His nana is coming up from Taupō.'] }),
].join('');
const famThread = thread({
    head: threadHead({ who: 'kiri', state: '', name: 'Kiri Wineera · About Tane', sub: 'Whānau · Tane Wineera · Kōwhai House', tabs: ['Chat', 'Files', 'Visits', 'Notes'], video: true }),
    bar: famBar,
    msgs: famMsgs,
    comp: composer({ ph: 'Message Kiri and the care team…', aud: 'Kiri and Tane’s care team' }),
});
const famList = [
    csh('Family', '3 waiting'),
    crow({ who: 'kiri', name: 'Kiri Wineera', tag: 'Family', time: '11:10', prev: 'About Tane · Can we visit on Saturday?', unread: 1, on: true }),
    crow({ who: 'jo', name: 'Jo Taylor', tag: 'Family', time: '09:40', prev: 'About Sam · Friday afternoon suits us' }),
    crow({ who: 'nina', name: 'Nina Taylor', tag: 'Family', time: 'Tue', prev: 'About Elliot · You: We’ll call you at 2 pm' }),
    csh('Favourites'),
    crow({ who: 'alex', name: 'Alex Morgan', time: '10:42', prev: 'You: Absolutely, I’ll check it before lunch.', state: 'on' }),
    crow({ icon: 'users', name: 'Kōwhai afternoon team', time: '10:36', prev: 'Hana: The updated plan is pinned', unread: 1, mention: true }),
    csh('Chats'),
    crow({ who: 'rohan', name: 'Rohan Patel', time: '09:58', prev: '', draft: 'Can we swap Thursday’s…', state: 'on' }),
    crow({ who: 'hana', name: 'Hana Wilson', time: 'Yesterday', prev: 'Thanks, see you Friday', state: 'leave' }),
].join('');
const famScene = scene(`<div class="c3-ws">${rail({ on: 'Family', body: famList })}${famThread}</div>`);

// ---------- 3 · measured difference ----------
const meas = `<div class="c3-note-row">
<div class="c3-meas"><b>Messages get the screen</b><p>Today’s family thread gives messages 191 px of a 900 px window (21%). The slim bar and inline tabs give them about 690 px of 900 (77%).</p><div class="bar" aria-hidden="true"><i style="width: 21%"></i></div><div class="bar" aria-hidden="true"><i class="m" style="width: 77%"></i></div><div class="lg"><span>Today · 191 px</span><span class="m">Proposed · ~690 px</span></div></div>
<div class="c3-meas"><b>One bar instead of three rows</b><p>The privacy banner, the owner row (Reassign, Staff coordination, Hand over) and the “Needs reply” row become one 42 px bar: who can read it, the owner, the reply clock and two actions. Everything else moves to the ⋯ menu and the Details panel.</p></div>
<div class="c3-meas"><b>Familiar from WhatsApp and Teams</b><p>Grouped bubbles with one name and photo, reactions under the bubble, photos as a grid, links you can click, typing and read rings. Actions appear on hover and right-click, not in a pop-up.</p></div>
</div>`;

// ---------- 4 · rail parts ----------
const rows = `<div class="ch-mini" style="padding: 10px 8px">${[
    csh('Row states'),
    crow({ who: 'alex', name: 'Alex Morgan', time: '10:42', prev: 'Alex: Thanks. Let’s catch up at handover.', unread: 3, state: 'on' }),
    crow({ icon: 'users', name: 'Kōwhai afternoon team', time: '10:36', prev: 'Hana: @Mere Thompson can you check…', unread: 1, mention: true }),
    crow({ who: 'rohan', name: 'Rohan Patel', time: '09:58', prev: '', draft: 'Can we swap Thursday’s shift?', state: 'on' }),
    crow({ icon: 'users', name: 'Night team', time: 'Mon', prev: 'You: Handover notes are in', muted: true }),
    crow({ who: 'hana', name: 'Hana Wilson', time: 'Yesterday', prev: 'Thanks, see you Friday', state: 'leave' }),
    crow({ who: 'nina', name: 'Nina Taylor', tag: 'Family', time: 'Tue', prev: 'About Elliot · You: We’ll call you at 2 pm' }),
].join('')}</div>`;
const ringKey = `<div class="ch-mini" style="padding: 14px 16px; display: grid; gap: 12px">
${cap2('People rings')}
<div style="display: flex; gap: 18px; align-items: center; flex-wrap: wrap">${[['alex', 'on', 'On shift'], ['hana', 'leave', 'On leave'], ['nina', '', 'Off shift'], ['rohan', 'dnd', 'Do not disturb']].map(([w, s, t]) => `<span style="display: inline-flex; gap: 8px; align-items: center; font-size: 12.5px; font-weight: 600">${av(w, 's32', s)}${t}</span>`).join('')}</div>
${cap2('Message rings · always with a word')}
<div style="display: flex; gap: 16px; flex-wrap: wrap" class="c3-mt">${['sending', 'sent', 'dlv', 'read', 'fail'].map((s) => `<span style="display: inline-flex; gap: 6px; align-items: center; font-size: 12px">${dr(s)}</span>`).join('')}</div>
${note('Read rings show for staff conversations. Family see “Seen by care team”, never which worker read it or when they were last online.', 'tint')}
</div>`;
function cap2(t) { return `<span class="ch-cap">${t}</span>`; }
const rowMenu = `<div style="position: relative; height: 360px">
<div class="ch-mini" style="padding: 8px; width: 300px">${crow({ icon: 'users', name: 'Kōwhai afternoon team', time: '10:36', prev: 'Hana: The updated plan is pinned', unread: 1, hov: true })}</div>
${menu([mi('pin', 'Pin to top'), mi('star', 'Add to favourites'), mi('unread', 'Mark as unread'), mi('belloff', 'Mute', { arrow: true }), ms(), mi('archive', 'Archive'), mi('leave', 'Leave group', { del: true })], 'abs', 'left: 20px; top: 62px', 'Conversation actions')}
<div class="c3-menu abs" role="menu" aria-label="Mute" style="left: 258px; top: 170px; width: 200px">${['For 1 hour', 'For 8 hours', 'Until tomorrow 7 am', 'Until I turn it back on'].map((t, i) => mi('', t, { on: i === 1 })).join('')}</div>
</div>`;

const sections = [
    sec('Staff conversation', 'Full page at 1440 × 1000 · grouped messages, reactions, photo grid, care card, voice note, typing, read ring', `<div class="c3-stage">${staffScene}</div>`),
    sec('A family conversation, seen by staff', 'One slim bar keeps the privacy line, owner, reply clock and hand-over — the messages keep the screen', `<div class="c3-stage">${famScene}</div>`),
    sec('Why this layout', 'Measured on oblivionfindings.com at 1440 × 900, 22 October', meas),
    sec('Conversation list parts', 'Rows carry presence, sender, drafts, mutes and mentions; right-click and the row kebab open the same menu', `<div class="c3-r3">${col('Row states', rows)}${col('Rings — the shape carries the state', ringKey)}${col('Right-click a row · Mute choices', rowMenu)}</div>`),
];
export const size = writeBoard('ChatV3-Workspace.html', 'Chat v3 — workspace', 'Chat v3 · workspace', 'Proposed rebuild of the staff and family threads — messages take the screen; Slate skin with the Oblivion signature.', sections);
console.log('ChatV3-Workspace.html', size);
