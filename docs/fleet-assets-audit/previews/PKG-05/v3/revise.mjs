import fs from 'node:fs';
import path from 'node:path';
import {fileURLToPath} from 'node:url';
const here=path.dirname(fileURLToPath(import.meta.url));
const read=n=>fs.readFileSync(path.join(here,n),'utf8');
const write=(n,s)=>fs.writeFileSync(path.join(here,n),s);
for(const name of ['build.mjs','serve.mjs','index.html'])write(name,read(name).replaceAll('v2','v3').replaceAll('4396','4397'));
let f=read('forms.tsx');
f=f.replace('onSaved:(result:string)=>void};','onSaved:(result:string)=>void;inline?:boolean;onDirty?:(dirty:boolean)=>void};');
f=f.replace('lostResponse,onClose,onCommit,onSaved}:Props','lostResponse,onClose,onCommit,onSaved,inline=false,onDirty}:Props');
f=f.replace("const op=useRef(crypto.randomUUID()),version=useRef(r.version);", "const op=useRef(crypto.randomUUID()),version=useRef(r.version);\n const planning=kind==='allocate'||kind==='reschedule';\n const [checkedDraft,setCheckedDraft]=useState('');\n const draftKey=JSON.stringify([p.vehicle,p.driver,p.escort,p.equipment,p.start,p.end]);\n const currentCheck=checkedDraft===draftKey&&availability==='available';");
f=f.replace("const set=(key:string,value:any)=>{setP(old=>({...old,[key]:value}));setDirty(true);setError('')};\n const submit", "const set=(key:string,value:any)=>{setP(old=>({...old,[key]:value}));setDirty(true);onDirty?.(true);setError('');if(planning){setCheckedDraft('');setAvailabilityMessage('Selections changed. Check this proposal before saving.')}};\n const submit");
f=f.replace("const submit=()=>{try{const result=onCommit", "const submit=()=>{try{if(planning&&!currentCheck)throw Error('Check availability for these exact resources and times before saving.');const result=onCommit");
const recheckStart=f.indexOf(' const recheck=()=>'),recheckEnd=f.indexOf('\n const actorLabel=',recheckStart);
f=f.slice(0,recheckStart)+` const recheck=()=>{
  setCheckedDraft('');setError('');
  const vehicle=fleet[p.vehicle as keyof typeof fleet];
  if(!vehicle||!p.driver||!p.equipment||r.escortRequired&&!p.escort){setAvailabilityMessage('Choose the required vehicle, driver, escort and equipment first.');return}
  if(!validDate(p.start)||!validDate(p.end)||p.end<=p.start){setAvailabilityMessage('Expected return must be after a valid pickup date and time.');return}
  if(vehicle.seats<r.seats||r.wheelchair&&!vehicle.wheelchair||p.vehicle==='tui'||vehicle.site!==r.site){setAvailabilityMessage('This vehicle cannot meet the site, capacity or accessibility requirements.');return}
  if(r.wheelchair&&p.equipment!=='Wheelchair restraints kit'){setAvailabilityMessage('This request needs the wheelchair restraints kit.');return}
  if(availability==='unknown'){setAvailabilityMessage('Fleet readiness configuration is missing. The owning Fleet team must resolve it.');return}
  if(availability==='conflict'&&p.start===r.start&&p.vehicle===(r.booking?.vehicle||'koru')){setAvailabilityMessage('This vehicle and time still conflict. Change the time or a suitable vehicle, then check again.');return}
  onAvailability('available');setCheckedDraft(draftKey);setAvailabilityMessage('Available for this proposal: vehicle, team, equipment and full time window checked.');
 };`+f.slice(recheckEnd);
f=f.replace("unavailable:id==='tui'?'Active Maintenance restriction':r.wheelchair&&!v.wheelchair?'No wheelchair position':undefined", "unavailable:v.site!==r.site?'Outside the request site':id==='tui'?'Active Maintenance restriction':v.seats<r.seats?'Not enough seats':r.wheelchair&&!v.wheelchair?'No wheelchair position':undefined");
const allocationStart=f.indexOf("   case 'allocate':case 'reschedule':"),allocationEnd=f.indexOf("   case 'approve':",allocationStart);
f=f.slice(0,allocationStart)+`   case 'allocate':case 'reschedule':return <><div className="allocation-intro"><strong>{r.booking?'Update '+r.booking.id:'Choose a vehicle and team'}</strong><p>{r.seats} occupants · {r.wheelchair?'wheelchair position':'standard seating'} · {r.escortRequired?'escort required':'no escort required'}</p></div>{selectors}<details className="plan-dates"><summary>Transport window · {localDateTimeLabel(p.start)} → {p.end.slice(11)} <span>Change</span></summary><DateTimeField id="plan-pickup" label="Pickup" value={p.start} onChange={v=>set('start',v)}/><DateTimeField id="plan-return" label="Expected return" value={p.end} onChange={v=>set('end',v)}/></details><div className="availability-check"><div><strong>{currentCheck?'Available for this proposal':availability==='unknown'?'Fleet setup needs attention':availability==='conflict'?'Resolve the time conflict':'Check your selections'}</strong><p role="status">{availabilityMessage||'Confirm the complete window before saving. Changing a selection requires another check.'}</p></div><Button variant="outline" onClick={recheck}>Check availability</Button></div></>;
`+f.slice(allocationEnd);
const returnStart=f.indexOf(' return <Modal open onClose={()=>dirty&&!recovered'),returnEnd=f.indexOf('\n}\n\ntype Upload=',returnStart);
let modal=f.slice(returnStart,returnEnd);
const contentStart=modal.indexOf('\n  {discard'),contentEnd=modal.lastIndexOf('\n </Modal>');
const body=modal.slice(contentStart,contentEnd);
f=f.slice(0,returnStart)+` const footer=recovered?<Button onClick={()=>onSaved(latestReceipt?.id||uncertain)}>Return to journey</Button>:<><Button variant="outline" onClick={()=>dirty?setDiscard(true):onClose()}>Cancel</Button><Button disabled={planning&&!currentCheck} onClick={submit}>{uncertain?'Retry this operation':actionLabels[kind]}</Button></>;
 const content=<>${body}</>;
 return inline?<section className="inline-planner-form" aria-label={formTitles[kind]}><div className="form-stack">{content}</div><div className="planner-save"><span>{currentCheck?'Ready for a booking decision':'Unsaved proposal'}</span><div className="button-row">{footer}</div></div></section>:<Modal open onClose={()=>dirty&&!recovered?setDiscard(true):onClose()} title={formTitles[kind]} description={r.id+' · '+r.person+' · Acting as '+actorLabel} footer={footer}>{content}</Modal>;`+f.slice(returnEnd);
write('forms.tsx',f);
