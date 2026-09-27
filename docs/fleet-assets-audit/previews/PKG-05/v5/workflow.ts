import {actors, canAllocate, canDecide, canDrive, activeDemand, outstandingItems, overdue, type Transport, type Role, type LoadState, type Operation} from './domain';

export type Kind = 'requests' | 'bookings' | 'journeys' | 'returns';
export type Step = {title:string; owner:string; detail:string; operation?:Operation};
export function journeyStep(r:Transport):Step {
 const b=r.booking,j=r.journey,driver=b?.driver||'Assigned driver';
 if(j?.state==='completed')return {title:'Journey completed',owner:driver,detail:'Passenger travel is closed. Any outstanding vehicle or equipment issue keeps its own owner.'};
 if(!activeDemand(r)||b?.state==='cancelled')return {title:'Transport cancelled',owner:r.owner,detail:r.outcome||'The planned transport was cancelled. Its history is retained.'};
 if(r.state==='information')return {title:'Provide missing information',owner:r.requester,detail:r.need,operation:'respond'};
 if(r.state==='assessment')return {title:'Assess transport need',owner:r.owner,detail:'Confirm the request details before planning or approving resources.',operation:'assess'};
 if(j?.arrived){
  if(!j.accounted)return {title:'Confirm passenger return',owner:driver,detail:`Confirm ${r.person} is back and accounted for.`,operation:'passengers'};
  if(j.medication==='outstanding')return {title:'Resolve medication handoff',owner:b?.escort||'Authorised medication worker',detail:'The authorised worker must finish the medication source handoff before the driver can complete this journey.',operation:'medication'};
  return {title:'Complete journey',owner:driver,detail:'Passenger return is confirmed and required handoffs are clear. The driver can now close the journey.',operation:'complete'};
 }
 if(j)return {title:'Record return',owner:driver,detail:`Record the actual arrival and who received the vehicle keys and equipment.${overdue(r)?' The expected return time has passed.':''}`,operation:'return'};
 if(b?.state==='pending')return {title:'Review booking decision',owner:'Authorised booking approver',detail:'An authorised booking approver must review this proposed Fleet booking.',operation:'approve'};
 if(b?.state==='rejected'||r.state==='allocation')return {title:'Choose suitable resources',owner:r.owner,detail:'Confirm a suitable vehicle and team for the whole window.',operation:'allocate'};
 if(r.check?.outcome==='fail')return {title:'Resolve vehicle restriction',owner:'Fleet maintenance team',detail:'The failed check prevents collection. Review the original Maintenance report.'};
 if(!r.check)return {title:'Complete vehicle check',owner:driver,detail:'Complete the assigned vehicle check before collecting the keys.',operation:'check'};
 if(!r.checkout)return {title:'Collect keys & equipment',owner:driver,detail:'Record the actual giver and confirm what you physically received.',operation:'checkout'};
 return {title:'Record departure',owner:driver,detail:'Confirm the passenger and actual departure time to start the journey.',operation:'depart'};
}
export function returnStep(r:Transport):Step {
 const items=outstandingItems(r);
 if(!r.receipts.length)return {title:r.journey?'Record return':'Await departure',owner:r.booking?.driver||'Assigned driver',detail:r.journey?'The driver records actual return, condition and the receiving worker.':'The vehicle has been collected but the passenger journey has not started.',operation:r.journey?'return':undefined};
 if(items.keys||items.equipment)return {title:'Receive missing items',owner:actors.receiver.name,detail:`Still to receive: ${[items.keys&&'keys',items.equipment&&'equipment'].filter(Boolean).join(' and ')}. Add a later receipt; keep the original return unchanged.`,operation:'custody'};
 if(r.handover?.state==='pending_acceptance')return {title:'Review shift handover',owner:r.handover.incoming,detail:'Check the outgoing worker’s note, then acknowledge it or explain what does not match.',operation:'accept'};
 const exception=r.exceptions.find(e=>e.state==='open');
 if(exception)return {title:'Reconcile exception',owner:exception.owner,detail:exception.detail,operation:'resolve'};
 return {title:'Return work complete',owner:r.receipts.at(-1)!.receiver,detail:r.handover?.state==='disputed'?'Items received and reconciliation recorded. The original disputed handover remains in history.':'Vehicle receipt and expected items are recorded. A shift handover is only needed if custody changes again.'};
}
export function requestStep(r:Transport):Step {
 if(r.state==='assessment')return {title:'Assess transport need',owner:r.owner,detail:'Confirm the practical needs before choosing resources.',operation:'assess'};
 if(r.state==='information')return {title:'Provide missing information',owner:r.requester,detail:r.need,operation:'respond'};
 if(!activeDemand(r))return {title:r.state==='completed'?'Journey completed':'Outcome recorded',owner:r.owner,detail:r.outcome||'Passenger travel is complete.'};
 return journeyStep(r);
}
export function stepFor(r:Transport,kind:Kind){return kind==='returns'?returnStep(r):kind==='requests'?requestStep(r):journeyStep(r)}
export function returnLabel(r:Transport){if(!r.receipts.length)return overdue(r)?'Overdue return':'Return due';const items=outstandingItems(r);if(items.keys||items.equipment)return 'Items missing';if(r.handover?.state==='pending_acceptance')return 'Handover to review';if(r.exceptions.some(e=>e.state==='open'))return 'Reconciliation due';return 'Return complete'}
export function operationsFor(r:Transport,kind:Kind,role:Role,load:LoadState,authority:boolean):{kind:Operation;label:string}[]{
 if(load!=='ready')return [];
 const b=r.booking,j=r.journey,driver=canDrive(role,r),escort=role==='escort'&&b?.escort===actors.escort.name;
 const ops:{kind:Operation;label:string}[]=[];
 const add=(condition:unknown,kind:Operation,label:string)=>{if(condition)ops.push({kind,label})};
 if(kind==='returns'){
  add(driver&&authority&&j&&!j.arrived,'return','Record return');
  const items=outstandingItems(r);
  add(role==='receiver'&&authority&&r.receipts.length&&(items.keys||items.equipment),'custody','Receive missing items');
  add(role==='receiver'&&r.handover?.incoming===actors.receiver.name&&r.handover.state==='pending_acceptance','accept','Acknowledge handover');
  add(role==='receiver'&&r.handover?.incoming===actors.receiver.name&&r.handover.state==='pending_acceptance','dispute','Dispute handover');
  add(canAllocate(role)&&r.exceptions.some(e=>e.state==='open')&&!items.keys&&!items.equipment,'resolve','Record reconciliation');
  add(driver&&r.receipts.length&&!r.handover,'handover','Create shift handover');
  add(['driver','receiver','allocator'].includes(role)&&r.checkout,'exception','Assign an exception');
 }else if(kind==='requests'||b?.state==='pending'||b?.state==='rejected'){
  add(canAllocate(role)&&r.state==='assessment','assess','Assess transport need');
  add(['requester','allocator'].includes(role)&&r.state==='information','respond','Provide missing information');
  add(canAllocate(role)&&r.state==='allocation','allocate','Choose resources');
  add(canDecide(role,b)&&b?.state==='pending'&&r.state!=='information','approve','Review booking approval');
  add(canDecide(role,b)&&b?.state==='pending','reject','Reject booking');
  add(canAllocate(role)&&activeDemand(r)&&!r.checkout,'information','Request information');
 }else{
  add(driver&&b?.state==='approved'&&!r.check,'check','Complete vehicle check');
  add(driver&&authority&&b?.state==='approved'&&r.check?.outcome==='pass','checkout','Collect keys & equipment');
  add(driver&&b?.state==='checked_out'&&!j,'depart','Record departure');
  add(driver&&authority&&j&&!j.arrived,'return','Record return');
  add((driver||escort)&&j?.arrived&&!j.accounted,'passengers','Confirm passenger return');
  add(escort&&j?.medication==='outstanding','medication','Open medication handoff');
  add(driver&&j?.state==='in_progress'&&j.arrived&&j.accounted&&j.medication!=='outstanding','complete','Complete journey');
 }
 add(canAllocate(role)&&b&&!r.checkout&&activeDemand(r)&&['allocation','allocated'].includes(r.state),'reschedule','Change time or resources');
 add(['requester','allocator'].includes(role)&&activeDemand(r)&&!b,'edit','Edit request');
 add(['requester','allocator'].includes(role)&&activeDemand(r)&&!r.checkout,'cancel','Cancel / not fulfilled');
 return ops;
}
