// Review-only presentation refinements. The original record actions remain local.
const openBeforeClarity=open;
open=function(route,extras={}){clearTimeout(toastTimer);$('#hi-toast').innerHTML='';openBeforeClarity(route,extras);};
function warmImpact(value){return ({'Thank you':'Thank you','Good job':'You made a difference',Impressive:'You inspired us',Exceptional:'Above and beyond'})[value]||value;}
const originalRecognitionCard=recognitionCard;
recognitionCard=function(record=S.recognition){
  let html=originalRecognitionCard({...record,impact:warmImpact(record.impact)});
  if(!record.value)html=html.replace('<span class="hi-badge">A moment worth recognising</span>','');
  return html;
};
S.recognition.impact='Thank you';S.recDetails=false;
choiceDetails['rec.impact']={
  'Thank you':['heart','A simple, personal thank-you'],
  'Good job':['hand-heart','Let them know their work mattered'],
  Impressive:['sparkles','Celebrate the example they set'],
  Exceptional:['trophy','Recognise the extra care they gave']
};
const recognitionBeforeClarity=views[1];
views[1]=()=>{
  const v=recognitionBeforeClarity(),r=S.recognition;
  if(S.step===0){v.body=recipientChooser();v.size='narrow hi-rec-pick';v.sub='Start with the person or team you want to thank.';}
  if(S.step===1){
    const customised=!!r.value||r.impact!=='Thank you';
    v.sub='A few thoughtful words can make someone’s day.';
    v.body=`<div class="hi-compose hi-simple-compose"><div class="hi-rec-message-form"><div class="hi-rec-to"><div class="hi-avatars">${r.mode==='team'?av(recognitionNames()):r.ids.map(id=>av(P(id).name)).join('')}</div><span class="hi-grow"><small>YOUR THANK-YOU IS FOR</small><b>${esc(recognitionNames())}</b></span>${L('Change','rec-step','0')}</div>${field('What would you like to thank them for?','rec.message',r.message,'textarea','Mention the moment and the difference it made.')}<div class="hi-row hi-between hi-rec-writing-help">${L('Help me get started','rec-prompt','','sparkles')}<span id="hi-count" class="hi-small hi-muted">${r.message.length} / 2,000</span></div><details class="hi-rec-options" ${S.recDetails?'open':''}><summary class="cursor-interaction">${icon('sliders-horizontal')}<span><b>${customised?'Card details':'Add a personal touch'}</b><small>${customised?esc([r.value,warmImpact(r.impact)].filter(Boolean).join(' · ')):'Choose a value or change the card heading · optional'}</small></span>${icon('chevron-down')}</summary><div class="hi-rec-options-body"><div class="hi-row hi-between hi-rec-field-label"><span id="hi-rec-value-label">A value worth celebrating</span>${r.value?L('Remove value','rec-clear-value'):''}</div><div class="hi-rec-values" role="group" aria-labelledby="hi-rec-value-label">${values.map(value=>`<button type="button" class="hi-value cursor-interaction" data-action="rec-value" data-value="${esc(value[0])}" aria-pressed="${r.value===value[0]}">${esc(value[0])}</button>`).join('')}</div><div class="hi-rec-impact">${select('Card heading','rec.impact',impacts,r.impact)}</div></div></details><p class="hi-rec-audience">${icon('users')} Shared with colleagues in the Hub after you review it.</p></div><aside class="hi-preview-rail"><span class="hi-label">Your card<span class="hi-dot"></span></span><div id="hi-rec-preview">${recognitionCard()}</div></aside></div>`;
    v.foot=v.foot.replace('Make it personal','Review before sharing').replace('Continue','Review shout-out');
  }
  if(S.step===2)v.body=v.body.replace('VALUE & IMPACT','CARD DETAILS').replace(esc(r.value)+' · '+esc(r.impact),esc([r.value,warmImpact(r.impact)].filter(Boolean).join(' · ')));
  return v;
};
const recNextBeforeClarity=handlers['rec-next'];
handlers['rec-next']=()=>{
  if(S.step!==1)return recNextBeforeClarity();
  const text=S.recognition.message.trim();
  if(text.length<10)return error('Add a few words about what you appreciated — at least 10 characters.');
  if(text.length>2000)return error('Shorten your message to 2,000 characters or fewer.');
  S.step=2;S.error='';render();
};
handlers['rec-clear-value']=()=>{S.recognition.value='';S.dirty=true;render();$('.hi-rec-options summary')?.focus();};
handlers['rec-again']=()=>{S.recognition={mode:'people',ids:[],team:'',value:'',impact:'Thank you',message:''};S.recDetails=false;open(1);};
handlers['rec-prompt']=()=>{
  if(!S.recognition.message.trim()){S.recognition.message='Thank you for ';S.dirty=true;render();}
  const input=$('[data-bind="rec.message"]');input?.focus();input?.setSelectionRange(input.value.length,input.value.length);
};
root.addEventListener('toggle',event=>{if(event.target.matches('.hi-rec-options'))S.recDetails=event.target.open;},true);

const journeyGroups=[
  {title:'Connect',icon:'heart-handshake',ids:[1,2,3,4,5,7,8,9,10,14]},
  {title:'Find information',icon:'search',ids:[11,12,16,17,18,19,36,37]},
  {title:'Make it yours',icon:'user-round',ids:[15,20,21,22,23]},
  {title:'Manage content',icon:'pencil-line',ids:[6,13,24,25,26,27,28,29,30,31,32,33,34,35]}
];
groups.splice(0,groups.length,...journeyGroups.map(group=>group.title));
journeyGroups.forEach((group,index)=>group.ids.forEach(id=>inventory[id-1][2]=index));
const journeyCopy={
  1:'Thank a colleague, a few people or a whole team.',2:'Read thank-you messages, appreciate them and reply.',3:'Welcome new colleagues and celebrate milestones.',4:'Join a conversation or review a hidden comment.',5:'See what’s happening and reserve your place.',6:'Plan an event, invite colleagues and manage attendance.',7:'Look back at a shared event through photos and stories.',8:'Suggest something that could make work better.',9:'Support an idea and follow what happens next.',10:'Have your say and see the results.',11:'Read important updates and confirm you’ve seen them.',12:'Find practical guidance and work through a checklist.',13:'Suggest a correction or help keep guidance up to date.',14:'Find the right person by name, role, team or site.',15:'Let colleagues know a little more about you.',16:'Explore a team’s people, updates and resources.',17:'Follow a shared initiative and your part in it.',18:'Get to the right staff service and follow a request.',19:'Find people, stories, resources and useful answers.',20:'Return to saved items and catch up on new activity.',21:'Choose the topics and teams you want to follow.',22:'Choose which updates you want to hear about.',23:'Keep your most useful destinations close.',24:'Start a story or page with a suitable layout.',25:'Adjust the welcome image, title and action.',26:'Add useful content and choose how it appears.',27:'Arrange sections and blocks on your page.',28:'Choose, organise and describe shared images and files.',29:'Keep the shared Hub navigation clear and useful.',30:'Review the audience, get approval or choose a publishing time.',31:'Compare saved versions and restore one as a draft.',32:'Recover a draft or safely leave unfinished work.',33:'Plan who will write an update and when it should appear.',34:'See which content helps people and what they cannot find.',35:'Explore loading, empty, error and recovery examples.',36:'Browse guides by topic, team or collection.',37:'Catch up on company news and save a story for later.'
};
indexView=function(){
  const query=S.q.trim().toLowerCase();
  const items=inventory.map((v,i)=>({v,id:i+1})).filter(({v,id})=>query?[v[0],v[3],journeyCopy[id],groups[v[2]]].join(' ').toLowerCase().includes(query):(S.group===-1||v[2]===S.group));
  const row=({v,id})=>`<button type="button" class="hi-index-card cursor-interaction" data-action="open" data-value="${id}"><span class="hi-journey-icon">${icon(v[1])}</span><span class="hi-grow"><b>${esc(v[0])}</b><small>${esc(journeyCopy[id])}</small></span>${query?`<span class="hi-journey-category">${groups[v[2]]}</span>`:''}${icon('arrow-right')}</button>`;
  const results=query?items.map(row).join(''):journeyGroups.map((group,i)=>{const subset=items.filter(item=>item.v[2]===i);return subset.length?`<section class="hi-journey-section"><h3>${group.title}</h3>${subset.map(row).join('')}</section>`:'';}).join('');
  return view('What would you like to do?','Choose a task, or search all 37 journeys.',`<div class="hi-index hi-task-index"><nav class="hi-index-nav" aria-label="Journey categories"><button type="button" class="cursor-interaction" data-action="group" data-value="all" aria-pressed="${S.group===-1||!!query}">${icon('grid-2x2')}<span>All journeys</span><small>37</small></button>${journeyGroups.map((group,index)=>`<button type="button" class="cursor-interaction" data-action="group" data-value="${index}" aria-pressed="${!query&&S.group===index}">${icon(group.icon)}<span>${group.title}</span><small>${group.ids.length}</small></button>`).join('')}</nav><div>${search('index','Search all journeys — try “idea”, “event” or “save”',S.q)}<div class="hi-row hi-between hi-results-caption"><span>${query?`${items.length} ${items.length===1?'result':'results'} across all journeys`:`${items.length} journeys`}</span>${query?L('Clear search','journeys-clear','','x'):''}</div><div class="hi-index-list" id="hi-index-results">${results||empty('No journeys match that search','Try a task such as “thank”, “event” or “profile”.','journeys-clear','Clear search')}</div></div></div>`,`<span class="hi-muted">Fictional desktop preview</span>${B('Give a shout-out','open','primary','1','sparkles')}`,'wide hi-journey-browser');
};
handlers.index=()=>open(-1,{group:-1});
handlers.group=value=>{S.group=value==='all'?-1:Number(value);S.q='';render();};
handlers['journeys-clear']=()=>{S.q='';render();$('[data-search="index"]')?.focus();};
handlers.more=()=>open(-1,{group:2});

const pageJourneys=new Set([6,24,25,26,27,29,30,33,40,42]);
const drawerJourneys=new Set([4,13,14,15,20,21,22,23]);
const renderBeforeClarity=render;
render=function(){
  renderBeforeClarity();
  root.dataset.journey=String(S.route);root.dataset.stage=String(S.step);
  const windowEl=$('.hi-window'),backdrop=$('.hi-backdrop');
  if(!windowEl)return;
  const isPage=pageJourneys.has(S.route)&&isEditor()&&S.scenario!=='Permission limited';
  if(isPage){
    windowEl.classList.add('hi-page-surface');backdrop.classList.add('hi-page-backdrop');
    const closeButton=windowEl.querySelector('.hi-dialog-head [data-action="close"]');
    if(closeButton){closeButton.className='hi-button quiet cursor-interaction';closeButton.setAttribute('aria-label','Back to Hub');closeButton.innerHTML=icon('arrow-left')+' Back to Hub';}
  }else if(drawerJourneys.has(S.route)){windowEl.classList.add('hi-task-drawer');backdrop.classList.add('hi-drawer-backdrop');}
  if(S.route===1){windowEl.classList.add('hi-recognition-window');const input=$('[data-bind="rec.message"]');input?.setAttribute('maxlength','2000');input?.setAttribute('placeholder','Thank you for…');}
  icons();
};
