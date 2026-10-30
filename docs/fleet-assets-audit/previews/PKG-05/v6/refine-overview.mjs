import fs from 'node:fs/promises';
import path from 'node:path';
import {fileURLToPath} from 'node:url';
const here=path.dirname(fileURLToPath(import.meta.url));
const read=name=>fs.readFile(path.join(here,name),'utf8'),write=(name,body)=>fs.writeFile(path.join(here,name),body);
let app=await read('app.tsx');
app=app.replace("import {PlannerBuilder}","import {TransportOverview} from './transport-overview';\nimport {SectionTabs} from './section-tabs';\nimport {PlannerBuilder}");
const begin=app.indexOf('    function Overview() {'),end=app.indexOf('    function Lists(){',begin);
app=app.slice(0,begin)+`    function Overview(){return <TransportOverview rows={searchRows(scoped)} day={route.day} site={route.site} query={query} load={load} onQuery={q=>setQueries(v=>({...v,overview:q}))} onRefresh={()=>setLoad('ready')} onDrill={(view,f)=>{setQueries(v=>({...v,[view]:query}));drill(view as View,f)}} onOpen={quickView} onCalendar={()=>navigate('calendar')} onOutings={()=>setSource('Outings')} onExport={openExport}/>;}
`+app.slice(end);
// The user's explicit two-tier correction keeps secondary navigation visible.
// All second rows use the shared Rule 2 component; no white card strip.
app=app.replace(/\{\['requests','journeys','returns'\]\.includes\(route\.view\)&&<PageHeaderFilterSelect label=\{queueOptions\[0\]\[1\]\}.*?\/>\}/,'');
app=app.replace("[['all','All returns'],['due','Due back']","[['all','All returns'],['due','Due back'],['overdue','Overdue']");
const marker='        {load===\'stale\'&&<Alert';
const tabs=`        <SectionTabs id={route.view+'-sections'} label={labels[route.view]+' sections'} value={filter} onChange={value=>setFilters(v=>({...v,[route.view]:value}))} tabs={queueOptions.map(([key,label],index)=>({key,label,icon:[Inbox,Clock,ClipboardList,KeyRound,Users,Flag,CheckCircle2][index%7]}))}/>
`;
app=app.replace(marker,tabs+marker);
app=app.replace("route.view!=='overview'&&<PageHeaderSearch", "<PageHeaderSearch");
// Remove the now-unneeded conditional braces, retaining one search on every view.
app=app.replace('>:<>{<PageHeaderSearch','>:<><PageHeaderSearch');
app=app.replace("[route.view]:text}))}}/>}{['requester'", "[route.view]:text}))}}/>{['requester'");
app=app.replace("    const overviewMeters = [","    const overviewScope=searchRows(scoped);\n    const overviewMeters = [");
const ms=app.indexOf('    const overviewMeters = ['),me=app.indexOf('    const count=',ms);
app=app.slice(0,ms)+app.slice(ms,me).replaceAll('scoped.filter','overviewScope.filter')+app.slice(me);
app=app.replace('<TransportCalendar records=',"<TransportCalendar workspaceTabs={tabs} onWorkspace={view=>navigate(view as View)} records=");
await write('app.tsx',app);
let planner=await read('planner-builder.tsx');
planner=planner.replace("import {planFrom", "import {SectionTabs} from './section-tabs';\nimport {planFrom");
const pstart=planner.indexOf('<div className="pb-queue-tabs"'),pend=planner.indexOf('</div>',pstart)+6;
planner=planner.slice(0,pstart)+planner.slice(pend);
const where='  <div className="pb-workspace">';
planner=planner.replace(where,`  <SectionTabs id="planner-sections" label="Planner request filter" value={queue} onChange={id=>change(()=>{setQueue(id);setSuccess('');onSelect('')})} tabs={[{key:'all',label:'All',icon:ClipboardCheck,count:records.length},{key:'ready',label:'Ready',icon:CheckCircle2,count:ready},{key:'planned',label:'Planned',icon:CalendarDays,count:records.filter(r=>!!r.booking).length}]}/>
`+where);
await write('planner-builder.tsx',planner);
let cal=await read('transport-calendar.tsx');
cal=cal.replace("import type {MenuItem}","import {SectionTabs} from './section-tabs';\nimport type {GroupedProfileNavTab} from '@/components/page/grouped-profile-nav';\nimport type {MenuItem}");
cal=cal.replace('type Props={records:', 'type Props={workspaceTabs:GroupedProfileNavTab[];onWorkspace:(key:string)=>void;records:');
cal=cal.replace('TransportCalendar({records,','TransportCalendar({workspaceTabs,onWorkspace,records,');
cal=cal.replace('rail={<PageHeaderRail items={views} value={view} onSelect={setView}/>}','rail={<PageHeaderRail items={workspaceTabs} value="calendar" onSelect={onWorkspace}/>}');
cal=cal.replace('</Button></div>{!available?', '</Button></div><SectionTabs id="calendar-sections" label="Calendar views" tabs={views} value={view} onChange={v=>setView(v as CalView)}/>{!available?');
await write('transport-calendar.tsx',cal);
let html=await read('index.html');html=html.replace('</head>','<link rel="stylesheet" href="./overview.css"></head>');await write('index.html',html);
console.log('Applied preview-only overview and shared secondary navigation.');
