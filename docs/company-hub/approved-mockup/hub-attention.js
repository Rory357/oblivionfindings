// Daily hub use: shared announcements, personal seen state and compact navigation.
const attentionBase={header,homeWelcome,render,handleAction,openPage,normaliseHubSnapshot,connectedTab,connectedLinks,manageShell,createMenu,returnToTop,stateEnvelope};
const noticeSeeds={
 'people-support':{published:{title:'A simpler way to reach People & Culture',body:'From Monday 19 October, use Get help → Ask People & Culture for everyday questions about working here. Your question will reach the right team, and you can follow its progress from My requests.\n\nExisting conversations can continue with the colleague who is already helping you. Please take a moment to familiarise yourself with the new route.',owner:'People & Culture',posted:'2026-10-12T09:00',expires:'2026-10-23T17:00',ackRequired:true},draft:null,revision:1}
};
function ensureAttention(){state.hubAttention??={};state.hubAttention.seen??=[];state.hubAttention.ack??={};state.hubAttention.notices??={};}
ensureAttention();
let activityFilter='new',noticeEditing=null,attentionRenderKey='',activityUndo=null;
const visitLabel='Friday 9 October · 4:30 pm';
const attentionNewSeeds=new Set(['garden',...teamUpdates.slice(0,2).map(row=>row[0]),'guide-handbook','guide-laptop']);
normaliseHubSnapshot=function(incoming){attentionBase.normaliseHubSnapshot(incoming);ensureAttention();};
function attentionNow(){return state.previewTime||'2026-10-12T09:00';}
function noticeRecords(){return {...noticeSeeds,...state.hubAttention.notices};}
function noticeRecord(id){return noticeRecords()[id];}
function noticeActive(record){const d=record?.published;return !!d&&(!d.expires||d.expires>attentionNow());}
function noticeKey(id,r=noticeRecord(id)){return `n:${id}:${r?.revision||1}`;}
function noticeAcknowledged(id){return !!state.hubAttention.ack[noticeKey(id)];}
function currentNotices(){return Object.entries(noticeRecords()).filter(([,r])=>noticeActive(r)).map(([id,r])=>({id,...r.published,key:noticeKey(id,r),revision:r.revision}));}
function activityItems(){
 const pages=pageList().filter(p=>p.published&&['story','knowledge'].includes(p.published.kind)&&(p.published.kind!=='story'||p.published.showInNews!==false)).map(p=>{
  const d=p.published,revision=p.revision||pageRecord(p.id)?.revision||1;
  return {key:`p:${p.id}:${revision}`,id:p.id,title:d.title,kind:d.kind==='story'?'Story':'Updated guide',type:d.kind==='story'?'news':'resources',owner:d.author||'Company Hub',date:d.date||'12 Oct 2026',icon:d.kind==='story'?'newspaper':'book-open',baseline:!!seedPageDocs[p.id]&&!attentionNewSeeds.has(p.id)&&revision===1};
 });
 return [...currentNotices().map(n=>({key:n.key,id:n.id,title:n.title,kind:'Announcement',type:'home',owner:n.owner,date:n.posted,icon:'megaphone',required:n.ackRequired&&!noticeAcknowledged(n.id)})),...pages];
}
function isUnseen(item){return !item.baseline&&!state.hubAttention.seen.includes(item.key);}
function newActivity(){return activityItems().filter(isUnseen);}
function unreadTarget(target){return newActivity().filter(item=>item.type===target).length;}
function rememberSeen(keys){
 const fresh=keys.filter(key=>!state.hubAttention.seen.includes(key));if(!fresh.length)return true;
 const c=clone(state);c.hubAttention.seen=[...new Set([...c.hubAttention.seen,...fresh])].slice(-90);return managementCommit(c);
}
openPage=function(id){
 if(!edit&&!preview&&pageRecord(id)?.published){const item=activityItems().find(x=>x.type!=='home'&&x.id===id);if(item&&isUnseen(item))rememberSeen([item.key]);}
 return attentionBase.openPage(id);
};
connectedTab=function(label,action,value,active=false,group=false){
 let html=attentionBase.connectedTab(label,action,value,active,group);
 if(!group&&['home','news','resources'].includes(value)){const count=unreadTarget(value);html=html.replace('<button ',`<button aria-description="${count?count+' new updates':'No new updates'}" `).replaceAll('</span>',`<span class="ha-nav-dot ${count?'':'is-read'}" aria-hidden="true"></span></span>`);}
 return html;
};
connectedLinks=function(items){return attentionBase.connectedLinks(items).replace(/(<button[^>]*data-value="([^"]+)"[^>]*>)([^<]+)/g,(all,start,target,label)=>start+label+(unreadTarget(target)?'<span class="ha-menu-new">New</span>':''));};
header=function(){
 let html=attentionBase.header();
 if(!activePageId&&!isEditing()){const start=html.indexOf('<div class="hp-hub-nav">');if(start>=0){const rail=html.slice(start,html.lastIndexOf('</header>'));html=html.slice(0,start)+'</header>'+rail;}}
 return html;
};
function attentionSummary(){
 const notices=currentNotices(),pending=notices.filter(n=>n.ackRequired&&!noticeAcknowledged(n.id)),lead=pending[0]||notices[0],count=newActivity().length;
 return `<section class="ha-pulse" aria-label="Company updates"><div class="ha-pulse-lead">${I(lead?'megaphone':'circle-check')}<div>${lead?`<span class="ha-kicker">Important update${pending.length?' · Please read':''}</span><button class="ha-announcement-link" data-action="ha-notice" data-value="${lead.id}">${esc(lead.title)} ${I('arrow-up-right')}</button>`:'<b>You’re up to date</b><small>No important announcements need your attention.</small>'}</div></div><button class="ha-catchup" data-action="ha-activity"><span>${count?`<b>${count}</b> new since your last visit`:'All caught up'}${I('arrow-right')}</span><small>${pending.length?`${pending.length} read confirmation pending`:'Your company updates, in one place'}</small></button></section>`;
}
homeWelcome=function(){return attentionSummary()+attentionBase.homeWelcome();};
function activityDialog(){
 const all=activityItems(),items=activityFilter==='new'?all.filter(isUnseen):all,count=all.filter(isUnseen).length,pending=currentNotices().filter(n=>n.ackRequired&&!noticeAcknowledged(n.id));
 openDialog('Since your last visit',`Last visit: ${visitLabel}`,`<div class="ha-feed-tabs">${[['new','New',count],['all','All updates',all.length]].map(([key,label,n])=>`<button data-action="ha-feed-filter" data-value="${key}" aria-pressed="${activityFilter===key}">${label} <span>${n}</span></button>`).join('')}</div>${pending.length?`<div class="ha-confirmation-note">${I('circle-alert')}<span>${pending.length} announcement ${pending.length===1?'needs':'need'} your read confirmation. Marking updates as seen won’t confirm them.</span>${B('Read announcement','ha-notice','arrow-right','',pending[0].id)}</div>`:''}<div class="ha-activity-list">${items.map(item=>`<button class="ha-activity-item" data-action="ha-open-item" data-value="${esc(item.key)}"><span class="hd-glyph">${I(item.icon)}</span><span><small>${item.kind} · ${esc(item.owner)}</small><b>${esc(item.title)}</b><em>${item.required?'Read confirmation needed':isUnseen(item)?'New for you':'Seen'}</em></span>${I('arrow-up-right')}</button>`).join('')||`<div class="ha-feed-empty">${I('check-check')}<h3>You’re all caught up.</h3><p>New stories, guides and announcements will appear here.</p></div>`}</div>${activityUndo?`<div class="ha-undo-line">Marked as seen. ${TB('Undo','ha-undo-seen','','undo-2')}</div>`:''}`,B('Close','close')+(count?B('Mark all as seen','ha-seen-all','check-check','primary'):''),'activity');
}
function noticeDialog(id){
 const r=noticeRecord(id),d=r?.published;if(!noticeActive(r)){toast('This announcement is no longer active.');return;}
 rememberSeen([noticeKey(id)]);render();
 const ack=state.hubAttention.ack[noticeKey(id)];
 openDialog(d.title,`${d.owner} · All staff`,`<div class="ha-notice-meta"><span class="hc-tag">Important announcement</span><span>${d.expires?'Available until '+formatTime(d.expires):'No expiry set'}</span></div><article class="ha-notice-body">${d.body.split('\n\n').map(p=>`<p>${esc(p)}</p>`).join('')}</article>${d.ackRequired?`<div class="ha-ack-state ${ack?'confirmed':''}">${I(ack?'circle-check':'circle-alert')}<span>${ack?'You confirmed you’ve read this · '+formatTime(ack):'Please confirm when you have read this announcement.'}</span></div>`:'<p class="hc-small hc-muted">For your information · No read confirmation needed.</p>'}`,B('Close','close')+(d.ackRequired&&!ack?B('I’ve read this','ha-ack','check','primary',id):''),'announcement');
}
function updatesManagement(){
 const records=Object.entries(noticeRecords());
 return `<div class="ha-manage-intro"><p>Keep important information visible for the right amount of time. Read confirmations are separate from new-content indicators.</p>${B('New announcement','ha-new','plus','primary')}</div><div class="ha-notice-list">${records.map(([id,r])=>{const d=r.draft||r.published,status=!r.published?'Draft':noticeActive(r)?'Active':'Expired';return `<article><span class="hd-glyph">${I('megaphone')}</span><div><h3>${esc(d.title||'Untitled announcement')}</h3><p>${esc(d.owner)} · All staff · ${d.ackRequired?'Read confirmation required':'Information only'}</p><small>${r.published?.expires?'Expires '+formatTime(r.published.expires):'No expiry'}${r.published&&r.draft?' · Unpublished changes':''}</small></div><span class="hc-tag ${status==='Active'?'success':''}">${status}</span>${B('Edit','ha-edit','pencil','',id)}${r.published&&state.publishPermission!=='author'?TB('Unpublish','ha-unpublish',id,'archive'):''}</article>`;}).join('')}</div><p class="hm-note">Fictional preview · Publishing and read confirmations stay in this mockup. Expiry follows the preview clock in Publishing.</p>`;
}
manageShell=function(){
 const extra=`<button data-action="hm-tab" data-value="updates" aria-pressed="${manageTab==='updates'}">${I('megaphone')}Announcements</button>`;
 if(manageTab!=='updates')return attentionBase.manageShell().replace('</nav>',extra+'</nav>');
 return `<div class="hm-top"><div><span class="hc-category">Manage hub</span><h2>Important announcements</h2><p>Help everyone stay informed, without adding noise.</p></div>${B('Back to hub','hm-home','arrow-left')}</div><nav class="hm-tabs" aria-label="Hub management">${[['media','Media library','images'],['navigation','Navigation','list-tree'],['publishing','Publishing','send']].map(([id,label,icon])=>`<button data-action="hm-tab" data-value="${id}" aria-pressed="false">${I(icon)}${label}</button>`).join('')}${extra}</nav>${updatesManagement()}`;
};
createMenu=function(){attentionBase.createMenu();const grid=dialog.querySelector('.hp-create-grid');if(grid){grid.insertAdjacentHTML('beforeend',`<button data-action="ha-new"><span class="hd-glyph">${I('megaphone')}</span><b>Important announcement</b><p>Share a time-sensitive update, with an optional read confirmation.</p><span class="hp-create-arrow">Create an announcement ${I('arrow-right')}</span></button>`);icons();}};
function editNotice(id){
 if(state.role!=='editor'||edit)return;noticeEditing=id;
 const r=id?noticeRecord(id):null,d=r?.draft||r?.published||{title:'',body:'',owner:'People & Culture',expires:'',ackRequired:false};
 openDialog(id?'Edit announcement':'New announcement','A shared update for all staff.',`<div class="ha-notice-form"><label class="hc-field">Title<input id="ha-title" aria-label="Announcement title" maxlength="90" value="${esc(d.title)}" placeholder="What do people need to know?"></label><label class="hc-field">Announcement<textarea id="ha-body" aria-label="Announcement text" rows="5" maxlength="900" placeholder="Keep it clear, useful and concise.">${esc(d.body)}</textarea></label><div class="hc-two"><label class="hc-field">Published by<select id="ha-owner" aria-label="Announcement owner">${companyTeams.map(t=>`<option ${t.name===d.owner?'selected':''}>${esc(t.name)}</option>`).join('')}</select></label><label class="hc-field">Expires (optional)<input type="datetime-local" id="ha-expiry" aria-label="Announcement expiry" value="${esc(d.expires||'')}"></label></div><label class="hc-checkbox"><input type="checkbox" id="ha-required" ${d.ackRequired?'checked':''}>Ask staff to confirm they’ve read this</label><p class="hc-small hc-muted">${r?.published?'Publishing changes creates a new version and asks for a fresh confirmation when required.':'An expiry automatically removes the update from staff view.'}</p><p id="ha-form-error" class="hm-error" role="alert"></p></div>`,B('Cancel','close')+B('Save draft','ha-save-notice','save','','draft')+(state.publishPermission!=='author'?B('Publish announcement','ha-save-notice','send','primary','publish'):''),'announcement-editor');
}
function saveNotice(publish){
 if(state.role!=='editor'||(publish&&state.publishPermission==='author'))return;
 const d={title:dialog.querySelector('#ha-title').value.trim(),body:dialog.querySelector('#ha-body').value.trim(),owner:dialog.querySelector('#ha-owner').value,expires:dialog.querySelector('#ha-expiry').value,ackRequired:dialog.querySelector('#ha-required').checked};
 const error=!d.title||d.title.length<3?'Add a clear title of at least three characters.':d.body.length<15?'Add an announcement of at least fifteen characters.':d.expires&&(!Number.isFinite(+new Date(d.expires))||d.expires<=attentionNow())?'Choose an expiry after the preview time, or leave it blank.':!noticeEditing&&Object.keys(state.hubAttention.notices).length>=5?'This preview holds five custom announcements. Edit an existing one.':'';
 if(error){dialog.querySelector('#ha-form-error').textContent=error;return;}
 const id=noticeEditing||'notice-'+Date.now().toString(36),r=noticeRecord(id)||{published:null,draft:null,revision:0},c=clone(state);
 c.hubAttention.notices[id]=publish?{published:{...d,posted:attentionNow()},draft:null,revision:r.revision+1}:{...r,draft:d};
 if(!managementCommit(c,publish?'Announcement published in the mockup.':'Announcement draft saved.'))return;
 close();activePageId=null;state.tab='manage';manageTab='updates';render();returnToTop();
}
function decorateNewContent(){
 const keys=new Map(newActivity().filter(x=>x.type!=='home').map(x=>[x.id,x]));
 root.querySelectorAll('[data-action="open-page"],[data-action="hd-article"]').forEach(button=>{const id=button.dataset.action==='hd-article'?'guide-'+button.dataset.value:button.dataset.value;if(!keys.has(id))return;const label=button.querySelector('.hp-eyebrow')||button.querySelector('h3');if(label&&!label.querySelector('.ha-new-tag'))label.insertAdjacentHTML('beforeend','<span class="ha-new-tag">New</span>');});
 const bell=root.querySelector('[data-action="notifications"]');if(bell){bell.setAttribute('aria-label','Company updates');if(newActivity().length)bell.insertAdjacentHTML('beforeend','<span class="ha-bell-dot" aria-hidden="true"></span>');}
}
function setupCompactNavigation(){
 const main=root.querySelector('.hc-main'),rail=root.querySelector('.hp-hub-nav'),hero=root.querySelector('.hp-connected-hero');if(!main||!rail||!hero)return;
 const update=()=>{rail.dataset.stuck=String(hero.getBoundingClientRect().bottom<=main.getBoundingClientRect().top+46);};
 main.addEventListener('scroll',update,{passive:true});update();
}
render=function(){
 const main=root.querySelector('.hc-main'),oldScroll=main?.scrollTop||0,key=(activePageId||state.tab)+'|'+isEditing();
 attentionBase.render();const next=root.querySelector('.hc-main');if(next&&attentionRenderKey===key)next.scrollTop=oldScroll;attentionRenderKey=key;
 if(!isEditing()){decorateNewContent();setupCompactNavigation();}icons();
};
returnToTop=function(){const main=root.querySelector('.hc-main');if(root.dataset.reading==='true'&&main)main.scrollTop=0;else attentionBase.returnToTop();};
handleAction=function(a,v,el){
 ensureAttention();
 if((a.startsWith('ha-')||a==='notifications')&&(edit||preview)){toast('Leave the designer to open company updates.');return;}
 if(['ha-new','ha-edit','ha-save-notice','ha-unpublish'].includes(a)&&state.role!=='editor')return;
 if(a==='notifications'||a==='ha-activity'){activityFilter='new';activityDialog();return;}
 if(a==='ha-feed-filter'){activityFilter=v;activityDialog();return;}
 if(a==='ha-notice'){noticeDialog(v);return;}
 if(a==='ha-open-item'){const item=activityItems().find(x=>x.key===v);if(!item)return;if(item.type==='home')noticeDialog(item.id);else{close();openPage(item.id);}return;}
 if(a==='ha-seen-all'){const keys=newActivity().map(x=>x.key);if(!keys.length)return;activityUndo=keys;if(rememberSeen(keys)){render();activityDialog();}return;}
 if(a==='ha-undo-seen'){if(!activityUndo)return;const c=clone(state);c.hubAttention.seen=c.hubAttention.seen.filter(key=>!activityUndo.includes(key));if(managementCommit(c)){activityUndo=null;render();activityDialog();}return;}
 if(a==='ha-ack'){const r=noticeRecord(v);if(!noticeActive(r)||!r.published.ackRequired)return;const c=clone(state);c.hubAttention.ack[noticeKey(v)]=attentionNow();if(managementCommit(c,'Your read confirmation was recorded in the mockup.')){render();noticeDialog(v);}return;}
 if(a==='ha-new'){editNotice(null);return;}
 if(a==='ha-edit'){editNotice(v);return;}
 if(a==='ha-save-notice'){saveNotice(v==='publish');return;}
 if(a==='ha-unpublish'){if(state.publishPermission==='author')return;const r=noticeRecord(v);if(!r?.published)return;const c=clone(state);c.hubAttention.notices[v]={...r,draft:r.draft||r.published,published:null};if(managementCommit(c,'Announcement unpublished. Its content is kept as a draft.'))render();return;}
 attentionBase.handleAction(a,v,el);
};
stateEnvelope=function(candidate=state){const result=attentionBase.stateEnvelope(candidate);result.modelContent={...result.modelContent,hubImprovements:['Compact sticky navigation','Important announcements and read confirmations','New since last visit']};return result;};
