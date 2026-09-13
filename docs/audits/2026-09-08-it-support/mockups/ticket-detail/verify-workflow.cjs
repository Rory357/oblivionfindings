/* Isolated DOM checks for the mockup. No application server, DB or build. */
const fs=require('node:fs');
const path=require('node:path');
const assert=require('node:assert/strict');
const {JSDOM}=require(path.resolve(__dirname,'../../../../../node_modules/jsdom'));
const KEY='oblivion-ticket-mockup-workflow-v4';
function boot(saved){
  const dom=new JSDOM(fs.readFileSync(path.join(__dirname,'index.html'),'utf8'),{url:'http://127.0.0.1:8793/',runScripts:'outside-only',pretendToBeVisual:true});
  const w=dom.window;
  w.structuredClone=structuredClone;
  w.HTMLDialogElement.prototype.showModal=function(){this.open=true;};
  w.HTMLDialogElement.prototype.close=function(){this.open=false;};
  w.HTMLElement.prototype.scrollIntoView=function(){};
  if(saved)w.sessionStorage.setItem(KEY,saved);
  for(const f of ['icons.js','workflow.js','mockup.js'])w.eval(fs.readFileSync(path.join(__dirname,f),'utf8'));
  return dom;
}
let dom;
let passed=0;
function check(ok,label){assert.ok(ok,label);passed++;}
function doc(){return dom.window.document;}
function el(selector){const found=doc().querySelector(selector);assert.ok(found,'Missing '+selector);return found;}
function click(selector){el(selector).click();}
function fill(id,value){const e=el('#'+id);e.value=value;e.dispatchEvent(new dom.window.Event('input',{bubbles:true}));e.dispatchEvent(new dom.window.Event('change',{bubbles:true}));}
function checked(selector,value=true){const e=el(selector);e.checked=value;e.dispatchEvent(new dom.window.Event('change',{bubbles:true}));}
function text(selector){return el(selector).textContent;}
const wait=()=>new Promise(resolve=>setTimeout(resolve,650));
async function run(){
  dom=boot();
  check(text('#ticket-priority').includes('P2'),'Initial P2 priority');
  check(doc().querySelectorAll('#meters .eh-meter').length===5,'Five header meters');
  click('[data-action="priority"]');checked('[name="priority-choice"][value="P1"]');fill('priority-reason','Main office service unavailable.');click('[data-action="save-priority"]');
  check(text('#ticket-priority').includes('P1'),'Priority can change to P1');
  click('[data-mode="internal"]');fill('reply-text','Verified the service after normal support hours.');
  fill('work-date','2026-09-14');fill('work-end-date','2026-09-14');fill('work-start','16:30');fill('work-end','17:30');
  click('[data-action="split-time"]');
  check(doc().querySelectorAll('.split-row').length===2,'Cross-boundary work splits into two portions');
  check(!el('[data-split-flag="0"]').checked&&el('[data-split-flag="1"]').checked,'Standard / after-hours split flags');
  click('[data-action="apply-split"]');fill('note-status','waiting');fill('note-action','Confirm printing with the office');fill('note-due','2026-09-15T10:00');fill('note-owner','Casey Morgan');fill('note-reason','Waiting for office confirmation.');
  click('[data-action="preview-options"]');click('[data-action="preview-send-error"]');click('[data-action="send"]');await wait();
  check(text('#reply-composer').includes('Preview send failed'),'Combined save retains draft on failure');
  check(text('#meters').includes('28 min'),'Failed save adds no time');
  click('[data-action="send"]');await wait();
  check(text('.next-action').includes('Confirm printing with the office'),'Next action saved with note');
  check(text('.next-action').includes('Casey Morgan'),'Follow-up owner saved');
  click('[data-tab="time"]');
  check(doc().querySelectorAll('.time-entry').length===4,'Retry creates exactly two split entries');
  check(text('.time-totals').includes('1h 28m')&&text('.time-totals').includes('30 min'),'Worked and after-hours totals');
  const after=Array.from(doc().querySelectorAll('.time-entry')).find(e=>e.textContent.includes('After hours'));
  after.querySelector('[data-request-approval]').click();click('[data-tab="approvals"]');
  click('[data-review-approval]');fill('approval-reason','Example supervisor review completed.');click('[data-action="save-approval-decision"]');
  check(text('#ticket-panel').includes('Approved'),'Time approval decision saved');
  click('[data-tab="time"]');
  const approved=Array.from(doc().querySelectorAll('.time-entry')).find(e=>e.textContent.includes('Approved'));
  approved.querySelector('[data-edit-time]').click();
  check(text('#dialog-title').includes('Request correction'),'Approved time cannot be edited directly');
  fill('unlock-reason','Correct the recorded end time.');click('[data-action="unlock-time"]');
  check(text('#dialog-title').includes('Correct time entry'),'Correction request unlocks time');
  fill('correction-start','2026-09-14T17:00');fill('correction-end','2026-09-14T17:45');fill('correction-reason','Actual finish was 5:45 pm.');click('[data-action="save-time-correction"]');
  check(text('.time-totals').includes('1h 43m'),'Correction updates net duration');
  click('[data-action="schedule"]');fill('plan-date','2026-09-13');fill('plan-start','12:45');fill('plan-end','13:15');checked('[data-book-person="Avery Taylor"]');
  click('[data-action="review-plan"]');check(text('#workflow-error').includes('Time conflict'),'Booking conflict blocks review');
  fill('plan-start','15:00');fill('plan-end','16:00');checked('[data-book-person="Casey Morgan"]');click('[data-action="review-plan"]');click('[data-action="save-plan"]');
  check(doc().querySelectorAll('.visit-members>div').length===2,'Two technicians in one visit');
  click('[data-visit-response][data-member="Avery Taylor"]');fill('response-reason','Available at the requested time.');click('[data-action="save-response"]');
  click('[data-visit-response][data-member="Casey Morgan"]');fill('response-status','declined');fill('response-reason','Another support commitment.');click('[data-action="save-response"]');
  check(text('#ticket-panel').includes('declined'),'Declined member response retained');
  click('[data-visit-work][data-member="Avery Taylor"]');fill('reply-text','Avery verified the workstation test during the visit.');fill('work-date','2026-09-13');fill('work-end-date','2026-09-13');fill('work-start','15:00');fill('work-end','15:30');click('[data-action="send"]');await wait();
  click('[data-tab="schedule"]');check(text('#ticket-panel').includes('Completed'),'Visit completed through actual work note');check(text('#ticket-panel').includes('30 min total'),'Visit actual time reported separately from plan');
  click('[data-tab="costs"]');click('[data-action="add-cost"]');fill('cost-label','Replacement printer cable');fill('cost-quantity','2');fill('cost-unit','75');click('[data-action="save-cost"]');
  check(text('.cost-entry').includes('$150.00'),'Part quantity and unit cost calculate total');
  check(text('.cost-entry').includes('Approval required'),'Threshold cost requires approval');
  click('[data-action="actions"]');click('[data-action="resolve"]');check(text('#dialog-title').includes('approvals'),'Approvals block resolution');click('#dialog [data-action="dialog-close"]');
  click('[data-action="log-time"]');fill('reply-text','Unfinished draft that must survive refresh.');fill('work-date','2026-09-15');fill('work-end-date','2026-09-15');fill('work-start','11:00');fill('work-end','11:30');
  const saved=dom.window.sessionStorage.getItem(KEY);dom.window.close();dom=boot(saved);
  check(text('#ticket-panel').includes('Saved work is available'),'Refresh offers recovery choice');
  click('[data-action="resume-draft"]');check(el('#reply-text').value==='Unfinished draft that must survive refresh.','Recovered note text');check(el('#work-start').value==='11:00','Recovered time fields');
  click('[data-group="activity"]');click('[data-tab="corrections"]');check(text('#ticket-panel').includes('Actual finish was 5:45 pm.'),'Correction reason persists with history');
  dom.window.close();dom=boot();
  click('[data-action="priority"]');check(doc().querySelectorAll('[name="priority-choice"]').length===4,'All four priority choices are available');click('#dialog [data-action="dialog-close"]');
  click('[data-mode="internal"]');fill('reply-text','Timer work with a recorded break.');
  let now=Date.parse('2026-09-14T03:00:00Z');dom.window.Date.now=()=>now;
  click('[data-action="timer-start"]');now+=10*60000;click('[data-action="timer-pause"]');
  now+=5*60000;click('[data-action="timer-resume"]');now+=20*60000;click('[data-action="timer-stop"]');
  check(text('#note-duration').includes('30 min'),'Timer excludes five paused minutes');
  check(el('#work-start').value==='15:00'&&el('#work-end').value==='15:35','Timer records actual start and end');
  click('[data-action="send"]');await wait();click('[data-tab="time"]');check(text('.time-totals').includes('58 min'),'Net timer work added to existing 28 minutes');
  click('[data-action="log-time"]');fill('reply-text','Overnight travel.');fill('work-date','2026-09-14');fill('work-end-date','2026-09-15');fill('work-start','23:30');fill('work-end','00:30');fill('work-type','Travel');fill('work-break','10');
  check(text('#note-duration').includes('50 min'),'Overnight work deducts manual break');
  fill('work-end-date','2026-09-14');click('[data-action="send"]');check(text('#note-time-error').includes('End must follow start'),'End-before-start is blocked');
  fill('work-end-date','2026-09-15');click('[data-action="send"]');await wait();click('[data-tab="time"]');check(text('.time-totals').includes('50 min'),'Travel total is separate');
  click('[data-group="conversation"]');click('[data-mode="public"]');fill('reply-text','Public update with explicit recipients.');
  for(const r of Array.from(doc().querySelectorAll('[data-recipient]')))checked('[data-recipient="'+r.dataset.recipient+'"]',false);
  click('[data-action="send"]');check(text('#ticket-panel').includes('Select at least one recipient'),'Recipient validation is visible without time logging');
  checked('[data-recipient="omar"]');click('[data-action="send"]');await wait();check(text('#ticket-panel').includes('Omar Patel <omar.patel@example.test>'),'Saved public reply retains exact recipient');
  click('[data-action="schedule"]');fill('plan-date','2026-09-15');checked('[data-book-person="Avery Taylor"]');click('[data-action="review-plan"]');click('[data-action="save-plan"]');
  click('[data-edit-visit]');fill('plan-start','15:00');fill('plan-end','16:00');fill('plan-reason','Moved to an available afternoon slot.');click('[data-action="review-plan"]');click('[data-action="save-plan"]');
  check(text('.visit-entry').includes('3:00 pm'),'Visit can be rescheduled');click('[data-action="undo-visit"]');check(text('.visit-entry').includes('1:00 pm'),'Rescheduling can be undone');
  click('[data-cancel-plan]');fill('cancel-reason','Support no longer required.');click('[data-action="save-cancel"]');check(text('.visit-entry').includes('Cancelled'),'Cancellation reasoned and recorded');click('[data-action="undo-visit"]');check(text('.visit-entry').includes('Requested'),'Cancellation can be undone');
  console.log('Passed '+passed+' isolated workflow checks.');
}
run().catch(error=>{console.error(error.stack);process.exitCode=1;}).finally(()=>dom?.window.close());
