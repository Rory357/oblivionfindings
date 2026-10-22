import * as C from './c3lib.mjs';
const { svg, av, gl, btn, ib, lens, badge, e, col, sec, note, group, ownMeta, day, unreadLine, composer, crow, csh, threadHead, thread, rc, rx, dr, voice, familyLock, writeBoard } = C;

// family portal shell (same identity as ChatFamily.html: Journey lock-up, lilac top, portal nav)
const navItems = [['home', 'Home'], ['activity', 'Timeline'], ['cal', 'Calendar &amp; visits'], ['clipboard', 'Notes &amp; to-dos'], ['msg', 'Chat'], ['heart', 'Health &amp; care'], ['mappin', 'Location'], ['file', 'Documents'], ['image', 'Photos']];
const portal = (on, body, h, count = 1) => `<div class="oc t-d l jf cf ch-frame pp-board b-journey lg-journey" style="height: ${h}px">
<header class="cf-top">
${familyLock()}
<span class="cf-pill">Family portal</span>
<span class="sp"></span>
<button class="cf-who" type="button" aria-label="Switch person: Tane Wineera">${av('tane', 's32', '')}Tane Wineera <small>Kōwhai House</small>${svg('chev', 14)}</button>
<button class="cf-ib" type="button" aria-label="Chat, ${count} unread">${svg('msg', 19)}<span class="ch-n">${count}</span></button>
<button class="cf-ib" type="button" aria-label="Notifications">${svg('bell', 19)}</button>
<span class="ch-av s40" style="background: #8a6d2f" aria-label="Your account: Kiri Wineera">KW</span>
</header>
<nav class="cf-nav" aria-label="Family portal">${navItems.map(([i, t]) => `<a${t === on ? ' class="on" aria-current="page"' : ''} href="#${i}">${svg(i, 16)}${t}${t === 'Chat' ? ` <span class="oc-count2 ${on === 'Chat' ? 'act' : 'ina'}">${count}</span>` : ''}</a>`).join('')}<a class="r" href="#urgent">${svg('lifebuoy', 16)}Urgent contact</a></nav>
<main class="cf-body">${body}</main>
</div>`;

const famRail = `<aside class="c3-rail" aria-label="Conversations">
<div class="c3-rail-h"><h1>Chat</h1>${ib('pencil', 'New conversation', 'b', 16)}</div>
<div class="ch-about"><span>About</span><button class="ch-sel" type="button" aria-haspopup="listbox" aria-label="About: Tane Wineera">${av('tane', 's28')}Tane Wineera${svg('chev', 15, 'chev')}</button></div>
<div class="ch-filt" role="tablist" aria-label="Show"><button type="button" class="on" aria-selected="true">All</button><button type="button">Unread<span class="n">1</span></button><button type="button">Favourites</button></div>
<div class="c3-list">${[
    crow({ icon: 'heart', name: 'Tane’s care team', time: '11:40', prev: 'Mere: The photo’s in Tane’s album too', unread: 1, on: true }),
    crow({ who: 'mere', name: 'Mere Thompson', tag: 'Key worker', time: 'Mon', prev: 'You: Thanks Mere, see you Saturday' }),
    crow({ icon: 'users', name: 'Tane’s whānau', tag: 'Family', time: 'Sun', prev: 'Aunty June: Kia ora koutou' }),
].join('')}</div>
<nav class="c3-rail-f" aria-label="Chat shortcuts"><a href="#calls">${svg('phone', 15)}Calls</a><a href="#saved">${svg('bookmark', 15)}Saved</a></nav>
</aside>`;

const care = `<div class="c3-care" aria-label="Who is caring for Tane today"><span class="h">${svg('sun', 13)}Caring for Tane today</span>
<div class="ppl"><div class="p">${av('mere', 's40', 'on')}<div><b>Mere Thompson</b><small>On shift until 3:00 pm</small></div></div><div class="p">${av('alex', 's40', '')}<div><b>Alex Morgan</b><small>From 3:00 pm</small></div></div></div>
<div class="c3-prom"><span class="c3-ring" style="--p: 38%" aria-hidden="true"></span><div><b>We usually reply within 2 hours</b><small>Mere will reply by 3:00 pm</small></div></div></div>`;

const req = (icon, b, s, ft = '') => `<div class="c3-req">${lens(icon, '', 15)}<b>${b}</b><small>${s}</small>${ft ? `<div class="ft">${ft}</div>` : ''}</div>`;
const famMsgs = [
    day('Today · Monday 19 October'),
    group({ who: 'mere', time: '10:05 am', role: 'Key worker', bubbles: ['Kia ora Kiri — Tane made his own lunch at cooking group today!', { raw: `<div class="c3-ph one p2" role="img" aria-label="Tane’s lunch" style="width: 220px"></div>` }], after: `<span class="c3-saved">${svg('check', 12)}Saved to Tane’s photos</span>${rx(rc('❤️', 2, true, 'You, Aunty June'), rc('🙌', 1))}` }),
    group({ who: 'kiri', own: true, bubbles: [{ html: voice('0:21', 0) }, 'Can we visit on Saturday afternoon? His nana is coming up from Taupō.'], after: req('cal', 'Visit request · Saturday 2:00–4:00 pm', 'Waiting for Kōwhai House to confirm'), meta: ownMeta('11:10 am', 'read', '').replace('<span class="read">Read</span>', '<span class="read">Seen by care team</span>') }),
    group({ who: 'mere', time: '11:40 am', role: 'Key worker', bubbles: [`Saturday works ${e('😊')} I’ll pop it on Tane’s calendar.`], after: req('phone', 'Callback · today after 2:00 pm', 'Mere will call you', `${btn('Change time', 'o', { xs: true })}`) }),
].join('');
const famThread = thread({
    head: threadHead({ icon: 'heart', name: 'Tane’s care team', sub: 'Kōwhai House · replies within 2 hours, 8 am–8 pm', tabs: ['Chat', 'Photos', 'Files'], on: 'Chat' }),
    bar: care,
    msgs: famMsgs,
    comp: composer({ ph: 'Message Tane’s care team…', aud: 'You and Tane’s care team' }),
});
const page = portal('Chat', `<div class="c3-ws" style="height: 100%">${famRail}${famThread}</div>`, 1100);

// smaller frames in the family skin
const fam = (inner, h = '') => `<div class="oc t-d l jf pp-board" style="background: transparent${h ? '; height: ' + h + 'px' : ''}">${inner}</div>`;
const EMO = (s) => Array.from(new Intl.Segmenter('en', { granularity: 'grapheme' }).segment(s), (x) => x.segment).filter((x) => x.trim());
const picker = `<div class="c3-pop c3-ep" role="dialog" aria-label="Emoji"><div class="c3-seg" role="tablist"><span class="on">${svg('smile', 14)}Emoji</span><span>${svg('sticker', 14)}Stickers</span></div>
<div class="ch-find">${svg('search', 15)}Search emoji</div>
<div class="c3-ep-cap">Recent</div><div class="c3-ep-grid">${EMO('❤️🙏😊👍🥰🙌🌸☀️').map((c) => `<span>${e(c)}</span>`).join('')}</div>
<div class="c3-ep-cap">Kia ora</div><div class="c3-stks" style="grid-template-columns: repeat(4, 1fr); gap: 10px; padding-bottom: 12px">${['Kia ora', 'Ngā mihi', 'Kia kaha', 'Arohanui'].map((t) => `<div class="c3-stk" style="font-size: 12px">${t}<i></i></div>`).join('')}</div></div>`;
const f1 = col('Family get the same emoji, stickers and reactions', fam(`<div style="display: grid; gap: 8px"><div style="display: flex; justify-content: flex-end">${picker}</div><div class="ch-mini">${composer({ text: 'Thank you so much ', focus: true, hint: false })}</div></div>`));
const seen = fam(`<div class="ch-mini" style="padding: 14px 16px; display: grid; gap: 12px">
<span class="ch-cap">What family see</span>
<div class="c3-mt" style="font-size: 12px; gap: 14px; flex-wrap: wrap">${[['sending', 'Sending'], ['sent', 'Sent'], ['dlv', 'Delivered'], ['read', 'Seen by care team']].map(([s, t]) => `<span style="display: inline-flex; gap: 6px; align-items: center">${dr(s, t)}</span>`).join('')}</div>
${note('Family never see which worker read a message, when someone was last online, or staff-only notes. They see who is caring for their person today and when to expect a reply.', 'tint', 'shield')}
<span class="ch-cap">Who can read this conversation</span>
<div style="display: flex; gap: 10px; align-items: center">${av('kiri', 's32', '')}<span style="font-size: 13px"><b style="font-weight: 650">You</b> and <b style="font-weight: 650">Tane’s care team</b> · other relatives only if you invite them</span></div>
</div>`);
const f2 = col('Seen by care team · never “last online”', seen);
const urgent = fam(`<div class="ch-mini" style="padding: 16px; display: grid; gap: 12px">
<div style="display: flex; gap: 12px; align-items: center">${lens('lifebuoy', 'crit s44', 19)}<div><b style="font-size: 15px">Need urgent help?</b><small class="oc-muted" style="display: block; font-size: 12.5px">Chat isn’t watched every minute</small></div></div>
<div class="ch-note crit">${svg('alert', 15)}<div>If someone is in danger, call <b>111</b>.</div></div>
<div class="c3-req" style="width: 100%">${lens('phone', '', 15)}<b>Kōwhai House duty phone</b><small>Answered 24 hours · 04 555 0123</small><div class="ft">${btn('Copy number', 'o', { xs: true, icon: 'copy' })}</div></div>
${note('Your administrator sets this number in Settings › Chat. It stays in the portal nav on every page.', '', 'info')}
</div>`);
const f3 = col('Urgent help · kept in the nav and in Chat', urgent);

const sections = [
    sec('Family conversation', 'Family portal at 1520 × 1100 · who is caring today, the reply promise, seen by care team, photos, voice notes, visit and callback requests', page),
    sec('Family parts', 'Same composer and reactions as staff; privacy rules stay', `<div class="c3-r3">${f1}${f2}${f3}</div>`),
];
export const size = writeBoard('ChatV3-Family.html', 'Chat v3 — family portal', 'Chat v3 · family', 'The family portal conversation in the Journey family skin — warm, clear about who is caring today, and private by default.', sections);
console.log('ChatV3-Family.html', size);
