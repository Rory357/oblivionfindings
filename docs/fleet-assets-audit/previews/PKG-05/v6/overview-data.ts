import {NOW,overdue,outstandingItems,type Transport} from './domain';
import type {Report} from './reports';

export const stageDefinitions=[
 {key:'unallocated',label:'Needs a plan',color:'var(--chart-1)',view:'requests',filter:'unallocated'},
 {key:'decision',label:'Awaiting decision',color:'var(--status-warning)',view:'requests',filter:'decision'},
 {key:'preparation',label:'Preparing to leave',color:'var(--status-info)',view:'journeys',filter:'preparation'},
 {key:'active',label:'On the road',color:'var(--tone-teal)',view:'journeys',filter:'active'},
 {key:'returned',label:'Returned · finish due',color:'var(--chart-5)',view:'journeys',filter:'returned'},
 {key:'completed',label:'Journey completed',color:'var(--status-success)',view:'journeys',filter:'completed'},
 {key:'cancelled',label:'Cancelled / not fulfilled',color:'var(--muted-foreground)',view:'requests',filter:'cancelled'},
];
export function stageKey(r:Transport):string{
 if(['cancelled','unfulfilled'].includes(r.state))return 'cancelled';
 if(r.journey?.state==='completed')return 'completed';
 if(r.journey?.arrived)return 'returned';
 if(r.journey)return 'active';
 if(r.booking?.state==='pending')return 'decision';
 if(r.booking&&['approved','checked_out'].includes(r.booking.state))return 'preparation';
 return 'unallocated';
}
export function stageCounts(rows:Transport[]){return stageDefinitions.map(s=>({...s,value:rows.filter(r=>stageKey(r)===s.key).length}))}
export function scheduleCounts(rows:Transport[]){
 const active=rows.filter(r=>!['cancelled','unfulfilled'].includes(r.state));
 const hours=Array.from({length:24},(_,hour)=>({hour,label:String(hour).padStart(2,'0')+':00',departures:active.filter(r=>Number(r.start.slice(11,13))===hour).length,returns:active.filter(r=>Number(r.end.slice(11,13))===hour).length}));
 const used=hours.filter(h=>h.departures||h.returns);
 const first=Math.min(6,...used.map(h=>h.hour)),last=Math.max(18,...used.map(h=>h.hour));
 return hours.filter(h=>h.hour>=first&&h.hour<=last);
}
export function attentionItems(rows:Transport[]){return rows.flatMap(r=>{
 const items:{id:string;r:Transport;title:string;detail:string;owner:string;kind:'requests'|'journeys'|'returns';priority:number}[]=[];
 if(overdue(r))items.push({id:r.id+'-late',r,title:'Overdue return',detail:'Expected '+r.end.slice(11)+' · confirm the actual return',owner:r.booking?.driver||r.owner,kind:'returns',priority:0});
 const missing=outstandingItems(r);
 if(r.receipts.length&&(missing.keys||missing.equipment))items.push({id:r.id+'-items',r,title:missing.keys?'Keys still to receive':'Equipment still to receive',detail:'Return receipt is incomplete',owner:r.receipts.at(-1)?.receiver||r.owner,kind:'returns',priority:1});
 else if(r.exceptions.some(e=>e.state==='open'))items.push({id:r.id+'-exception',r,title:'Return exception to resolve',detail:r.exceptions.find(e=>e.state==='open')!.kind,owner:r.exceptions.find(e=>e.state==='open')!.owner,kind:'returns',priority:1});
 if(r.journey?.arrived&&r.journey.state!=='completed')items.push({id:r.id+'-finish',r,title:'Finish passenger journey',detail:'Check remaining passenger and source handoffs',owner:r.journey.medication==='outstanding'?r.booking?.escort||'Authorised worker':r.journey.driver,kind:'journeys',priority:2});
 if(r.booking?.state==='pending')items.push({id:r.id+'-decision',r,title:'Booking decision needed',detail:'Plan proposed · not yet confirmed',owner:'Authorised booking approver',kind:'requests',priority:3});
 if(['assessment','information','allocation'].includes(r.state))items.push({id:r.id+'-plan',r,title:r.state==='assessment'?'Assess transport need':r.state==='information'?'Information needed':'Allocate transport',detail:r.state==='information'?'Waiting for the requester':'Needed for '+r.start.slice(11)+' departure',owner:r.state==='information'?r.requester:r.owner,kind:'requests',priority:4});
 return items;
}).sort((a,b)=>a.priority-b.priority||a.r.start.localeCompare(b.r.start))}
export function nextDepartures(rows:Transport[]){return rows.filter(r=>!['cancelled','unfulfilled','completed'].includes(r.state)&&!r.journey).sort((a,b)=>a.start.localeCompare(b.start))}
export function overviewReport(rows:Transport[],scope:string[]):Report{
 return {title:'Transport overview',scope,count:rows.length,sections:[
  {title:'Journey stages',columns:['Stage','Requests'],rows:stageCounts(rows).map(s=>[s.label,String(s.value)])},
  {title:'Scheduled movement by hour',paragraphs:['Scheduled departures and expected returns grouped by local hour. Includes unallocated demand; excludes cancelled and unfulfilled requests. These are not actual movement times.'],columns:['Hour','Scheduled departures','Expected returns'],rows:scheduleCounts(rows).map(h=>[h.label,String(h.departures),String(h.returns)])},
  {title:'Needs attention',columns:['Record / passenger','Action','Next person'],rows:attentionItems(rows).map(i=>[i.r.id+' · '+i.r.person,i.title,i.owner])},
  {title:'Source context',paragraphs:['Counts use permitted records in the selected scope. Journey completion and vehicle custody remain separate. Example observation time '+NOW+'. No historical trend or on-time performance is inferred.']},
 ]};
}
