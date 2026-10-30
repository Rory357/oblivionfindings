import fs from 'node:fs';const dir=new URL('./',import.meta.url);const edit=(n,fn)=>{const p=new URL(n,dir);fs.writeFileSync(p,fn(fs.readFileSync(p,'utf8')))};
edit('app.tsx',s=>{
 s=s.replace("import {TransportCalendar}","import {PlannerBuilder} from './planner-builder';\nimport {planIssues,planFingerprint,type KeptDraft,type PlanDraft} from './planning';\nimport {TransportCalendar}");
 s=s.replace("const [plannerEpoch,setPlannerEpoch]=useState(0);","const [builderDrafts,setBuilderDrafts]=useState<Record<string,KeptDraft>>({});\n    const [plannerEpoch,setPlannerEpoch]=useState(0);");
 s=s.replace("setPeek(null);setResumePeek(null);setExportData(null)","setPeek(null);setResumePeek(null);setExportData(null);setBuilderDrafts({});setPlannerDirty(false)");
 s=s.replace("const result = executeWithKeys(store, command, context);",`if(command.type==='allocate'||command.type==='reschedule'){const r=store.records.find(v=>v.id===command.record)!;const p=Object.fromEntries(['vehicle','driver','escort','equipment','start','end'].map(k=>[k,command.payload[k]])) as PlanDraft;const rows=store.records.filter(v=>permitted(v,role,load));const issues=planIssues(r,p,rows,availability);if(issues.length)throw Error(issues.map(i=>i.message).join(' '));if(command.payload.planCheck!==planFingerprint(r,p,rows,availability))throw Error('Availability changed. Check the current plan before saving.');}const result = executeWithKeys(store, command, context);`);
 s=s.replace("if(peek){setResumePeek(peek);setPeek(null)}setForm", "if(kind==='allocate'||kind==='reschedule'){setPlannerId(r?.id||'');setPlannerMode('allocate');navigate('planner');return}if(peek){setResumePeek(peek);setPeek(null)}setForm");
 s=s.replace("setStore(seedKeys(initialStore()));", "setBuilderDrafts({});setPlannerDirty(false);setStore(seedKeys(initialStore()));");
 const start=s.indexOf('    function Planner()'),end=s.indexOf('    function History(',start);if(start<0||end<0)throw Error('Planner boundary missing');
 s=s.slice(0,start)+`    function Planner(){
      const records=store.records.filter(r=>permitted(r,role,load)&&(route.site==='all'||r.site===route.site));
      if(plannerMode==='calendar')return <div className="page-stack"><div className="pb-workspace-heading"><div><h2>Transport calendar</h2><p>The same Fleet bookings, arranged by day, week or month.</p></div><Button variant="outline" onClick={()=>setPlannerMode('allocate')}><ArrowLeft className="size-4"/>Back to plan builder</Button></div><TransportCalendar records={records} query={query} day={route.day} onDay={day=>navigate('planner','','planner',route.from,route.site,day)} onOpen={r=>quickView(r,'bookings')} onContext={(e,r)=>menus.open(e,{r,kind:'bookings'})} onFleet={()=>setSource('Fleet calendar')}/></div>;
      return <PlannerBuilder records={plannerRows} allRecords={store.records.filter(r=>permitted(r,role,load))} role={role} load={load} availability={availability} onAvailability={setAvailability} selectedId={plannerId} onSelect={setPlannerId} dirty={plannerDirty} onDirty={setPlannerDirty} drafts={builderDrafts} onKeep={(id,draft)=>setBuilderDrafts(v=>{const next={...v};if(draft)next[id]=draft;else delete next[id];return next})} onCommit={commit} onOpen={r=>openRecord(r,r.booking?'bookings':'requests')} onAction={openForm} onCalendar={()=>setPlannerMode('calendar')} onSaved={notify}/>;
    }
`+s.slice(end);
 s=s.replace("'Select a request · choose resources · check and save'","'Build a transport plan · review it together · send for a decision'");
 s=s.replaceAll('Save or cancel the proposal before','Keep or reset your draft before').replaceAll('Save or cancel this proposal before','Keep or reset your draft before');
 return s;
});
edit('index.html',s=>s.replace('</head>','<link rel="stylesheet" href="./planner-builder.css"></head>'));
