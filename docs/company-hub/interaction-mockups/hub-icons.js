// Original Hub feature glyphs, drawn for this mockup at the user's request.
// Shared grammar: open loops, offset joins, round terminals and generous counters.
// Utility controls retain their familiar Lucide arrows, close and status symbols.
const hubGlyphs={
 appreciation:{label:'Appreciation',route:1,idea:'A little round of applause',shape:'<path d="m10.5 21-5-5a4.5 4.5 0 0 1-1.3-3.2V8a1.3 1.3 0 0 1 2.6 0v3.2-6a1.3 1.3 0 0 1 2.6 0V11 4a1.3 1.3 0 0 1 2.6 0v7-4.5a1.3 1.3 0 0 1 2.6 0v7l1.1-1.1a1.5 1.5 0 0 1 2.1 2.1l-3.3 3.3V21"/><path d="m18 20.5 2.1-2.1a4.8 4.8 0 0 0 1.4-3.4V9a1.3 1.3 0 0 0-2.6 0v2M18 5.5V3M21 5l1-1"/>'},
 people:{label:'People',route:14,idea:'Room for one another',shape:'<circle cx="8.6" cy="7" r="3"/><path d="M14.4 4.7a3 3 0 0 1 0 5.7M2.8 19v-1.3a5.8 5.8 0 0 1 11.6 0V19H8.6"/><path d="M17.2 13a4.2 4.2 0 0 1 4 4.2V19h-3.7"/>'},
 ideas:{label:'Ideas',route:8,idea:'A thought opening out',shape:'<path d="M9 17.5H6.8A3.8 3.8 0 0 1 3 13.7v-6A3.8 3.8 0 0 1 6.8 4H12a3.8 3.8 0 0 1 3.8 3.8v1"/><path d="M9 21v-8.3a3.5 3.5 0 0 1 3.5-3.5H21v3.3a3.5 3.5 0 0 1-3.5 3.5H14M13 13h4"/><circle cx="19.5" cy="4" r="1.3" fill="currentColor" stroke="none"/>'},
 gatherings:{label:'Gatherings',route:5,idea:'A place at the table',shape:'<path d="M4 9.5h16v7.2a3.3 3.3 0 0 1-3.3 3.3H7.3A3.3 3.3 0 0 1 4 16.7v-3.2"/><circle cx="6" cy="5" r="1.8"/><circle cx="12" cy="4" r="1.8"/><circle cx="18" cy="5" r="1.8"/><path d="M9.5 15h5"/>'},
 stories:{label:'Stories',route:37,idea:'A page with a voice',shape:'<path d="M14.5 3H7A3 3 0 0 0 4 6v12a3 3 0 0 0 3 3h9.5a3 3 0 0 0 3-3v-6M14.5 3v4a2 2 0 0 0 2 2h3V7.5L15 3"/><path d="M8 13h7M8 17h4"/><circle cx="20" cy="3.5" r="1.2" fill="currentColor" stroke="none"/>'},
 resources:{label:'Resources',route:36,idea:'An open place to learn',shape:'<path d="M12 7c-2.8-2.4-5.5-3-9-2v13c3.5-1 6.2-.4 9 2 2.8-2.4 5.5-3 9-2V9M12 7v13"/><path d="M16 6h5M18.5 3.5v5M6.5 10h2M6.5 14h2M15.5 14H18"/>'},
 care:{label:'Care',route:18,idea:'A little support underneath',shape:'<path d="M12 13.5 7.3 9a3 3 0 0 1 4.2-4.3l.5.5.5-.5A3 3 0 0 1 16.7 9L15 10.6"/><path d="M3 14.5h3.3c1.8 0 2.7 2 4.5 2H14a2 2 0 0 1 2 2v.5H9"/><path d="m16 17.5 2.3-3a2 2 0 0 1 3.1 2.5l-2.3 3a3 3 0 0 1-2.4 1.2H8l-5-3"/>'},
 growth:{label:'Progress',route:17,idea:'Small steps growing together',shape:'<path d="M4 20h8.5a6.5 6.5 0 0 0 6.5-6.5V8M8 16v-4.5A4.5 4.5 0 0 0 3.5 7H3v4.5A4.5 4.5 0 0 0 7.5 16H8ZM19 8h-4.5A4.5 4.5 0 0 1 19 3h2v2a3 3 0 0 1-3 3"/><circle cx="11" cy="4.5" r="1.3" fill="currentColor" stroke="none"/>'},
 conversation:{label:'Conversation',route:4,idea:'One voice makes room for another',shape:'<path d="M14 14.5H8l-4 3v-3.8A4 4 0 0 1 2.5 10.5v-4A3.5 3.5 0 0 1 6 3h8a3.5 3.5 0 0 1 3.5 3.5V8"/><path d="M13 11h5a3.5 3.5 0 0 1 3.5 3.5V21l-4-2.5H13a3.5 3.5 0 0 1 0-7ZM7 7.5h5"/>'},
 celebrations:{label:'Celebrations',route:3,idea:'A ribbon around a shared moment',shape:'<path d="M4 10h16v7a3 3 0 0 1-3 3H7a3 3 0 0 1-3-3v-3M12 10v10M12 10C6 10 4 7.8 5.5 5.5S10 4 12 10Zm0 0c6 0 8-2.2 6.5-4.5S14 4 12 10Z"/><circle cx="21" cy="3.5" r="1.2" fill="currentColor" stroke="none"/>'},
 saved:{label:'Saved',route:20,idea:'Keep something within reach',shape:'<path d="M16.5 3H7a3 3 0 0 0-3 3v15l6-3.5 6 3.5V10M19 3v4.5A2.5 2.5 0 0 1 16.5 10H10V7.5A4.5 4.5 0 0 1 14.5 3h2"/>'},
 place:{label:'Our Hub',route:16,idea:'An open door, a shared circle',shape:'<path d="M19.5 13.5A8 8 0 1 1 10.5 4M8 20V9a3 3 0 0 1 3-3h4v14M11.5 20H19M15 6h2a3 3 0 0 1 3 3"/><circle cx="19" cy="3.5" r="1.3" fill="currentColor" stroke="none"/>'}
};
const hubGlyphAliases={sparkles:'appreciation',star:'growth','heart-handshake':'appreciation',users:'people','users-round':'people',lightbulb:'ideas','calendar-days':'gatherings','calendar-plus':'gatherings',newspaper:'stories','file-plus-2':'stories','book-open':'resources',library:'resources','hand-heart':'care','hand-helping':'care',heart:'care',trophy:'growth',flag:'growth','messages-square':'conversation','message-circle':'conversation','party-popper':'celebrations',gift:'celebrations',bookmark:'saved','building-2':'place'};
function hubIconMarkup(name){const key=hubGlyphs[name]?name:hubGlyphAliases[name],glyph=hubGlyphs[key];return glyph?`<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" class="hi-hub-glyph" data-hub-icon="${key}" aria-hidden="true" focusable="false">${glyph.shape}</svg>`:'';}

// Preserve stored values while presenting the new, descriptive icon names.
const choiceMetaBeforeHubIcons=choiceMeta;
choiceMeta=function(key,value){const meta=choiceMetaBeforeHubIcons(key,value);if(key==='hero.icon')meta.label=({sparkles:'Appreciation',users:'People',heart:'Care','building-2':'Our Hub','book-open':'Resources'})[value]||value;return meta;};
choiceDetails['hero.icon'].sparkles=['sparkles','A little round of applause'];

views[43]=()=>view('A family of our own','Original feature icons for the Company Hub.',`<div class="hi-icon-intro"><div class="hi-icon-signature">${icon('appreciation')}</div><div><span class="hi-label">APPRECIATION</span><h3>A little round of applause.</h3><p>Open loops and rounded joins echo Oblivion’s circular mark.</p></div></div><div class="hi-icon-family">${Object.entries(hubGlyphs).map(([key,glyph])=>`<button type="button" class="hi-icon-specimen cursor-interaction" data-action="open" data-value="${glyph.route}"><span class="hi-icon-drawing">${icon(key)}</span><b>${glyph.label}</b><small>${glyph.idea}</small><span class="hi-icon-at-size">${icon(key)}<span>In the Hub</span>${icon('arrow-up-right')}</span></button>`).join('')}</div>`,`<span class="hi-muted">Select an icon to see its journey</span>${B('Give a shout-out','open','primary','1','appreciation')}`,'wide hi-icon-family-window');
const indexBeforeHubIcons=indexView;
indexView=function(){const v=indexBeforeHubIcons();v.foot=`${B('Our icon family','open','','43','appreciation')}${B('Give a shout-out','open','primary','1','appreciation')}`;return v;};

const renderBeforeHubIcons=render;
render=function(){renderBeforeHubIcons();
 if(S.route===1||S.route===2){const title=$('#hi-dialog-title');if(title&&!title.querySelector('[data-hub-icon]'))title.insertAdjacentHTML('afterbegin',`<span class="hi-title-glyph">${icon('appreciation')}</span>`);}
 // Old decorative sparkle values deliberately resolve to the custom glyph too.
 $$('[data-lucide="sparkles"],[data-lucide="star"],.lucide-sparkles,.lucide-star').forEach(node=>{node.outerHTML=icon(node.classList.contains('lucide-star')||node.dataset.lucide==='star'?'growth':'appreciation');});
};
