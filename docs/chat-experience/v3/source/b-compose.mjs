import * as C from './c3lib.mjs';
const { svg, av, gl, btn, ib, lens, badge, e, col, sec, note, group, ownMeta, day, composer, mi, ms, mc, menu, rc, rx, wave, writeBoard } = C;

const mini = (inner, style = '') => `<div class="ch-mini"${style ? ` style="${style}"` : ''}>${inner}</div>`;
const msgs = (inner, st = '') => `<div class="c3-msgs top" style="flex: none; padding-bottom: 12px${st}">${inner}</div>`;
const stack = (pop, comp, align = 'end') => `<div style="display: grid; gap: 8px">${`<div style="display: flex; justify-content: flex-${align}">${pop}</div>`}${mini(comp)}</div>`;
const seg = (on) => `<div class="c3-seg" role="tablist">${[['smile', 'Emoji'], ['sticker', 'Stickers'], ['image', 'GIFs']].map(([i, t]) => `<span role="tab" aria-selected="${t === on}"${t === on ? ' class="on"' : ''}>${svg(i, 14)}${t}</span>`).join('')}</div>`;
const grid = (list, on = -1) => `<div class="c3-ep-grid">${[...list].map((ch, i) => `<span${i === on ? ' class="on"' : ''}>${e(ch)}</span>`).join('')}</div>`;
const EMO = (s) => Array.from(new Intl.Segmenter('en', { granularity: 'grapheme' }).segment(s), (x) => x.segment).filter((x) => x.trim());
const tabs = (on = 1) => `<div class="c3-ep-tabs" role="tablist" aria-label="Categories">${[['clock', 'Recent'], ['smile', 'Smileys'], ['users', 'People'], ['leaf', 'Nature'], ['utensils', 'Food'], ['car', 'Travel and places'], ['bulb', 'Objects'], ['heart', 'Symbols'], ['flag', 'Flags']].map(([i, t], n) => `<span role="tab" aria-label="${t}"${n === on ? ' class="on" aria-selected="true"' : ''}>${svg(i, 17)}</span>`).join('')}</div>`;
const tones = `<div class="c3-tones" role="radiogroup" aria-label="Skin tone">${EMO('✋✋🏻✋🏼✋🏽✋🏾✋🏿').map((t, i) => `<span role="radio" aria-checked="${i === 0}"${i === 0 ? ' class="on"' : ''}>${e(t)}</span>`).join('')}</div>`;

// 1 · emoji picker
const picker = `<div class="c3-pop c3-ep" role="dialog" aria-label="Emoji, stickers and GIFs">${seg('Emoji')}
<div class="ch-find">${svg('search', 15)}Search emoji in English or te reo Māori</div>
${tabs(1)}
<div class="c3-ep-cap">Recent</div>${grid(EMO('👍❤️😂🙏🎉👏😊✅'))}
<div class="c3-ep-cap">Smileys</div>${grid(EMO('😀😃😄😁😆🥹😅😂🤣🥲😊😇🙂🙃😉😌😍🥰😘😋😛😜🤪🤗🤭🫡🤔😐😴😮‍💨🤒😢'), 19)}
<div class="c3-ep-f"><span class="pv">${e('🥰')}</span><div><b>Smiling face with hearts</b><small>:smiling_face_with_hearts: · aroha</small></div></div><div class="c3-ep-f t"><span class="oc-hint">Skin tone</span>${tones}</div></div>`;
const f1 = col('Emoji picker · search, recents, categories, skin tone', stack(picker, composer({ text: 'Thanks for covering tonight ', focus: true, hint: false, mode: 'auto' })));

// 2 · te reo keyword
const reo = `<div class="c3-pop c3-ep" role="dialog" aria-label="Emoji search results">${seg('Emoji')}
<div class="ch-find has">${svg('search', 15)}aroha<span style="margin-left: auto">${svg('x', 14)}</span></div>
<div class="c3-ep-cap">Results <span>· aroha means love</span></div>${grid(EMO('❤️💜🫶💗💕🥰😍🤗'), 0)}
<div class="c3-ep-cap">Also in te reo Māori</div>
<div style="display: grid; gap: 6px; padding: 4px 14px 14px">${[['👋', 'kia ora', 'hello, thank you'], ['👍', 'ka pai', 'good, well done'], ['👨‍👩‍👧', 'whānau', 'family'], ['🌅', 'mōrena', 'good morning'], ['💪', 'kia kaha', 'be strong']].map(([c, w, m]) => `<div style="display: flex; align-items: center; gap: 10px; font-size: 13px"><span style="font-size: 20px">${e(c)}</span><b style="font-weight: 650">${w}</b><span class="oc-muted">${m}</span></div>`).join('')}</div>
<div class="c3-ep-f"><span class="pv">${e('❤️')}</span><div><b>Red heart</b><small>:heart: · aroha · love</small></div></div></div>`;
const f2 = col('Te reo Māori keywords · Oblivion writes its own emoji names', stack(reo, composer({ text: 'Ngā mihi for today ', focus: true, hint: false })));

// 3 · reaction quick bar on hover
const hbar = `<div class="c3-hbar" role="toolbar" aria-label="Message actions" style="left: 0; right: auto; top: -64px">${EMO('👍❤️😂😮😢🙏').map((c) => `<button type="button" aria-label="React ${c}">${e(c)}</button>`).join('')}<button type="button" aria-label="More reactions">${svg('smileplus', 17)}</button><span class="vr" aria-hidden="true"></span><button type="button" aria-label="Reply">${svg('reply', 16)}</button><button type="button" aria-label="Reply in thread">${svg('thread', 16)}</button><button type="button" class="on" aria-label="More actions">${svg('more', 16)}</button></div>`;
const f3 = col('Hover a message · six reactions, more, reply, reply in thread, more', mini(msgs([
    group({ who: 'alex', time: '10:24 am', bubbles: ['The afternoon handover is ready. Could you check it before lunch?'], hbar }),
    group({ who: 'mere', own: true, bubbles: ['On it. Thanks Alex!'], after: rx(rc('🙏', 1, false, 'Alex')), meta: ownMeta('10:31 am', 'read') }),
].join(''), '; padding-top: 74px; overflow: visible')) + note('Any emoji can be a reaction. Your organisation can limit reactions in Settings › Chat (for example in family conversations).', 'tint'));

// 4 · stickers
const stk = (t) => `<div class="c3-stk" role="img" aria-label="Sticker: ${t}">${t}<i></i></div>`;
const ostk = (t) => `<div class="c3-stk org" role="img" aria-label="Sticker: ${t}">${t}<i></i></div>`;
const stickers = `<div class="c3-pop c3-ep" role="dialog" aria-label="Stickers">${seg('Stickers')}
<div class="c3-ep-cap">Oblivion <span>· drawn for us, free to use</span></div>
<div class="c3-stks">${['Kia ora', 'Ka pai', 'Ngā mihi', 'Mōrena', 'Kia kaha', 'Thank you'].map(stk).join('')}</div>
<div class="c3-ep-cap">Kōwhai Care Trust <span>· your organisation</span></div>
<div class="c3-stks">${['Great teamwork', 'Safe shift', 'Welcome aboard'].map(ostk).join('')}</div></div>`;
const f4 = col('Stickers · the Oblivion pack and your organisation’s own', stack(stickers, composer({ hint: false })));

// 5 · GIFs: organisation library only
const gif = (t, p) => `<div class="c3-gif c3-ph ${p}" role="img" aria-label="GIF: ${t}"><span class="tg">GIF</span><b>${t}</b></div>`;
const gifs = `<div class="c3-pop c3-ep" role="dialog" aria-label="GIFs">${seg('GIFs')}
<div class="ch-find">${svg('search', 15)}Search your organisation’s GIFs</div>
<div class="c3-ep-cap">Organisation library <span>· added by your administrator</span></div>
<div class="c3-gifs">${[['Celebrate', 'p4'], ['Thank you', 'p2'], ['Welcome', 'p1'], ['Well done', 'p3'], ['Morning tea', 'p2'], ['Safe travels', 'p1']].map(([t, p]) => gif(t, p)).join('')}</div>
<div style="padding: 0 12px 12px">${note('Outside GIF services are off, so nothing you type is sent to another company. Administrators add GIFs in Settings › Chat.', '', 'shield')}</div></div>`;
const f5 = col('GIFs · your organisation’s library; outside services off', stack(gifs, composer({ hint: false })));

// 6 · how stickers and big emoji look when sent
const f6 = col('Sent · stickers and emoji-only messages show large', mini(msgs([
    group({ who: 'hana', time: '2:58 pm', bubbles: [{ raw: `<div class="c3-stk big" role="img" aria-label="Sticker: Ka pai">Ka pai<i></i></div>` }] }),
    group({ who: 'mere', own: true, bubbles: [{ html: `<span class="big">${e('🎉🎉')}</span>`, cls: 'emo' }], meta: ownMeta('3:01 pm', 'dlv') }),
    group({ who: 'rohan', time: '3:04 pm', bubbles: [`Great work today, team ${e('💪')} The van is booked for Saturday.`], after: rx(rc('👍', 4, true, 'You, Alex, Hana, Mere'), rc('🎉', 2)) }),
].join(''))));

// 7 · formatting (Teams-like, Slate)
const fmt = `<div class="c3-big" role="group" aria-label="Message with formatting"><div class="c3-fmt" role="toolbar" aria-label="Formatting">${ib('bold', 'Bold', 'on', 16)}${ib('italic', 'Italic', '', 16)}${ib('underline', 'Underline', '', 16)}${ib('strike', 'Strikethrough', '', 16)}<span class="vr"></span>${ib('list', 'Bulleted list', 'on', 16)}${ib('listord', 'Numbered list', '', 16)}<span class="vr"></span>${ib('quote', 'Quote', '', 16)}${ib('code', 'Code', '', 16)}${ib('link', 'Link', '', 16)}${ib('x', 'Hide formatting', '', 15)}</div>
<div class="bd"><b>Tonight at Kōwhai House</b><ul><li>Lock the side gate after 8 pm</li><li>Check Tane’s window is shut</li><li>Fridge temperature goes in the <u>kitchen log</u></li></ul></div>
<div class="bt">${ib('smile', 'Emoji', '', 18)}${ib('sticker', 'Stickers and GIFs', '', 18)}${ib('clip', 'Attach', '', 18)}${ib('aa', 'Hide formatting', 'on', 18)}<span class="sp"></span><span class="oc-hint" style="margin-right: 8px">Ctrl + Enter to send</span><button class="c3-round p" type="button" aria-label="Send" style="width: 38px; height: 38px">${svg('send', 17)}</button></div></div>`;
const f7 = col('Formatting · Aa opens the toolbar; Ctrl + B, I, U work too', mini(`<div class="c3-comp" style="border-top: 0">${fmt}</div>`));

// 8 · attach menu
const attach = menu([
    mi('image', 'Photos and videos', { sub: 'Up to 5 files, 20 MB each' }),
    mi('camera', 'Camera', { sub: 'Take a photo now' }),
    mi('file', 'Document', { sub: 'PDF, Word, Excel, text' }),
    ms(),
    mi('clipboard', 'Care card…', { sub: 'Share a client, shift, incident or task' }),
    mi('poll', 'Poll', { sub: 'Ask the group a quick question' }),
    mi('mappin', 'Live location', { sub: 'For lone work and outings' }),
    mi('mic', 'Voice note', { sub: 'Or press the microphone' }),
], 'w300', '', 'Attach');
const f8 = col('Attach (+) · everything you can add, in one menu', stack(attach, composer({ hint: false }).replace('class="c3-round o" type="button" aria-label="Attach"', 'class="c3-round on" type="button" aria-label="Attach" aria-expanded="true"'), 'start'));

// 9 · inline voice recording
const rec = `<div class="c3-rec" role="group" aria-label="Recording a voice note">${ib('trash', 'Delete recording', '', 17)}<span class="t"><span class="c3-recdot" aria-hidden="true"></span>0:12</span>${wave(40, 40, 7)}<span class="lim">2:00 limit</span>${ib('pause', 'Pause recording', '', 17)}</div>`;
const f9 = col('Voice note · records in the composer, like WhatsApp', mini(msgs(group({ who: 'alex', time: '10:24 am', bubbles: ['Can you send me a quick voice note about the fridge?'] })) + composer({ replace: rec, send: `<button class="c3-round p" type="button" aria-label="Send voice note">${svg('send', 18)}</button>`, hint: false }).replace('<div class="c3-cin"><button class="c3-round o" type="button" aria-label="Attach">' + svg('plus', 19) + '</button>', '<div class="c3-cin">')) + note('Pause to listen back before sending. The microphone turns off when you stop or leave. Voice notes follow the same file rules as photos.', '', 'mic'));

// 10 · slash commands
const slash = menu([mc('Commands'),
    `<div class="c3-mi on" role="menuitem"><span class="cmd">/task</span><div><b>Add a task</b><small>From this conversation, with a link back</small></div></div>`,
    `<div class="c3-mi" role="menuitem"><span class="cmd">/incident</span><div><b>Report incident</b><small>Opens the report with this chat linked</small></div></div>`,
    `<div class="c3-mi" role="menuitem"><span class="cmd">/handover</span><div><b>Start a handover note</b><small>For the next shift at Kōwhai House</small></div></div>`,
    `<div class="c3-mi" role="menuitem"><span class="cmd">/note</span><div><b>Add a shift note</b><small>To Tane Wineera’s notes</small></div></div>`,
    `<div class="c3-mi" role="menuitem"><span class="cmd">/poll</span><div><b>Ask a quick question</b><small>Two to six choices</small></div></div>`,
], 'w320', '', 'Commands');
const f10 = col('Type / for commands · records start from the conversation', stack(slash, composer({ text: '/', focus: true, hint: false }), 'start'));

// 11 · @mentions with live groups
const ment = menu([mc('Groups · worked out from the roster now'),
    `<div class="c3-mi on" role="menuitem">${gl('users', 's32')}<div><b>@on-shift · Kōwhai House</b><small>3 people on shift now</small></div></div>`,
    `<div class="c3-mi" role="menuitem">${gl('home', 's32')}<div><b>@house-lead</b><small>Rohan Patel today</small></div></div>`,
    `<div class="c3-mi" role="menuitem">${gl('phone', 's32')}<div><b>@on-call</b><small>Alex Morgan from 7:00 pm</small></div></div>`,
    mc('People in this conversation'),
    `<div class="c3-mi" role="menuitem">${av('alex', 's32', 'on')}<div><b>Alex Morgan</b><small>On shift until 3 pm</small></div></div>`,
    `<div class="c3-mi" role="menuitem">${av('hana', 's32', 'leave')}<div><b>Hana Wilson</b><small>On leave until Friday</small></div></div>`,
], 'w320', '', 'Mention someone');
const f11 = col('@ mentions · people and roster groups', stack(ment, composer({ text: 'Side gate is fixed, thanks @on', focus: true, hint: false }), 'start'));

// 12 · paste and drop
const drop = `<div style="display: grid; justify-items: center; gap: 8px; text-align: center; padding: 22px 16px; margin: 12px 14px 0; border: 2px dashed color-mix(in oklch, var(--primary) 45%, var(--border)); border-radius: 14px; background: var(--tint)">${lens('upload', 's44', 19)}<b style="font-size: 14px">Drop to send to Alex Morgan</b><span class="oc-hint">Photos, videos and documents · or paste with Ctrl + V</span></div>`;
const staged = `<div class="ch-q" style="padding-left: 48px">${[['image', 'IMG_2041.jpg', 'Ready', ''], ['file', 'Lunch plan.pdf', 'Checking for viruses…', '']].map(([i, n, s]) => `<span class="ch-qi">${lens(i, 's22', 12)}${n} <small>· ${s}</small>${ib('x', 'Remove ' + n, '', 13)}</span>`).join('')}</div>`;
const f12 = col('Paste or drag files in · checked before sending', mini(drop + composer({ text: 'Photos from this morning', above: staged, hint: false })));

const sections = [
    sec('Emoji and reactions', 'A real picker — today the emoji button only adds one smiley, and thumbs up is the only reaction you can add', `<div class="c3-r3">${f1}${f2}${f3}</div>`),
    sec('Stickers and GIFs', 'Our own sticker pack in the Journey style; no outside GIF service by default', `<div class="c3-r3">${f4}${f5}${f6}</div>`),
    sec('Writing and attaching', 'Formatting like Teams, attach like WhatsApp, voice notes in place', `<div class="c3-r3">${f7}${f8}${f9}</div>`),
    sec('Shortcuts that know Oblivion', 'Commands start records; mentions can reach whoever is on shift', `<div class="c3-r3">${f10}${f11}${f12}</div>`),
];
export const size = writeBoard('ChatV3-Compose.html', 'Chat v3 — composer', 'Chat v3 · writing a message', 'Emoji, reactions, stickers, GIFs, formatting, attachments, voice notes, commands and mentions.', sections);
console.log('ChatV3-Compose.html', size);
