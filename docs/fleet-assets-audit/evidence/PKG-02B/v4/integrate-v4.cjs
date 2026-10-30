const fs = require('node:fs');
const path = require('node:path');
const base=path.resolve(__dirname,'../../../previews/PKG-02B/v4');
function edit(file,fn){const p=path.join(base,file);let s=fs.readFileSync(p,'utf8').replace(/\r\n/g,'\n');s=fn(s);fs.writeFileSync(p,s);}
function rep(s,a,b){if(!s.includes(a))throw Error('Missing: '+a.slice(0,100));return s.replace(a,b);}
edit('operations.tsx',s=>{
 let a=s.indexOf('    function booking(start'),b=s.indexOf('    function conflict(',a);
 s=s.slice(0,a)+`    function booking(start = '2026-09-23T09:00', id?: string, block = false) {
        const b = data.bookings.find(x => x.id === id);
        const approvalBlocked = hold || data.profile.life !== 'In service' || data.compliance.some(c => c.applies === 'Unknown' || (c.applies === 'Applicable' && (!c.evidence || c.outcome === 'Failed' || (c.due && c.due < TODAY) || (c.high > 0 && odo > c.high))));
        const statusFor=(v:Values)=>block?'Unavailable':v.approvalRoute==='Approval not required'&&canManage&&!approvalBlocked?'Confirmed':'Pending approval';
        open({title:block?'Add unavailable period':b?'Change booking':'Request vehicle booking',verb:b?'Save changes':block?'Record block':'Save booking request',
            alternatives:[1,2,3].map(n=>({start:addDays(start.slice(0,10),n)+'T09:00',end:addDays(start.slice(0,10),n)+'T10:00'})).filter(v=>!conflict(v.start,v.end,id)),
            values:{start,end:(+start.slice(11,13)===23?addDays(start.slice(0,10),1)+'T00':start.slice(0,11)+String(+start.slice(11,13)+1).padStart(2,'0'))+':'+start.slice(14,16),requester:'Alex Morgan',driver:'Jamie Taylor',purpose:'',pickup:'Kōwhai House · key cabinet',reason:'',approvalRoute:'Approval required',exemptionReason:'',files:'[]',...(b?asValues(b):{})},
            sections:[section('Vehicle & times','Kōwhai van · VH-014 · Kōwhai House · Pacific/Auckland',[f('start','Pickup / block start','datetime'),f('end','Return / block end','datetime')]),
                section(block?'Block reason':'People & purpose',block?'The unavailable interval appears on the calendar.':'Only operational journey details; no passenger health information.',block?[f('purpose','Reason for unavailable period','textarea')]:[f('requester','Requester','person'),f('driver','Driver','person'),f('purpose','Purpose','textarea'),f('pickup','Pickup / return and keys'),...(b?[f('reason','Reason for change','textarea')]:[])]),
                ...(!block?[section('Approval & evidence','Example approval routing. An authorised coordinator can record that approval is not required. Readiness and conflict checks still apply.',[f('approvalRoute','Booking approval','approval'),f('exemptionReason','Reason / policy authority for approval not required','textarea',false,'Required when no evidence is attached for the approval-not-required path.'),f('files','Approval evidence','files',false)])]:[])],
            validate:v=>v.end<=v.start?'Return must follow pickup.':conflict(v.start,v.end,id)?conflict(v.start,v.end,id)+' Choose another time; no conflicting booking will be submitted.':!block&&v.approvalRoute==='Approval not required'&&!v.exemptionReason.trim()&&!readFiles(v.files).length?'Add a reason or upload evidence for approval not required.':'',
            note:hold?'This vehicle is restricted. Requests remain pending until readiness is resolved.':canManage?'Approval required → coordinator review. Approval not required → confirmed only when readiness and conflict checks pass. Keys, checkout and return are always recorded.':'You can request the approval-not-required path with a reason or evidence. A coordinator must verify your authority before confirmation.',
            save:v=>{const bookingId=b?.id??(block?'BLOCK':'BOOK')+'-DEMO-'+(data.bookings.length+1);const status=statusFor(v);patch(d=>({...d,documents:[...d.documents,...readFiles(v.files).map(file=>({...file,owner:bookingId}))],bookings:b?d.bookings.map(x=>x.id===id?{...x,start:v.start,end:v.end,requester:v.requester,driver:v.driver,purpose:v.purpose,pickup:v.pickup,approvalRoute:v.approvalRoute,exemptionReason:v.exemptionReason,status,history:[...x.history,'22 Sep · Changed: '+v.reason,'Approval path: '+v.approvalRoute+' · '+(v.exemptionReason||'Supporting evidence')]}:x):[...d.bookings,{id:bookingId,start:v.start,end:v.end,requester:v.requester,driver:v.driver,purpose:v.purpose,pickup:v.pickup,status,approvalRoute:v.approvalRoute,exemptionReason:v.exemptionReason,outKm:0,returnKm:0,condition:'',block,history:['22 Sep · Created in preview','Approval path: '+(v.approvalRoute||'Unavailable period')+' · '+(v.exemptionReason||'No exemption reason'),status==='Confirmed'?'Coordinator authority recorded; readiness and conflicts checked.':'Coordinator review required.']}]}),block?'Unavailable period recorded.':status==='Confirmed'?'Booking confirmed with approval-not-required authority recorded.':'Booking saved pending coordinator review.');},
        },!block);
    }
`+s.slice(b);
 // Show filenames in review rather than serialized metadata.
 s=rep(s,"f.type === 'datetime'\n                                                        ?", "f.type === 'files' ? readFiles(values[f.key]).map(file=>file.name).join(', ') || 'No files attached' : f.type === 'datetime'\n                                                        ?");
 return s;
});
edit('flows.tsx',s=>{
 s="import {EvidenceField,readFiles,type EvidenceFile} from './evidence-field';\n"+s;
 s=rep(s,'export type Run = {','export type Run = {\n    attachments?: EvidenceFile[];');
 s=rep(s,"[notes, setNotes] = useState(''),","[notes, setNotes] = useState(''),\n        [attachments, setAttachments] = useState('[]'),");
 s=rep(s,'Boolean(notes.trim());',"Boolean(notes.trim()) || readFiles(attachments).length > 0;");
 s=rep(s,'                answers: [...answers],','                answers: [...answers],\n                attachments: readFiles(attachments),');
 s=rep(s,'                                <p className="muted">\n                                    Evidence from a failed check can be attached','                                <EvidenceField label="Check photos & documents" value={attachments} onChange={setAttachments}/>\n                                <p className="muted">\n                                    Evidence from a failed check can be attached');
 s=rep(s,'<ReviewRow label="Notes" value={notes} />','<ReviewRow label="Notes" value={notes} />\n                                    <ReviewRow label="Attachments" value={readFiles(attachments).map(f=>f.name).join(", ") || "None attached"}/>');
 s=rep(s,": 'No separate attachment recorded'",": run.attachments?.map(f=>f.name).join(', ') || 'No separate attachment recorded'");
 s=rep(s,'onSaved?: (files: { id: string; name: string }[]) => void;','onSaved?: (files: EvidenceFile[]) => void;');
 s=rep(s,'saved.map((x) => ({ id: x.id!, name: x.file.name }))','saved.map((x) => ({ id: x.id!, name: x.file.name, type: x.file.type, size: x.file.size, url: URL.createObjectURL(x.file) }))');
 return s;
});
for(const file of ['serve.mjs','index.html','main.tsx'])edit(file,s=>s.replaceAll('v3','v4').replaceAll('4338','4339'));
