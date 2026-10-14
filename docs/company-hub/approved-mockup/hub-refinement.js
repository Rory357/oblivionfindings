// Clearer discovery and a useful, searchable updates centre.
const refinementBase={connectedNavigation,homeWelcome,handleAction,noticeDialog,render};
let updateSearch='';
const hubDestinations={
 home:['house','Your shared company home'],news:['newspaper','Stories from across our company'],shoutouts:['heart-handshake','Recognise someone’s good work'],events:['calendar-days','What’s coming up, and who’s going'],resources:['book-open','Guides, policies and useful answers'],pages:['panels-top-left','Explore shared company pages'],saved:['bookmark','Your stories and guides for later'],teams:['building-2','Meet the teams behind our work'],directory:['users-round','Find a colleague or expertise'],services:['concierge-bell','Get support and follow your requests']
};
function hubDiscoveryLink(n){
 const page=n.target?.startsWith('page:')?pageRecord(n.target.slice(5))?.published:null,[icon,description]=hubDestinations[n.target]||['file-text',page?.summary||page?.tagline||'Open company page'];
 return `<button class="hr-discovery-link cursor-interaction" data-action="hm-follow" data-value="${esc(n.target)}" ${activePageId?n.target==='page:'+activePageId?'aria-current="page"':'':state.tab===n.target?'aria-current="page"':''}><span class="hr-discovery-icon">${I(icon)}</span><span><b>${esc(n.label)}${unreadTarget(n.target)?'<i class="hr-new-dot" aria-label="New updates"></i>':''}</b><small>${esc(description.slice(0,95))}</small></span>${I('arrow-up-right')}</button>`;
}
connectedNavigation=function(limit=Infinity){
 let html=refinementBase.connectedNavigation(limit);
 if(navMenu!=='hub-rail-more'||!Number.isFinite(limit))return html;
 const items=state.navigation.filter(navVisible),overflow=items.filter(n=>!n.parent).slice(limit);
 if(!overflow.length)return html;
 const menu=`<div class="hm-nav-menu hr-mega-menu"><div class="hr-mega-head"><span>Explore your hub</span><small>People, information & everyday support</small></div><div class="hr-mega-links">${overflow.flatMap(n=>n.group?items.filter(c=>c.parent===n.id):[n]).map(hubDiscoveryLink).join('')}</div></div>`;
 return html.replace(/<div class="hm-nav-menu">[\s\S]*?<\/div>(?=<\/div><\/nav>$)/,menu);
};
homeWelcome=function(){return refinementBase.homeWelcome().replace('<span class="hp-eyebrow">Across our company</span><h2>Good things happen together.</h2>','<h2>From across the company</h2>');};
function updateBookmark(item){return item.type==='news'?'story:'+item.id:item.type==='resources'?'page:'+item.id:null;}
function updateSaved(item){const key=updateBookmark(item);return key&&state.bookmarks.includes(key);}
function filteredUpdates(){
 const q=updateSearch.trim().toLowerCase();
 return activityItems().filter(item=>(activityFilter==='all'||activityFilter==='new'&&isUnseen(item)||activityFilter==='attention'&&item.required||activityFilter==='saved'&&updateSaved(item))&&(!q||(item.title+' '+item.owner+' '+item.kind).toLowerCase().includes(q)));
}
function updateRow(item){
 const fresh=isUnseen(item),saved=updateSaved(item),story=item.type==='news'?pageRecord(item.id)?.published:null;
 return `<article class="hr-update-row"><button class="hr-update-open" data-action="ha-open-item" data-value="${esc(item.key)}">${story?`<span class="hr-update-thumbnail">${photo(heroFor(story).image,'')}</span>`:`<span class="hr-update-glyph">${I(item.icon)}</span>`}<span class="hr-update-copy"><span class="hr-update-overline">${esc(item.owner)}${fresh?'<span class="ha-new-tag">New</span>':''}</span><b>${esc(item.title)}</b><small>${item.required?'<span class="hr-required">Read confirmation needed</span>':item.type==='home'?'Company announcement':item.kind}${!item.required&&item.date?' · '+esc(item.type==='home'?formatTime(item.date):item.date):''}</small></span></button><div class="hr-update-actions">${updateBookmark(item)?`<button class="hc-iconbtn" data-action="hr-save-update" data-value="${esc(item.key)}" aria-label="${saved?'Unsave':'Save'} ${esc(item.title)}" aria-pressed="${!!saved}" data-tooltip="${saved?'Remove from saved':'Save for later'}">${I(saved?'bookmark-check':'bookmark')}</button>`:''}${fresh?`<button class="hc-iconbtn" data-action="hr-see-update" data-value="${esc(item.key)}" aria-label="Mark ${esc(item.title)} as seen" data-tooltip="Mark as seen">${I('check')}</button>`:'<span class="hr-seen" aria-label="Seen">'+I('check-check')+'</span>'}</div></article>`;
}
function updateResults(){
 const items=filteredUpdates();
 if(!items.length){const searching=!!updateSearch.trim();return `<div class="ha-feed-empty">${I(searching?'search':activityFilter==='attention'?'circle-check':activityFilter==='saved'?'bookmark':'check-check')}<h3>${searching?'No matching updates':activityFilter==='attention'?'Nothing needs your confirmation':activityFilter==='saved'?'Keep something for later':'You’re all caught up.'}</h3><p>${searching?'Try a different word, title or team.':activityFilter==='saved'?'Save a story or guide using its bookmark button.':activityFilter==='attention'?'Required announcements will appear here.':'New stories, guides and announcements will appear here.'}</p>${searching?B('Clear search','hr-clear-search','x'):''}</div>`;}
 return [['home','Important announcements'],['news','Company stories'],['resources','Guides & resources']].map(([type,label])=>{const group=items.filter(item=>item.type===type);return group.length?`<section class="hr-feed-group"><h3>${label}<span>${group.length}</span></h3>${group.map(updateRow).join('')}</section>`:'';}).join('');
}
function updateFooter(){const fresh=filteredUpdates().filter(isUnseen).length;return `<span class="hr-feed-footnote">Seen indicators don’t confirm required reading.</span><div class="hc-row">${B('Close','close')}${fresh?B('Mark shown as seen','hr-see-shown','check-check','primary'):''}</div>`;}
function refineUpdateControls(){const count=filteredUpdates().length;dialog.querySelector('#hr-update-count').textContent=count+' update'+(count===1?'':'s');dialog.querySelectorAll('button').forEach(b=>b.classList.add('cursor-interaction'));}
function refreshUpdateResults(){const results=dialog.querySelector('#hr-update-results');if(!results)return;results.innerHTML=updateResults();dialog.querySelector('.hc-dialog-foot').innerHTML=updateFooter();refineUpdateControls();icons();}
activityDialog=function(){
 const all=activityItems(),pending=all.filter(item=>item.required),count=all.filter(isUnseen).length;
 openDialog('Your company updates',`Since your last visit · ${visitLabel}`,`<div class="hr-update-summary"><span>${count?`<b>${count}</b> new updates to explore`:'You’ve caught up with the latest updates'}</span>${pending.length?`<button data-action="ha-feed-filter" data-value="attention">${I('circle-alert')}${pending.length} to confirm ${I('arrow-right')}</button>`:`<span class="hr-confirmed">${I('circle-check')}No confirmations pending</span>`}</div><div class="ha-feed-tabs hr-feed-tabs" aria-label="Update filters">${[['new','New',count],['attention','Needs attention',pending.length],['saved','Saved',all.filter(updateSaved).length],['all','All updates',all.length]].map(([key,label,n])=>`<button data-action="ha-feed-filter" data-value="${key}" aria-pressed="${activityFilter===key}">${label}<span>${n}</span></button>`).join('')}</div><label class="hr-update-search">${I('search')}<input id="hr-update-search" aria-label="Search your updates" placeholder="Search a title, topic or team…" value="${esc(updateSearch)}" autocomplete="off"><span id="hr-update-count" aria-live="polite">${filteredUpdates().length} updates</span></label><div id="hr-update-results">${updateResults()}</div>${activityUndo?`<div class="ha-undo-line">Updates marked as seen. ${TB('Undo','ha-undo-seen','','undo-2')}</div>`:''}`,updateFooter(),'activity');
 dialog.dataset.refinement='updates';
 refineUpdateControls();
};
noticeDialog=function(id){
 refinementBase.noticeDialog(id);
 if(!dialog.open||dialogKind!=='announcement')return;
 dialog.removeAttribute('data-refinement');
 const foot=dialog.querySelector('.hc-dialog-foot');if(foot){foot.insertAdjacentHTML('afterbegin',B('Back to updates','hr-back-updates','arrow-left','ghost'));icons();}
};
root.addEventListener('input',event=>{if(event.target.id==='hr-update-search'){updateSearch=event.target.value;refreshUpdateResults();}});
root.addEventListener('click',event=>{if(navMenu&&!event.target.closest('.hp-hub-nav')&&!dialog.open&&!edit){navMenu='';fitConnectedNavigation();}});
handleAction=function(a,v,el){
 if(a.startsWith('hr-')&&(edit||preview))return;
 if(a==='ha-activity'||a==='notifications')updateSearch='';
 if(a==='hr-clear-search'){updateSearch='';activityDialog();dialog.querySelector('#hr-update-search')?.focus();return;}
 if(a==='hr-back-updates'){activityFilter='attention';updateSearch='';activityDialog();return;}
 if(a==='hr-save-update'){
  const item=activityItems().find(x=>x.key===v),key=item&&updateBookmark(item);if(!key)return;const c=clone(state);c.bookmarks=c.bookmarks.includes(key)?c.bookmarks.filter(k=>k!==key):[...c.bookmarks,key];if(managementCommit(c)){render();activityDialog();}return;
 }
 if(a==='hr-see-update'||a==='hr-see-shown'){
  const keys=a==='hr-see-update'?activityItems().filter(item=>item.key===v&&isUnseen(item)).map(item=>item.key):filteredUpdates().filter(isUnseen).map(item=>item.key);if(!keys.length)return;
  if(rememberSeen(keys)){activityUndo=keys;render();activityDialog();}return;
 }
 refinementBase.handleAction(a,v,el);
};
render=function(){refinementBase.render();if(dialogKind!=='activity')dialog.removeAttribute('data-refinement');};
dialog.addEventListener('close',()=>dialog.removeAttribute('data-refinement'));
