// Board 1 — ChatV3-Settings.html (plan item C7.8, Settings › Chat on the Fleet settings pattern)
import {
    cap, svg, av, btn, badge, col, sec, note, callout, field, input, sel, box, radio, link, ring,
    sw, seg, num, tpick, dpick, xchip, row, changed, cant, siteOnly, loosens, follows,
    group, gg, gcol, lc, tally, rv, rvr, chg, savebar, dlg, sky, gbtn, fchip, t2,
    settingsMenu, scene, settingsPage, crop, ground, writeBoard, lens,
} from './lib.mjs';

const RAIL = ['General', 'Messages', 'Calls', 'Family', 'Notifications', 'Privacy and records', 'History'];
const header = (on, scope = 'Whole organisation', filter = 'All settings') =>
    sky({
        icon: 'chattext',
        title: 'Chat settings',
        sub: 'Messages, calls and family contact · nothing changes until you review and save',
        actions: gbtn('Open Chat', 'chat', 'arrowur') + gbtn('Changes', 'history'),
        filters: fchip(scope, true) + fchip(filter),
        tabs: RAIL,
        on,
    });
const menu = settingsMenu('Chat settings');

// ---------- 1 · Overview ----------
const overviewBody = [
    tally([
        ['1 of 2', 'Sites using Chat', 'info', 'Tōtara service is still on Messages'],
        ['3', 'Can’t switch on yet', 'warn', 'Server set-up needed'],
        ['2', 'Service checks need attention', 'warn', 'Checked today, 9:42 am'],
        ['4', 'Changes this week', 'neutral', 'Last by Rohan Patel, Thursday'],
    ]),
    lc('Chat at a glance', 'Organisation settings · each card opens its area'),
    `<div class="st-gg st-eq">
${rv({ icon: 'building', title: 'Rollout', lines: ['Chat is on at <b>Kōwhai House</b>. <b>Tōtara service</b> still opens the old Messages page.', 'Family conversations are on at both Sites.'], cta: 'Review rollout' })}
${rv({ icon: 'chattext', title: 'Messages and files', badgeH: badge('1 can’t switch on yet', 'warn'), lines: ['Edit for 15 minutes. Delete for everyone for 1 hour. The original is always kept.', 'Photos and documents can’t be switched on yet: there’s no virus scanner on your server.'], cta: 'Review messages and files' })}
${rv({ icon: 'phone', title: 'Calls', lines: ['Staff voice and video calls are on: up to 8 people on voice, 4 on video.', 'Family video calls are off at both Sites.'], cta: 'Review calls' })}
${rv({ icon: 'home', title: 'Family contact', lines: ['Families see “We usually reply within 4 working hours”, Monday to Friday, 8 am to 6 pm.', 'Messages are checked before family see them. Urgent help number: on.'], cta: 'Review family contact' })}
${rv({ icon: 'bell', title: 'Notifications', badgeH: badge('Push not set up', 'warn'), lines: ['In-app notices are on for every Chat notice. Email for mentions, urgent messages and family work and replies.', 'Push can’t be switched on yet.'], cta: 'Review notifications' })}
${rv({ icon: 'shield', title: 'Privacy and records', lines: ['Nothing deletes itself: no retention period is approved yet.', '1 person can open conversations for an investigation. They open access themselves and every step is logged.'], cta: 'Review privacy and records' })}
${rv({
        icon: 'activity', title: 'Service health', badgeH: badge('2 need attention', 'warn'),
        lines: ['Checked today at 9:42 am by Demo Admin.'],
        rows: rvr('Messages', badge('Check passed', 'ok')) + rvr('Live updates', badge('Needs attention', 'warn')) + rvr('Push notifications', badge('Not set up', 'neutral')) + rvr('Uploads and virus scanning', badge('Not set up', 'neutral')) + rvr('Calls', badge('Check passed', 'ok')),
        extra: `<div style="display: flex; gap: 8px; align-items: center">${btn('Check again', 'o', { xs: true, icon: 'refresh' })}<span class="oc-hint">Up to 6 checks a minute</span></div>`,
        cta: 'Review service health',
    })}
${rv({ icon: 'history', title: 'Change history', lines: ['<b>Thu 29 Oct, 4:05 pm</b> · Rohan Patel switched on Chat at Kōwhai House.', '<b>Wed 28 Oct, 11:20 am</b> · Demo Admin changed delete for everyone to 1 hour.', '<b>Mon 26 Oct, 9:02 am</b> · Demo Admin switched on family video calls, then off again.'], cta: 'Review change history' })}
</div>`,
];
const s1 = sec('Overview', 'Full page at 1440 · opens on one review card per area, each with its way in · Service health on the overview (C1.11)',
    scene(settingsPage({ menu, header: header(0), t2: t2([['Overview', 'grid'], ['Rollout by Site', 'building'], ['Service health', 'activity']], 0), body: overviewBody.join(''), save: savebar({}) }), { h: 1510 }));

// ---------- 2 · General › Rollout by Site ----------
const rolloutBody = gg(
    gcol(
        group({
            icon: 'chattext', title: 'Chat for staff', cap: 'Organisation setting · each Site below can differ',
            rows: [
                row({ label: 'Chat', badges: badge('On at 1 of 2 Sites', 'info'), hint: 'New messages and conversations. Turning it off keeps history readable for the people who could already see it.', ctrl: sw(true) }),
                row({ label: 'Family conversations', hint: 'Families message the care team from the family portal.', ctrl: sw(true) }),
            ],
        }),
        group({
            icon: 'server', title: 'Waiting for your server', cap: 'Your IT provider sets these up first. Until then they stay off.',
            rows: [
                row({ label: 'Live updates', badges: cant(), state: 'dis', hint: 'New messages show within 30 seconds instead of straight away. Your IT provider switches on Oblivion Live on the server.', ctrl: sw(false, { dis: true }) }),
                row({ label: 'Drafts kept across devices', badges: cant(), state: 'dis', hint: 'Unsent messages are kept on this browser only. The server needs an update before this can go on.', ctrl: sw(false, { dis: true }) }),
                row({ label: 'Virus scanner for files', badges: cant(), state: 'dis', hint: 'No scanner is installed, so photos and documents can’t be shared yet. Your IT provider installs one.', ctrl: sw(false, { dis: true }), sub: link('See service health') }),
            ],
        }),
    ),
    gcol(
        group({
            icon: 'building', title: 'Sites', cap: 'Switch Chat on one Site at a time',
            rows: [
                row({ label: 'Kōwhai House', hint: 'On since Thu 29 Oct · 18 staff, 6 families', ctrl: sw(true), sub: `<span class="st-chips">${btn('Kōwhai House settings', 'g', { xs: true, after: 'chevr' })}</span>` }),
                row({ label: 'Tōtara service', badges: changed(), state: 'changed', hint: 'After you save, the Messages page sends people here to Chat, with their history. 11 staff, 4 families.', ctrl: sw(true, { foc: true }) }),
            ],
        }),
        note('Chat goes on for a Site only when both the organisation and that Site are on. A Site can’t give anyone more access than the organisation allows.', 'tint'),
    ),
);
const s2 = sec('General · rollout by Site', 'Full page · “Chat” shows its real state · Sites switch on one at a time · server parts say why they can’t go on yet',
    scene(settingsPage({ menu, header: header(0), t2: t2([['Overview', 'grid'], ['Rollout by Site', 'building'], ['Service health', 'activity']], 1), body: rolloutBody, save: savebar({ count: 1, title: '1 change to review · Tōtara service' }) }), { h: 1040 }));

// ---------- 3 · Messages and files at Kōwhai House (a Site's own settings) ----------
const msgBody = [
    note('<b>You’re looking at Kōwhai House.</b> 7 settings follow the organisation and 2 are set for this Site. A Site can’t give anyone more access than the organisation allows.', 'tint', 'building'),
    gg(
        group({
            icon: 'pencil', title: 'Editing and removing', cap: 'Counted from when a message is sent · the original is always kept',
            rows: [
                row({ label: 'Edit own messages', hint: follows('On'), ctrl: sw(true) }),
                row({ label: 'Edit for up to', badges: siteOnly(), hint: 'Organisation: 15 minutes · <a class="st-go" href="#inherit" style="font-size: 12px">Use the organisation’s setting</a>', ctrl: num('10', 'minutes') }),
                row({ label: 'Delete for everyone', hint: follows('On'), ctrl: sw(true) }),
                row({ label: 'Delete for everyone for up to', badges: changed(), state: 'changed', hint: 'Kōwhai House only after you save · organisation: 1 hour', ctrl: num('30', 'minutes', { focus: true }) }),
                row({ label: 'Show “Edited” and “Removed” to everyone', hint: follows('On') + ' · investigators can still see the original', ctrl: sw(true) }),
            ],
        }),
        gcol(
            group({
                icon: 'clip', title: 'Photos and documents', cap: 'Shared in the conversation, not added to client records',
                rows: [
                    row({ label: 'Share photos and documents', badges: cant(), state: 'dis', hint: 'There’s no virus scanner on your server, so files can’t be checked. Your IT provider installs one; then you can switch this on.', ctrl: sw(false, { dis: true }), sub: link('See service health') }),
                    row({ label: 'Largest file', state: 'dis', hint: follows('20 MB'), ctrl: num('20', 'MB', { dis: true }) }),
                    row({ label: 'Files per message', state: 'dis', hint: follows('5'), ctrl: num('5', 'files', { dis: true }) }),
                ],
            }),
            group({
                icon: 'mic', title: 'Voice messages', cap: 'Recorded in the message box, up to 2 minutes',
                rows: [
                    row({ label: 'Voice messages', badges: cant(), state: 'dis', hint: 'Needs photo and document sharing, which can’t be switched on yet.', ctrl: sw(false, { dis: true }) }),
                ],
            }),
        ),
    ),
].join('');
const s3 = sec('A Site’s own settings', 'Full page, scope set to Kōwhai House · inherited vs “Kōwhai House only” · no virus scanner, so files can’t go on yet',
    scene(settingsPage({ menu, header: header(1, 'Kōwhai House'), t2: t2([['Overview', 'grid'], ['Messages and files', 'chattext'], ['Reactions and links', 'smile']], 1), body: msgBody, save: savebar({ count: 1, title: '1 change to review · Kōwhai House only', actions: btn('Use organisation settings here', 'g', { sm: true, icon: 'undo' }) + btn('Discard changes', 'o', { sm: true }) + btn('Review changes', 'p', { sm: true }) }) }), { h: 1060 }));

// the other Messages sub-tab, as a crop
const reactCrop = crop(t2([['Overview', 'grid'], ['Messages and files', 'chattext'], ['Reactions and links', 'smile']], 2) + gg(
    group({
        icon: 'smile', title: 'Reactions and stickers', cap: 'Emoji are content: they show in messages, never as buttons',
        rows: [
            row({ label: 'Quick reactions', hint: 'The six shown first when someone reacts. Any emoji is still one tap away.', ctrl: btn('Change', 'o', { xs: true }), sub: '<span class="st-emo" aria-label="Quick reactions"><span>👍</span><span>❤️</span><span>😂</span><span>😮</span><span>🙏</span><span>✅</span></span>' }),
            row({ label: 'Oblivion stickers', hint: 'The built-in set, with te reo Māori words.', ctrl: sw(true) }),
            row({ label: 'Your organisation’s stickers and GIFs', hint: '14 uploaded. No outside GIF service is used.', ctrl: sw(true), sub: btn('Manage the library', 'o', { xs: true, after: 'arrowur' }) }),
        ],
    }),
    group({
        icon: 'link', title: 'Links', cap: 'Oblivion links always become care cards',
        rows: [
            row({ label: 'Previews of outside websites', hint: 'Oblivion’s server fetches the preview, so the reader’s browser never contacts the site. Internal addresses and large pages are refused.', ctrl: sw(true) }),
            row({ label: 'Senders can remove a preview', hint: 'Shows a remove button on their own previews.', ctrl: sw(true) }),
        ],
    }),
), {});

// ---------- 4 · Calls (crop) ----------
const callsCrop = crop(t2([['Overview', 'grid'], ['Staff calls', 'phone'], ['Family calls', 'home']], 1) + gg(
    gcol(
        group({
            icon: 'phone', title: 'Voice and video', cap: 'One click to call · active calls can finish when you change these',
            rows: [
                row({ label: 'Voice calls', hint: 'Between staff, from any conversation.', ctrl: sw(true) }),
                row({ label: 'Largest voice call', hint: '2 to 8 people', ctrl: num('8', 'people') }),
                row({ label: 'Video calls', hint: 'Camera on or off, any time in the call.', ctrl: sw(true) }),
                row({ label: 'Largest video call', hint: '2 to 4 people', ctrl: num('4', 'people') }),
                row({ label: 'Screen sharing', hint: 'One screen at a time, in video calls.', ctrl: sw(true) }),
            ],
        }),
    ),
    gcol(
        group({
            icon: 'link', title: 'Call links', cap: 'For staff only, never families',
            rows: [
                row({ label: 'Call links for staff', hint: 'A signed link a colleague opens to join.', ctrl: sw(true), sub: `<span class="oc-lbl" style="font-size: 12.5px">Links stop working after</span>${seg(['1 hour', '8 hours', '24 hours'], '8 hours')}` }),
            ],
        }),
        group({
            icon: 'server', title: 'Connection', cap: 'Set up on your server',
            rows: [
                row({ label: 'Relay for strict networks', badges: cant(), state: 'dis', hint: 'Your server doesn’t have the call relay yet. Most calls connect without it; some hospital, hotel and mobile networks may block them. Your IT provider sets it up.', ctrl: sw(false, { dis: true }) }),
            ],
        }),
        note('Calls are never recorded or transcribed.', '', 'info'),
    ),
), {});
const familyCallsCol = ground(`<div class="st-lc"><b>Family calls</b><span>the other Calls sub-tab</span></div>` + group({
    icon: 'home', title: 'Family calls', cap: 'With the care team · video is one-to-one only',
    rows: [
        row({ label: 'Family voice calls', hint: 'Families call the care team from the portal. Unanswered calls offer a callback request.', ctrl: sw(true) }),
        row({ label: 'Family video calls', hint: 'Off at every Site by default. Switch on per Site.', ctrl: sw(false) }),
        row({ label: 'Video at Kōwhai House', hint: follows('Off'), ctrl: sw(false) }),
        row({ label: 'Video at Tōtara service', hint: follows('Off'), ctrl: sw(false) }),
    ],
}));
const s4 = sec('Calls', 'Staff calls at the page column’s width (924 px) · family video off per Site (D5)',
    `<div class="st-cols" style="grid-template-columns: 924px 480px">${callsCrop}${familyCallsCol}</div>`);

// ---------- 5 · Family contact (time pickers, urgent help, a loosening change) ----------
const days = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'].map((d) => `<div class="st-day"><span>${d}</span>${tpick('8:00 am', '')}<span class="oc-muted">to</span>${tpick('6:00 pm', '')}${btn('', 'g', { xs: true, icon: 'x', aria: `Remove ${d} hours` })}</div>`).join('') +
    ['Saturday', 'Sunday'].map((d) => `<div class="st-day closed"><span>${d}</span><span>Closed</span></div>`).join('');
const famBody = gg(
    gcol(
        group({
            icon: 'home', title: 'Family conversations', cap: 'Between families and the care team',
            rows: [
                row({ label: 'Family conversations', hint: 'Named workers’ conversations can be read by authorised cover, and families are told.', ctrl: sw(true) }),
                row({ label: 'Check messages before family see them', badges: changed(), state: 'changed', hint: 'Warns staff when a message names another person supported, has a photo without consent or mentions health details.', ctrl: sw(false, { foc: true }), sub: loosens() }),
                row({ label: '“Seen by care team”', hint: 'Families see that the team read it, never which person.', ctrl: `<span class="st-lockd">${svg('lock', 13)}Always on</span>` }),
            ],
        }),
        group({
            icon: 'phone', title: 'Urgent help', cap: 'Shown with “In an emergency, call 111”',
            rows: [
                row({ label: 'Show an urgent help number', hint: 'Families see it in the portal and in their conversation.', ctrl: sw(true) }),
                `<div class="oc-setline st-row"><div class="st-sub" style="gap: 10px; justify-items: stretch">
${field('Name shown to families', input('Kōwhai House on-call'))}
${field('Phone number', input('09 555 0142'))}
${field('When to use it', input('After 6 pm and at weekends, if it can’t wait until the morning.', 'st-ta'))}
${box(true, 'I’ve checked this number and wording are current and approved for families', 'Ticks clear when anyone edits the number or wording.')}
</div></div>`,
            ],
        }),
    ),
    group({
        icon: 'clock', title: 'Reply times', cap: 'What families are told about when they’ll hear back',
        rows: [
            row({ label: 'Show families a reply time', hint: 'Off: families see no promise at all.', ctrl: sw(true) }),
            row({ label: 'We usually reply within', hint: 'Counted in service hours only', ctrl: num('4', 'working hours') }),
            row({ label: 'Service hours', hint: 'New Zealand time. Split overnight hours across two days.', sub: `<div class="st-days">${days}</div>${btn('Add hours', 'o', { xs: true, icon: 'plus' })}` }),
            row({ label: 'Closed dates', hint: 'Public holidays aren’t added for you. Add each day the service is closed.', sub: `<div class="st-chips">${xchip('Fri 25 Dec 2026')}${xchip('Mon 28 Dec 2026')}${xchip('Fri 1 Jan 2027')}${xchip('Mon 4 Jan 2027')}</div>${dpick('Add a closed date', '', { empty: true })}` }),
            row({ label: 'Extra words for families', hint: 'Shown under the reply time. It doesn’t change the time.', sub: input('If it’s urgent, call the on-call number below.', 'st-ta', 'width: 100%') }),
            row({ label: 'Families see', hint: '“We usually reply within 4 working hours (Monday to Friday, 8 am to 6 pm).”' }),
        ],
    }),
);
const s5 = sec('Family contact', 'Full page · real time and date pickers · urgent help · switching off the privacy check is marked before review',
    scene(settingsPage({ menu, header: header(3), t2: t2([['Overview', 'grid'], ['Contact and replies', 'home'], ['Family relationships', 'users']], 1), body: famBody, save: savebar({ count: 1, title: '1 change to review', loose: 'It loosens a safety check' }) }), { h: 1385 }));

// ---------- 6 · Notifications (In-app / Email / Push columns) ----------
const mxRow = (title, hint, [ia, em, pu], o = {}) => `<div class="oc-setrow${o.changed ? ' changed' : ''}"><div><b>${title}${o.badge || ''}</b><small>${hint}</small></div>${[ia, em, pu].map((c) => `<span>${c}</span>`).join('')}</div>`;
const lockOn = `<span class="st-lockd">${svg('lock', 13)}Always on</span>`;
const pushOff = sw(false, { dis: true });
const notifBody = [
    note('These are the starting choices for everyone. Each person can change their own in My notifications, except notices that are always on. <a class="st-go" href="#mine" style="display: inline-flex; margin: 0 0 0 4px">Open My notifications' + svg('arrowur', 13) + '</a>', '', 'info'),
    `<section class="oc-card st-grp st-mx"><div class="oc-card-h">${lens('bell')}<div><div class="oc-card-t st-gt">Chat notices</div><div class="oc-card-s">Notices never show client names or message text outside the app</div></div></div>
<div class="oc-setrow h"><span>Notice</span><span>${svg('bell', 13)}In-app</span><span>${svg('mail', 13)}Email</span><span>${svg('phonedev', 13)}Push ${badge('Not set up', 'neutral')}</span></div>
${mxRow('New messages', 'In conversations you’re in. Muted and already-read messages don’t send one.', [sw(true), sw(false), pushOff])}
${mxRow('Mentions', 'When someone @mentions you.', [sw(true), sw(true), pushOff])}
${mxRow('Urgent messages', 'Messages marked urgent, and read-and-confirm reminders.', [lockOn, sw(true), pushOff])}
${mxRow('Family work handed to you', 'When a family conversation becomes yours. No care details in the notice.', [sw(true), sw(true), pushOff])}
${mxRow('Missed calls', 'Calls you were invited to but didn’t answer.', [sw(true), sw(true, { foc: true }), pushOff], { changed: true, badge: changed() })}
${mxRow('Replies from the care team', 'For family members: replies in their own conversations.', [sw(true), sw(true), pushOff])}
</section>`,
    note('<b>Push can’t be switched on yet.</b> Your server needs the Oblivion push sender. Your IT provider sets it up; then each person allows push on their own browser. <a class="st-go" href="#delivery" style="display: inline-flex; margin: 0 0 0 4px">Review delivery and channels' + svg('arrowur', 13) + '</a>', 'warn', 'alert'),
].join('');
const s6 = sec('Notifications', 'Full page · In-app, Email and Push switch columns · push isn’t set up yet, and says why',
    scene(settingsPage({ menu, header: header(4, 'Whole organisation', 'Everyone'), t2: t2([['Overview', 'grid'], ['Chat notices', 'bell'], ['Delivery and channels', 'radio']], 1), body: notifBody, save: savebar({ count: 1, title: '1 change to review · Missed calls by email' }) }), { h: 1055 }));

const deliveryCrop = crop(t2([['Overview', 'grid'], ['Chat notices', 'bell'], ['Delivery and channels', 'radio']], 2) + gg(
    gcol(
        group({
            icon: 'bell', title: 'In the app', cap: 'Working',
            rows: [
                row({ label: 'Pop-up for new messages on other pages', hint: 'Shows who wrote and a way to open it. Never the message text.', ctrl: sw(true) }),
                row({ label: 'Unread count in the browser tab', ctrl: sw(true) }),
            ],
        }),
        group({
            icon: 'phonedev', title: 'Push', cap: 'Not set up',
            rows: [
                row({ label: 'Push notifications', badges: cant(), state: 'dis', hint: 'Your server needs the Oblivion push sender. Push says who wrote, never the text.', ctrl: sw(false, { dis: true }) }),
            ],
        }),
    ),
    gcol(
        group({
            icon: 'mail', title: 'Email', cap: 'Sent from your organisation’s email settings',
            rows: [
                row({ label: 'Chat notices by email', hint: 'For the notices switched on in the Email column.', ctrl: sw(true) }),
                row({ label: 'Daily summary of unread messages', hint: 'One email instead of many.', ctrl: sw(true), sub: `<div style="display: flex; gap: 10px; align-items: center">${tpick('5:00 pm')}${seg(['Every day', 'Weekdays only'], 'Weekdays only')}</div>` }),
            ],
        }),
        group({
            icon: 'moon', title: 'Off shift', cap: 'Uses the roster',
            rows: [
                row({ label: 'Hold messages until the next shift', hint: 'Staff off shift get one summary when their next shift starts.', ctrl: sw(true) }),
                row({ label: 'Urgent messages', hint: 'Still come straight away, on or off shift.', ctrl: lockOn }),
            ],
        }),
    ),
) + `<section class="oc-card st-grp" style="padding: 14px 16px; display: grid; gap: 10px"><div class="st-lc"><b>Check saved choices</b><span>A dry run with a made-up example. Nothing is sent.</span></div><div style="display: flex; gap: 10px">${sel('Mentions', '', 'width: 280px')}${btn('Check saved choices', 'o', { sm: true })}</div></section>`, {});
const s6b = sec('Notifications · delivery and channels', 'The separate delivery tab, with real controls',
    `<div class="st-cols" style="grid-template-columns: 924px 1fr">${deliveryCrop}${`<div class="ch-col">${cap('What to check')}<ul class="oc-sig-list"><li>Every channel has real switches; status words sit in the group caption.</li><li>Push can’t be switched on until the server has the push sender (C2.3).</li><li>Urgent messages ignore off-shift holding and are always on.</li><li>“Check saved choices” is Fleet’s dry run: nothing is sent.</li></ul></div>`}</div>`);

// ---------- 7 · Privacy and records (crop) ----------
const privacyCrop = crop(t2([['Overview', 'grid'], ['Records and access', 'shield'], ['Leavers', 'userx']], 1) + gg(
    group({
        icon: 'archive', title: 'Keeping records', cap: 'Nothing deletes itself',
        rows: [
            row({ label: 'Delete old messages automatically', badges: cant(), state: 'dis', hint: 'No retention period has been approved for Chat yet. Your privacy officer approves one first.', ctrl: sw(false, { dis: true }) }),
            row({ label: 'Keep the original of edited and removed messages', hint: 'Stays in the restricted history for investigations.', ctrl: lockOn }),
            row({ label: 'Calls', hint: 'Never recorded or transcribed. A call leaves a short record in the conversation.', ctrl: lockOn }),
        ],
    }),
    group({
        icon: 'shieldcheck', title: 'Investigations', cap: 'Private conversations open only for a recorded case',
        rows: [
            row({ label: 'People who can investigate', hint: '1 person: Mere Tūhoe. Nobody has it by default; it’s given in Roles.', ctrl: btn('Manage in Roles', 'o', { xs: true, after: 'arrowur' }) }),
            row({ label: 'A second person approves each case', hint: 'Off: investigators open access themselves and every step is logged. On: another investigator approves first.', ctrl: sw(false) }),
        ],
    }),
) + note('Managing these settings doesn’t let you read anyone’s private conversations.', '', 'lock'), {});
const s7 = sec('Privacy and records', 'Nothing deletes itself · second-person approval for investigators starts off (D11)',
    `<div class="st-cols" style="grid-template-columns: 924px 1fr">${privacyCrop}${`<div class="ch-col">${cap('What to check')}<ul class="oc-sig-list"><li>Automatic deletion stays off until a retention period is approved; nothing invents one.</li><li>Who can investigate is given in Roles, never here. This page only links there.</li><li>Turning second-person approval off again would loosen a safety check and save in red.</li><li>Managing settings never opens anyone’s conversations.</li></ul></div>`}</div>`);

// ---------- 8 · Saving: review, loosening, failure, conflict, guards ----------
const affects = (t) => `<div class="st-aff">${svg('users', 13)}<span>${t}</span></div>`;
const rcard = (icon, title, rows, aff) => `<section class="oc-rvc"><div class="oc-rvc-h">${svg(icon, 15)}${title}</div>${rows}${aff ? affects(aff) : ''}</section>`;
const recorded = `<p class="oc-hint" style="margin: 0; display: flex; gap: 6px; align-items: center">${svg('history', 13)}Saved changes are recorded in Change history with your name and the time.</p>`;
const reviewOk = dlg({
    size: 'md', icon: 'layers', title: 'Review Chat changes', sub: '2 changes · nothing applies until you save',
    body: rcard('building', 'Rollout', rvr('Tōtara service · Chat', chg('Off', 'On')), 'Tōtara service’s 11 staff and 4 families. The Messages page sends them to Chat, with their history.') +
        rcard('pencil', 'Messages and files · Kōwhai House', rvr('Delete for everyone for up to', chg('1 hour (organisation)', '30 minutes (Kōwhai House only)')), 'Kōwhai House’s conversations: 18 staff, 6 families. Tōtara service and private staff chats keep 1 hour.') + recorded,
    foot: btn('Keep editing', 'o') + btn('Save changes', 'p'),
});
const reviewLoose = dlg({
    size: 'md', icon: 'alert', tone: 'warn', title: 'Review Chat changes', sub: '1 change · nothing applies until you save',
    body: rcard('home', 'Family contact', rvr('Check messages before family see them', chg('On', 'Off', loosens())), 'Every family conversation at Kōwhai House and Tōtara service: 10 families, 29 staff.') +
        callout('<b>This change loosens a safety check.</b> Staff won’t be warned before sending a family a message that names another person supported, a photo without consent, or health details. Save only if that’s intended.', 'warn', 'alert') + recorded,
    foot: btn('Keep editing', 'o') + btn('Save changes', 'x'),
});
const reviewFail = dlg({
    size: 'md', icon: 'layers', title: 'Review Chat changes', sub: '2 changes · nothing applies until you save',
    body: note('<b>Couldn’t save — nothing was changed.</b> The connection dropped. Your changes are kept; try again.', 'crit', 'alert') +
        rcard('building', 'Rollout', rvr('Tōtara service · Chat', chg('Off', 'On'))) + rcard('pencil', 'Messages and files · Kōwhai House', rvr('Delete for everyone for up to', chg('1 hour (organisation)', '30 minutes (Kōwhai House only)'))),
    foot: btn('Keep editing', 'o') + btn('Try again', 'p', { icon: 'refresh' }),
});
const conflict = dlg({
    size: 'md', icon: 'refresh', tone: 'warn', title: 'Settings changed elsewhere', sub: 'Someone saved Chat settings after you opened this page',
    body: note('<b>Rohan Patel saved Chat settings at 10:49 am.</b> Your changes are kept. Refresh to take his, then review and save yours again.', 'warn', 'refresh') +
        `<section class="oc-rvc st-tblc"><table class="oc-table"><thead><tr><th>Setting</th><th>Rohan saved</th><th>Your draft</th></tr></thead><tbody>
<tr><td><b>Kōwhai House · Voice messages</b></td><td>Off</td><td class="oc-muted">Not changed — takes Rohan’s</td></tr>
<tr><td><b>Tōtara service · Chat</b></td><td>Off</td><td><b>On</b> — yours is kept</td></tr>
<tr><td><b>Kōwhai House · Delete for everyone for up to</b></td><td>1 hour</td><td><b>30 minutes</b> — yours is kept</td></tr>
</tbody></table></section>`,
    foot: btn('Keep editing', 'o') + btn('Refresh and review', 'p'),
});
const leave = dlg({
    size: 'sm', icon: 'alert', tone: 'warn', title: 'Leave with unsaved changes?', sub: 'Your saved settings stay as they are',
    body: '<p class="oc-help" style="font-size: 13px">You have 2 changes you haven’t saved. This browser keeps them as a draft where it can.</p>',
    foot: btn('Keep editing', 'o') + btn('Leave page', 'p'),
});
const scopeGuard = dlg({
    size: 'sm', icon: 'building', title: 'Switch to Tōtara service?', sub: 'You have 1 unsaved change at Kōwhai House',
    body: '<p class="oc-help" style="font-size: 13px">Review and save it first, or discard it and switch.</p>',
    foot: btn('Stay here', 'o') + btn('Discard and switch', 'o') + btn('Review changes', 'p'),
});
const discard = dlg({
    size: 'sm', icon: 'undo', title: 'Discard Chat changes?', sub: 'Your saved settings are kept',
    body: '<p class="oc-help" style="font-size: 13px">The 2 changes you haven’t saved will be removed.</p>',
    foot: btn('Keep editing', 'o') + btn('Discard changes', 'p'),
});
const bars = ground([
    col('Clean', savebar({})),
    col('Unsaved', savebar({ count: 3, title: '3 changes to review · General, Messages and files' })),
    col('Unsaved, one loosens a safety check', savebar({ count: 2, title: '2 changes to review', loose: '1 loosens a safety check' })),
    col('Saving', savebar({ count: 2, title: 'Saving 2 changes…', sub: 'Please keep this page open.', actions: btn('Discard changes', 'o', { sm: true, dis: true }) + btn('Saving…', 'p', { sm: true, dis: true }) })),
    col('Saved (the page notice, focus moves here)', note('<b>Chat settings saved.</b> Call changes apply to new calls; calls already going can finish.', 'ok', 'checkc')),
    col('A draft found on this browser', note(`<b>You have unsaved Chat changes from Thursday on this browser.</b><div style="display: flex; gap: 8px">${btn('Resume draft', 'o', { sm: true })}${btn('Discard browser draft', 'g', { sm: true })}</div>`, 'tint', 'history')),
].join(''));
const s8 = sec('Saving', 'Review changes (720): before → after and who it affects · loosening a safety check saves in red · nothing is lost',
    `<div class="c3-r2">${col('Review changes · before → after, who it affects', reviewOk)}${col('A change that loosens a safety check · destructive save', reviewLoose)}${col('Save failed · the draft is kept', reviewFail)}${col('Someone else changed it first', conflict)}</div>
<div class="st-cols" style="grid-template-columns: 900px 500px">${col('Save bar states (pinned to the bottom of the page)', bars)}<div class="ch-col">${col('Unsaved-draft guard · leaving the page', leave)}${col('Unsaved-draft guard · switching Site', scopeGuard)}${col('Discard', discard)}</div></div>`);

// ---------- 9 · My Chat preferences ----------
const prefHeader = sky({
    markAv: 'DA', title: 'My Chat preferences', sub: 'Only you see these · they work inside your organisation’s Chat settings',
    actions: gbtn('Open Chat', 'chat', 'arrowur'), tabs: [], find: false,
});
const prefBody = gg(
    gcol(
        group({
            icon: 'aa', title: 'Reading and writing', cap: 'Changes show in the preview straight away',
            rows: [
                row({ label: 'Text size', badges: changed(), state: 'changed', sub: seg(['Normal', 'Large', 'Extra large'], 'Large') }),
                row({ label: 'Message spacing', sub: seg(['Comfortable', 'Compact'], 'Comfortable') }),
                row({ label: 'Enter key', sub: seg(['Sends the message', 'Adds a new line'], 'Sends the message') }),
                row({ label: 'Light and dark mode', hint: 'Set once for the whole app.', ctrl: link('Appearance') }),
            ],
        }),
        group({
            icon: 'phone', title: 'Calls', cap: 'Calls start in one click',
            rows: [
                row({ label: 'Ring on this computer', ctrl: sw(true) }),
                row({ label: 'Microphone and speakers', hint: 'Run this if people can’t hear you.', ctrl: btn('Check my microphone', 'o', { xs: true, icon: 'mic' }) }),
            ],
        }),
    ),
    gcol(
        group({
            icon: 'bell', title: 'Alerts and sounds', cap: 'Urgent messages always come through',
            rows: [
                row({ label: 'Quiet hours', badges: changed(), state: 'changed', hint: 'Non-urgent Chat alerts wait until quiet hours end.', ctrl: sw(true), sub: `<div style="display: flex; gap: 10px; align-items: center">${tpick('10:00 pm')}<span class="oc-muted">to</span>${tpick('7:00 am')}</div>` }),
                row({ label: 'Message and call sounds', ctrl: sw(true) }),
                row({ label: 'Show message text in notices inside the app', hint: 'Outside the app, notices never show names or message text.', ctrl: sw(false) }),
                row({ label: 'Which notices, and by email', hint: 'In-app, email and push for each Chat notice.', ctrl: link('My notifications') }),
            ],
        }),
        `<section class="oc-card st-grp"><div class="oc-card-h">${lens('eye')}<div><div class="oc-card-t st-gt">Preview</div><div class="oc-card-s">Large text · comfortable spacing</div></div></div>
<div class="c3-msgs top" style="padding: 4px 18px 16px; justify-content: flex-start">
<article class="c3-g" aria-label="Messages from Rohan Patel" style="max-width: 100%">${av('rohan', 's32')}<div class="c3-gb"><div class="c3-gh"><b>Rohan Patel</b><span>10:42 am</span></div><div class="c3-bw"><div class="c3-b s" style="font-size: 17px">Handover’s ready when you are.</div></div></div></article>
<article class="c3-g own" aria-label="Messages from you"><div class="c3-gb"><div class="c3-bw"><div class="c3-b s" style="font-size: 17px">Thanks, I’ll read it now.</div></div><div class="c3-mt"><span>10:43 am</span><span class="sp"></span><span class="c3-dr read" aria-hidden="true"></span><span class="read">Read</span></div></div></article>
</div></section>`,
    ),
);
const s9 = sec('My Chat preferences', 'Full page · a personal page in Settings › General · no theme switch: that stays in Appearance (C7.8)',
    scene(settingsPage({ menu: settingsMenu('My Chat preferences'), header: prefHeader, body: prefBody, save: savebar({ count: 2, title: '2 changes · Text size, Quiet hours', sub: 'The preview shows them now. They apply everywhere when you save.', actions: btn('Discard changes', 'o', { sm: true }) + btn('Save preferences', 'p', { sm: true }) }) }), { crumbsT: ['Home', 'Settings', 'My Chat preferences'], h: 940 }));

const len = writeBoard('ChatV3-Settings.html', 'Chat v3 — settings', 'Chat v3 · settings',
    'Settings › Chat on the Fleet settings pattern (C7.8): an overview first, titled groups of switches, one save bar, a Site’s own settings, and a plain reason when something can’t be switched on yet.',
    [s1, s2, s3, sec('Messages · reactions and links', 'The other Messages sub-tab · quick six (C3.6), stickers (D6), link previews on (D7)', `<div class="st-cols" style="grid-template-columns: 924px 1fr">${reactCrop}${`<div class="ch-col">${cap('What to check')}<ul class="oc-sig-list"><li>The quick six are emoji in the content font, never icons.</li><li>Organisation stickers and GIFs come from a library you upload; there is no outside GIF service (D6).</li><li>Link previews are on by default (D7); the hint says the server fetches them, not the reader’s browser.</li></ul></div>`}</div>`), s4, s5, s6, s6b, s7, s8, s9]);
console.log('ChatV3-Settings.html', len);
