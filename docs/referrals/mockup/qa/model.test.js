import {describe,it,expect} from 'vitest';
import {seed,makeReferral,makeFunding,coverage,activationBlocks,conversionBlocks,money,roles} from '../data';
import {normalise,decisionChanged,fundingIssues,allocationIssues,canReadRecord,allowedSites,eligibleOwners,followupsFor,businessDate,routeIntake,policyWarnings,clone,pinnedConfig,stageIssue} from '../model';
const data=()=>normalise(seed(),seed()),noah=()=>data().records.find(r=>r.name==='Noah Clarke');
import { reportCsv } from '../report-export';
import { reportingScenario } from '../report-scenario';
import { handoverFields, openInWorkspace, serviceStartUpdate } from '../model';

describe('Website-to-supported-living continuity',()=>{
 it('transfers identity, communication and stated general goals without copying restricted answers',()=>{
   const r=noah();Object.assign(r,{preferred:'Preferred',dob:'1990-03-04',preferredContact:'Email',leaveMessage:'Do not leave a message',interpreter:'NZSL',intakeAnswers:[{id:'g',destination:'Goals',sensitivity:'General intake',value:'Grow vegetables'},{id:'private',destination:'Goals',sensitivity:'Restricted assessment',value:'Restricted narrative'}]});
   const p=handoverFields(r);expect(p).toMatchObject({preferred:'Preferred',dob:'1990-03-04',goals:'Grow vegetables',preferredContact:'Email',interpreter:'NZSL'});expect(JSON.stringify(p)).not.toContain('Restricted narrative');expect(p.assessmentRef).toBe(r.id);
 });
 it('retains converted referral follow-ups in operational queues but not the open intake queue',()=>{
   const r={...noah(),stage:'Converted'};expect(openInWorkspace(r,'Follow-ups')).toBe(true);expect(openInWorkspace(r,'Funding assistance')).toBe(true);expect(openInWorkspace(r,'Referrals')).toBe(false);expect(openInWorkspace({...r,stage:'Closed'},'Follow-ups')).toBe(false);
 });
 it('schedules future support without prematurely activating it, then records explicit commencement',()=>{
   const r=noah(),p={id:'SP-test',status:'Onboarding',services:[],proposedServices:[{referralId:r.id,service:r.service}]};
   const scheduled=serviceStartUpdate(r,p,{start:'2026-10-20',note:'Receiving team ready'},'Emma','2026-10-16');expect(scheduled.record.serviceStarted).toBe(false);expect(scheduled.person.status).toBe('Onboarding');expect(scheduled.person.services).toEqual([]);expect(scheduled.record.tasks.at(-1).done).toBe(false);
   const started=serviceStartUpdate({...r,...scheduled.record},scheduled.person,{start:'2026-10-20',note:'First visit completed'},'Emma','2026-10-20');expect(started.record.serviceStarted).toBe(true);expect(started.person.services).toContain(r.service);expect(started.record.serviceStartHistory).toHaveLength(2);expect(started.record.tasks.at(-1).done).toBe(true);expect(started.person.serviceStarts[0].note).toBe('First visit completed');
 });
 it('preserves existing active service status and original commencement when adding support',()=>{
   const r=noah(),p={id:'SP-test',status:'Active',start:'2025-01-01',services:['Community participation'],proposedServices:[{referralId:r.id,service:r.service}]};
   const scheduled=serviceStartUpdate(r,p,{start:'2026-10-20',note:'Additional support'},'Emma','2026-10-16');expect(scheduled.person.status).toBe('Active');expect(scheduled.person.services).toEqual(['Community participation']);
   const started=serviceStartUpdate(r,p,{start:'2026-10-16',note:'Started'},'Emma','2026-10-16');expect(started.person.start).toBe('2025-01-01');expect(started.person.services).toContain('Supported living');
 });
 it('counts valid funding for every proposed service line without treating unrelated awards as coverage',()=>{
   const r=noah(),f=r.funding[0];r.funding.push({...f,id:'second',reference:'SECOND',service:'Community participation',amount:200,allocated:200,awarded:200});r.proposal.lines.push({id:'second-line',service:'Community participation',weekly:200});expect(coverage(r)).toBe(Number(f.amount)+200);r.proposal.lines.pop();expect(coverage(r)).toBe(Number(f.amount));
 });
});

describe('Whole-prototype audit regressions',()=>{
 it('applies destination requirements when explicitly reopening',()=>{
   const d=data(),r={...d.records[0],stage:'Closed'};
   expect(stageIssue(r,'Ready for handover',d.config,'Referral manager',{reopening:true})).toContain('Identity');
   expect(stageIssue(r,'Assessment',d.config,'Referral manager',{reopening:true})).toBe('');
   expect(stageIssue(r,'Assessment',d.config,'Referral manager')).toContain('Reopen');
 });
 it('uses configured receiving staff and role scope for a new approved site',()=>{
   const d=data();d.config.regions.push('Wellington');
   d.config.staffDirectory.find(p=>p.name==='Emma Taylor').sites.push('Wellington');
   d.config.scopeRules.Coordinator.sites.push('Wellington');
   d.config.routingRules.unshift({id:'w',region:'Wellington',service:'Any service',pathway:'Any pathway',site:'Wellington',owner:'Emma Taylor',team:'Intake'});
   expect(eligibleOwners(d,'Wellington')).toContain('Emma Taylor');
   expect(routeIntake({region:'Wellington',service:'Supported living'},d.config).owner).toBe('Emma Taylor');
   expect(allowedSites(d,'Coordinator')).toContain('Wellington');
 });
 it('retains timed primary and funding work and distinguishes cancellation',()=>{
   const r=noah();r.nextTime='09:30';Object.assign(r.funding[0],{next:'Review',nextTime:'14:15',nextDone:true,nextStatus:'Cancelled'});
   expect(followupsFor(r).find(w=>w.primary).time).toBe('09:30');
   expect(followupsFor(r).some(w=>w.fundingId)).toBe(false);
   expect(followupsFor(r,true).find(w=>w.fundingId)).toMatchObject({time:'14:15',status:'Cancelled'});
 });
 it('exports scope and measures as literal spreadsheet values',()=>{
   const csv=reportCsv({title:'Report',period:'All current open work',site:'Auckland',search:'=1+1',measures:[{label:'Open referrals',value:4}]});
   expect(csv).toContain('All current open work');expect(csv).toContain('"Open referrals","4"');expect(csv).toContain("'=1+1");
 });
 it('provides varied fictional dates and linked outcomes without changing the standard seed',()=>{
   const d=reportingScenario();expect(d.records).toHaveLength(32);expect(new Set(d.records.map(r=>r.createdAt)).size).toBeGreaterThan(7);
   expect(d.records.some(r=>r.notes.some(n=>n.successful))).toBe(true);expect(d.records.some(r=>r.serviceStarted)).toBe(true);
   for(const r of d.records.filter(r=>r.personId))expect(d.people.some(p=>p.id===r.personId)).toBe(true);
   expect(seed().records).toHaveLength(8);
 });
});
describe('Funding and service start boundaries',()=>{
 it('keeps unknown costs distinct from a real zero',()=>{expect(makeReferral().proposal.weekly).toBeNull();expect(makeFunding('ACC').amount).toBeNull();expect(money(null)).toBe('Unknown');expect(money(0)).toBe('$0');});
 it('counts the valid seeded arrangement and requires a line allocation',()=>{const r=noah();expect(coverage(r)).toBe(1200);expect(allocationIssues(r)).toContain('Supported living: allocate coverage to this service line');});
 for(const [name,change,fragment] of [['expired',{validTo:'2000-01-01'},'not valid'],['future',{validFrom:'2099-01-01',validTo:'2100-01-01'},'not valid'],['conditional',{conditionsMet:false},'Conditions'],['unverified',{verified:false},'verification'],['withdrawn',{status:'Withdrawn'},'withdrawn'],['unknown',{amount:null},'unknown'],['wrong service',{service:'Unrelated service'},'scope'],['income',{income:true},'income'],['overallocated',{allocated:1201},'total award']])it(`blocks ${name} funding`,()=>{const r=noah();Object.assign(r.funding[0],change);expect(fundingIssues(r.funding[0],r).some(x=>x.includes(fragment))).toBe(true);expect(coverage(r)).toBe(0);});
 it('rejects overlapping authorisations',()=>{const r=noah();r.funding.push({...r.funding[0],id:'other'});expect(coverage(r)).toBe(0);});
 it('distinguishes administration from material decision edits',()=>{const f=noah().funding[0];expect(decisionChanged(f,{...f,next:'Call agency',due:'2030-01-01'})).toBe(false);expect(decisionChanged(f,{...f,amount:1300})).toBe(true);});
 it('allows a fully reviewed allocated proposal and rejects excess allocation',()=>{const r=noah();Object.assign(r,{capacity:true,agreement:true,safety:true});Object.assign(r.proposal,{approved:true,accepted:true,allocations:[{lineId:r.proposal.lines[0].id,fundingId:r.funding[0].id,weekly:1200}]});expect(activationBlocks(r)).toEqual([]);r.proposal.allocations[0].weekly=1201;expect(allocationIssues(r).length).toBeGreaterThan(0);});
});
describe('Lifecycle, sites and assigned work',()=>{
 it('blocks closed conversion and converted stage changes',()=>{const r=noah();r.stage='Closed';expect(conversionBlocks(r).join(' ')).toContain('Reopen');r.stage='Converted';expect(stageIssue(r,'Assessment',data().config,'Referral manager')).toContain('canonical');});
 it('blocks unidentified callback conversion and withdrawn authority',()=>{expect(conversionBlocks({...noah(),generalOnly:true}).join(' ')).toContain('Identify');expect(conversionBlocks({...noah(),authorityState:'Withdrawn'}).join(' ')).toContain('withdrawn');});
 it('enforces assignment in central intake',()=>{const d=data(),r=d.records[0];d.config.scopeRules.Coordinator={person:'Emma Taylor',sites:['Auckland'],ownership:'Assigned referrals'};d.roles.Coordinator.central=true;r.site='Central intake';r.owner='Intake team';expect(canReadRecord(d,'Coordinator',r)).toBe(false);r.owner='Emma Taylor';expect(canReadRecord(d,'Coordinator',r)).toBe(true);});
 it('limits Waikato-only scope and eligible receiving staff',()=>{const d=data();d.config.scopeRules.Coordinator.sites=['Waikato'];expect(allowedSites(d,'Coordinator')).toEqual(['Waikato']);expect(eligibleOwners(d,'Waikato')).not.toContain('Emma Taylor');});
 it('fallback routing remains accountable when matching staff are absent',()=>{const d=data(),v={region:'Auckland',service:'Supported living',path:'ACC'};expect(routeIntake(v,d.config).owner).toBe('Emma Taylor');d.config.absentStaff=['Emma Taylor'];expect(routeIntake(v,d.config).site).toBe('Central intake');});
 it('keeps primary, task and case-specific deadlines independent',()=>{const r=noah();r.funding[0].next='Agency review';r.funding[0].due='2030-01-02';r.tasks=[{id:'a',label:'Call',owner:'Sam Patel',due:'2030-02-02',done:true}];expect(followupsFor(r).some(w=>w.due==='2030-01-02')).toBe(true);expect(followupsFor(r).some(w=>w.id==='a')).toBe(false);expect(followupsFor(r,true).some(w=>w.id==='a')).toBe(true);});
 it('skips weekends and configured closed days',()=>expect(businessDate(1,'2026-10-16',{holidays:['2026-10-19']})).toBe('2026-10-20'));
 it('preserves pinned case policy when current policy changes',()=>{const d=data(),r=d.records[0];d.config={...clone(d.config),version:2,allowPending:!d.config.allowPending};expect(pinnedConfig(d,r).version).toBe(1);});
});
describe('Settings safeguards and responsibility presets',()=>{
 for(const [name,mutate] of [['retention',c=>c.retention=1],['approval delegation',c=>c.approvalLimit=99999],['separate reviewer',c=>c.separateReviewer=false],['preparation checklist',c=>c.requirements.ACC=[]],['site scope',c=>c.scopeRules.Coordinator.sites.push('Other site')],['ownership scope',c=>c.scopeRules.Coordinator.ownership='All permitted referrals'],['stage gate',c=>c.stageRules=[]]])it(`warns on loosening ${name}`,()=>{const d=data(),next=clone(d.config);mutate(next);expect(policyWarnings(d.config,next,d.roles,d.roles).length).toBeGreaterThan(0);});
 it('reception has contact and follow-up rights without assessment access',()=>{expect(roles.Receptionist.contact).toBe(true);expect(roles.Receptionist.tasks).toBe(true);expect(roles.Receptionist.sensitive).toBe(false);});
 it('finance sees funding without clinical narratives',()=>{expect(roles['Finance reviewer'].fundingRead).toBe(true);expect(roles['Finance reviewer'].sensitive).toBe(false);});
 it('settings administration does not imply access to referrals or governance',()=>{expect(roles['Settings administrator'].settings).toBe(true);expect(roles['Settings administrator'].view).toBe(false);expect(roles['Settings administrator'].governance).toBe(false);});
});
