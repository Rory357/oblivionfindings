const fs=require('node:fs');
const root='docs/fleet-assets-audit/previews/PKG-02B/v2/';
let main=fs.readFileSync(root+'main.tsx','utf8');
const replace=(text,old,next)=>{if(!text.includes(old)) throw new Error('Missing anchor '+old.slice(0,90));return text.replace(old,next);};
main=replace(main,'import "./styles.css";','import { VehicleCalendar } from "./vehicle-calendar";\nimport { VehicleMap } from "./vehicle-map";\nimport "./styles.css";');
main=replace(main,'  { key: "calendar", label: "Calendar", icon: CalendarDays },','  { key: "map", label: "Map", icon: MapPin },\n  { key: "calendar", label: "Calendar", icon: CalendarDays },');
main=replace(main,'    { key: "tracking", label: "Tracking", icon: Satellite },','');
main=replace(main,'  calendar: [{ key: "summary", label: "Upcoming context", icon: CalendarDays }],','  map: [{ key: "summary", label: "Map & observations", icon: MapPin }],\n  calendar: [{ key: "summary", label: "Calendar", icon: CalendarDays }],');
main=main.replaceAll('onClick={() => setSub("tracking")}','onClick={() => nav("map")}').replaceAll('action={() => setSub("tracking")}','action={() => nav("map")}');
const trackStart=main.indexOf('                    {view === "overview" && sub === "tracking" && (');
const trackEnd=main.indexOf('                    {view === "compliance" && sub === "summary" && (',trackStart);
if(trackStart<0||trackEnd<0) throw new Error('Map replacement anchor');
main=main.slice(0,trackStart)+`                    {view === "map" && <VehicleMap noTracker={stale || empty} dark={dark} unavailable={fault === "map"} onMileage={() => nav("compliance", "mileage")} />}\n`+main.slice(trackEnd);
main=replace(main,'          ) : (\n            <>\n              <PageHeader','          ) : view === "calendar" && !work ? (\n            <VehicleCalendar hold={hold} empty={empty} ready={ready} onBack={() => nav("overview")} onOpen={(kind) => {setDetail(kind);setDialog("event");}} />\n          ) : (\n            <>\n              <PageHeader');
// The standalone calendar owns its canonical header and five-view rail.
const calStart=main.indexOf('                    {view === "calendar" && (');
const calEnd=main.indexOf('\n                  </div>\n                </>\n              )}',calStart);
if(calStart<0||calEnd<0)throw new Error('Old calendar block');
main=main.slice(0,calStart)+main.slice(calEnd);
main=main.replaceAll('PKG-02B v1','PKG-02B v2').replaceAll('PKG-02B · v1','PKG-02B · v2');
main=replace(main,'<option value="upload">Partial upload failure</option>','<option value="upload">Partial upload failure</option>\n            <option value="map">Map imagery unavailable</option>');
main=replace(main,'          linked={Boolean(linked[run.id])}','          linked={Boolean(linked[run.id])}\n          canReport={!actionDisabled}');
main=replace(main,'          title="Find in this vehicle"','          size="standard"\n          icon={Search}\n          title="Find in this vehicle"');
main=replace(main,'          title={\n            detail === "booking"','          icon={CalendarDays}\n          title={\n            detail === "booking"');
fs.writeFileSync(root+'main.tsx',main);

let flows=fs.readFileSync(root+'flows.tsx','utf8');
const start=flows.indexOf('export function RunDetail('),end=flows.indexOf('export function ReportFlow(',start);
if(start<0||end<0)throw new Error('Run detail anchor');
flows=flows.slice(0,start)+`export function RunDetail({run,onClose,onReport,linked,canReport=true}:{run:Run;onClose:()=>void;onReport:()=>void;linked:boolean;canReport?:boolean}) {
  const [section,setSection]=useState(0);
  const sections=[{key:"record",label:"Check record",blurb:"Outcome and original answers",icon:ClipboardCheck},{key:"source",label:"Source & timing",blurb:"Version and observation",icon:History},{key:"followup",label:"Follow-up",blurb:"Evidence and related work",icon:Wrench}];
  return <WizardShell open title={\`Check \${run.id}\`} description={\`\${run.template} · Original submitted record\`} railIcon={ClipboardCheck} railTitle="Vehicle check" railSub={run.id} steps={sections} stepIndex={section} onStepClick={setSection} headerLabel={sections[section].label} pct={null} maxWidth="min(92vw, 1100px)" onClose={onClose} railExtra={<div className="detail-rail-summary"><Badge tone={run.outcome==="Failed"?"critical":run.outcome==="Passed"?"success":"warning"}>{run.outcome}</Badge><p>{run.version} · original version</p></div>} footerStart={<Button variant="outline" onClick={onClose}>Back to vehicle</Button>} footerEnd={canReport?<Button onClick={onReport}>{linked?"Review linked maintenance":"Create or link maintenance"}</Button>:<span className="muted">View-only access</span>}>
    <WizardStepPane k={section}>
      <div className="flow-stack">{locked}
      {section===0&&<><div className="section-intro"><div><span className="eyebrow">SUBMITTED CHECK</span><h3 className="text-section-title">{run.template}</h3></div><Badge tone={run.outcome==="Failed"?"critical":run.outcome==="Passed"?"success":"warning"}>{run.outcome}</Badge></div><ReviewCard icon={ClipboardCheck} title="Answers as submitted">{exampleQuestions.map((q,i)=><ReviewRow key={q} label={q} value={run.answers[i]}/>)}</ReviewCard><ReviewCard icon={MessageSquare} title="Observation notes"><p className="body-copy">{run.notes||"No additional notes recorded."}</p></ReviewCard><Notice title="The check is evidence, not a release">The original outcome stays with this submission. Maintenance assessment and any authorised release remain separate.</Notice></>}
      {section===1&&<><ReviewCard icon={FileText} title="Original source"><ReviewRow label="Template" value={run.template}/><ReviewRow label="Version" value={run.version}/><ReviewRow label="Run reference" value={run.id}/><ReviewRow label="Vehicle" value="Kōwhai van · VH-014"/></ReviewCard><ReviewCard icon={ClockIcon} title="Timing & author"><ReviewRow label="Observed" value={localDateTimeLabel(run.observed)}/><ReviewRow label="Submitted" value={run.submitted+" · Pacific/Auckland"}/><ReviewRow label="Recorded by" value="Alex Morgan · demo staff"/></ReviewCard><Notice title="Original evidence is preserved">Later templates or repair notes do not change these answers. Corrections retain their own author and record.</Notice></>}
      {section===2&&<><ReviewCard icon={Wrench} title="Maintenance follow-up"><ReviewRow label="Relationship" value={linked?"Linked to an existing work record":"No follow-up link recorded in this preview"}/><p className="body-copy mt-3">Carry this exact check and version into the maintenance report. The Coordinator assesses the condition and decides the next action.</p></ReviewCard><ReviewCard icon={FileText} title="Supporting evidence"><ReviewRow label="Original evidence" value={run.id==="CHK-0182"?"EV-DEMO-0182 · condition note":"No separate attachment recorded"}/><p className="body-copy mt-3">Files added to related work do not rewrite the submitted answers.</p></ReviewCard></>}
      </div>
    </WizardStepPane>
  </WizardShell>;
}

`+flows.slice(end);
flows=replace(flows,'  Loader2,','  Loader2,\n  Clock as ClockIcon,');
flows=replace(flows,'  const next = () => {','  const reportDirty = Boolean(title !== (run ? "Condition concern from vehicle check" : "Vehicle condition concern") || notes !== (run ? "Please review the original check and assess the next action." : "Please assess this vehicle concern and the next action.") || link || showDates || choice !== "link");\n  const closeReport = () => { if(!saving) saved || !reportDirty ? onClose() : setDiscard(true); };\n  const next = () => {');
const reportStart=flows.indexOf('export function ReportFlow(');
const submitIndex=flows.indexOf('  const submit = () => {',reportStart);
flows=flows.slice(0,submitIndex)+flows.slice(submitIndex).replace('  const submit = () => {','  const submit = () => {\n    if(saving) return;\n    if(!title.trim() || (showDates && (!range[0] || !range[1]))) {setStep(0);setError("Enter a title and finish the chosen range, or choose Not known yet.");return;}\n    if(!reportOnly && choice === "link" && !link) {setStep(1);setError("Choose an existing work record or create a new report.");return;}');
flows=flows.replaceAll('onStepClick={(i) => i < step && !saving && setStep(i)}','onStepClick={(i) => {if(!saving){setError("");setStep(i);}}}');
flows=replace(flows,'pct={step === 2 ? 100 : (step + 1) * 33}','pct={Math.round([Boolean(title.trim()),Boolean(notes.trim()),!showDates || Boolean(range[0] && range[1]),reportOnly || choice === "new" || Boolean(link)].filter(Boolean).length / 4 * 100)}');
flows=replace(flows,'onClose={() => (saved ? onClose() : setDiscard(true))}','onClose={closeReport}');
flows=replace(flows,'            variant="ghost"\n            disabled={saving}\n            onClick={() => setDiscard(true)}','            variant="outline"\n            disabled={saving}\n            onClick={closeReport}');
flows=flows.replaceAll('<Button disabled={saving} onClick={step < 2 ? next : submit}>','<Button disabled={saving} onClick={step < 2 ? next : submit}>\n              {saving && <Loader2 className="h-4 w-4 animate-spin"/>}');
flows=flows.replaceAll('<Button disabled={saving} onClick={step < 2 ? advance : submit}>','<Button disabled={saving} onClick={step < 2 ? advance : submit}>\n              {saving && <Loader2 className="h-4 w-4 animate-spin"/>}');
fs.writeFileSync(root+'flows.tsx',flows);
for(const name of ['serve.mjs','index.html']){let data=fs.readFileSync(root+name,'utf8').replaceAll('4336','4337').replaceAll('PKG-02B-v1','PKG-02B-v2').replaceAll('PKG-02B v1','PKG-02B v2').replaceAll('/v1/','/v2/');if(name==='serve.mjs')data=data.replace("img-src 'self' data: blob:;","img-src 'self' data: blob: https://tile.openstreetmap.org https://server.arcgisonline.com;");fs.writeFileSync(root+name,data);}
console.log('v2 calendar, map, modal and wizard changes applied; v1 untouched.');
