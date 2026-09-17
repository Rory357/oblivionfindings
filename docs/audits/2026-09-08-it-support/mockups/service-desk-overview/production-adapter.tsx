// Isolated visual QA: actual application component, existing synthetic mockup data.
import {ItOverviewBoard} from '@/components/it/it-overview-board';
import {overviewQueues} from '@/components/it/it-overview-workboard';
const at='2026-09-13T02:00:00Z';
const clock=(text:string)=>({state:text.includes('Breached')?'breached':text.includes('At risk')?'at_risk':text.includes('Met')?'met':text.includes('Paused')?'paused':text.includes('Unmeasured')?'unmeasured':'ok',due_at:at,completed_at:text.includes('Met')?'2026-09-13T01:00:00Z':null,remaining_minutes:text.includes('At risk')?Number(text.match(/(\d+)m/)?.[1]??24):null,reason:null,breached_at:null,ever_breached:text.includes('Breached'),policy_recorded:!text.includes('Unmeasured'),paused:text.includes('Paused'),paused_minutes:0});
export function ActionOverview(props:any) {
 const rows=Object.fromEntries(props.tickets.map((t:any)=>[t.id,{id:t.id,reference:`IT-${t.id}`,lock_version:7,title:t.title,priority:t.priority,status:t.status,site:t.site,assignee:t.owner,assigned_to_user_id:t.owner==='Alex Morgan'?12:t.owner?22:null,age:t.ageLabel,first_reply_needed:t.first,waiting_party:t.party,conversation:{last_public:null,next_response_party:['it','requester'].includes(t.party)?t.party:null,state:t.party==='it'?'awaiting_it':t.party==='requester'?'awaiting_requester':'unknown'},can_manage:true,sla:{state:t.state==='at-risk'?'at_risk':t.state,coverage:t.response.includes('Unmeasured')?'none':t.resolution.includes('Unmeasured')?'partial':'full',ever_breached:t.state==='breached',evaluated_at:at,clocks:{first_response:clock(t.response),resolution:clock(t.resolution)}}}]));
 const scopes=Object.fromEntries(['all','urgent','high','normal','low'].map(pri=>{
  const tickets=props.tickets.filter((t:any)=>pri==='all'||t.priority===pri);
  const response=tickets.find((t:any)=>t.first&&t.response.includes('Breached'))??tickets.find((t:any)=>t.first);
  const assignment=tickets.find((t:any)=>!t.owner&&t.id!==response?.id);
  const followup=tickets.find((t:any)=>t.age>7&&t.party==='it')??tickets.find((t:any)=>t.age>7);
  return [pri,{actions:{response:response?.id??null,assignment:assignment?.id??null,follow_up:followup?.id??null},queues:Object.fromEntries(Object.keys(overviewQueues).map(key=>{const matching=tickets.filter((t:any)=>props.isMatch(t,key));return[key,{total:matching.length,ids:matching.slice(0,6).map((t:any)=>t.id)}]}))}];
 }));
 return <ItOverviewBoard board={{scopes,tickets:rows,unmeasured_total:3,evaluated_at:at} as any} priority={props.priority==='all'?null:props.priority} conversationReady canManage actorId={12} onOpenTicket={props.onPeek} onAssign={(row:any)=>props.onTake(props.tickets.find((t:any)=>t.id===row.id))} onClearPriority={props.onClearPriority} average={null} activity={<p className="text-caption">Synthetic visual QA of the production component</p>}/>;
}
