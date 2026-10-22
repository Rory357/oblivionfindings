import * as C from './c3lib.mjs';
const { svg, av, gl, btn, ib, lens, badge, e, col, sec, note, mi, ms, mc, menu, scene, writeBoard, nm } = C;

// ---------- 1 · the Calls page inside Chat ----------
const ci = ({ who, icon, name, kind, ic, cls = '', time, on = false }) => `<div class="c3-ci${on ? ' on' : ''}">${who ? av(who, '', '') : gl(icon)}<div style="min-width: 0"><b>${name}</b><small class="${cls}">${svg(ic, 13)}<span class="tx">${kind}</span></small></div><div class="r">${on ? ib('phone', 'Voice call ' + name, '', 16) + ib('video', 'Video call ' + name, '', 17) : `<time>${time}</time>`}</div></div>`;
const callsPane = `<aside class="c3-calls" aria-label="Calls">
<div class="hdr"><h1>Calls</h1>${ib('link', 'New call link', '', 17)}${btn('Start call', 'p', { sm: true, icon: 'phone' })}</div>
<div class="ch-find" style="margin: 0 12px 10px">${svg('search', 15)}Search people and groups</div>
<div class="c3-sh" style="padding: 2px 18px 6px">Favourites</div>
<div class="c3-favs">${[['alex', 'on', 'Alex'], ['rohan', 'on', 'Rohan'], ['hana', 'leave', 'Hana'], ['kiri', '', 'Kiri']].map(([w, s, t]) => `<span class="c3-fav">${av(w, 's40', s)}${t}</span>`).join('')}<span class="c3-fav add">${gl('plus', 's40')}Add</span></div>
<div class="c3-sh" style="padding: 12px 18px 4px">Recent</div>
<div class="c3-recent">
${ci({ who: 'kiri', name: 'Kiri Wineera · About Tane', kind: 'Missed voice call', ic: 'phonemiss', cls: 'miss', time: '9:58', on: true })}
${ci({ who: 'alex', name: 'Alex Morgan', kind: 'Outgoing video · 12 min', ic: 'phoneout', time: '8:41' })}
${ci({ icon: 'users', name: 'Kōwhai afternoon team', kind: 'Group video · 4 people · 25 min', ic: 'video', time: 'Yesterday' })}
${ci({ who: 'rohan', name: 'Rohan Patel', kind: 'Incoming voice · 3 min', ic: 'phonein', time: 'Yesterday' })}
${ci({ icon: 'heart', name: 'Tane’s care team', kind: 'Answered by Ana Rēweti · 6 min', ic: 'phonein', time: 'Sun' })}
${ci({ who: 'jo', name: 'Jo Taylor · About Sam', kind: 'Missed voice call · called back', ic: 'phonemiss', time: 'Sat' })}
</div>
</aside>`;
const detail = `<section class="c3-th" aria-label="Call details" style="padding: 28px 32px; gap: 22px; overflow: hidden">
<div style="display: flex; gap: 18px; align-items: center">${av('kiri', 's64', '')}<div><h2 style="margin: 0; font-size: 22px; font-weight: 700; letter-spacing: -0.02em">Kiri Wineera</h2><p style="margin: 2px 0 8px; color: var(--mfg)">Whānau · Tane Wineera · Kōwhai House</p><div style="display: flex; gap: 8px">${badge('Missed voice call · 9:58 am', 'crit')}${badge('Reply due by 3:00 pm', 'warn')}</div></div>
<div style="margin-left: auto; display: flex; gap: 10px">${btn('Message', 'o', { icon: 'msg' })}${btn('Video', 'o', { icon: 'video' })}${btn('Call back', 'p', { icon: 'phone' })}</div></div>
<div style="display: grid; grid-template-columns: minmax(0, 1fr) 340px; gap: 20px; align-items: start">
<div class="ch-cardp"><h3 style="margin: 0; font-size: 15px">Calls with Kiri</h3>
<div class="oc-path">${[['crit', 'Missed voice call', 'Rang Tane’s care team · nobody was free', 'Today 9:58 am'], ['lit', 'Voice call · 8 min', 'Answered by Mere Thompson · call record added to Tane’s notes', 'Yesterday 5:12 pm'], ['lit', 'Video call · 15 min', 'Kiri, Tane and Alex Morgan', 'Friday 4:30 pm'], ['end', 'Callback requested', 'Done by Ana Rēweti', 'Wednesday']].map(([c, b, s, t]) => `<div class="oc-path-i ${c}"><span class="k" aria-hidden="true"></span><div style="display: flex; gap: 10px; justify-content: space-between; align-items: baseline"><div><b>${b}</b><small>${s}</small></div><span class="t">${t}</span></div></div>`).join('')}</div></div>
<div style="display: grid; gap: 14px">
<div class="ch-cardp" style="gap: 10px"><h3 style="margin: 0; font-size: 15px">Call link for a staff huddle</h3><p style="margin: 0; font-size: 12.5px; color: var(--mfg)">Anyone signed in at Kōwhai Care Trust can join. Family can’t use call links.</p><div class="oc-input" style="font-size: 12.5px">oblivioncare.nz/call/k7p-2mx${svg('copy', 15)}</div>${btn('Copy link', 'o', { sm: true, icon: 'copy' })}</div>
${note('Calls are never recorded. After a family call you can add a short call record to the person’s notes.', '', 'lock')}
</div></div>
<div class="ch-cardp" style="gap: 12px"><div style="display: flex; align-items: center; gap: 12px">${gl('heart', 's40')}<div><h3 style="margin: 0; font-size: 15px">When Kiri calls Tane’s care team</h3><p style="margin: 2px 0 0; font-size: 12.5px; color: var(--mfg)">Everyone on shift at Kōwhai House rings at once; the first to answer takes the call.</p></div></div>
<div style="display: flex; align-items: center; gap: 14px; flex-wrap: wrap">${['On shift now', 'then the house lead', 'then on call'].map((t, i) => `<span style="display: inline-flex; align-items: center; gap: 8px; font-size: 12.5px; font-weight: 600">${i === 0 ? `<span class="c3-stk3">${av('mere', 's28', '')}${av('alex', 's28', '')}${av('ana', 's28', '')}</span>` : i === 1 ? av('rohan', 's28', 'on') : av('alex', 's28', '')}${t}</span>${i < 2 ? svg('chevr', 14) : ''}`).join('')}</div>
<div class="oc-hint">Off-shift staff never ring. Missed calls go to the family inbox with a reply time.</div></div>
</section>`;
const hub = scene(`<div class="c3-ws">${callsPane}${detail}</div>`);

// ---------- 2 · ringing ----------
const incoming = ({ kind, name, role, aud, avHtml, video = false, decline = '' }) => `<section class="ch-call" role="region" aria-label="Incoming ${kind} from ${name}">
<div class="lbl">${svg(video ? 'video' : 'phonein', 15)}${kind}${ib('volume', 'Silence ringtone', '', 16)}</div>
<div class="who">${avHtml}<div><h3>${name}</h3><p>${role}</p></div></div>
<div class="ch-callctx">${svg('lock', 14)}${aud}</div>
${video ? `<div class="c3-vid cam" style="height: 132px; border-radius: 12px"><span class="person" aria-hidden="true"></span><span class="nm">${svg('video', 12)}Your camera · off until you answer</span></div>` : ''}
<div class="two" style="grid-template-columns: 1fr 1fr">${btn('Decline', 'x', { icon: 'phoneoff', after: 'chev' })}${btn('Answer', 'p', { icon: video ? 'video' : 'phone', aria: video ? 'Answer with video' : 'Answer' })}</div>
${decline}
<div class="ch-keep">${svg('pencil', 12)}Your work stays open</div>
</section>`;
const v1 = incoming({ kind: 'Voice call', name: 'Alex Morgan', role: 'Support worker · Kōwhai House', aud: 'Just you and Alex', avHtml: av('alex', 's52', 'ringing') });
const decl = menu([mc('Decline with a message'), mi('msg', 'Can’t talk now, I’ll call you back'), mi('msg', 'I’m with someone — message me'), mi('clock', 'Call me in 10 minutes'), ms(), mi('pencil', 'Write a message…')], 'w300', 'margin-top: -4px', 'Decline with a message');
const v2 = incoming({ kind: 'Video call', name: 'Kōwhai afternoon team', role: 'Rohan Patel is calling · 3 people in the call', aud: 'Members of this group', avHtml: gl('users', 's40').replace('c3-gl s40', 'c3-gl s40" style="width: 52px; height: 52px'), video: true });
const ringing = `<div class="ch-cardp" style="justify-items: center; text-align: center; gap: 12px; padding: 26px 20px">${av('rohan', 's84', 'ringing')}<div><h3 style="margin: 8px 0 2px; font-size: 18px">Calling Rohan Patel…</h3><p style="margin: 0; font-size: 13px; color: var(--mfg)">Site lead · Kōwhai House</p></div>${badge('Ringing · you can hear it ring', 'live')}<div class="ch-ctrls" style="margin-top: 6px"><span class="ch-ctrl"><button class="c" type="button" aria-label="Mute">${svg('mic', 19)}</button>Mute</span><span class="ch-ctrl"><button class="c" type="button" aria-label="Turn camera on">${svg('videooff', 19)}</button>Camera</span><span class="ch-ctrl"><button class="c end" type="button" aria-label="Cancel call">${svg('phoneoff', 19)}</button>Cancel</span></div></div>`;
const ended = `<div class="ch-cardp" style="gap: 12px">${`<div style="display: flex; gap: 12px; align-items: center">${av('kiri', 's40', '')}<div><b style="font-size: 14px">Call ended · 12 min</b><small style="display: block; color: var(--mfg); font-size: 12.5px">Kiri Wineera · About Tane · 10:14 am</small></div></div>`}
<p style="margin: 0; font-size: 13px">Add a short note so the next shift knows what was agreed.</p>
<div style="display: flex; gap: 8px">${btn('Add a call record to Tane’s notes', 'p', { sm: true, icon: 'clipboard' })}${btn('Not needed', 'o', { sm: true })}</div>
${note('Only you and staff who can see Tane’s notes will see the record. Kiri sees “Call with the care team · 12 min”.', '', 'lock')}</div>`;

// ---------- 3 · video call ----------
const tile = (cls, name, o = {}) => `<div class="c3-vid ${cls}${o.speak ? ' speak' : ''}">${o.av ? `<span>${av(o.av, 's84', '')}</span>` : '<span class="person" aria-hidden="true"></span>'}${o.hand ? `<span class="hand">${svg('hand', 13)}Raised hand</span>` : ''}<span class="nm${o.mute ? ' mute' : ''}">${o.mute ? svg('micoff', 12) : svg('mic', 12)}${name}</span></div>`;
const ctl = (icon, label, cls = '') => `<span class="c3-ctrl"><button class="c${cls ? ' ' + cls : ''}" type="button" aria-label="${label}">${svg(icon, 19)}</button>${label}</span>`;
const ctrls = `<div class="c3-ctrls">${ctl('mic', 'Mute')}${ctl('video', 'Camera')}${ctl('share', 'Share screen')}<span class="c3-ctrl"><span class="vr"></span></span>${ctl('users', 'People')}${ctl('msg', 'Chat')}${ctl('hand', 'Raise hand')}${ctl('headphones', 'Devices')}<span class="c3-ctrl"><span class="vr"></span></span>${ctl('phoneoff', 'Leave', 'end')}</div>`;
const callTop = (b, s, extra = '') => `<div class="top">${gl('users', 's32')}<div><b>${b}</b><small style="display: block">${s}</small></div><span class="r">${extra}<span class="oc-badge live" style="background: color-mix(in oklch, var(--live) 18%, transparent); color: var(--ok-chrome)">Connected · 12:08</span>${ib('minimise', 'Keep working', '', 17).replace('ch-ib', 'ch-ib" style="color: var(--chrome-fg)')}</span></div>`;
const vgrid = `<div class="ch-frame" style="height: 600px"><div class="c3-callw">${callTop('Kōwhai afternoon team', 'Video call · 4 of 4 people')}
<div class="c3-grid4">${tile('cam', 'Mere Thompson (you)')}${tile('cam2', 'Alex Morgan', { speak: true })}${tile('', 'Rohan Patel', { av: 'rohan', mute: true })}${tile('cam3', 'Hana Wilson', { hand: true })}</div>
${ctrls}</div></div>`;
const share = `<div class="ch-frame" style="height: 560px"><div class="c3-callw">${callTop('Kōwhai afternoon team', 'Rohan Patel is sharing their screen', `<span style="font-size: 12px; color: var(--chrome-fg)">${svg('share', 13)} Sharing</span>`)}
<div class="c3-share"><div class="c3-screen"><div class="bar"><i></i><i></i><i></i>Rostering · Kōwhai House · week of 19 October</div><div class="bd"><b>Next week’s roster</b>${['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'].map((d) => `<span>${d}</span>`).join('')}${['Mere 7–3', 'Alex 7–3', 'Ana 7–3', 'Mere 7–3', 'Hana 7–3', 'Open', 'Alex 7–3', 'Alex 3–11', 'Hana 3–11', 'Rohan 3–11', 'Ana 3–11', 'Alex 3–11', 'Open', 'Hana 3–11'].map((t) => `<span class="${t === 'Open' ? 'g' : t.startsWith('Mere') ? 'a' : ''}">${t}</span>`).join('')}</div><span class="lbl">${svg('share', 13)}Rohan’s screen</span></div>
<div class="side">${tile('cam', 'Mere (you)')}${tile('', 'Rohan Patel', { av: 'rohan', speak: true })}${tile('cam3', 'Hana Wilson', { mute: true })}</div></div>
${ctrls}</div></div>`;
const docks = `<div style="display: grid; gap: 16px; justify-items: start">
<div class="c3-dockv"><div class="c3-vid cam2" style="height: 168px"><span class="person" aria-hidden="true"></span><span class="nm">${svg('mic', 12)}Alex Morgan</span><span class="c3-vid cam pip" aria-label="Your camera"></span></div><div class="row"><div><b>Alex Morgan</b><small><span class="ch-livedot" aria-hidden="true"></span>06:12 · Video call</small></div><span class="r">${ib('expand', 'Back to the call', 'b', 16)}${btn('Mute', 'o', { xs: true, icon: 'mic' })}${btn('Leave', 'o', { xs: true, icon: 'phoneoff', cls: 'ch-dec' })}</span></div></div>
<section class="ch-ongo" aria-label="Current call with Kiri Wineera"><div class="r1">${av('kiri', 's40', 'on')}<div><b>Kiri Wineera · About Tane</b><small><span class="ch-livedot" aria-hidden="true"></span>03:40 · Voice call</small></div>${ib('expand', 'Expand current call', 'b', 16)}</div><div class="r2">${btn('Mute', 'o', { sm: true, icon: 'mic' })}${btn('Message', 'o', { sm: true, icon: 'msg' })}${btn('End', 'o', { sm: true, icon: 'phoneoff', cls: 'ch-dec' })}</div></section>
${note('The call follows you round Oblivion in this corner, so you can look things up while you talk. Alt + Shift + C brings it back.', '', 'info')}
</div>`;

const scope = `<div class="c3-note-row" style="grid-template-columns: repeat(3, minmax(0, 1fr))">
<div class="c3-meas"><b>What calls can do</b><p>Voice calls for up to 8 people and video for up to 4, screen sharing, call links for staff huddles, ringing a whole care team, and a call record afterwards.</p></div>
<div class="c3-meas"><b>What they never do</b><p>No recordings or transcripts. Family can’t start call links or see who is online. Your camera stays off until you choose to turn it on.</p></div>
<div class="c3-meas"><b>When you can’t be reached</b><p>Missed calls show in Calls and in your notifications. Off shift, calls go to the care team instead of you.</p></div>
</div>`;

const sections = [
    sec('Calls page', 'Full page at 1440 × 1000 · favourites, recent calls with rings, call back, call links', `<div class="c3-stage">${hub}</div>`),
    sec('Ringing and after the call', 'Answer, decline or decline with a message; calls you make ring back; a short call record afterwards', `<div class="c3-r3">${C.col('Incoming voice call', v1)}${C.col('Incoming video call · decline with a message', `<div style="display: grid; gap: 8px">${v2}${decl}</div>`)}${C.col('Calling · and when it ends', `<div style="display: grid; gap: 20px">${ringing}${ended}</div>`)}</div>`),
    sec('In a call', 'Video grid, screen sharing and a call dock that stays with you while you work', `<div class="c3-r2w">${C.col('Video call · 4 people, one speaking, one hand raised', vgrid)}${C.col('Keep working · video and voice docks', docks)}</div><div class="c3-r2w">${C.col('Screen sharing', share)}${C.col('Scope', scope.replace('repeat(3, minmax(0, 1fr))', '1fr'))}</div>`),
];
export const size = writeBoard('ChatV3-Calls.html', 'Chat v3 — calls', 'Chat v3 · calls', 'Voice and video calls, screen sharing, the Calls page, ringing, call docks and call records.', sections);
console.log('ChatV3-Calls.html', size);
