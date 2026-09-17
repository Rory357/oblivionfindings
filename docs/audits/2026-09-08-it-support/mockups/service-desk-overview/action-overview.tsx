import {useEffect, useRef, useState, type ReactNode} from 'react';
import {Button} from '@/components/ui/button';
import {StatusBadge} from '@/components/ui/status-badge';
import {Popover, PopoverContent, PopoverTrigger} from '@/components/ui/popover';
import {ArrowRight, ArrowUpRight, MessageSquareText, UserRound, Clock, CircleHelp, ChevronDown, Check, Inbox, Activity, FilterX, Eye} from 'lucide-react';

type Ticket = {
 id:number; title:string; priority:string; owner:string|null; requester:string; site:string;
 status:string; party:string; first:boolean; age:number; ageLabel:string; state:string;
 response:string; resolution:string; next:string; detail:string;
};
type View = 'attention'|'mine'|'unassigned'|'breached'|'breaching'|'awaiting_it'|'awaiting_reply'|'waiting_requester'|'waiting'|'aging'|'all_open'|'unmeasured'|'recently_resolved';
type Props = {
 tickets:Ticket[]; priority:string; view:View;
 viewInfo:Record<View,{label:string;blurb:string;href:string}>;
 isMatch:(t:Ticket,v:View)=>boolean;
 onViewChange:(v:View)=>void; onClearPriority:()=>void;
 onPeek:(id:number,reply?:boolean)=>void; onTake:(t:Ticket)=>void;
 onNavigate:(href:string,title?:string)=>void;
 renderTable:(list:Ticket[])=>ReactNode;
};
const cap=(s:string)=>s.charAt(0).toUpperCase()+s.slice(1);
const tone=(s:string)=>s.includes('Breached')?'critical':s.includes('At risk')?'warning':'neutral';
function SlaFact({name,value}:{name:string;value:string}) {
 return <div className={`ao-sla-fact ${tone(value)}`}><span>{name}</span><strong>{value}</strong></div>;
}

export function ActionOverview({tickets,priority,view,viewInfo,isMatch,onViewChange,onClearPriority,onPeek,onTake,onNavigate,renderTable}:Props) {
 const [expanded,setExpanded]=useState(false);
 const [assignedId,setAssignedId]=useState<number|null>(null);
 const assignedActionRef=useRef<HTMLButtonElement>(null);
 const [priorityMenu,setPriorityMenu]=useState(false);
 useEffect(()=>{setExpanded(false);},[view,priority]);
 useEffect(()=>{setAssignedId(null);},[priority]);
 useEffect(()=>{if(assignedId) assignedActionRef.current?.focus();},[assignedId]);
 const scope=tickets.filter(t=>priority==='all'||t.priority===priority);
 const firstReplies=scope.filter(t=>t.first);
 const response=firstReplies.find(t=>t.response.includes('Breached'))??firstReplies.find(t=>t.response.includes('At risk'))??firstReplies[0];
 const unassigned=scope.filter(t=>!t.owner&&t.id!==response?.id);
 const newlyAssigned=scope.find(t=>t.id===assignedId&&t.owner==='Alex Morgan');
 const assignment=newlyAssigned??unassigned.find(t=>t.priority==='urgent')??unassigned[0];
 const followUp=scope.filter(t=>t.age>7&&t.party==='it').sort((a,b)=>b.age-a.age)[0]
  ??scope.filter(t=>t.age>7).sort((a,b)=>b.age-a.age)[0];
 const featuredIds=[response?.id,assignment?.id,followUp?.id];
 const matching=scope.filter(t=>isMatch(t,view)).sort((a,b)=>view==='aging'?b.age-a.age:0);
 const supporting=view==='attention'?matching.filter(t=>!featuredIds.includes(t.id)):matching;
 const shown=expanded?supporting:supporting.slice(0,3);
 const queueViews:View[]=['attention','mine','unassigned','breached','breaching','awaiting_it','awaiting_reply','waiting_requester','aging','waiting','unmeasured','all_open'];
 const openHref=viewInfo[view].href||viewInfo.all_open.href;
 const followContext=followUp?.party==='it'?'Awaiting IT':followUp?.party==='requester'?'Awaiting requester':followUp?.party==='vendor'?'Waiting on vendor':'Waiting on approver';

 return <section id="overview-content" className="ao-overview" aria-label="Service Desk Overview redesign">
  <div className="ao-heading"><div><h2>What needs you now</h2><p>{priority==='all'?'A missed response first. Then assignment and follow-up.':`Actions for ${priority}-priority tickets.`}</p></div><span className="mock-label"><span/>Interactive mockup · synthetic data</span></div>
  <div className="ao-actions" aria-label="Prioritised ticket actions">
   <article className={`ao-response ${response?.response.includes('Breached')?'has-breach':''}`}>
    <div className="ao-action-label"><span className="ao-icon"><MessageSquareText size={17}/></span><span>First response</span>{response&&<StatusBadge variant={tone(response.response)}>{response.response.includes('Breached')?'SLA breached':response.response.includes('At risk')?'SLA at risk':'Reply needed'}</StatusBadge>}</div>
    {response?<><h3>{response.title}</h3><div className="ao-ticket-meta"><span>IT-{response.id}</span><StatusBadge variant={response.priority==='urgent'||response.priority==='high'?'critical':response.priority==='normal'?'info':'neutral'} size="sm">{cap(response.priority)}</StatusBadge><span>{response.site}</span></div>
     <p className="ao-reason">No first response recorded. {response.owner?`Assigned to ${response.owner==='Alex Morgan'?'you':response.owner}.`:'No technician assigned yet.'}</p>
     <div className="ao-response-clocks"><SlaFact name="Response SLA" value={response.response}/><SlaFact name="Resolution SLA" value={response.resolution}/></div>
     <div className="ao-response-footer"><Button onClick={()=>onPeek(response.id,true)}>Draft first reply<ArrowRight/></Button><button className="ao-text-action" onClick={()=>onPeek(response.id)}>View ticket<ArrowUpRight size={14}/></button><span>Draft only · nothing sent</span></div>
    </>:<div className="ao-action-empty"><Inbox size={24}/><h3>No first replies in this selection</h3><p>Use the queues below to find other permitted work.</p><Button variant="outline" size="sm" onClick={()=>onViewChange('mine')}>View my work</Button></div>}
   </article>
   <div className="ao-side-actions">
    <article className="ao-small-action" aria-label="Assignment action">
     <div className="ao-small-top"><span className="ao-icon"><UserRound size={17}/></span><h3>{newlyAssigned?'Assigned to you':assignment?.priority==='urgent'?'Assign the next urgent ticket':assignment?'Assign an unassigned ticket':'Unassigned work'}</h3>
      <Popover open={priorityMenu} onOpenChange={setPriorityMenu}><PopoverTrigger asChild><button className="ao-icon-menu" aria-label="Browse unassigned tickets by priority"><ChevronDown size={16}/></button></PopoverTrigger><PopoverContent className="w-64 p-2" align="end"><div className="ao-popover-title">Unassigned · full queue</div>{['urgent','high','normal','low'].map(pri=><button className="ao-priority-option" key={pri} onClick={()=>{setPriorityMenu(false);onNavigate(`/it?tab=tickets&view=unassigned&ticket_priority=${pri}`);}}><span>{cap(pri)}</span><b>{tickets.filter(t=>!t.owner&&t.priority===pri).length}</b><ArrowUpRight size={13}/></button>)}</PopoverContent></Popover>
     </div>
     {assignment?<><button className="ao-ticket-link" onClick={()=>onPeek(assignment.id)}>{assignment.title}<ArrowUpRight size={13}/></button><p className="ao-small-meta">IT-{assignment.id}<span>·</span>{cap(assignment.priority)}<span>·</span>{newlyAssigned?'You are the assigned technician':'Response '+assignment.response.toLowerCase().replace('at risk · ','')}</p><div className="ao-small-footer">{newlyAssigned?<><span className="ao-assigned" role="status"><Check size={14}/>Assigned in this mockup</span><button ref={assignedActionRef} className="ao-text-action" onClick={()=>onViewChange('unassigned')}>Remaining work<ArrowRight size={14}/></button></>:<><Button variant="outline" size="sm" onClick={()=>{setAssignedId(assignment.id);onTake(assignment);}}>Assign to me</Button><span>Ready to pick up</span></>}</div></>:<div className="ao-small-empty"><p>No other unassigned tickets in this selection.</p><button className="ao-text-action" onClick={()=>onViewChange('unassigned')}>Review unassigned queue<ArrowRight size={14}/></button></div>}
    </article>
    <article className="ao-small-action" aria-label="Ageing follow-up action">
     <div className="ao-small-top"><span className="ao-icon"><Clock size={17}/></span><h3>Move an ageing ticket forward</h3></div>
     {followUp?<><button className="ao-ticket-link" onClick={()=>onPeek(followUp.id)}>{followUp.title}<ArrowUpRight size={13}/></button><p className="ao-small-meta">IT-{followUp.id}<span>·</span>{followUp.ageLabel} open<span>·</span>{followContext}</p><div className="ao-small-footer"><button className="ao-text-action" onClick={()=>onPeek(followUp.id)}>Review follow-up<ArrowRight size={14}/></button><span>{followUp.owner==='Alex Morgan'?'Assigned to you':followUp.owner??'Unassigned'}</span></div></>:<div className="ao-small-empty"><p>No tickets older than 7 days in this selection.</p><button className="ao-text-action" onClick={()=>onViewChange('waiting')}>Review waiting work<ArrowRight size={14}/></button></div>}
    </article>
   </div>
  </div>

  <section className="ao-supporting" aria-label="Supporting ticket list">
   <div className="ao-list-heading"><div><h2>{view==='attention'?'Next in line':viewInfo[view].label}</h2><p>{view==='attention'?'Other tickets with a recorded SLA alert.':viewInfo[view].blurb}</p></div><div className="ao-queue-controls"><label htmlFor="ao-queue">Queue</label><select id="ao-queue" aria-label="Supporting ticket queue" value={view} onChange={e=>onViewChange(e.target.value as View)}>{queueViews.map(v=><option key={v} value={v}>{viewInfo[v].label} ({scope.filter(t=>isMatch(t,v)).length})</option>)}</select><a href={openHref} onClick={e=>{e.preventDefault();onNavigate(openHref,view==='aging'?'All open · oldest first':undefined);}}>{view==='attention'?'Browse all tickets':'Open full queue'}<ArrowUpRight size={14}/></a></div></div>
   <div className="ao-list-surface">
    {shown.length?renderTable(shown):<div className="ao-empty-state"><FilterX size={23}/><div><h3>{matching.length&&view==='attention'?'The matching alerts are shown above':`No ${priority==='all'?'':priority+'-priority '}tickets in this queue`}</h3><p>{matching.length&&view==='attention'?'Work from the action cards or choose another queue.':'This selection has no recorded matches. Missing SLA measurements still need separate review.'}</p></div><Button size="sm" variant="outline" onClick={onClearPriority}>Clear Priority</Button></div>}
    <div className="ao-list-footer"><span>{shown.length?`${shown.length} of ${supporting.length} shown`:'0 additional tickets'}{view==='attention'?' · featured actions excluded':''}</span>{supporting.length>3?<button className="ao-text-action" onClick={()=>setExpanded(!expanded)}>{expanded?'Show fewer':`Show ${supporting.length-3} more`}<ChevronDown size={14}/></button>:<span><Eye size={13}/>Select a title for a quick preview</span>}</div>
   </div>
  </section>

  <div className="ao-evidence"><CircleHelp size={17}/><p><strong>Across the desk, 3 open tickets have unmeasured SLA evidence.</strong> Watchdog checks are unverified.</p><button className="ao-text-action" onClick={()=>onViewChange('unmeasured')}>Review measurement<ArrowRight size={14}/></button></div>
  <section className="ao-activity"><div className="ao-activity-title"><Activity size={16}/><h2>Recent activity</h2><span>13 Sep · synthetic snapshot</span></div><div className="ao-activity-grid">{[{id:2039,who:'System',verb:'flagged a resolution breach',at:'9:18 am'},{id:2050,who:'Isla Brown',verb:'added a public reply',at:'9:10 am'},{id:2042,who:'Maya Patel',verb:'picked up the ticket',at:'8:56 am'}].map(a=><button key={a.id} onClick={()=>onPeek(a.id)}><span><strong>{a.who}</strong> {a.verb}</span><small>IT-{a.id}<span>·</span>{a.at}</small></button>)}</div></section>
  <footer className="ao-footer"><span>Average first response · 30d: <strong>Unavailable</strong></span><a href="/it/reports" onClick={e=>{e.preventDefault();onNavigate('/it/reports');}}>Service reports<ArrowUpRight size={13}/></a><span>Review only · no live changes or messages</span></footer>
 </section>;
}
