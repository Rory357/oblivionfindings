// One-time v5 to v6 migration evidence. Do not rerun against the revised source. Use build.cjs to rebuild.
const fs = require('node:fs');
const path = require('node:path');
const target = path.join(__dirname, 'profile.tsx');
let s = fs.readFileSync(target, 'utf8').replace(/\r\n/g, '\n');
const rep = (a,b) => { if(!s.includes(a)) throw new Error('Missing: '+a.slice(0,100)); s=s.replace(a,b); };
const between = (a,b,replacement) => { const start=s.indexOf(a); const end=s.indexOf(b,start); if(start<0||end<0)throw new Error('Missing block '+a); s=s.slice(0,start)+replacement+s.slice(end); };
const button = (label) => { const end=s.indexOf('>'+label+'</Button>'); };
function takeButton(label, region) {
  const at=s.indexOf(label,s.indexOf(region));
  if(at<0)throw new Error('Missing button '+label);
  const start=s.lastIndexOf('<Button',at), end=s.indexOf('</Button>',at)+9;
  const out=s.slice(start,end);s=s.slice(0,start)+s.slice(end);return out;
}
s='import { FieldErrors, ModalFrame } from "./modal-ui";\n'+s;
rep('  Camera,','  Camera,\n  Loader2,');
rep('  hint,\n}: {\n  label: string;', '  hint,\n  required = false,\n}: {\n  label: string;');
rep('  hint?: string;\n}) {\n  const id = React.useId();','  hint?: string;\n  required?: boolean;\n}) {\n  const id = React.useId();\n  const fieldError = React.useContext(FieldErrors)[label];');
rep('<div className="field">\n      <label htmlFor={id}>{label}</label>', '<div className="field" data-field-name={label}>\n      <label htmlFor={id}>{label}{required && <span className="field-required">Required</span>}</label>');
rep('React.cloneElement(children as React.ReactElement<any>, { id })','React.cloneElement(children as React.ReactElement<any>, { id, "aria-required": required || undefined, "aria-invalid": !!fieldError, "aria-describedby": [hint ? id+"-hint" : "", fieldError ? id+"-error" : ""].filter(Boolean).join(" ") || undefined })');
rep('{hint && <small>{hint}</small>}', '{hint && <small id={id+"-hint"}>{hint}</small>}\n      {fieldError && <small className="field-error" id={id+"-error"}>{fieldError}</small>}');
rep('  const [open, setOpen] = useState(false);\n  const [state, setState]', '  const pickerId = React.useId();\n  const pickerError = React.useContext(FieldErrors)[label];\n  const [open, setOpen] = useState(false);\n  const [state, setState]');
rep('<div className="field">\n      <span>{label}</span>', '<div className="field" data-field-name={label}>\n      <label htmlFor={pickerId}>{label}</label>');
rep('            role="combobox"\n            aria-label={label}', '            id={pickerId}\n            role="combobox"\n            aria-invalid={!!pickerError}\n            aria-describedby={pickerError ? pickerId+"-error" : undefined}\n            aria-label={label}');
rep('<PopoverContent className="w-[360px] p-0" align="start">','<PopoverContent className="asset-picker p-0" style={{width:420,maxWidth:"calc(100vw - 48px)"}} align="start" collisionPadding={20} onEscapeKeyDown={e=>e.stopPropagation()}>');
rep('      </Popover>\n    </div>\n  );\n}\nfunction DatePicker', '      </Popover>\n      {pickerError && <small className="field-error" id={pickerId+"-error"}>{pickerError}</small>}\n    </div>\n  );\n}\nfunction DatePicker');
rep('  const [modal, setModal] = useState<any>(null),', '  const [fieldErrors, setFieldErrors] = useState<Record<string,string>>({});\n  const [modalTrail, setModalTrail] = useState<any[]>([]);\n  const [modal, setModal] = useState<any>(null),');
rep('  function open(type: string, data?: any) {', '  function open(type: string, data?: any) {\n    if (busy) return;');
rep('    lastTrigger.current = document.activeElement as HTMLElement;',`    if (!modal) {
      lastTrigger.current = document.activeElement as HTMLElement;
      setModalTrail([]);
      setMode("Transfer"); setDestination(currentLocation); setPerson(movement.recipient + " · " + movement.destination.split(" · ")[0]); setDate("2026-09-30");
    } else {
      setModalTrail(trail => [...trail, {modal, step, mode, destination, person, date, reason, dirty, files, docTitle, docType, receiptOutcome, editDraft, kitDraft, draftProgress, draftAction, note, notesOpen, error, fieldErrors, failure}]);
    }
    setFieldErrors({}); setNote(""); setNotesOpen(false);`);
// Replace the close tail with one common path that restores nested drafts and root focus.
rep('    setModal(null);\n    setTimeout(() => {\n      const target = lastTrigger.current;', `    leaveModal();
  }
  function leaveModal() {
    setDraftClose(false);
    if (modalTrail.length) {
      const frame = modalTrail[modalTrail.length-1];
      setModalTrail(trail=>trail.slice(0,-1));
      setModal(frame.modal.doc ? {...frame.modal,doc:docs.find(d=>d.id===frame.modal.doc.id)||frame.modal.doc} : frame.modal);
      setStep(frame.step); setMode(frame.mode); setDestination(frame.destination); setPerson(frame.person); setDate(frame.date); setReason(frame.reason); setDirty(frame.dirty); setFiles(frame.files); setDocTitle(frame.docTitle); setDocType(frame.docType); setReceiptOutcome(frame.receiptOutcome); setEditDraft(frame.editDraft); setKitDraft(frame.kitDraft); setDraftProgress(frame.draftProgress); setDraftAction(frame.draftAction); setNote(frame.note); setNotesOpen(frame.notesOpen); setError(frame.error); setFieldErrors(frame.fieldErrors); setFailure(frame.failure); setSuccess(""); setBusy(false);
      return;
    }
    setModal(null); setDirty(false); setNote(""); setFiles([]); setFieldErrors({});
    setTimeout(() => {
      const target = lastTrigger.current;`);
rep('  function say(s: string) {', `  useEffect(() => {
    if (!error || !modal) return;
    const timer = window.setTimeout(() => document.querySelector<HTMLElement>('.modal-error-summary')?.focus(), 280);
    return () => window.clearTimeout(timer);
  }, [error, step, modal?.type]);
  function say(s: string) {`);
rep('    setModal(null);\n    setWorkComplete(false);','    setModal(null);\n    setModalTrail([]); setFieldErrors({});\n    setWorkComplete(false);');
// Step semantics and validation are per flow. Rail remains freely navigable; submission revalidates every step.
between('      function advance() {','      function submit() {',`      const stepMeta: Record<string, {blurb:string;icon:any}> = {
        Movement:{blurb:"Type, destination and return",icon:Truck}, "Recipient & kit":{blurb:"Person and dispatched items",icon:UserRound},
        Identity:{blurb:"Name, serial and category",icon:Package}, "Ownership & condition":{blurb:"Owner and recorded condition",icon:ShieldCheck},
        "Files & details":{blurb:"File, title and classification",icon:Upload}, "Receipt & kit":{blurb:"Arrival, items and discrepancies",icon:ClipboardCheck},
        "Problem & evidence":{blurb:"Observation and supporting files",icon:CircleAlert}, Dependencies:{blurb:"Outstanding decisions and owners",icon:Archive}, Review:{blurb:"Confirm the details and outcome",icon:CheckCircle2}
      };
      function validate(all=false) {
        const problems: Array<{field:string;message:string;step:number}> = [];
        const add=(field:string,message:string,at=0)=>problems.push({field,message,step:at});
        if (isUpload) {
          if (!files.length) add("Document file","Choose one file before continuing.");
          if (!docTitle.trim()) add("Document title","Enter a title people can recognise.");
          if (type === "replace" && !reason.trim()) add("Reference / version reason","Explain why this version replaces the original.");
          if (modal.photo && files.some(f=>!f.type.startsWith("image/"))) add("Document file","Choose an image for the asset photo.");
        }
        if (type === "edit") {
          if (!editDraft.name.trim()) add("Asset name","Enter the asset name.");
          if (!editDraft.serial.trim()) add("Serial number","Enter the serial required by this sample.");
        }
        if (type === "report") {
          if (reportMode === "new" && !reportTitle.trim()) add("Problem summary","Give this new issue a short summary.");
          if (!reason.trim()) add("What happened?","Describe what you observed.");
        }
        if (type === "custody") {
          if (mode === "Loan" && hold) add("Movement","Loan for use is blocked while the asset hold is active.");
          if (!person.includes(destination.split(" · ")[0])) add("Responsible recipient","Choose a recipient permitted at the destination site.",1);
        }
        if (type === "receipt") {
          if (receiptOutcome === "Confirm complete receipt" && kitDraft.some(x=>!x)) add("Kit items","Confirm every item, or choose a discrepancy or dispute.");
          if (receiptOutcome !== "Confirm complete receipt" && !reason.trim()) add("Receipt notes / discrepancy","Describe the discrepancy or dispute.");
          if (actor !== movement.recipient) add("Receiving person","This receipt must be recorded by " + movement.recipient + " in this sample.");
        }
        const relevant=problems.filter(p=>all||p.step===step);
        setFieldErrors(Object.fromEntries(relevant.map(p=>[p.field,p.message])));
        if(relevant.length){setStep(relevant[0].step);setError("Before continuing: " + relevant.map(p=>p.message).join(" "));return false;}
        setError("");return true;
      }
      function advance(){if(!busy && validate())setStep(s=>Math.min(s+1,steps.length-1));}
`);
rep('      function submit() {\n        if (type === "retire")', '      function submit() {\n        if (busy || success || !validate(true)) return;\n        if (type === "retire")');
rep('        <WizardShell\n          open', '        <WizardShell\n          key={modal.type}\n          open\n          maxWidth="min(94vw, 1060px)"\n          maxHeight="min(88vh, 790px)"');
rep('railIcon={isUpload ? Upload : type === "edit" ? Package : UserRound}', 'railIcon={isUpload ? Upload : type === "edit" ? Package : type === "report" ? CircleAlert : type === "retire" ? Archive : type === "receipt" ? ClipboardCheck : Truck}');
rep('railSub="AS-104 · Transfer hoist"','railSub={"AS-104 · " + name}');
between('          steps={steps.map((s, i) => ({','          footerStart={',`          steps={steps.map(label=>({key:label,label,...stepMeta[label],disabled:busy}))}
          stepIndex={step}
          onStepClick={i=>{if(!busy){setStep(i);setError("");setFieldErrors({});}}}
          pct={null}
          railExtra={<div className="modal-rail-context"><LockKeyhole size={14}/><p>Private asset record<br/><strong>AS-104 · {name}</strong></p><small>Selections stay in this tab until you close or refresh.</small><details className="modal-preview-tools"><summary>Preview testing</summary>{footerFailure}</details></div>}
`);
rep('<Button variant="outline" onClick={close}>\n                Cancel','<Button variant="outline" disabled={busy} onClick={close}>\n                {modalTrail.length ? "Back to record" : "Cancel"}');
rep('<Button variant="ghost" onClick={() => setStep((s) => s - 1)}>','<Button variant="ghost" disabled={busy} onClick={() => {setStep(s=>s-1);setError("");setFieldErrors({});}}>');
rep('              {footerFailure}\n              <Button','              <Button');
rep('                {busy\n                  ? "Saving preview…"','                {busy && <Loader2 size={15} className="animate-spin"/>}\n                {busy\n                  ? "Saving preview…"');
rep(': "Save preview"\n                    : "Continue"}', ': type === "edit" ? "Save asset details" : "Record " + mode.toLowerCase()\n                    : step === steps.length-2 ? "Review details" : "Continue"}');
rep('                      setModal(null);\n                      say(success);','                      leaveModal();\n                      say(success);');
rep('                    Return to asset\n                  </Button>','                    {modalTrail.length ? "Back to record" : "Return to asset"}\n                  </Button>');
rep('<div className="form-stack">\n              <div className="locked-context">','<fieldset className="form-stack modal-form" disabled={busy}>\n              <div className="locked-context">');
rep('<strong>Transfer hoist</strong>\n                  <small>AS-104 · Kōwhai House · Synthetic record</small>','<strong>{name}</strong>\n                  <small>AS-104 · {currentLocation}</small>');
rep('<div className="form-error" role="alert">','<div className="form-error modal-error-summary" role="alert" tabIndex={-1}>');
rep('            </div>\n          </WizardStepPane>\n        </WizardShell>','            </fieldset>\n          </WizardStepPane>\n        </WizardShell>');
rep('<Truck size={19} />\n                          <strong>{x}</strong>','{x === "Assign" ? <UserRound size={19}/> : x === "Return" ? <RotateCcw size={19}/> : x === "Loan" ? <Clock3 size={19}/> : <Truck size={19}/>}\n                          <strong>{x}</strong>');
rep('{custody === "Awaiting receipt" && (','{["Awaiting receipt","Incomplete receipt","Disputed"].includes(custody) && (');
rep('Resolve TR-104-08 before starting another movement. You','Resolve {movement.reference} before starting another movement. You');
rep('<ReviewRow label="Recipient" value={person} />','<ReviewRow label="Recipient" value={person} />\n                        <ReviewRow label="Kit at dispatch" value={kitDraft.map((yes,i)=>(yes?"Included: ":"Not confirmed: ")+["Hoist frame","Battery pack","Charger"][i]).join(" · ")} />');
rep('value="Kōwhai House · Equipment room"','value={currentLocation}');
rep('<ReviewRow label="Classification" value={docType} />','<ReviewRow label="Classification" value={docType} />\n                        {type === "replace" && <ReviewRow label="Replaces" value={modal.doc.file+" · v"+modal.doc.version+" → v"+(modal.doc.version+1)}/>}\n                        {type === "replace" && <ReviewRow label="Original source" value={modal.doc.source}/>}');
rep('<ReviewRow label="Problem" value={reason} />','<ReviewRow label="Problem" value={reason} />\n                        <ReviewRow label="Evidence" value={files.length ? files.map(f=>f.name).join(", ") : "No files attached"}/>');
rep('{reason && (\n                      <ReviewRow','{reason && type !== "report" && (\n                      <ReviewRow');
for(const field of ['Asset name','Serial number','Problem summary','What happened?','Document title'])rep('<Field label="'+field+'">','<Field label="'+field+'" required>');
rep('<Field label="Reference / version reason">','<Field label="Reference / version reason" required={type === "replace"} hint={type === "replace" ? "Earlier versions remain in the original document history." : "Optional source reference or note."}>');
rep('<Field label="Receipt notes / discrepancy">','<Field label="Receipt notes / discrepancy" required={receiptOutcome !== "Confirm complete receipt"}>');
rep('                  <FileDropzone\n                    id="document-upload"','                  {type === "replace" && <div className="replacement-context"><FileText size={20}/><div><strong>{modal.doc.name} · v{modal.doc.version} → v{modal.doc.version+1}</strong><small>{modal.doc.file}</small><small>{modal.doc.source} · Original retained</small></div></div>}\n                  <div data-field-name="Document file">\n                  <FileDropzone\n                    id="document-upload"');
rep('                  {files.map((f, i) => (\n                    <StagedFileCard','                  {fieldErrors["Document file"] && <p className="field-error">{fieldErrors["Document file"]}</p>}\n                  </div>\n                  {files.map((f, i) => (\n                    <StagedFileCard');
// Fixed action bands for simple dialogs, extracted from their existing handlers.
let workAction=takeButton('Save work details','<div className="work-grid">');
let releaseAction=takeButton('Confirm release',') : modal.type === "releaseAssignment"');
let archiveAction=takeButton('Archive version',') : modal.type === "archiveDoc"');
let exceptionAction=takeButton('Record owned exception',') : modal.type === "exception" || modal.type === "departure"');
workAction=workAction.replace('setDirty(false);','setDirty(!!note.trim());');
archiveAction=archiveAction.replace('setModal(null);','setSuccess("Version archived in this preview. The original remains in document history.");');
releaseAction=`<Button disabled={busy || readOnly} onClick={()=>mutate(()=>setAssignment("Unassigned"),"Assignment released. Earlier responsibility remains in history; physical receipt is recorded separately.")}>{busy?"Recording…":"Release assignment"}</Button>`;
exceptionAction=`<Button disabled={busy || readOnly || !reason.trim()} onClick={()=>mutate(()=>setCustody("Disputed"),"Custody exception · " + exceptionType + " · " + reason + ". Current custodian retained pending resolution.")}>{busy?"Recording…":"Record exception"}</Button>`;
const start=s.indexOf('    return (\n      <Dialog open onOpenChange={(o) => !o && close()}>',s.indexOf('function modalContent'));
const body=s.indexOf('          <div className="dialog-scroll">',start);
if(start<0||body<0)throw new Error('simple shell missing');
s=s.slice(0,start)+`    const detailTitle = modal.type === "work" ? "Brake assessment · MW-271" : modal.type === "check" ? "Original check · " + modal.check : modal.type === "document" ? modal.doc.name : modal.type === "context" ? contextNames[modal.kind] : modal.type === "exception" ? "Record custody exception" : modal.type === "departure" ? "Resolve an outstanding loan" : modal.type === "archiveDoc" ? "Archive document version" : modal.type === "releaseAssignment" ? "Release current assignment" : modal.type === "completion" ? (modal.intent === "Cancelled" ? "Cancellation review" : "Completion review") : modal.title || "Source record";
    const detailIcon = modal.type === "work" || modal.type === "completion" ? Wrench : modal.type === "document" ? FileText : modal.type === "check" ? ClipboardCheck : modal.type === "archiveDoc" ? Archive : modal.type === "exception" || modal.type === "departure" ? CircleAlert : modal.type === "releaseAssignment" ? UserRound : Link2;
    const detailActions = modal.type === "work" ? (${workAction}) : modal.type === "releaseAssignment" ? (${releaseAction}) : modal.type === "archiveDoc" ? (${archiveAction}) : modal.type === "exception" || modal.type === "departure" ? (${exceptionAction}) : null;
    return (
      <ModalFrame key={modal.type} title={detailTitle} description={denied ? "Permitted workspace context · No asset details disclosed" : "AS-104 · " + name + " · Private record"} icon={detailIcon} width={modal.type === "work" ? 1100 : ["archiveDoc","releaseAssignment","completion"].includes(modal.type) ? 540 : 760} onClose={close} backLabel={modalTrail.length ? "Back to " + (modalTrail[modalTrail.length-1].modal.type === "work" ? "work" : modalTrail[modalTrail.length-1].modal.type === "custody" ? "custody" : "record") : undefined} busy={busy} success={success} actions={detailActions} tools={["work","check","archiveDoc","exception","departure","releaseAssignment"].includes(modal.type) ? <details className="modal-preview-tools"><summary>Preview testing</summary>{footerFailure}</details> : null}>
          <div className="dialog-scroll">
            {error && <p className="form-error modal-error-summary" role="alert" tabIndex={-1}>{error}</p>}
`+s.slice(body+'          <div className="dialog-scroll">'.length);
rep('          </div>\n        </DialogContent>\n      </Dialog>\n    );\n  }\n  function kitInputs()', '          </div>\n      </ModalFrame>\n    );\n  }\n  function kitInputs()');
// Remove duplicated body controls/errors now owned by the dialog frame.
const modalStart=s.indexOf('    const detailTitle');const modalEnd=s.indexOf('  function kitInputs()',modalStart);
let detail=s.slice(modalStart,modalEnd);
detail=detail.replace(/\s*\{footerFailure\}/g,''); // Restore one test tool in the frame below.
detail=detail.replace('<summary>Preview testing</summary></details>','<summary>Preview testing</summary>{footerFailure}</details>');
detail=detail.replace(/\{error && \(\s*<p (?:className="form-error" role="alert"|role="alert" className="form-error")>\s*\{error\}\s*<\/p>\s*\)\}/g,'');
detail=detail.replace(/<Button variant="outline" onClick=\{(?:close|openWork)\}>\s*(?:Return to Asset Profile|Return to asset|Return to work)\s*<\/Button>/g,'');
detail=detail.replace('onChange={(e) => setNote(e.target.value)}','onChange={(e) => {setNote(e.target.value);setDirty(true);}}');
detail=detail.replace('"Nia Patel · " + NOW + " — " + note','actor + " · " + NOW + " — " + note');
detail=detail.replace('setNote("");\n                              say(','setNote("");\n                              setDirty(draftProgress !== progress || draftAction !== nextAction);\n                              say(');
detail=detail.replace('<Field label="Archive reason">','<Field label="Archive reason" required hint="The file stays in history. Explain why it should leave the current library.">');
detail=detail.replace('<Field label="What needs resolving?">','<Field label="What needs resolving?" required hint="Describe the next action for the responsible person.">');
detail=detail.replace('<div className="document-preview">','<div className="document-preview document-preview-compact">');
detail=detail.replace('<dl>\n                  <Fact label="Original source">','<dl className="facts-grid document-record-facts">\n                  <Fact label="Original source">');
detail=detail.replace('<Notice title="Completion requirements are not satisfied">','<Notice title={modal.intent === "Cancelled" ? "Cancellation needs an authorised decision" : "Completion requirements are not satisfied"}>');
s=s.slice(0,modalStart)+detail+s.slice(modalEnd);
rep('        {modalContent()}','        <FieldErrors.Provider value={fieldErrors}>{modalContent()}</FieldErrors.Provider>');
rep('<DialogContent>\n              <DialogTitle>Keep this draft?</DialogTitle>','<DialogContent className="asset-discard-dialog" style={{width:"min(92vw,480px)",maxWidth:"min(92vw,480px)"}}>\n              <DialogTitle>Discard unsaved changes?</DialogTitle>');
rep('Your entries remain in this browser tab while you continue.\n                Discard closes the form; files are not uploaded.','Continue editing to keep your entries and selected files. Discard removes this unsaved draft; saved records stay unchanged.');
rep('<Button variant="outline" onClick={() => setDraftClose(false)}>','<Button autoFocus onClick={() => setDraftClose(false)}>');
rep('                <Button\n                  onClick={() => {\n                    setDraftClose(false);\n                    setDirty(false);\n                    setModal(null);','                <Button variant="outline"\n                  onClick={() => {\n                    leaveModal();');
fs.writeFileSync(target,s);
console.log('Applied v6 modal changes');
