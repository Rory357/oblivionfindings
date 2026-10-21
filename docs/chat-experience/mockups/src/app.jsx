import React,{useState,useEffect,useRef} from 'react';
import {createRoot} from 'react-dom/client';
import {groups} from './screens.js';
import {useCallPreview,createIncomingCallViews} from './incoming-calls.jsx';

const autoScenes={
 M04:{direct:'people',group:'group-create',details:'group-details',invite:'group-invite',remove:'group-remove',leave:'group-leave',transfer:'group-transfer',error:'group-create'},
 M05:{manage:'channel-manage'},M06:{actions:'actions'},
 M07:{attachments:'attachments',uploading:'uploading',failed:'upload-failed',record:'voice-record',preview:'voice-preview',publish:'publish',restricted:'file-restricted'},
 M09:{notification:'notification-centre',expired:'session-expired',denied:'notifications-blocked'},M10:{multi:'switch-client'},
 M12:{team:'family-people',worker:'family-people',invite:'family-invite',history:'family-history',removed:'family-removed'},
 M13:{handoff:'handoff',followup:'followup'},M14:{selector:'conversation-selector'},M18:{invite:'call-invite',end:'end-call'},
 M19:{callback:'callback'},M21:{conflict:'settings-conflict'},M22:{investigator:'investigation'}
};
function readLocation(){const [id,state]=location.hash.slice(1).split('/');const g=groups.find(g=>g.id===id)||groups[0];return {id:g.id,variant:g.variants.find(v=>v.id===state)?.id||g.variants[0].id}}
function readNotes(){try{return JSON.parse(localStorage.getItem('oblivion-chat-design-review-v1'))||{}}catch{return {}}}
export function mountReview(kit){
const {Ctx,Btn,IconBtn,I,Notice,PillTag,Field,Modal,Dialogs,ChatPage,FamilyHome,FamilyInbox,SearchPage,TeamsPage,SettingsPage,CallScreen,EmbeddedPage,ContextPage,ControlPage,AdjacentPage,Shell,Thread,Details}=kit;
const {CallOverlay,IncomingWorkPage}=createIncomingCallViews(kit);
function App(){
 const call=useCallPreview();
 const [scene,setScene]=useState(readLocation);const group=groups.find(g=>g.id===scene.id);const variant=scene.variant;
 const familyRole=['familyHome','familyChat','familyPeople','familyCall'].includes(group.layout)||group.id==='M25'&&variant==='portal';const previousRole=useRef(familyRole);
 useEffect(()=>{if(previousRole.current!==familyRole){call.reset();call.setRecordOpen(false);previousRole.current=familyRole}},[familyRole]);
 useEffect(()=>{if(group.id!=='M25')return;call.reset();if(variant==='missed'||variant==='elsewhere'){call.setResult({id:'alex',name:'Alex Morgan',tone:'purple',reason:variant==='missed'?'Missed call':'Answered elsewhere'});}else{call.receive(['family','portal','group'].includes(variant)?variant:'staff',variant==='busy');}},[group.id,variant]);
 const [device,setDevice]=useState(group.id==='M02'?'phone':'desktop');const [theme,setTheme]=useState('light');
 const [collapsed,setCollapsed]=useState(false),[detail,setDetail]=useState(false),[conv,setConv]=useState('alex');
 const [filter,setFilter]=useState('All'),[search,setSearch]=useState(''),[query,setQuery]=useState('handover');
 const [draft,drafts]=useState({}),[extra,setExtra]=useState({}),[reply,setReply]=useState(false),[dirty,setDirty]=useState(false),[muted,setMuted]=useState(false);
 const [modal,setModal]=useState(null),[autoDismissed,setAutoDismissed]=useState(false),[notification,setNotification]=useState('');
 const [catalog,setCatalog]=useState(false),[notesOpen,setNotesOpen]=useState(false),[notes,setNotes]=useState(readNotes),[resetKey,setResetKey]=useState(0);
 const timer=useRef();const currentNote=notes[group.id]||{status:'Needs review',text:''};
 const toast=message=>{setNotification(message);clearTimeout(timer.current);timer.current=setTimeout(()=>setNotification(''),5500)};
 const go=(id,state)=>{const g=groups.find(g=>g.id===id)||groups[0];const v=g.variants.find(v=>v.id===state)?.id||g.variants[0].id;setScene({id:g.id,variant:v});history.replaceState(null,'','#'+g.id+'/'+v);setModal(null);setAutoDismissed(false);setDetail(false);setReply(false);setSearch('');setFilter('All');setDirty(false);if(g.id==='M02'||g.id==='M24'&&v==='keyboard')setDevice('phone');};
 useEffect(()=>{const fn=()=>{setScene(readLocation());setModal(null);setAutoDismissed(false);setDetail(false)};window.addEventListener('hashchange',fn);return()=>window.removeEventListener('hashchange',fn)},[]);
 useEffect(()=>{document.title=group.id+' · '+group.title+' · Oblivion Care design review'},[group.id]);
 useEffect(()=>{window.scrollTo({top:0,left:0,behavior:'instant'})},[group.id,variant]);
 const close=()=>{setModal(null);setAutoDismissed(true)};
 const setDraft=(id,text)=>drafts(prev=>({...prev,[id]:text}));
 const sent=id=>{const message=draft[id]?.trim();if(!message)return;setExtra(prev=>({...prev,[id]:[...(prev[id]||[]),message]}));setDraft(id,'');setReply(false);toast('Sample message sent in this preview only.')};
 const saveNote=change=>{const value={...notes,[group.id]:{...currentNote,...change}};setNotes(value);try{localStorage.setItem('oblivion-chat-design-review-v1',JSON.stringify(value))}catch{toast('Browser storage is unavailable. Export notes before closing.')}};
 const exportNotes=()=>{const payload={title:'Oblivion Care Chat UI/UX review',date:new Date().toISOString(),scope:'Design approval only. No implementation approval.',groups:groups.map(g=>({id:g.id,title:g.title,...(notes[g.id]||{status:'Needs review',text:''})}))};const url=URL.createObjectURL(new Blob([JSON.stringify(payload,null,2)],{type:'application/json'}));const a=document.createElement('a');a.href=url;a.download='oblivion-chat-design-feedback.json';a.click();setTimeout(()=>URL.revokeObjectURL(url),1000);toast('Review notes exported. Share the downloaded file when ready.')};
 const step=delta=>{const all=groups.flatMap(g=>g.variants.map(v=>({id:g.id,variant:v.id})));const at=all.findIndex(s=>s.id===group.id&&s.variant===variant);const target=all[(at+delta+all.length)%all.length];go(target.id,target.variant)};
 const resetSettings=()=>{setResetKey(k=>k+1);setDirty(false);toast('Unsaved sample settings discarded.')};
 const value={group,variant,go,device,theme,collapsed,setCollapsed,detail,setDetail,conv,setConv,filter,setFilter,search,setSearch,query,setQuery,draft,setDraft,extra,sent,reply,setReply,dirty,setDirty,resetSettings,muted,setMuted,open:setModal,toast,call};
 let page;switch(group.layout){
  case 'incoming':page=<IncomingWorkPage call={call}/>;break;
  case 'familyHome':page=<FamilyHome/>;break;case 'familyInbox':page=<FamilyInbox/>;break;
  case 'familyPeople':page=<Shell family><div className="workspace"><Thread family/></div></Shell>;break;
  case 'search':page=variant==='history'?<ChatPage/>:<SearchPage/>;break;
  case 'teams':page=<TeamsPage/>;break;case 'settings':page=<SettingsPage key={resetKey}/>;break;case 'preferences':page=<SettingsPage personal key={resetKey}/>;break;
  case 'call':page=<CallScreen/>;break;case 'familyCall':page=<CallScreen family/>;break;case 'callError':page=<CallScreen error/>;break;
  case 'embedded':page=<EmbeddedPage/>;break;case 'context':page=<ContextPage/>;break;case 'control':page=<ControlPage/>;break;case 'adjacent':page=<AdjacentPage/>;break;
  default:page=<ChatPage/>;
 }
 const dialog=modal||(!autoDismissed?autoScenes[group.id]?.[variant]:null);const scenario=group.variants.find(v=>v.id===variant);const total=groups.reduce((n,g)=>n+g.variants.length,0);
 return <Ctx.Provider value={value}><div className={'review '+theme}>
  <header className="reviewbar"><div className="reviewbrand"><span><I name="chat" size={16}/></span><div>Chat design review<div className="small-muted">Oblivion Care · Web first</div></div></div>
   <div className="control"><label htmlFor="screen-group">Screen group</label><select id="screen-group" value={group.id} onChange={e=>go(e.target.value)}>{groups.map(g=><option key={g.id} value={g.id}>{g.id} · {g.title}</option>)}</select></div>
   <div className="control"><label htmlFor="scenario">Scenario</label><select id="scenario" value={variant} onChange={e=>go(group.id,e.target.value)}>{group.variants.map(v=><option key={v.id} value={v.id}>{v.label}</option>)}</select></div>
   <div className="reviewactions"><div className="review-controls"><select aria-label="Preview width" value={device} onChange={e=>setDevice(e.target.value)}><option value="desktop">Desktop</option><option value="tablet">Tablet · 820</option><option value="phone">Phone web · 390</option><option value="narrow">Small web · 320</option></select><IconBtn name={theme==='light'?'moon':'sun'} label={theme==='light'?'Switch to dark theme':'Switch to light theme'} onClick={()=>setTheme(t=>t==='light'?'dark':'light')}/></div><Btn small icon="grid" onClick={()=>setCatalog(true)}>All screens</Btn><Btn small icon="edit" className="hide-small" onClick={()=>setNotesOpen(true)}>Review notes</Btn></div>
  </header>
  <div className="reviewmeta"><div className="row"><span className="review-badge">Interactive mockup</span><strong>{group.section}</strong><span className="meta-detail">{group.note}</span></div><div className="row"><span className="scene-count">{group.id} · {group.variants.findIndex(v=>v.id===variant)+1}/{group.variants.length}</span><IconBtn name="left" label="Previous scenario" onClick={()=>step(-1)}/><IconBtn name="right" label="Next scenario" onClick={()=>step(1)}/></div></div>
  <div className="call-review-tools"><span><I name="phone" size={14}/> Call preview</span><select aria-label="Preview an incoming call" value="" onChange={e=>call.receive(e.target.value)}><option value="" disabled>Show a call over this page…</option>{['familyHome','familyChat','familyPeople','familyCall'].includes(group.layout)||group.id==='M25'&&variant==='portal'?<option value="portal">Care team calling family</option>:<><option value="staff">Staff call · Alex Morgan</option><option value="family">Family call · Jo Taylor</option><option value="group">Group call · Afternoon team</option></>}</select>{call.incoming&&<select aria-label="Preview incoming call outcome" value="" onChange={e=>call.resolve(e.target.value)}><option value="" disabled>Preview a call outcome…</option><option>Missed call</option><option>Cancelled by caller</option><option>Answered elsewhere</option></select>}{(call.incoming||call.active||call.result)&&<Btn small onClick={call.reset}>Clear call preview</Btn>}<span className="call-review-hint">Mockup controls · No real ringing or microphone access</span></div>
  <div className="canvas-wrap"><div className={'stage '+device+(variant==='large'?' large-text':'')+(variant==='reduced'?' reduced':'')+((call.incoming||call.active||call.result)?' has-call-dock':'')} style={{'--call-dock-height':call.height+'px'}} data-screen={group.id} data-scenario={variant}>
   <div key={group.id+'/'+variant}>{page}</div><CallOverlay call={call}/>{dialog&&<Dialogs key={group.id+'/'+variant+'/'+dialog} kind={dialog} onClose={close}/>} 
  </div></div>
  <div className="review-note"><span>Sample people and data · No real messages, calls, uploads or settings changes</span><span className="secondary">Native iOS / Android app is a later phase</span><button className="linkbtn" onClick={()=>setNotesOpen(true)}>Leave feedback</button></div>
  <section className="review-details"><div><h3>{group.id} · {group.title}</h3><p>{scenario.label} · {group.variants.length} scenarios in this group. Use the scenario selector for errors, access restrictions and secondary flows.</p></div><div><h3>Design coverage</h3><p>Inventory: {group.surfaces.join(', ')}<br/>Acceptance: {group.checks.join(', ')}</p></div><div><h3>Awaiting design review</h3><p>{currentNote.status}. Policy values labelled “design” or “pending” remain proposals. No implementation begins from this preview.</p></div></section>
  {catalog&&<div className="catalog-shade"><section className="catalog" role="dialog" aria-modal="true" aria-label="All mockup screens"><div className="catalog-head"><div className="grow"><h2>Every conversation, one shared experience.</h2><p>{groups.length} screen groups · {total} scenarios · desktop, tablet and mobile browser · light and dark</p></div><IconBtn name="x" label="Close all screens" onClick={()=>setCatalog(false)}/></div><div className="catalog-grid">{groups.map(g=><button className="catalog-item" key={g.id} onClick={()=>{go(g.id);setCatalog(false)}}><span className="id">{g.id} / {g.section}</span><h3>{g.title}</h3><p>{g.note}</p><div className="row between"><span>{g.variants.length} scenarios</span><span>{notes[g.id]?.status||'Needs review'} <I name="arrow" size={11}/></span></div></button>)}</div><div className="review-form"><Notice>Start with M01–M03, then family M10–M14, calls M18–M20 and settings M21–M23. M25 shows incoming calls while working elsewhere. Every group can also be viewed at phone browser width. The native app comes later.</Notice><Btn onClick={exportNotes} icon="download">Export all review notes</Btn></div></section></div>}
  {notesOpen&&<div className="catalog-shade"><section className="catalog" role="dialog" aria-modal="true" aria-label="Design review notes" style={{maxWidth:650}}><div className="catalog-head"><div className="grow"><h2>{group.id} · Review notes</h2><p>{group.title}</p></div><IconBtn name="x" label="Close review notes" onClick={()=>setNotesOpen(false)}/></div><div className="review-form"><Field label="Design review status"><select value={currentNote.status} onChange={e=>saveNote({status:e.target.value})}><option>Needs review</option><option>Changes requested</option><option>Design approved</option></select></Field><Field label="Feedback for this screen group"><textarea value={currentNote.text} onChange={e=>saveNote({text:e.target.value})} placeholder="Tell me what to keep or change. Include the scenario name if useful."/></Field><Notice>Notes stay in this browser until you export them. Design approval here does not authorise implementation.</Notice><div className="row between"><Btn icon="download" onClick={exportNotes}>Export all notes</Btn><Btn kind="primary" onClick={()=>setNotesOpen(false)}>Done</Btn></div></div></section></div>}
  {notification&&<div className="toast" role="status"><I name="check" size={16}/><span>{notification}</span><IconBtn name="x" label="Dismiss message" onClick={()=>setNotification('')}/></div>}
 </div></Ctx.Provider>
}
createRoot(document.getElementById('root')).render(<App/>);
}
