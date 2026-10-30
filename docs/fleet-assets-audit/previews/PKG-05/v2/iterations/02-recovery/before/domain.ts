// Synthetic, in-memory design model. No API, storage, notification or clinical effects.
// Separate source records and immutable event snapshots keep each lifecycle explicit.
export type Role = 'requester' | 'allocator' | 'approver' | 'driver' | 'escort' | 'receiver';
export type RequestState = 'assessment' | 'information' | 'allocation' | 'allocated' | 'completed' | 'cancelled' | 'unfulfilled';
export type BookingState = 'pending' | 'approved' | 'rejected' | 'checked_out' | 'returned' | 'cancelled';
export type LoadState = 'ready' | 'loading' | 'error' | 'stale' | 'denied';
export type Availability = 'available' | 'conflict' | 'expired' | 'unknown';
export const TODAY = '2026-09-28';
export const NOW = TODAY + 'T10:35';
export const siteNames: Record<string,string> = {aurora:'Aurora House',kowhai:'Kōwhai House'};
export const actors = {
  requester:{name:'Ava Thompson',title:'Requester',sites:['aurora']},
  allocator:{name:'Mia Chen',title:'Transport allocator',sites:['aurora','kowhai']},
  approver:{name:'Rory Williams',title:'Booking approver',sites:['aurora','kowhai']},
  driver:{name:'Nia Patel',title:'Assigned driver',sites:['aurora']},
  escort:{name:'Sara Wilson',title:'Assigned escort',sites:['aurora']},
  receiver:{name:'Ben Carter',title:'Receiving worker',sites:['aurora']},
};
export const fleet = {
  koru:{name:'Koru · Toyota Hiace',ref:'OFV-014',seats:6,wheelchair:true,site:'aurora'},
  rimu:{name:'Rimu · Toyota Corolla',ref:'OFV-031',seats:4,wheelchair:false,site:'aurora'},
  tui:{name:'Tui · Ford Transit',ref:'OFV-022',seats:6,wheelchair:true,site:'kowhai'},
};
export type Vehicle = keyof typeof fleet;
export interface Event {id:string;source:string;time:string;actor:string;title:string;detail:string}
export interface Booking {id:string;state:BookingState;vehicle:Vehicle;driver:string;escort:string;equipment:string;start:string;end:string;approval:'required'|'not_required';authority:string;requestedBy:string;version:number}
export interface Check {id:string;time:string;actor:string;outcome:'pass'|'fail';note:string;template:string}
export interface Journey {id:string;state:'in_progress'|'completed';departed:string;arrived?:string;driver:string;passengers:number;accounted:boolean;medication:'none'|'outstanding'|'resolved'}
export interface Receipt {id:string;operation:string;time:string;odometer:number;fuel:string;keys:boolean;equipment:boolean;giver:string;receiver:string;site:string;condition:string;recordedBy:string}
export interface Handover {id:string;state:'pending_acceptance'|'accepted'|'disputed';outgoing:string;incoming:string;site:string;items:string;note:string;decision?:string;acknowledgedAt?:string}
export interface Exception {id:string;kind:string;detail:string;owner:string;state:'open'|'resolved'}
export interface Evidence {id:string;name:string;version:number;time:string;actor:string;note:string}
export interface Transport {
  id:string;version:number;person:string;site:string;purpose:string;pickup:string;destination:string;start:string;end:string;
  seats:number;wheelchair:boolean;escortRequired:boolean;equipment:string;requester:string;owner:string;state:RequestState;
  need:string;outcome?:string;notes:string[];booking?:Booking;check?:Check;journey?:Journey;receipts:Receipt[];
  handover?:Handover;exceptions:Exception[];evidence:Evidence[];maintenance?:{id:string;checkId:string;issue:string;restricted:boolean};
  events:Event[];checkout?:{time:string;odometer:number;fuel:string;giver:string;receiver:string;site:string};
}
export interface Store {records:Transport[];operations:Record<string,{fingerprint:string;result:string}>;sequence:number}
export const stateLabel:Record<RequestState,string>={assessment:'Needs assessment',information:'Needs information',allocation:'Awaiting allocation',allocated:'Allocated',completed:'Completed',cancelled:'Cancelled',unfulfilled:'Not fulfilled'};
export function bookingLabel(b:Booking){return ({pending:'Awaiting booking decision',approved:'Confirmed',rejected:'Booking rejected',checked_out:'Vehicle checked out',returned:'Vehicle returned',cancelled:'Booking cancelled'})[b.state]}
export function journeyLabel(r:Transport){return !r.journey?'Preparing to depart':r.journey.state==='completed'?'Completed':r.journey.arrived?'Returned · completion due':'In progress'}
export function requestLabel(r:Transport){return r.state==='allocated'&&r.booking?.state==='pending'?'Awaiting booking approval':r.state==='allocated'&&r.booking?.state==='rejected'?'Allocation needs review':stateLabel[r.state]}
export const activeDemand=(r:Transport)=>!['cancelled','unfulfilled','completed'].includes(r.state);
export const hasReturnWork=(r:Transport)=>!!r.checkout && (!r.receipts.length || !r.receipts.at(-1)?.keys || !r.receipts.at(-1)?.equipment) || !!r.handover&&r.handover.state!=='accepted' || r.exceptions.some(e=>e.state==='open');
export const overdue=(r:Transport)=>!!r.checkout&&!r.receipts.length&&r.end<NOW;
export function permitted(r:Transport,role:Role,load:LoadState){return load!=='denied'&&actors[role].sites.includes(r.site)}
export function canAllocate(role:Role){return role==='allocator'}
export function canDecide(role:Role,b?:Booking){return role==='approver'&&!!b&&b.requestedBy!==actors.approver.name}
export function canDrive(role:Role,r:Transport){return role==='driver'&&r.booking?.driver===actors.driver.name}
export function nextAction(r:Transport){
  if(!activeDemand(r))return r.exceptions.some(e=>e.state==='open')?'Review outstanding exception':'View outcome';
  if(r.state==='assessment')return 'Assess transport need';
  if(r.state==='information')return 'Provide missing information';
  if(r.state==='allocation'||r.booking?.state==='rejected')return 'Choose suitable resources';
  if(r.booking?.state==='pending')return 'Review booking decision';
  if(!r.check||r.check.outcome==='fail')return 'Complete vehicle check';
  if(!r.checkout)return 'Collect keys and equipment';
  if(!r.journey)return 'Record departure';
  if(!r.journey.arrived)return 'Record return';
  if(!r.journey.accounted)return 'Confirm passenger return';
  if(r.journey.medication==='outstanding')return 'Resolve medication handoff';
  if(r.journey.state!=='completed')return 'Complete journey';
  return 'Review outstanding return';
}
function base(id:string,person:string,time:string,state:RequestState,site='aurora'):Transport {
 return {id,person,site,version:1,purpose:'Community appointment',pickup:siteNames[site],destination:'Harbour community centre',start:TODAY+'T'+time,end:TODAY+'T12:30',seats:2,wheelchair:false,escortRequired:false,equipment:'No additional equipment',requester:'Ava Thompson',owner:'Mia Chen',state,need:'',notes:[],receipts:[],exceptions:[],evidence:[],events:[{id:id+'-request',source:id,time:TODAY+'T08:00',actor:'Ava Thompson',title:'Transport requested',detail:'Time, pickup and authorised transport needs recorded.'}]};
}
function reservation(id:string,start:string,end:string,approval:'required'|'not_required'='required'):Booking{return {id,state:approval==='required'?'pending':'approved',vehicle:'koru',driver:'Nia Patel',escort:'Sara Wilson',equipment:'Wheelchair restraints kit',start,end,approval,authority:approval==='required'?'Independent Fleet booking approval':'Authority and reason recorded in Fleet booking',requestedBy:'Mia Chen',version:1}}
export function initialStore():Store {
 const a=base('TR-1042','Alex Morgan','11:30','assessment');Object.assign(a,{purpose:'Physiotherapy appointment',destination:'Harbour Physiotherapy',seats:3,wheelchair:true,escortRequired:true,equipment:'Wheelchair restraints kit',need:'Confirm transfer support and appointment finish time.'});
 const b=base('TR-1043','Jordan Lee','12:00','information','kowhai');Object.assign(b,{destination:'Library',need:'Requester to confirm the pickup entrance.',requester:'Eli Thompson',end:TODAY+'T14:00'});
 const c=base('TR-1044','Taylor Green','10:45','allocated');c.booking=reservation('BK-210',c.start,c.end);c.booking.vehicle='rimu';c.booking.escort='';c.booking.equipment='No additional equipment';
 const d=base('TR-1045','Morgan Smith','11:00','allocated');d.booking=reservation('BK-212',d.start,d.end,'not_required');d.booking.vehicle='rimu';d.booking.escort='';d.booking.equipment='No additional equipment';
 const e=base('TR-1046','Sam Wilson','09:00','allocated');e.end=TODAY+'T10:00';e.booking=reservation('BK-208',e.start,e.end,'not_required');e.booking.state='checked_out';e.check={id:'CHK-441',time:TODAY+'T08:45',actor:'Nia Patel',outcome:'pass',note:'Required items checked; no new issue observed.',template:'Accessible vehicle pre-use · version 3'};e.checkout={time:TODAY+'T08:50',odometer:48210,fuel:'¾',giver:'Ben Carter',receiver:'Nia Patel',site:'aurora'};e.journey={id:'J-608',state:'in_progress',departed:TODAY+'T09:02',driver:'Nia Patel',passengers:1,accounted:false,medication:'none'};
 const f=base('TR-1047','Charlie Brown','08:00','allocated');f.end=TODAY+'T10:15';f.booking=reservation('BK-209',f.start,f.end,'not_required');f.booking.state='returned';f.checkout={...e.checkout,time:TODAY+'T07:50',odometer:36102};f.check={...e.check,id:'CHK-442'};f.journey={id:'J-609',state:'in_progress',departed:TODAY+'T08:00',arrived:TODAY+'T10:10',driver:'Nia Patel',passengers:1,accounted:true,medication:'outstanding'};f.receipts=[{id:'RC-312',operation:'seed-return',time:TODAY+'T10:10',odometer:36138,fuel:'½',keys:true,equipment:true,giver:'Nia Patel',receiver:'Ben Carter',site:'aurora',condition:'No new issue',recordedBy:'Nia Patel'}];
 const g=base('TR-1048','Casey Jones','07:00','completed');g.end=TODAY+'T08:30';g.booking=reservation('BK-207',g.start,g.end,'not_required');g.booking.state='returned';g.checkout={...e.checkout,time:TODAY+'T06:50',odometer:22400};g.journey={id:'J-607',state:'completed',departed:TODAY+'T07:02',arrived:TODAY+'T08:25',driver:'Nia Patel',passengers:1,accounted:true,medication:'none'};g.receipts=[{...f.receipts[0],id:'RC-310',odometer:22435,time:TODAY+'T08:25',equipment:false}];g.handover={id:'HO-87',state:'disputed',outgoing:'Nia Patel',incoming:'Ben Carter',site:'aurora',items:'Keys and equipment',note:'Wheelchair kit expected at handover.',decision:'Kit not received. Keys accepted separately.'};g.exceptions=[{id:'EX-21',kind:'Missing equipment',detail:'Wheelchair kit not received by Ben. Confirm its current holder.',owner:'Mia Chen',state:'open'}];
 const h=base('TR-1049','Jamie Cooper','09:30','cancelled');h.outcome='Client chose not to travel';
 const i=base('TR-1050','Elliot White','13:00','allocation');i.end=TODAY+'T15:00';i.need='Assessment complete. Allocate a vehicle and driver.';
 for(const r of [c,d,e,f,g])if(r.booking)r.events.push({id:r.booking.id+'-created',source:r.booking.id,time:TODAY+'T08:05',actor:'Mia Chen',title:r.booking.state==='pending'?'Booking requested':'Booking confirmed',detail:r.booking.approval==='not_required'?'Approval not required: authority recorded with the source booking.':'Independent decision required; no default approver inferred.'});
 return {records:[a,b,c,d,e,f,g,h,i],operations:{},sequence:1100};
}
export type Operation = 'assess'|'information'|'respond'|'allocate'|'reschedule'|'approve'|'reject'|'check'|'checkout'|'depart'|'return'|'passengers'|'medication'|'complete'|'cancel'|'handover'|'accept'|'dispute'|'exception'|'resolve'|'evidence'|'note';
export interface Command {id:string;record:string;type:Operation;version:number;payload:Record<string,any>}
export interface CommandContext {role:Role;load:LoadState;availability:Availability}
export function validDate(v:string){
 if(typeof v!=='string'||!/^\d{4}-\d{2}-\d{2}T([01]\d|2[0-3]):[0-5]\d$/.test(v))return false;
 const [year,month,day]=v.slice(0,10).split('-').map(Number),date=new Date(Date.UTC(year,month-1,day));
 return date.getUTCFullYear()===year&&date.getUTCMonth()===month-1&&date.getUTCDate()===day;
}
function requireThat(test:unknown,message:string):asserts test {if(!test)throw Error(message)}
export function execute(current:Store,command:Command,ctx:CommandContext):{store:Store;result:string;replayed:boolean} {
 const source=current.records.find(r=>r.id===command.record);
 requireThat(source&&permitted(source,ctx.role,ctx.load),'This record is unavailable in your permitted scope.');
 requireThat(ctx.load==='ready','Refresh the source before recording an action. Your draft is retained.');
 const fingerprint=JSON.stringify({record:command.record,type:command.type,payload:command.payload,actor:actors[ctx.role].name});
 const prior=current.operations[command.id];
 if(prior){requireThat(prior.fingerprint===fingerprint,'This retry has changed observations. Recover the original receipt before starting a correction.');return {store:current,result:prior.result,replayed:true}}
 requireThat(source.version===command.version,'This record changed. Refresh and review the current record before saving.');
 const store=structuredClone(current),r=store.records.find(v=>v.id===command.record)!,p=command.payload,actor=actors[ctx.role].name;
 const allocate=()=>requireThat(canAllocate(ctx.role),'The transport allocator must make this change.');
 const drive=()=>requireThat(canDrive(ctx.role,r),'Only the assigned driver can record this action in this example.');
 const booking=()=>{requireThat(r.booking,'Allocate and confirm a Fleet booking first.');return r.booking};
 const ready=()=>{requireThat(ctx.availability==='available',ctx.availability==='unknown'?'Readiness needs assessment or configuration. No vehicle can be confirmed.':ctx.availability==='expired'?'Availability result expired. Recheck the same booking before continuing.':'The selected window conflicts with another booking. Change the time or suitable vehicle.');requireThat(!r.maintenance?.restricted,'An active vehicle restriction prevents checkout. Open the Maintenance source.')};
 const required=(key:string,label:string)=>requireThat(typeof p[key]==='string'&&p[key].trim(),label+' is required.');
 const moment=(key:string)=>{requireThat(validDate(p[key]),'Choose a complete valid '+key+' date and time.');return p[key] as string};
 const event=(title:string,detail:string,sourceId=r.id)=>r.events.push({id:'EV-'+(++store.sequence),source:sourceId,time:p.time||NOW,actor,title,detail});
 let result='Saved';
 switch(command.type){
  case 'assess': allocate();requireThat(r.state==='assessment','Assessment is not the current step.');required('note','Assessment');r.state='allocation';r.need=p.note;r.owner=actor;event('Transport need assessed',p.note);result='Assessment saved';break;
  case 'information':allocate();requireThat(activeDemand(r)&&!r.checkout,'Information can be requested before checkout.');required('reason','Information needed');r.state='information';r.need=p.reason;event('Information requested',p.reason);result='Request updated; requester owns the next response';break;
  case 'respond':requireThat(ctx.role==='requester'||ctx.role==='allocator','The requester or allocator can supply this information.');requireThat(r.state==='information','No information request is open.');required('note','Response');r.need=p.note;r.state='assessment';r.notes.push(actor+': '+p.note);event('Missing information supplied',p.note);result='Information supplied for assessment';break;
  case 'allocate':case 'reschedule':{
   allocate();requireThat(activeDemand(r)&&!r.checkout,'Resources can be changed only before checkout.');requireThat(['allocation','allocated'].includes(r.state),'Assess the request and resolve missing information before allocation.');ready();
   requireThat(p.vehicle in fleet,'Select a vehicle.');const v=fleet[p.vehicle as Vehicle];requireThat(v.seats>=r.seats&&(!r.wheelchair||v.wheelchair),'This vehicle cannot meet the assessed capacity or accessibility need.');requireThat(p.vehicle!=='tui','Tui has an active Maintenance restriction.');
   requireThat(p.driver==='Nia Patel','A suitable available driver must be selected.');requireThat(!r.escortRequired||p.escort==='Sara Wilson','Select the required available escort.');requireThat(!r.wheelchair||p.equipment==='Wheelchair restraints kit','Confirm the required equipment.');
   const start=moment('start'),end=moment('end');requireThat(end>start,'Expected return must be after pickup.');
   const b=r.booking;requireThat(!b||!['checked_out','returned'].includes(b.state),'The active reservation cannot be replaced.');
   r.booking={id:b?.id||'BK-'+(++store.sequence),state:'pending',vehicle:p.vehicle,driver:p.driver,escort:p.escort||'',equipment:p.equipment||'No additional equipment',start,end,approval:b?.approval||'required',authority:b?.authority||'Independent Fleet booking decision required',requestedBy:b?.requestedBy||actor,version:(b?.version||0)+1};
   r.start=start;r.end=end;r.state='allocated';event(b?'Booking resources changed':'Resources proposed',`${v.name}; ${p.driver}. Readiness rechecked; awaiting source booking decision.`,r.booking.id);result=`${r.booking.id} saved for a booking decision`;break;
  }
  case 'approve':{const b=booking();requireThat(canDecide(ctx.role,b),'Fleet booking approval permission and an independent actor are required.');requireThat(b.state==='pending','Only a pending booking can be decided.');requireThat(r.state!=='information','Resolve the requested information before confirming.');ready();requireThat(p.reviewed,'Review the current readiness result.');b.state='approved';b.version++;event(b.approval==='required'?'Booking approved':'Booking confirmed',p.reason||'Current availability and readiness reviewed.',b.id);result=b.approval==='required'?'Booking approved':'Booking confirmed';break}
  case 'reject':{const b=booking();requireThat(canDecide(ctx.role,b)&&b.state==='pending','Only an authorised independent booking approver can reject this pending booking.');required('reason','Rejection reason');b.state='rejected';b.version++;r.state='allocation';r.need=p.reason;event('Booking rejected',p.reason,b.id);result='Booking rejected; demand returned to allocator';break}
  case 'check':drive();requireThat(booking().state==='approved'&&r.state==='allocated','Assessment, allocation and a confirmed booking are required before the vehicle check.');ready();requireThat(!r.check||r.check.outcome==='fail','The submitted check is retained. A new check needs a source amendment.');required('note','Check observation');r.check={id:'CHK-'+(++store.sequence),time:moment('time'),actor,outcome:p.failed?'fail':'pass',note:p.note,template:'Assigned vehicle pre-use · version 3'};event('Vehicle check submitted',p.note,r.check.id);if(p.failed){r.maintenance={id:'M-'+(++store.sequence),checkId:r.check.id,issue:p.note,restricted:true};event('Maintenance report linked',p.note,r.maintenance.id)}result=p.failed?'Failed check retained; Maintenance report linked':'Vehicle check recorded';break;
  case 'checkout':{
   drive();requireThat(r.state==='allocated'&&booking().state==='approved','Assessment, allocation and booking confirmation are required before checkout.');ready();requireThat(r.check?.outcome==='pass','Submit a successful vehicle check first.');requireThat(p.keys===true&&p.equipment===true,'Confirm actual keys and required equipment received.');requireThat(Number(p.odometer)>0,'Enter a valid odometer.');required('giver','Actual giver');requireThat(p.site===r.site,'Unexpected site: record an owned exception before reconciliation.');r.checkout={time:moment('time'),odometer:Number(p.odometer),fuel:p.fuel,giver:p.giver,receiver:actor,site:p.site};r.booking!.state='checked_out';event('Vehicle checked out',`Keys and equipment: ${p.giver} → ${actor}; ${p.odometer} km.`,r.booking!.id);result='Checkout recorded; ready to record departure';break;
  }
  case 'depart':drive();requireThat(r.state==='allocated'&&booking().state==='checked_out'&&r.checkout,'Complete the confirmed booking, vehicle check and actual checkout first.');requireThat(!r.journey,'An actual journey already exists.');requireThat(p.passengers,'Confirm the permitted passenger list before departure.');const departed=moment('time');requireThat(departed>=r.checkout.time,'Departure cannot be before checkout.');r.journey={id:'J-'+(++store.sequence),state:'in_progress',departed,driver:actor,passengers:1,accounted:false,medication:'none'};event('Journey departed',`${r.person}; actual driver ${actor}.`,r.journey.id);result=`${r.journey.id} started`;break;
  case 'return':{
   drive();requireThat(r.journey&&r.checkout,'An actual journey and checkout are required.');requireThat(!r.journey.arrived,'Recover the existing receipt. Corrections are separate source events.');const time=moment('time');requireThat(time>=r.journey.departed,'Return cannot be before actual departure.');requireThat(Number(p.odometer)>=r.checkout.odometer,'Return odometer cannot be below checkout.');required('receiver','Actual receiver');requireThat(p.site===r.site,'Unexpected return site: retain the observations and assign reconciliation.');required('condition','Return condition');
   const receipt:Receipt={id:'RC-'+(++store.sequence),operation:command.id,time,odometer:Number(p.odometer),fuel:p.fuel,keys:p.keys===true,equipment:p.equipment===true,giver:actor,receiver:p.receiver,site:p.site,condition:p.condition,recordedBy:actor};r.receipts.push(receipt);r.journey.arrived=time;r.booking!.state='returned';event('Return receipt recorded',`${receipt.id}; ${receipt.odometer} km; keys ${receipt.keys?'received':'outstanding'}; equipment ${receipt.equipment?'received':'outstanding'}.`,receipt.id);
   if(!receipt.keys||!receipt.equipment)r.exceptions.push({id:'EX-'+(++store.sequence),kind:'Partial return',detail:!receipt.keys?'Keys remain outstanding.':'Equipment remains outstanding.',owner:'Mia Chen',state:'open'});
   if(p.damage){r.maintenance={id:'M-'+(++store.sequence),checkId:r.check?.id||'',issue:p.condition,restricted:true};event('Post-use issue reported',p.condition,r.maintenance.id)}result=receipt.id;break;
  }
  case 'passengers':requireThat(ctx.role==='escort'||canDrive(ctx.role,r),'The assigned driver or escort must confirm passengers.');requireThat(r.journey?.arrived,'Record actual arrival first.');requireThat(p.confirmed,'Confirm the actual passenger return.');r.journey.accounted=true;event('Passenger return confirmed',r.person+' returned to '+siteNames[r.site],r.journey.id);result='Passenger return recorded';break;
  case 'medication':requireThat(ctx.role==='escort','Open the permitted medication logistics source with an authorised worker.');requireThat(r.journey?.medication==='outstanding','No medication handoff is outstanding.');requireThat(p.confirmed,'A resolved source result is required.');r.journey.medication='resolved';event('Medication logistics source refreshed','Synthetic source now reports no unresolved custody or required record.',r.journey.id);result='Source result refreshed';break;
  case 'complete':drive();requireThat(r.journey?.state==='in_progress'&&r.journey.arrived,'Record return before completing the journey.');requireThat(r.journey.accounted,'Confirm passenger return first.');requireThat(r.journey.medication!=='outstanding','Resolve outstanding medication logistics in its authorised source first.');r.journey.state='completed';r.state='completed';event('Journey completed','Passenger journey closed. Independent vehicle restrictions and custody exceptions remain open.',r.journey.id);result='Journey completed';break;
  case 'cancel':requireThat(ctx.role==='allocator'||ctx.role==='requester','The requester or allocator can change demand.');requireThat(activeDemand(r)&&!r.checkout,'Travel has started. Record actual return and outstanding obligations instead.');required('reason','Outcome reason');requireThat(['Client chose not to travel','Requester cancelled','No suitable transport'].includes(p.outcome),'Choose a demand outcome.');r.state=p.outcome==='No suitable transport'?'unfulfilled':'cancelled';r.outcome=p.outcome+': '+p.reason;if(r.booking)r.booking.state='cancelled';event(r.state==='cancelled'?'Request cancelled':'Transport not fulfilled',r.outcome);result='Demand outcome recorded';break;
  case 'handover':drive();requireThat(r.receipts.length,'Record a return receipt before a shift handover.');requireThat(!r.handover,'A source handover already exists.');requireThat(p.incoming==='Ben Carter','Choose a permitted incoming worker.');required('items','Items handed over');required('reason','Handover note');r.handover={id:'HO-'+(++store.sequence),state:'pending_acceptance',outgoing:actor,incoming:p.incoming,site:r.site,items:p.items,note:p.reason};event('Shift handover sent',`${actor} → ${p.incoming}; ${p.items}.`,r.handover.id);result='Handover awaiting receiver acknowledgement';break;
  case 'accept':case 'dispute':requireThat(ctx.role==='receiver'&&r.handover?.incoming===actor,'Only the named incoming worker can acknowledge or dispute.');requireThat(r.handover.state==='pending_acceptance','This handover already has a decision. Reconciliation retains the original decision.');required('reason','Receipt observation');r.handover.state=command.type==='accept'?'accepted':'disputed';r.handover.decision=p.reason;r.handover.acknowledgedAt=NOW;event(command.type==='accept'?'Handover accepted':'Handover disputed',p.reason,r.handover.id);if(command.type==='dispute')r.exceptions.push({id:'EX-'+(++store.sequence),kind:'Disputed handover',detail:p.reason,owner:'Mia Chen',state:'open'});result=command.type==='accept'?'Receipt acknowledged':'Dispute recorded; reconciliation remains open';break;
  case 'exception':requireThat(['allocator','driver','receiver'].includes(ctx.role),'A permitted worker must record the exception.');required('reason','What is outstanding');required('owner','Accountable owner');r.exceptions.push({id:'EX-'+(++store.sequence),kind:p.kind||'Custody exception',detail:p.reason,owner:p.owner,state:'open'});event('Exception assigned',p.reason+' Owner: '+p.owner);result='Exception assigned; original custody decision retained';break;
  case 'resolve':allocate();required('reason','Reconciliation evidence');const exception=r.exceptions.find(e=>e.id===p.exception);requireThat(exception,'Exception not found.');exception.state='resolved';event('Exception reconciled',p.reason,exception.id);result='Reconciliation recorded; original handover retained';break;
  case 'evidence':requireThat(['driver','allocator','receiver'].includes(ctx.role),'Evidence requires record permission.');requireThat(Array.isArray(p.files)&&p.files.length,'Stage at least one file.');for(const file of p.files){const previous=r.evidence.filter(e=>e.name===file.name);r.evidence.push({id:'DOC-'+(++store.sequence),name:file.name,version:previous.length+1,time:NOW,actor,note:file.note||''})}event('Evidence recorded',p.files.length+' local synthetic evidence records; earlier versions retained.');result='Evidence added';break;
  case 'note':required('note','Note');r.notes.push(actor+': '+p.note);event('Note added',p.note);result='Note recorded';break;
 }
 r.version++;store.operations[command.id]={fingerprint,result};return {store,result,replayed:false};
}
export function newRequest(store:Store,p:Record<string,any>,ctx:CommandContext):{store:Store;id:string}{
 requireThat(p.person!=='Jordan Lee'||p.site==='kowhai','Passenger and request site must match the permitted client context.');requireThat(p.person!=='Alex Morgan'||p.site==='aurora','Passenger and request site must match the permitted client context.');
 requireThat(ctx.load==='ready'&&['requester','allocator'].includes(ctx.role),'An authorised requester must open this request.');requireThat(actors[ctx.role].sites.includes(p.site),'Choose a permitted site.');for(const key of ['person','purpose','pickup','destination'])requireThat(typeof p[key]==='string'&&p[key].trim(),'Complete '+key+'.');requireThat(validDate(p.start)&&validDate(p.end)&&p.end>p.start,'Choose a complete pickup and later expected return.');requireThat(Number.isInteger(Number(p.seats))&&Number(p.seats)>=2&&Number(p.seats)<=8,'Enter 2–8 total occupants, including driver and any escort.');requireThat(!p.escortRequired||Number(p.seats)>=3,'Include a seat for the passenger, driver and required escort.');
 const next=structuredClone(store),id='TR-'+(++next.sequence),r=base(id,p.person,p.start.slice(11),'assessment',p.site);Object.assign(r,{purpose:p.purpose,pickup:p.pickup,destination:p.destination,start:p.start,end:p.end,seats:Number(p.seats),wheelchair:!!p.wheelchair,escortRequired:!!p.escortRequired,equipment:p.wheelchair?'Wheelchair restraints kit':'No additional equipment',requester:actors[ctx.role].name,need:p.need||'',notes:[],events:[{id:id+'-request',source:id,time:NOW,actor:actors[ctx.role].name,title:'Transport requested',detail:'Unallocated request submitted for assessment.'}]});next.records.unshift(r);return {store:next,id};
}
