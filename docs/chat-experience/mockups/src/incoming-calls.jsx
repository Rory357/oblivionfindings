import React,{useEffect,useRef,useState} from 'react';

// Synthetic review state only. This module never captures media or contacts a service.
const callers={
 staff:{id:'alex',name:'Alex Morgan',initials:'AM',role:'Support worker · Kōwhai House',kind:'Staff voice call',audience:'Just you and Alex',tone:'purple'},
 family:{id:'jo-sam',name:'Jo Taylor',initials:'JT',role:'Sam Taylor’s mother',kind:'Family voice call',audience:'About Sam Taylor · Calling the care team',tone:'green'},
 portal:{id:'care-sam',name:'Mere Thompson',initials:'MT',role:'Sam’s care team · Cover for Alex',kind:'Care-team voice call',audience:'About Sam Taylor · Just you and Mere',tone:'green'},
 group:{id:'afternoon',name:'Kōwhai afternoon team',initials:'',role:'Alex Morgan is inviting you',kind:'Group voice call',audience:'Alex Morgan, Hana Wilson and you',tone:'purple'},
 rohan:{id:'rohan',name:'Rohan Patel',initials:'RP',role:'Site lead · Kōwhai House',kind:'Staff voice call',audience:'Just you and Rohan',tone:'blue'}
};

export function useCallPreview(){
 const [incoming,setIncoming]=useState(null),[active,setActive]=useState(null),[result,setResult]=useState(null);
 const [expanded,setExpanded]=useState(false),[confirmSwitch,setConfirmSwitch]=useState(false),[seconds,setSeconds]=useState(0);
 const [silent,setSilent]=useState(false),[messageOpen,setMessageOpen]=useState(false),[messages,setMessages]=useState({}),[messageDrafts,setMessageDrafts]=useState({});
 const [workDrafts,setWorkDrafts]=useState({staff:'Confirm Saturday’s visit arrangements with Jo before publishing.',portal:'We would like to visit Sam on Saturday afternoon.'});
 const [recordOpen,setRecordOpen]=useState(false),[savedRecord,setSavedRecord]=useState(null);
 const [familyNoteDraft,setFamilyNoteDraft]=useState(''),[shareFamilyNote,setShareFamilyNote]=useState(false);
 const [announcement,setAnnouncement]=useState(''),[height,setHeight]=useState(0);
 const restoreTarget=useRef(null),cardRef=useRef(null),connectionTimer=useRef(null);
 const restoreFocus=()=>{requestAnimationFrame(()=>{if(restoreTarget.current?.isConnected)restoreTarget.current.focus()})};
 const receive=(type='staff',busy=false)=>{
  if(busy)clearTimeout(connectionTimer.current);
  restoreTarget.current=document.activeElement;
  const call={...(typeof type==='object'?type:callers[type]),status:'ringing'};
  setIncoming(call);setResult(null);setSilent(false);setConfirmSwitch(false);
  if(busy){setActive({...callers.rohan,status:'active',muted:false});setSeconds(272)}
  setAnnouncement(`Incoming ${call.kind.toLowerCase()} from ${call.name}. Call controls are available. Press Alt Shift C to reach them.`);
 };
 const resolve=reason=>{if(!incoming)return;setResult({...incoming,reason});setIncoming(null);setConfirmSwitch(false);setAnnouncement(`${incoming.name}: ${reason}. Your work is still here.`);restoreFocus()};
 const answer=()=>{
  if(!incoming)return;
  if(active&&!confirmSwitch){setConfirmSwitch(true);return}
  const answered={...incoming,status:'connecting',muted:false};
  setActive(answered);setIncoming(null);setResult(null);setExpanded(false);setConfirmSwitch(false);setSeconds(0);setMessageOpen(false);
  setAnnouncement(`Connecting to ${answered.name}.`);
  connectionTimer.current=setTimeout(()=>{setActive(c=>c?.id===answered.id?{...c,status:'active'}:c);setAnnouncement(`Connected to ${answered.name}.`)},650);
  requestAnimationFrame(()=>cardRef.current?.querySelector('button')?.focus());
 };
 const end=()=>{clearTimeout(connectionTimer.current);if(active)setResult({...active,reason:'Call ended'});setActive(null);setExpanded(false);setMessageOpen(false);setConfirmSwitch(false);setAnnouncement('Call ended. Your work is still here.');restoreFocus()};
 const reset=()=>{clearTimeout(connectionTimer.current);setIncoming(null);setActive(null);setResult(null);setExpanded(false);setMessageOpen(false);setConfirmSwitch(false);setAnnouncement('');setSeconds(0)};
 useEffect(()=>{if(active?.status!=='active')return;const timer=setInterval(()=>setSeconds(s=>s+1),1000);return()=>clearInterval(timer)},[active?.status,active?.id]);
 useEffect(()=>()=>clearTimeout(connectionTimer.current),[]);
 useEffect(()=>{const key=e=>{if(e.altKey&&e.shiftKey&&e.code==='KeyC'){e.preventDefault();cardRef.current?.querySelector('button')?.focus()}};document.addEventListener('keydown',key);return()=>document.removeEventListener('keydown',key)},[]);
 const send=()=>{const id=active?.id,text=messageDrafts[id]?.trim();if(!id||!text)return;setMessages(v=>({...v,[id]:[...(v[id]||[]),text]}));setMessageDrafts(v=>({...v,[id]:''}));setAnnouncement('Message sent in this call conversation.')};
 return {incoming,active,result,expanded,setExpanded,confirmSwitch,setConfirmSwitch,seconds,silent,setSilent,messageOpen,setMessageOpen,messages,messageDrafts,setMessageDrafts,send,workDrafts,setWorkDrafts,recordOpen,setRecordOpen,savedRecord,setSavedRecord,familyNoteDraft,setFamilyNoteDraft,shareFamilyNote,setShareFamilyNote,announcement,height,setHeight,cardRef,receive,resolve,answer,end,reset,setActive,setResult};
}

export function createIncomingCallViews({Btn,IconBtn,I,Avatar,Notice,PillTag,Field,Modal,Shell,useReview,CallConversation}){
 function RecordReview({call}){
  const [ack,setAck]=useState(false),[step,setStep]=useState('summary');
  const share=call.shareFamilyNote,setShare=call.setShareFamilyNote,familyText=call.familyNoteDraft,setFamilyText=call.setFamilyNoteDraft,summary=call.workDrafts.staff,setSummary=text=>call.setWorkDrafts(v=>({...v,staff:text}));
  const save=()=>{call.setSavedRecord({summary,share,familyText});call.setRecordOpen(false)};
  return <Modal title={step==='summary'?'Save communication':'Share a Family Note'} subtitle={'Sam Taylor · '+(step==='summary'?'Staff record':'Family-visible update')} icon={step==='summary'?'filecheck':'users'} onClose={()=>call.setRecordOpen(false)} footer={<><Btn onClick={()=>step==='family'?setStep('summary'):call.setRecordOpen(false)}>{step==='family'?'Back':'Keep editing'}</Btn><Btn kind="primary" disabled={!summary.trim()||(step==='family'&&(!ack||!familyText.trim()))} onClick={()=>step==='summary'&&share?setStep('family'):save()}>{step==='summary'&&share?'Review Family Note':step==='family'?'Save both notes':'Save record'}</Btn></>}>
   <div className="compact-record-review">
   {step==='summary'?<><p className="record-destination-line"><I name="lock" size={15}/><span><strong>Client profile → Communication Record</strong><br/>Authorised staff only</span></p><Field label="Reviewed summary"><textarea rows={3} value={summary} onChange={e=>setSummary(e.target.value)}/></Field><label className="checkbox-line"><input type="checkbox" checked={share} onChange={e=>{setShare(e.target.checked);setAck(false)}}/>Also create a shared Family Note</label></>:<><p className="record-destination-line"><I name="users" size={15}/><span><strong>Client profile → Family Notes</strong><br/>Eligible family members may see this, including relatives outside the call.</span></p><Field label="Family-visible note"><textarea rows={3} value={familyText} onChange={e=>{setFamilyText(e.target.value);setAck(false)}} placeholder="Write the agreed update for the family…"/></Field><label className="checkbox-line"><input type="checkbox" checked={ack} onChange={e=>setAck(e.target.checked)}/>I have reviewed the wording and family audience.</label></>}
   </div>
  </Modal>;
 }
 function CallOverlay({call}){
  const {incoming,active,result}=call;const {actor,s,open}=useReview();const groupCall=(s?.callSession?.members?.length||0)>2;const finishCall=()=>groupCall?open('leave-call'):call.end();
  const dock=useRef(null);
  useEffect(()=>{if(!dock.current){call.setHeight(0);return}const el=dock.current;const observer=new ResizeObserver(()=>call.setHeight(el.getBoundingClientRect().height));observer.observe(el);return()=>observer.disconnect()},[!!incoming,!!active,!!result]);
  const duration=Math.floor(call.seconds/60).toString().padStart(2,'0')+':'+(call.seconds%60).toString().padStart(2,'0');
  const toggleMute=()=>call.setActive(c=>({...c,muted:!c.muted}));
  return <><span className="sr-only" role="status" aria-live="polite" aria-atomic="true">{call.announcement}</span>
   {(incoming||active||result)&&<div ref={dock} className="global-call-dock" data-call-state={incoming?'incoming':active?'active':'result'}>
    <div ref={call.cardRef}>
    {incoming&&<section className={'incoming-card '+(incoming.tone==='green'?'family':'')} role="region" aria-label={'Incoming call from '+incoming.name}>
     <div className="incoming-label"><span><I name="incoming" size={16}/>{incoming.kind}</span><IconBtn name={call.silent?'belloff':'volume'} label={call.silent?'Unmute ringtone':'Silence ringtone'} aria-pressed={call.silent} onClick={()=>call.setSilent(v=>!v)}/></div>
     <div className="incoming-person"><span className={'incoming-avatar '+(call.silent?'silent':'')}><Avatar name={incoming.initials} icon={incoming.id==='afternoon'?'users':null} tone={incoming.tone} size="lg"/></span><div><h2>{incoming.name}</h2><p>{incoming.role}</p></div></div>
     <p className="incoming-context"><I name={incoming.tone==='green'?'shield':'lock'} size={14}/>{incoming.audience}</p>
     <div className="incoming-status"><span className="call-status-dot"/>{call.silent?'Ringtone silenced · Still calling':'Incoming call…'}</div>
     {active&&<div className="busy-call-notice"><I name="phone" size={15}/><span>You’re speaking with <strong>{active.name}</strong>. Answering will end that call.</span></div>}
     {call.confirmSwitch?<div className="call-switch-confirm"><strong>End the call with {active.name}?</strong><p>You’ll then answer {incoming.name}.</p><div className="incoming-actions"><Btn onClick={()=>call.setConfirmSwitch(false)}>Keep current call</Btn><Btn kind="primary" onClick={call.answer}>End and answer</Btn></div></div>:<div className="incoming-actions"><Btn icon="hang" className="call-decline" onClick={()=>call.resolve('Declined')}>Decline</Btn><Btn icon="phone" className="call-answer" onClick={call.answer}>{active?'End current call and answer':'Answer'}</Btn></div>}
     <p className="call-preserve"><I name="edit" size={12}/>Your work stays open</p>
    </section>}
    {active&&<section className="ongoing-card" aria-label={'Current call with '+active.name}>
      <div className="ongoing-person"><Avatar name={active.initials} icon={active.id==='afternoon'?'users':null} tone={active.tone}/><div className="grow"><strong>{active.name}</strong><span>{active.status==='connecting'?'Connecting…':`${duration} · ${active.muted?'Microphone muted':'Voice call'}`}</span></div><IconBtn name="expand" label="Expand current call" onClick={()=>call.setExpanded(true)}/></div>
      <div className="ongoing-actions"><Btn icon={active.muted?'micoff':'mic'} aria-pressed={active.muted} onClick={toggleMute}>{active.muted?'Unmute':'Mute'}</Btn><Btn icon="chat" onClick={()=>{call.setExpanded(true);call.setMessageOpen(true)}}>Message</Btn><Btn icon="hang" className="call-decline" onClick={finishCall}>{groupCall?'Leave':'End'}</Btn></div>
    </section>}
    {result&&<section className="call-result-card" aria-label={result.reason+' from '+result.name}><div className="row"><I name={result.reason==='Call ended'?'hang':'missed'}/><div className="grow"><strong>{result.reason}</strong><p>{result.name}{result.tone==='green'?' · '+(s?.threads?.find(t=>t.id===result.thread)?.client||'Sam Taylor'):''}</p></div><IconBtn name="x" label="Dismiss call update" onClick={()=>call.setResult(null)}/></div><p>{result.reason==='Answered elsewhere'?'This call was answered on your other device.':result.reason==='Cancelled by caller'?'The caller stopped ringing.':result.reason==='Missed call'?'You can return to this call from Chat → Calls.':'Your work is still here.'}</p>{actor!=='jo'&&['jo-sam','sam-jo','sam-alex','sam-nina'].includes(result.id)&&result.reason==='Call ended'&&<Btn icon="filecheck" onClick={()=>call.setRecordOpen(true)}>Record communication</Btn>}</section>}
    </div>
   </div>}
   {actor!=='jo'&&call.recordOpen&&<RecordReview call={call}/>}
   {active&&call.expanded&&<Modal title={active.name} subtitle={active.kind+' · '+duration} icon="phone" onClose={()=>call.setExpanded(false)} footer={<><Btn icon="minimise" onClick={()=>call.setExpanded(false)}>Keep working</Btn><Btn icon="hang" kind="danger" onClick={finishCall}>{groupCall?'Leave call':'End call'}</Btn></>}>
    <div className="expanded-call-person"><Avatar name={active.initials} icon={active.id==='afternoon'?'users':null} tone={active.tone} size="xl"/><h3>{active.audience}</h3><PillTag tone="green" icon="phone">{active.status==='connecting'?'Connecting':active.muted?'Microphone muted':'Connected'}</PillTag></div>
    <div className="row wrap" style={{justifyContent:'center',margin:'22px 0'}}><Btn icon={active.muted?'micoff':'mic'} aria-pressed={active.muted} onClick={toggleMute}>{active.muted?'Unmute microphone':'Mute microphone'}</Btn><Btn icon="chat" aria-pressed={call.messageOpen} onClick={()=>call.setMessageOpen(v=>!v)}>Conversation</Btn></div>
    {call.messageOpen&&(CallConversation?<CallConversation/>:<section className="call-conversation" aria-label={'Call conversation with '+active.name}><p><I name="lock" size={13}/> {active.audience}</p><div className="call-message-list"><div className="call-message received">{active.name}: Thanks for taking the call.</div>{(call.messages[active.id]||[]).map((m,i)=><div key={i} className="call-message sent">You: {m}</div>)}</div><Field label={'Message '+active.name}><textarea value={call.messageDrafts[active.id]||''} onChange={e=>call.setMessageDrafts(v=>({...v,[active.id]:e.target.value}))}/></Field><Btn kind="primary" icon="send" disabled={!call.messageDrafts[active.id]?.trim()} onClick={call.send}>Send message</Btn></section>)}
    <Notice icon="shield">Voice only. This call is not being recorded.</Notice>
   </Modal>}
  </>;
 }
 function IncomingWorkPage({call}){
  const {variant,toast,actor}=useReview();const portal=variant==='portal'||actor==='jo';
  return <Shell family={portal}><div className="scrollpage call-work-page"><div className="pageheading"><div><p className="eyebrow">{portal?'Family portal / Saturday’s visit':'My Day / Care communication'}</p><h1>{portal?'Plan your next visit':'Record communication'}</h1><p>{portal?'Sam Taylor · Kōwhai House':'Sam Taylor · Kōwhai House · Mere Thompson'}</p></div><PillTag icon="edit">Unfinished draft</PillTag></div><div className="call-work-layout"><section className="panel stack"><div><h2>{portal?'Your visit details':'A note you’re working on'}</h2><p className="small-muted">{portal?'Share your plans with the care team when you’re ready.':'Review the details before adding them to the care record.'}</p></div><div className="note-destination"><I name={portal?"users":"lock"} size={16}/><div><strong>{portal?"Visit request":"Destination: Client profile → Communication Record"}</strong><p>{portal?"Visible to you and the care team when shared.":"Authorised staff only. Adding a Family Note is a separate, reviewed step."}</p></div></div>{!portal&&call.savedRecord&&<Notice tone="green" icon="check">Saved in this preview: Communication Record.{call.savedRecord.share?" A separate Family Note was also created using only the family-visible wording.":" Nothing was added to Family Notes."}</Notice>}<div className="formgrid"><Field label={portal?'Visit date':'Communication date'}><input type="date" defaultValue="2026-10-17"/></Field><Field label={portal?'Visiting with':'Contact'}><input defaultValue={portal?'Jo Taylor':'Jo Taylor · Mother'}/></Field></div><Field label={portal?'Anything the team should know?':'What was discussed?'}><textarea className="call-work-note" value={call.workDrafts[portal?'portal':'staff']} onChange={e=>call.setWorkDrafts(v=>({...v,[portal?'portal':'staff']:e.target.value}))}/></Field><div className="row"><PillTag icon="lock">{portal?'You and the care team':'Staff record'}</PillTag><span className="small-muted">{!portal&&call.savedRecord?"Saved in this preview only":"Not yet published"}</span></div><div className="row" style={{justifyContent:'flex-end'}}><Btn icon="save" onClick={()=>toast('Draft kept in this preview. No care record was published.')}>Keep draft</Btn><Btn kind="primary" onClick={()=>portal?toast('Visit review is a separate family workflow. No visit request is sent by this preview.'):call.setRecordOpen(true)}>{portal?'Review visit details':'Review record'}</Btn></div></section><aside className="panel call-work-context"><h2>Before you finish</h2><div className="call-work-step"><I name="check"/><div><strong>Check the arrangements</strong><p>Make sure the date and agreed next steps are clear.</p></div></div><div className="call-work-step"><I name="shield"/><div><strong>Choose what to share</strong><p>Conversation messages are separate from the formal care record.</p></div></div><div className="call-work-step"><I name="clock"/><div><strong>Pick up where you left off</strong><p>Your unfinished work stays here when you answer or decline a call.</p></div></div></aside></div></div></Shell>;
 }
 return {CallOverlay,IncomingWorkPage};
}
