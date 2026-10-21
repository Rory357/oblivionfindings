async(page)=>{
 page.setDefaultTimeout(6500);
 const findings=[],errors=[];
 const onError=e=>errors.push(String(e));page.on('pageerror',onError);
 const b=name=>page.getByRole('button',{name,exact:true});
 const nav=name=>page.getByRole('complementary').getByRole('button',{name,exact:true}).click();
 const tab=name=>page.getByRole('tab',{name,exact:true}).click();
 const next=()=>b('Continue').click();
 const fill=(name,value)=>page.getByRole('textbox',{name,exact:false}).fill(value);
 const select=(name,value)=>page.getByRole('combobox',{name,exact:true}).selectOption(value);
 const state=()=>page.evaluate(()=>JSON.parse(localStorage.getItem('oblivion-referrals-prototype-v1')));
 const done=async(label='Save')=>{await b(label).click();await page.getByRole('heading',{name:'Saved successfully',exact:true}).waitFor();await b('Done').click();};
 const reset=async()=>{await page.keyboard.press('Escape');if(await b('Discard changes').isVisible())await b('Discard changes').click();if(await b('Staff workspace').isVisible())await b('Staff workspace').click();await b('Walkthroughs').click();await b('Reset fictional examples').click();await b('Reset examples').click();};
 const open=async(name)=>{await nav('Referrals');await page.getByRole('radio',{name:'Table',exact:true}).click();await page.getByRole('table').getByText(name,{exact:true}).click();};
 const run=async(name,fn)=>{try{findings.push({name,...await fn()})}catch(e){findings.push({name,testError:String(e),screen:(await page.locator('body').innerText()).slice(-5000)})}};
 try{
 await page.setViewportSize({width:1440,height:1000});await page.emulateMedia({reducedMotion:'reduce'});
 await run('Unchanged funding case save clears verification',async()=>{
  await reset();await open('Noah Clarke');await tab('Funding');const before=(await state()).records.find(r=>r.name==='Noah Clarke').funding[0];
  await b('Work on case').click();await next();await next();await next();await done();
  const after=(await state()).records.find(r=>r.name==='Noah Clarke').funding[0];
  return {reproduced:before.verified&&!after.verified,before:{status:before.status,verified:before.verified,amount:before.amount},after:{status:after.status,verified:after.verified,amount:after.amount}};
 });
 await run('Closed referral can convert without reopening',async()=>{
  await reset();await open('Noah Clarke');await page.getByText('Case management',{exact:true}).click();await b('Stage / hold / close').click();await select('Stage','Closed');await fill('Reason and person’s preference','Person chose to stop the enquiry.');await next();await done();
  const before=(await state()).records.find(r=>r.name==='Noah Clarke').stage;
  await b('Review handover').click();await page.getByRole('checkbox',{name:'I checked identity and possible duplicates',exact:true}).check();await next();await page.getByRole('checkbox',{name:'I reviewed the information to transfer',exact:true}).check();await next();await done('Confirm handover');
  const after=(await state()).records.find(r=>r.name==='Noah Clarke');return {reproduced:before==='Closed'&&after.stage==='Converted',before,after:after.stage,personId:after.personId};
 });
 await run('Coordinator intake hands off outside their readable queue',async()=>{
  await reset();await select('Preview role','Coordinator');await b('New referral').click();await fill('Person’s full name','Gap audit intake');await fill('Phone','021 000 0011');await next();await fill('What support is being sought?','Fictional enquiry for audit');await next();await next();await done('Create referral');
  const r=(await state()).records.find(r=>r.name==='Gap audit intake');const visible=await page.getByRole('table').getByText('Gap audit intake',{exact:true}).isVisible();return {reproduced:!visible,site:r.site,owner:r.owner,visibleInCreatorQueue:visible,note:'Scope denial is correct; improve the handoff receipt rather than widening access.'};
 });
 await run('Professional intake handoff omits captured details and receipt navigation throws',async()=>{
  await reset();await b('Website entry').click();await b('Professional referral').click();await fill('Person being referred','Audit Person');await fill('Your name and organisation','Audit Referrer at Example Agency');await fill('Safe phone number','021 000 0022');await fill('Safe email address','referrer@example.invalid');await fill('When and how is it safe','Contact the referrer only, weekday afternoons');await next();await fill('What support would you like','Supported living discussion');await select('Your funding situation','I have an allocation / approval');await fill('Agency / host and current arrangement','Example Host arrangement 22');await fill('What would a good week look like','Attend the community garden');await select('Where is support needed?','Waikato');await next();await fill('Basis of authority','Fictional permission to discuss referral');await page.getByRole('checkbox',{name:'I understand how these details will be used for this enquiry',exact:true}).check();await page.getByRole('checkbox',{name:'It is safe to contact me using the details provided',exact:true}).check();await next();await done('Send enquiry to prototype');
  const count=errors.length;await b('Our support').click();const receiptError=errors.slice(count);
  await page.getByRole('button',{name:/Follow this enquiry in the staff prototype/}).click();let text=await page.locator('body').innerText();await tab('Contacts');text+='\n'+await page.locator('body').innerText();await tab('Funding');text+='\n'+await page.locator('body').innerText();
  const r=(await state()).records.find(r=>r.name==='Audit Person');return {reproduced:!text.includes('Audit Referrer at Example Agency'),receiptError,referrerVisible:text.includes('Audit Referrer at Example Agency'),customAnswerVisible:text.includes('Attend the community garden'),hostVisible:text.includes('Example Host arrangement 22'),requestedRegionVisible:text.includes('Waikato'),stored:{source:r.source,referrer:r.referrer,funder:r.funder,region:r.region,funding:r.funding},contactLabelledAsPerson:text.includes('Person seeking support')};
 });
 await run('Ordinary reception can see an unrestricted narrative',async()=>{
  await reset();await open('Maia Bennett');await b('Edit details').click();await fill('What matters to the person','AUDIT SENSITIVE assessment narrative');await next();await fill('Review note / evidence','Synthetic privacy probe');await next();await done();await select('Preview role','Receptionist');
  return {reproduced:(await page.locator('body').innerText()).includes('AUDIT SENSITIVE assessment narrative'),logContactAvailable:await b('Log contact').count()};
 });
 await run('Calendar Mine opens every permitted follow-up',async()=>{
  await reset();await select('Preview role','Funding navigator');await nav('Calendar');await page.getByRole('link',{name:'Open my referral follow-ups',exact:true}).click();
  const text=await page.locator('body').innerText();return {reproduced:text.includes('Emma Taylor'),showsOtherOwner:text.includes('Emma Taylor'),screen:text.slice(0,1700)};
 });
 await run('Public request-support phone layout',async()=>{
  await reset();await page.setViewportSize({width:390,height:844});await b('Website entry').click();await b('Quick enquiry').click();await page.screenshot({path:'output/playwright/referrals-gap-public-phone.png',fullPage:false});
  await page.addScriptTag({path:'C:/Users/steph/Herd/oblivionfindings/node_modules/axe-core/axe.min.js'});
  const violations=await page.evaluate(async()=>(await axe.run(document,{runOnly:{type:'tag',values:['wcag2a','wcag2aa']}})).violations.map(v=>({id:v.id,impact:v.impact,targets:v.nodes.map(n=>n.target)})));
  return {violations,overflow:await page.evaluate(()=>document.documentElement.scrollWidth>innerWidth)};
 });
 const result={findings,errors};await page.evaluate(result=>localStorage.setItem('referrals-gap-audit-evidence',JSON.stringify(result)),result);return result;
 }finally{page.off('pageerror',onError)}
}
