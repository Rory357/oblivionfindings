const fs=require('fs'),path=require('path');const dir=path.resolve(__dirname,'../../../previews/PKG-02B/v3');const edit=(f,fn)=>{const p=path.join(dir,f);fs.writeFileSync(p,fn(fs.readFileSync(p,'utf8')));};
edit('main.tsx',s=>{
 s=s.replace('setWorkReturn(view);',"setWorkReturn(`${view}:${sub}`);").replace('nav(workReturn);',"nav(workReturn.split(':')[0],workReturn.split(':')[1]||'summary');");
 s=s.replace(/\{work\s*\? work === 'WO-0188'[\s\S]*?: readiness\}/, "{work ? model.data.works.find(w=>w.id===work)?.status ?? 'Open' : readiness}");
 s=s.replace('                                            Start check\r\n                                        </button>','                                            Start check\r\n                                        </button>\r\n                                        <PageHeaderGlassButton disabled={actionDisabled} onClick={()=>{setReportFromCheck(false);setDialog(\'report\');}}>Report a problem</PageHeaderGlassButton>');
 if(!s.includes('>Report a problem</PageHeaderGlassButton>'))s=s.replace('                                            Start check\n                                        </button>','                                            Start check\n                                        </button>\n                                        <PageHeaderGlassButton disabled={actionDisabled} onClick={()=>{setReportFromCheck(false);setDialog(\'report\');}}>Report a problem</PageHeaderGlassButton>');
 s=s.replace("'2 files'","`${model.data.documents.filter(x=>x.owner===work).length} attached`");
 s=s.replace('linked={Boolean(linked[run.id])}','linked={linked[run.id] || false}');
 s=s.replace('onDone={(id) => {','onDone={(id, report) => {').replace("run.id : 'Vehicle profile',","run.id : 'Vehicle profile', report,");
 return s;
});
edit('flows.tsx',s=>{
 s=s.replace('linked: boolean;', 'linked: string | false;').replace("? 'Linked to an existing work record'",'? `Linked to ${linked}`');
 s=s.replace('onDone: (id: string) => void;', 'onDone: (id: string, report:{title:string;notes:string;range:[string|null,string|null]}) => void;').replace('onDone(saved)','onDone(saved,{title,notes,range:showDates?range:[null,null]})');
 s=s.replace('id: `ATT-DEMO-${410 + n}`','id: `ATT-DEMO-${crypto.randomUUID().slice(0,8)}`');
 const last=s.lastIndexOf('onDiscard={onClose}');if(last>=0)s=s.slice(0,last)+s.slice(last).replace('onDiscard={onClose}','onDiscard={finish}');return s;
});
edit('operations.tsx',s=>{
 s=s.replace('type Work = {','type Work = {\n    estimateStart?:string;estimateEnd?:string;');
 s=s.replace("status: 'Assessed',","status: w.status==='Awaiting assessment'?'Assessed':w.status,");
 s=s.replace('const reportCreated = (id: string, source: string) =>','const reportCreated = (id: string, source: string, report?:{title:string;notes:string;range:[string|null,string|null]}) =>');
 s=s.replace("makeWork(id, 'Reported vehicle concern', source)","{...makeWork(id, report?.title || 'Reported vehicle concern', source),notes:report?.notes||'',estimateStart:report?.range[0]||'',estimateEnd:report?.range[1]||''}");
 s=s.replace('    data.bookings\n',`    data.works.filter(w=>w.estimateStart&&w.estimateEnd).forEach(w=>event('estimate-'+w.id,'Estimated work · advisory','asset',w.estimateStart+'T00:00:00',addDays(w.estimateEnd!,1)+'T00:00:00',w.id,true));
    data.bookings\n`);
 s=s.replace('    data.bookings\r\n',`    data.works.filter(w=>w.estimateStart&&w.estimateEnd).forEach(w=>event('estimate-'+w.id,'Estimated work · advisory','asset',w.estimateStart+'T00:00:00',addDays(w.estimateEnd!,1)+'T00:00:00',w.id,true));\n    data.bookings\r\n`);
 s=s.replace("mode === 'approve' || mode === 'out'\n", "mode === 'approve' || mode === 'out'\n");
 s=s.replace("? 'Active restriction prevents approval or checkout.'\n                        : conflict(b.start, b.end, id)","? 'Active restriction prevents approval or checkout.'\n                        : data.profile.life!=='In service'?'Vehicle lifecycle does not permit use.':data.compliance.some(c=>c.applies==='Unknown'||c.applies==='Applicable'&&(!c.evidence||c.outcome==='Failed'||c.due&&c.due<TODAY))?'Resolve missing, failed or expired compliance evidence before approval.':mode==='out'&&+v.odo<odo?'Checkout reading must not precede the selected reading.':mode==='out'&&b.start.slice(0,10)>TODAY?'Pickup is in the future. Checkout requires a current booking.':conflict(b.start, b.end, id)");
 s=s.replace("? 'Active restriction prevents approval or checkout.'\r\n                        : conflict(b.start, b.end, id)","? 'Active restriction prevents approval or checkout.'\r\n                        : data.profile.life!=='In service'?'Vehicle lifecycle does not permit use.':data.compliance.some(c=>c.applies==='Unknown'||c.applies==='Applicable'&&(!c.evidence||c.outcome==='Failed'||c.due&&c.due<TODAY))?'Resolve missing, failed or expired compliance evidence before approval.':mode==='out'&&+v.odo<odo?'Checkout reading must not precede the selected reading.':mode==='out'&&b.start.slice(0,10)>TODAY?'Pickup is in the future. Checkout requires a current booking.':conflict(b.start, b.end, id)");
 s=s.replace('disabled={!m.canRequest}\n                        onClick={m.amendment}','disabled={!m.canManage}\n                        onClick={m.amendment}');
 s=s.replace('disabled={!m.canRequest}\r\n                        onClick={m.amendment}','disabled={!m.canManage}\r\n                        onClick={m.amendment}');
 return s;
});
