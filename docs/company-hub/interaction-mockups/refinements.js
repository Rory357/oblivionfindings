// Local preview controls. Every choice uses the existing draft/state handlers.
const choiceDetails={
  role:{Staff:['user-round','Read, take part and recognise colleagues'],Editor:['pencil-line','Create and manage shared Hub content'],HR:['badge-check','Manage individual birthday sharing'],Moderator:['shield-check','Review and restore hidden comments']},
  scenario:{Ready:['circle-check','The everyday experience'],Loading:['loader-circle','Waiting for content to arrive'],Empty:['inbox','A useful next step when there is no content'],'Inline validation':['circle-alert','Help with missing or incorrect details'],'Recoverable error':['rotate-ccw','Keep your work and try again'],'Permission limited':['lock-keyhole','Explain who can perform this action'],'Stale changes':['git-compare-arrows','Resolve a newer saved version']},
  'rec.impact':{'Thank you':['heart','A thoughtful gesture'],'Good job':['thumbs-up','Something done well'],Impressive:['sparkles','A standout contribution'],Exceptional:['trophy','An extraordinary difference']},
  'hero.height':{Compact:['panel-top','Keep content close'],Comfortable:['rectangle-horizontal','A little more breathing room']},
  publishMode:{'Publish now':['send','Share with your audience now'],'Submit for review':['message-square','Ask an editor to check the draft'],'Schedule publication':['calendar-clock','Choose when it becomes visible']},
  pageType:{'News story':['newspaper','An update with a clear story'],'Company page':['layout-template','Information people return to'],'Knowledge resource':['book-open','Practical answers and guidance'],'Team space':['users','A home for a team’s work'],Campaign:['flag','Bring a shared initiative together']},
  'hero.icon':{sparkles:['sparkles','A bright welcome'],users:['users','People and connection'],heart:['heart','Care and appreciation'],'building-2':['building-2','Our organisation'],'book-open':['book-open','Learning and resources']},
  blockLayout:{Mosaic:['panels-top-left','Lead with one story'],'Equal columns':['columns-2','Give stories equal space'],'Single story':['panel-top','Focus on one story'],Cards:['layout-grid','Visual cards'],List:['list','A compact reading list']},
  designWidth:{Full:['rectangle-horizontal','12 of 12 columns'],'Two thirds':['panel-left','8 of 12 columns'],Half:['columns-2','6 of 12 columns'],'One third':['columns-3','4 of 12 columns']}
};
const inlineKeys=new Set(['rec.impact','hero.height','designWidth','blockLayout','publishMode']);
const recognitionBeforeRefinement=views[1];
views[1]=()=>{
  const v=recognitionBeforeRefinement();if(S.step!==1)return v;
  const r=S.recognition;
  v.body=`<div class="hi-compose"><div class="hi-rec-message-form"><div class="hi-row hi-between hi-rec-for"><span class="hi-small">For <b>${esc(recognitionNames())}</b></span>${L('Change','rec-step','0')}</div>${field('What did they do, and why did it matter?','rec.message',r.message,'textarea')}<div class="hi-row hi-between hi-rec-writing-help">${L('Help me get started','rec-prompt','','sparkles')}<span id="hi-count" class="hi-small hi-muted">${r.message.length} / 2,000</span></div><span class="hi-rec-field-label" id="hi-rec-value-label">Which value did they bring to life?</span><div class="hi-rec-values" role="group" aria-labelledby="hi-rec-value-label">${values.map(value=>`<button type="button" class="hi-value cursor-interaction" data-action="rec-value" data-value="${esc(value[0])}" aria-pressed="${r.value===value[0]}">${esc(value[0])}</button>`).join('')}</div><div class="hi-rec-impact">${select('How would you describe the impact?','rec.impact',impacts,r.impact)}</div></div><aside class="hi-preview-rail"><span class="hi-label">Live card preview<span class="hi-dot"></span></span><div id="hi-rec-preview">${recognitionCard()}</div><p class="hi-small hi-muted" style="margin-top:15px">A specific moment makes it personal. Your words are the part that matters most.</p></aside></div>`;
  return v;
};
const choiceId=key=>'hi-choice-'+key.replace(/[^a-zA-Z0-9_-]/g,'-');
function choiceMeta(key,value){
  const person=people.find(p=>p.name===value);
  if(person)return {label:value,person,description:person.role+' · '+person.site};
  const detail=choiceDetails[key]?.[value];
  const label=key==='rec.impact'?warmImpact(value):key==='hero.icon'?({sparkles:'Sparkles',users:'People',heart:'Heart','building-2':'Organisation','book-open':'Book'}[value]||value):value;
  return {label,icon:detail?.[0],description:detail?.[1]||''};
}
function choiceMark(meta){return meta.person?av(meta.person.name):meta.icon?`<span class="hi-choice-mark">${icon(meta.icon)}</span>`:'';}
function choiceField(label,key,opts,val){
  const selected=opts.includes(val)?val:opts[0],id=choiceId(key);
  if(inlineKeys.has(key))return `<div class="hi-field hi-choice-field"><span id="${id}-label">${esc(label)}</span><div class="hi-inline-choices ${key==='publishMode'?'vertical':''}" role="radiogroup" aria-labelledby="${id}-label">${opts.map(o=>{const m=choiceMeta(key,o);return `<button type="button" class="hi-inline-choice cursor-interaction" role="radio" aria-checked="${o===selected}" tabindex="${o===selected?'0':'-1'}" data-choice="${key}" data-value="${esc(o)}">${choiceMark(m)}<span class="hi-choice-copy"><b>${esc(m.label)}</b>${m.description?`<small>${esc(m.description)}</small>`:''}</span><span class="hi-choice-tick">${icon('check')}</span></button>`;}).join('')}</div></div>`;
  return `<div class="hi-field hi-choice-field"><span id="${id}-label">${esc(label)}</span>${pickerButton(label,key,opts,selected,'bind')}</div>`;
}
function pickerButton(label,key,opts,val,kind){
  const id=choiceId(key),m=choiceMeta(key,val);
  return `<button type="button" class="hi-picker-trigger ${kind==='control'?'compact':''} cursor-interaction" id="${id}" role="combobox" aria-label="${esc(label)}: ${esc(m.label)}" aria-haspopup="listbox" aria-expanded="false" aria-controls="${id}-list" data-picker="${key}" data-picker-kind="${kind}" data-options="${esc(JSON.stringify(opts))}" data-current="${esc(val)}">${choiceMark(m)}<span class="hi-choice-copy">${kind==='control'?`<small>${key==='role'?'View as':'State'}</small>`:''}<span>${esc(m.label)}</span></span>${icon('chevron-down')}</button>`;
}
toolbar=function(){
  $('#hi-toolbar').innerHTML=`<div class="hi-toolbar"><div class="hi-row"><span class="hi-dot"></span><b>Interaction collection</b><span class="hi-muted hi-demo-caption">Fictional demo · no live changes</span></div><div class="hi-row">${B('All journeys','index','','','grid-2x2')}${pickerButton('Preview permission','role',['Staff','Editor','HR','Moderator'],S.role,'control')}${pickerButton('Preview state','scenario',['Ready','Loading','Empty','Inline validation','Recoverable error','Permission limited','Stale changes'],S.scenario,'control')}${IB('Change light or dark appearance','theme',S.theme==='dark'?'sun':'moon')}</div></div>`;
};
let activePicker=null;
function closePicker(restore=true){
  if(!activePicker)return;
  const {trigger,popup}=activePicker;activePicker=null;
  trigger.setAttribute('aria-expanded','false');trigger.removeAttribute('aria-activedescendant');
  if(popup.matches(':popover-open'))popup.hidePopover();popup.remove();
  if(restore&&trigger.isConnected)trigger.focus({preventScroll:true});
}
const originalRender=render;
render=function(){closePicker(false);originalRender();refineRecognition();};
function refineRecognition(){
  $$('.hi-value').forEach(button=>{const v=values.find(x=>x[0]===button.dataset.value);if(v)button.innerHTML=`<span class="hi-choice-mark">${icon(v[1])}</span><span class="hi-choice-copy"><b>${esc(v[0])}</b><small>${esc(v[2])}</small></span><span class="hi-choice-tick">${icon('check')}</span>`;});
  const segment=$('.hi-segment:has([data-action="recipient-mode"])');
  if(segment){segment.classList.add('hi-recipient-mode');segment.querySelectorAll('button').forEach(button=>{const team=button.dataset.value==='team';button.classList.add('cursor-interaction');button.innerHTML=`<span class="hi-choice-mark">${icon(team?'users':'user-round')}</span><span class="hi-choice-copy"><b>${team?'Whole team':'People'}</b><small>${team?'Celebrate everyone together':'One person or a few colleagues'}</small></span>`;});}
  icons();
}
function openPicker(trigger){
  if(activePicker?.trigger===trigger){closePicker();return;}
  closePicker(false);
  const key=trigger.dataset.picker,options=JSON.parse(trigger.dataset.options),popup=document.createElement('div');
  popup.className='hi-picker-menu';popup.setAttribute('popover','manual');
  const searchable=options.length>=6&&trigger.dataset.pickerKind!=='control';
  popup.innerHTML=`${searchable?`<div class="hi-picker-search">${icon('search')}<input type="search" role="combobox" aria-autocomplete="list" aria-expanded="true" aria-controls="${choiceId(key)}-list" aria-label="Search ${esc(trigger.getAttribute('aria-label').split(':')[0].toLowerCase())}" placeholder="Search by name, role or site" autocomplete="off"></div>`:''}<div class="hi-picker-list" role="listbox" id="${choiceId(key)}-list" aria-label="${esc(trigger.getAttribute('aria-label').split(':')[0])}"></div><div class="hi-picker-hint">${icon('corner-down-left')} Choose <span>↑ ↓ to browse · Esc to close</span></div>`;
  (trigger.closest('.hi-window')||root).append(popup);
  activePicker={trigger,popup,key,options,current:trigger.dataset.current,kind:trigger.dataset.pickerKind,filtered:options,active:Math.max(0,options.indexOf(trigger.dataset.current)),query:'',searchable};
  trigger.setAttribute('aria-expanded','true');paintPicker();popup.showPopover();positionPicker();
  if(searchable)popup.querySelector('input').focus({preventScroll:true});else trigger.focus({preventScroll:true});
}
function paintPicker(){
  const p=activePicker;if(!p)return;
  const list=p.popup.querySelector('.hi-picker-list');
  list.innerHTML=p.filtered.length?p.filtered.map((value,index)=>{const m=choiceMeta(p.key,value);return `<div class="hi-picker-option cursor-interaction" id="${choiceId(p.key)}-option-${index}" role="option" aria-selected="${value===p.current}" data-option="${esc(value)}" data-option-index="${index}">${choiceMark(m)}<span class="hi-choice-copy"><b>${esc(m.label)}</b>${m.description?`<small>${esc(m.description)}</small>`:''}</span><span class="hi-option-check">${value===p.current?icon('check'):''}</span></div>`;}).join(''):`<div class="hi-picker-empty" role="status">${icon('search')}<b>No matches for “${esc(p.query)}”</b><span>Try a name, role or site.</span><button type="button" class="hi-link cursor-interaction" data-picker-clear>Clear search</button></div>`;
  icons();markActive(false);
}
function markActive(scroll=true){
  const p=activePicker;if(!p)return;
  p.popup.querySelectorAll('[data-option]').forEach((node,i)=>node.dataset.active=String(i===p.active));
  const target=p.popup.querySelector('input')||p.trigger;
  if(p.filtered.length)target.setAttribute('aria-activedescendant',`${choiceId(p.key)}-option-${p.active}`);else target.removeAttribute('aria-activedescendant');
  if(scroll)p.popup.querySelector('[data-active="true"]')?.scrollIntoView({block:'nearest'});
}
function positionPicker(){
  const p=activePicker;if(!p)return;
  const r=p.trigger.getBoundingClientRect(),bounds=root.getBoundingClientRect();
  const leftEdge=Math.max(8,bounds.left+8),rightEdge=Math.min(innerWidth-8,bounds.right-8),topEdge=Math.max(8,bounds.top+8),bottomEdge=Math.min(innerHeight-8,bounds.bottom-8);
  const width=Math.min(Math.max(r.width,p.kind==='control'?294:310),rightEdge-leftEdge);
  p.popup.style.width=width+'px';
  const above=r.top-topEdge-8,below=bottomEdge-r.bottom-8,flip=below<260&&above>below;
  p.popup.style.maxHeight=Math.max(140,Math.min(450,flip?above:below))+'px';
  p.popup.style.left=Math.min(Math.max(leftEdge,r.left),rightEdge-width)+'px';
  const height=p.popup.getBoundingClientRect().height;
  p.popup.style.top=Math.max(topEdge,flip?r.top-height-7:r.bottom+7)+'px';
  p.popup.dataset.side=flip?'above':'below';
}
function applyChoice(key,value,kind,trigger){
  const content=$('.hi-dialog-content'),scroll=content?.scrollTop||0;
  closePicker(false);
  if(kind==='control'){S[key]=value;S.error=value==='Inline validation'?'Check the highlighted details before continuing.':'';}
  else{S.form[key]=value;S.dirty=true;onBind(key,value,trigger);}
  render();
  if($('.hi-dialog-content'))$('.hi-dialog-content').scrollTop=scroll;
  const next=$(`[data-picker="${CSS.escape(key)}"]`)||$(`[data-choice="${CSS.escape(key)}"][data-value="${CSS.escape(value)}"]`);
  next?.focus({preventScroll:true});
}
root.addEventListener('click',event=>{
  const trigger=event.target.closest('[data-picker]'),choice=event.target.closest('[data-choice]'),option=event.target.closest('[data-option]'),clear=event.target.closest('[data-picker-clear]');
  if(trigger&&!trigger.disabled){event.stopImmediatePropagation();openPicker(trigger);}
  else if(choice&&!choice.disabled){event.stopImmediatePropagation();applyChoice(choice.dataset.choice,choice.dataset.value,'bind',choice);}
  else if(option&&activePicker){event.stopImmediatePropagation();applyChoice(activePicker.key,option.dataset.option,activePicker.kind,activePicker.trigger);}
  else if(clear&&activePicker){event.stopImmediatePropagation();const input=activePicker.popup.querySelector('input');input.value='';input.dispatchEvent(new Event('input',{bubbles:true}));input.focus();}
},true);
document.addEventListener('pointerdown',event=>{if(activePicker&&!activePicker.popup.contains(event.target)&&!activePicker.trigger.contains(event.target))closePicker(false);},true);
root.addEventListener('input',event=>{
  if(!activePicker||event.target!==activePicker.popup.querySelector('input'))return;
  event.stopImmediatePropagation();const p=activePicker;p.query=event.target.value;
  p.filtered=p.options.filter(value=>{const m=choiceMeta(p.key,value);return `${m.label} ${m.description}`.toLowerCase().includes(p.query.toLowerCase());});p.active=0;paintPicker();positionPicker();
},true);
root.addEventListener('pointermove',event=>{const option=event.target.closest('[data-option-index]');if(activePicker&&option){activePicker.active=Number(option.dataset.optionIndex);markActive(false);}});
root.addEventListener('keydown',event=>{
  const trigger=event.target.closest('[data-picker]'),choice=event.target.closest('[data-choice]');
  if(activePicker){
    const p=activePicker;if(event.key==='Escape'){event.preventDefault();event.stopImmediatePropagation();closePicker();return;}
    if(event.key==='Tab'){
      event.preventDefault();event.stopImmediatePropagation();const trigger=p.trigger;closePicker();
      const scope=trigger.closest('.hi-window')||$('#hi-toolbar');const all=[...scope.querySelectorAll('button:not(:disabled),input:not(:disabled),textarea:not(:disabled),[tabindex="0"]')].filter(el=>el.tabIndex>=0&&el.offsetParent!==null);
      const index=all.indexOf(trigger),next=all[(index+(event.shiftKey?-1:1)+all.length)%all.length];next?.focus();return;
    }
    if(['ArrowDown','ArrowUp','Home','End'].includes(event.key)&&(!(event.target.tagName==='INPUT')||!['Home','End'].includes(event.key))){
      event.preventDefault();event.stopImmediatePropagation();const n=p.filtered.length;if(n)p.active=event.key==='Home'?0:event.key==='End'?n-1:(p.active+(event.key==='ArrowDown'?1:-1)+n)%n;markActive();return;
    }
    if(event.key==='Enter'||(event.key===' '&&event.target.tagName!=='INPUT')){event.preventDefault();event.stopImmediatePropagation();if(p.filtered.length)applyChoice(p.key,p.filtered[p.active],p.kind,p.trigger);return;}
    if(event.key.length===1&&!event.ctrlKey&&!event.metaKey&&event.target.tagName!=='INPUT'){
      event.preventDefault();event.stopImmediatePropagation();p.query=Date.now()-(p.typedAt||0)<650?p.query+event.key:event.key;p.typedAt=Date.now();const index=p.filtered.findIndex(value=>choiceMeta(p.key,value).label.toLowerCase().startsWith(p.query.toLowerCase()));if(index>=0){p.active=index;markActive();}return;
    }
  }
  if(trigger&&['ArrowDown','ArrowUp','Home','End'].includes(event.key)){
    event.preventDefault();event.stopImmediatePropagation();openPicker(trigger);if(event.key==='Home')activePicker.active=0;if(event.key==='End')activePicker.active=activePicker.filtered.length-1;markActive();
  }
  if(choice&&['ArrowDown','ArrowUp','ArrowLeft','ArrowRight','Home','End'].includes(event.key)){
    event.preventDefault();event.stopImmediatePropagation();const all=[...choice.closest('[role="radiogroup"]').querySelectorAll('[data-choice]')],index=all.indexOf(choice);const next=all[event.key==='Home'?0:event.key==='End'?all.length-1:(index+(['ArrowRight','ArrowDown'].includes(event.key)?1:-1)+all.length)%all.length];applyChoice(next.dataset.choice,next.dataset.value,'bind',next);
  }
},true);
window.addEventListener('resize',()=>closePicker());
window.addEventListener('scroll',event=>{if(activePicker&&!activePicker.popup.contains(event.target))closePicker(false);},true);
