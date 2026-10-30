const fs=require('fs'),path=require('path');
const dir=path.resolve(__dirname,'../../../previews/PKG-02B/v3');
const read=f=>fs.readFileSync(path.join(dir,'../v2',f),'utf8');const write=(f,s)=>fs.writeFileSync(path.join(dir,f),s);
let s=read('main.tsx');
s=s.replace("import { VehicleMap } from './vehicle-map';","import { VehicleMap } from './vehicle-map';\nimport { useVehicleModel, VehicleSurface, WorkRecord, BookingPanel, CheckRequirements, OperationDialog, dateLabel, serviceStatus } from './operations';\nimport { ObservationHistory } from './observation-history';\nimport './operations.css';");
s=s.replace("{ key: 'summary', label: 'Evidence & due dates', icon: FileText },","{ key: 'summary', label: 'Evidence & due dates', icon: FileText },\n        { key: 'schedules', label: 'Service schedules', icon: Wrench },\n        { key: 'reminders', label: 'Reminders', icon: Bell },");
s=s.replace("[linked, setLinked] = useState<Record<string, string>>({}),","[linked, setLinked] = useState<Record<string, string>>({'CHK-0182':'WO-0264'}),\n        [checkTemplate, setCheckTemplate] = useState('condition'),\n        [observation, setObservation] = useState('latest'),");
s=s.replace("    const denied =", "    const model = useVehicleModel(scenario, fault);\n    const denied =");
s=s.replace(/    const hold = \['hold', 'awaiting', 'readonly', 'reportonly'\]\.includes\(\s*scenario,\s*\);/,"    const hold = model.hold;");
s=s.replace("    const show = (name: string) => {",`    const show = (name: string) => {
        if (name === 'Next service') { nav('compliance','schedules'); return; }
        if (name === 'Notifications') { nav('compliance','reminders'); return; }
        if (name === 'Progress') { model.assess(work || 'WO-0264'); return; }
        if (name === 'Complete work') { model.complete(work || 'WO-0264'); return; }
        if (name === 'Cancelled work') { openWork('WO-0210'); return; }
        if (name === 'Mileage source') { nav('compliance','mileage'); return; }
        if (['WoF','Registration','RUC','CoF'].includes(name)) { nav('compliance'); return; }
`);
const evstart=s.indexOf("            value: empty\r\n                ? 'Not scheduled'");
// Handle either newline convention.
s=s.replace(/value: empty\s*\? 'Not scheduled'\s*: overdue\s*\? '18 Sep 2026 \/ 82,000 km'\s*: '24 Sep 2026 \/ 85,000 km',/,"value: model.data.schedules[0] ? serviceStatus(model.data.schedules[0], model.odo).label : 'Not scheduled',");
s=s.replace("value: empty ? 'Not recorded' : '82,460 km',","value: model.odo ? `${model.odo.toLocaleString()} km` : 'Not recorded',");
s=s.replace("setScenario(e.target.value);", "setScenario(e.target.value);\n                            setRuns([originalRun]); setRun(originalRun); setLinked({'CHK-0182':'WO-0264'}); setCreatedReport(false); setCheckTemplate('condition');");
s=s.replace("                    ) : view === 'calendar' && !work ? (\r\n                        <VehicleCalendar", "                    ) : view === 'calendar' && !work ? (\r\n                        <><VehicleCalendar");
if(!s.includes('<><VehicleCalendar')) s=s.replace("                    ) : view === 'calendar' && !work ? (\n                        <VehicleCalendar", "                    ) : view === 'calendar' && !work ? (\n                        <><VehicleCalendar");
const calstart=s.indexOf('<><VehicleCalendar'),calend=s.indexOf('                    ) : (',calstart);
s=s.slice(0,calstart)+`<><VehicleCalendar
                            hold={hold} empty={empty} ready={ready}
                            items={model.calendarItems}
                            onCreate={model.canRequest ? (start) => model.booking(start) : undefined}
                            onBack={() => nav('overview')}
                            onOpen={(id) => {
                                if(model.data.works.some(w=>w.id===id)) { openWork(id); return; }
                                if(model.data.schedules.some(s=>s.id===id)) { nav('compliance','schedules'); return; }
                                if(model.data.compliance.some(c=>c.id===id)) { nav('compliance'); return; }
                                if(id==='check-due') { nav('checks'); return; }
                                if(id==='restriction') { model.detail('Restriction record',[['Reference','RST-DEMO-12'],['Started','21 Sep 2026 · 8:20 am'],['End','No release recorded'],['Owner','Operations Manager'],['Source','CHK-0182 → WO-0264']]); return; }
                                const booking=model.data.bookings.find(b=>b.id===id);
                                model.detail(booking?'Booking record':'Busy',booking?[['Reference',booking.id],['Purpose',booking.purpose],['Status',booking.status],['Next action','Use Vehicle bookings & custody below the calendar.']]:[['Busy interval','25 Sep 10:00 am–12:00 pm'],['Access','Booking details restricted']]);
                            }}
                        /><div className="content"><BookingPanel model={model}/></div></>
`+s.slice(calend);
const ws=s.indexOf("                            {work ? (",s.indexOf('{readonly && (')),we=s.indexOf("                                    <TierTwoTabs",ws);
s=s.slice(0,ws)+`                            {work ? (
                                <WorkRecord model={model} id={work} onBack={closeWork} onCheck={()=>{setRun(originalRun);setDialog('run');}}/>
                            ) : (
                                <>
`+s.slice(we);
const cs=s.indexOf("                                        {view === 'overview' &&"),ce=s.indexOf("                                        {view === 'checks' &&",cs);
s=s.slice(0,cs)+`                                        <VehicleSurface model={model} view={view} sub={sub} onNav={nav} onWork={openWork} onCheck={()=>setDialog('check')}/>
                                        {view === 'map' && <div className="stack"><VehicleMap noTracker={stale||empty} dark={dark} unavailable={fault==='map'} onMileage={()=>nav('compliance','mileage')} observation={observation}/><ObservationHistory noTracker={stale||empty} selected={observation} onSelect={setObservation} onDetail={model.detail}/></div>}
                                        {view === 'checks' && <CheckRequirements model={model} onStart={()=>{setCheckTemplate('condition');setDialog('check');}}/>}
`+s.slice(ce);
const ms=s.indexOf("                                        {view === 'maintenance' &&"),me=s.indexOf("                                    </div>\r\n                                </>",ms);
const mend=me<0?s.indexOf("                                    </div>\n                                </>",ms):me;
s=s.slice(0,ms)+s.slice(mend);
s=s.replace("{dialog === 'check' && (","{dialog === 'check' && !actionDisabled && (");
s=s.replace("                    nextId={`CHK-DEMO-", "                    initialTemplate={checkTemplate}\n                    nextId={`CHK-DEMO-");
s=s.replace("                    onDone={(r) => {", "                    onDone={(r) => {\n                        if(actionDisabled) return;");
s=s.replace("{dialog === 'report' && (","{dialog === 'report' && !actionDisabled && (");
s=s.replace("{dialog === 'upload' && (","{dialog === 'upload' && !actionDisabled && !reportOnly && (");
s=s.replace("                    canReport={!actionDisabled}","                    canReport={Boolean(linked[run.id]) || !actionDisabled}");
s=s.replace("                        if (actionDisabled) {", "                        if (actionDisabled && !linked[run.id]) {");
s=s.replace("if (id === 'WO-DEMO-0269') setCreatedReport(true);", "if (id === 'WO-DEMO-0269') { setCreatedReport(true); model.reportCreated(id, reportFromCheck ? run.id : 'Vehicle profile'); }");
s=s.replace(/onClick=\{\(\) =>\s*setDialog\(\s*'check',\s*\)\s*\}/g,"disabled={actionDisabled} onClick={() => {setCheckTemplate('return');setDialog('check');}}");
s=s.replace(/action=\{\(\) =>\s*setDialog\(\s*'check',\s*\)\s*\}/g,"action={() => {if(actionDisabled) {model.detail('Return condition template',[['Version','DEMO-2'],['Example questions','Exterior condition; cabin condition'],['Access','View only · no submission available']]);return;} setCheckTemplate('return');setDialog('check');}}");
// Any ordinary check entry resets the selected template.
s=s.replace(/onClick=\{\(\) => setDialog\('check'\)\}/g,"onClick={() => {setCheckTemplate('condition');setDialog('check');}}");
s=s.replace("                                ...views.map((v) => ({",`                                ...model.data.schedules.map(s=>({label:s.name+' · '+s.id,keywords:'schedule service',action:()=>nav('compliance','schedules')})),
                                ...model.data.works.map(w=>({label:w.title+' · '+w.id,keywords:w.source,action:()=>openWork(w.id)})),
                                ...model.data.compliance.map(c=>({label:c.name+' · '+c.id,keywords:c.evidence,action:()=>nav('compliance')})),
                                {label:'Reminders and delivery history',keywords:'notifications task',action:()=>nav('compliance','reminders')},
                                ...views.map((v) => ({`);
s=s.replace("            {dialog === 'check'",`            <OperationDialog model={model}/>
            {model.message && <div className="op-toast" role="status"><span>{model.message}</span>{model.canUndo&&<Button variant="outline" size="sm" onClick={model.undo}>Undo</Button>}<Button variant="ghost" size="sm" onClick={()=>model.setMessage('')}>Dismiss</Button></div>}
            {dialog === 'check'`);
s=s.replaceAll('PKG-02B v2','PKG-02B v3').replaceAll('PKG-02B · v2','PKG-02B · v3').replaceAll('As at 21 Sep 2026','As at 22 Sep 2026');
write('main.tsx',s);
s=read('flows.tsx').replace("    nextId,\n", "    nextId,\n    initialTemplate = 'condition',\n");
if(!s.includes("initialTemplate ="))s=s.replace("    nextId,\r\n", "    nextId,\r\n    initialTemplate = 'condition',\r\n");
s=s.replace('    nextId: string;', '    nextId: string;\n    initialTemplate?: string;').replace("useState('condition')","useState(initialTemplate)").replace("template !== 'condition'","template !== initialTemplate");write('flows.tsx',s);
s=read('vehicle-calendar.tsx').replace('const referenceDay = new Date(2026, 8, 21, 9, 30);','const referenceDay = new Date(2026, 8, 22, 9, 30);').replace("label: 'Bookings · busy only'","label: 'Bookings & unavailable'");
s=s.replace('    onBack,','    items,\n    onCreate,\n    onBack,').replace('    onBack: () => void;', '    items?: CalendarItem[];\n    onCreate?: (start: string) => void;\n    onBack: () => void;');
s=s.replace('(empty\r\n                ? []','(items ?? (empty\r\n                ? []').replace('(empty\n                ? []','(items ?? (empty\n                ? []');
s=s.replace('            ).map((entry)', '            )).map((entry)').replace('[empty, hold, ready]', '[empty, hold, ready, items]');
s=s.replace('        onSelect,',`        onSelect,
        onCreateAt: onCreate ? (date:Date,hour=9)=>onCreate(
            [date.getFullYear(),String(date.getMonth()+1).padStart(2,'0'),String(date.getDate()).padStart(2,'0')].join('-')+'T'+String(Math.floor(hour)).padStart(2,'0')+':'+String(Math.round((hour%1)*60)).padStart(2,'0')
        ) : undefined,`);
s=s.replace('                        <PageHeaderSearch',`                        {onCreate&&<PageHeaderGlassButton onClick={()=>onCreate([navDate.getFullYear(),String(navDate.getMonth()+1).padStart(2,'0'),String(navDate.getDate()).padStart(2,'0')].join('-')+'T09:00')}>Request booking</PageHeaderGlassButton>}
                        <PageHeaderSearch`);
// Header appointment derives from the same feed as the rendered calendar.
s=s.replace("{empty ? 'None' : '24 Sep'}", "{events.find(e=>e.source==='event')?new Date(events.find(e=>e.source==='event')!.start).toLocaleDateString('en-NZ',{day:'numeric',month:'short'}):'None'}");
s=s.replace("{empty\r\n                                    ? 'No source record'\r\n                                    : '9–11 am · internal plan'}", "{events.find(e=>e.source==='event')?.title??'No source record'}");
s=s.replace("{empty\n                                    ? 'No source record'\n                                    : '9–11 am · internal plan'}", "{events.find(e=>e.source==='event')?.title??'No source record'}");
write('vehicle-calendar.tsx',s);
for(const f of ['serve.mjs','index.html','README.md'])write(f,read(f).replaceAll('v2','v3').replaceAll('4337','4338'));
