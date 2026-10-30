import {fleet,validDate,type Transport,type Availability,type Vehicle} from './domain';
export type PlanDraft={vehicle:string;driver:string;escort:string;equipment:string;start:string;end:string};
export type KeptDraft={plan:PlanDraft;step:number;version:number};
export type PlanIssue={area:'time'|'vehicle'|'driver'|'escort'|'equipment'|'source';message:string;record?:string};
export const planFrom=(r:Transport):PlanDraft=>({vehicle:r.booking?.vehicle||'',driver:r.booking?.driver||'',escort:r.booking?.escort||'',equipment:r.booking?.equipment||r.equipment,start:r.start,end:r.end});
export function vehicleReason(r:Transport,id:string){const v=fleet[id as Vehicle];if(!v)return 'Choose a vehicle';if(v.site!==r.site)return 'Outside this request’s site';if(id==='tui')return 'Maintenance restriction';if(v.seats<r.seats)return `Needs ${r.seats} seats including the driver`;if(r.wheelchair&&!v.wheelchair)return 'No wheelchair position';return ''}
export function planIssues(r:Transport,p:PlanDraft,records:Transport[],availability:Availability):PlanIssue[]{
 const issues:PlanIssue[]=[];const add=(area:PlanIssue['area'],message:string,record?:string)=>issues.push({area,message,record});
 if(!validDate(p.start)||!validDate(p.end)||p.end<=p.start)add('time','Choose a valid departure and later expected return.');
 const reason=vehicleReason(r,p.vehicle);if(reason)add('vehicle',reason+'.');
 if(r.site!=='aurora'||!['Nia Patel','Liam Chen'].includes(p.driver))add('driver','Choose an eligible driver.');
 if(r.escortRequired&&p.escort!=='Sara Wilson')add('escort','Choose the required escort.');
 if(p.escort&&p.escort!=='Sara Wilson')add('escort','Choose a permitted escort.');
 if(!['No additional equipment','Wheelchair restraints kit','First-aid kit'].includes(p.equipment))add('equipment','Choose the required equipment.');
 if(r.wheelchair&&p.equipment!=='Wheelchair restraints kit')add('equipment','This request requires the wheelchair restraints kit.');
 // Display/validate the same synthetic Fleet reservations, not a second booking store.
 for(const other of records){const b=other.booking;if(other.id===r.id||!b||!['approved','checked_out'].includes(b.state)||p.end<=b.start)continue;
  const overdue=b.state==='checked_out'&&b.end<=p.start;
  if(p.start>=b.end&&!overdue)continue;
  const unresolved=overdue?' Its return is not yet confirmed.':'';
  if(p.vehicle&&p.vehicle===b.vehicle)add('vehicle',`${fleet[b.vehicle].name} is ${overdue?'still out on another booking':'already reserved during this window'}.${unresolved}`,b.id);
  if(p.driver&&p.driver===b.driver)add('driver',`${p.driver} is ${overdue?'still assigned to a journey awaiting return':'assigned to another booking during this window'}.`,b.id);
  if(p.escort&&p.escort===b.escort)add('escort',`${p.escort} is ${overdue?'still assigned to a journey awaiting return':'already assigned during this window'}.`,b.id);
  if(p.equipment==='Wheelchair restraints kit'&&b.equipment===p.equipment)add('equipment','The required kit is assigned to another booking.'+unresolved,b.id);
 }
 if(availability==='unknown')add('source','Fleet readiness is not configured. The Fleet team must confirm it before this plan can be saved.');
 if(availability==='conflict')add('source','Fleet reports a conflicting reservation. Review the linked calendar and request a fresh result.');
 if(availability==='expired')add('source','The Fleet availability result has expired. Refresh availability and check this plan again.');
 if(r.maintenance?.restricted)add('vehicle','An active Maintenance restriction needs source review.');
 return issues;
}
export function planFingerprint(r:Transport,p:PlanDraft,records:Transport[],availability:Availability){return JSON.stringify({id:r.id,version:r.version,p,availability,reservations:records.filter(v=>v.booking).map(v=>[v.id,v.version,v.booking])})}
