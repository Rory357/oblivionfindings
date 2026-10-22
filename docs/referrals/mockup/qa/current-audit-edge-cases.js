async(page)=>{
 const results=[],errors=[];page.setDefaultTimeout(6500);const onError=e=>errors.push(String(e));page.on('pageerror',onError);
 const b=n=>page.getByRole('button',{name:n,exact:true}),tab=n=>page.getByRole('tab',{name:n,exact:true}).click(),nav=n=>page.getByRole('complementary').getByRole('button',{name:n,exact:true}).click(),fill=(n,v)=>page.getByRole('textbox',{name:n,exact:false}).fill(v),select=(n,v)=>page.getByRole('combobox',{name:n,exact:true}).selectOption(v),next=()=>b('Continue').click();
 const state=()=>page.evaluate(()=>JSON.parse(localStorage.getItem('oblivion-referrals-prototype-v1'))),record=async name=>(await state()).records.find(r=>r.name===name);
 const finish=async(heading='Saved successfully')=>{await b('Save').click();await page.getByRole('heading',{name:heading}).waitFor();await b('Done').click()};
 const reset=async()=>{await page.goto('http://127.0.0.1:5186/');await page.keyboard.press('Escape');if(await b('Discard changes').isVisible())await b('Discard changes').click();if(await b('Staff workspace').isVisible())await b('Staff workspace').click();await select('Preview role','Referral manager');await b('Walkthroughs').click();await b('Reset fictional examples').click();await b('Reset examples').click()};
 const open=async name=>{await nav('Referrals');await page.getByRole('radio',{name:'Table',exact:true}).click();await page.getByRole('table').getByText(name,{exact:true}).click()};
 const run=async(name,fn)=>{try{await reset();results.push({name,evidence:await fn()})}catch(e){results.push({name,error:String(e),screen:(await page.locator('body').innerText()).slice(-3200)})}};
 await page.setViewportSize({width:1440,height:1000});
 await run('Reception can reassign and place a case on hold through primary follow-up',async()=>{
   await open('Maia Bennett');const before=await record('Maia Bennett');await select('Preview role','Receptionist');await b('Work on next action').click();await select('Action','Cancel');await page.getByRole('combobox',{name:'Owner',exact:true}).click();await page.getByRole('option',{name:'Emma Taylor',exact:true}).click();await next();await fill('Outcome / reason','Duplicate task; no hold was requested by the person');await fill('Next action after this one','Make the initial contact');await next();await finish('Follow-up saved');const after=await record('Maia Bennett');return {before:{stage:before.stage,owner:before.owner},after:{stage:after.stage,owner:after.owner,holdReason:after.holdReason}};
 });
 await run('Reopen bypasses ready-for-handover stage checks',async()=>{
   await open('Maia Bennett');const before=await record('Maia Bennett');await page.getByText('Case management',{exact:true}).click();await b('Stage / hold / close').click();await select('Stage','Closed');await fill('Reason for this change','Fictional close for reopening check');await next();await next();await finish();await b('Reopen referral').click();await select('Stage','Ready for handover');await fill('Reason for this change','Fictional reopening without completing prerequisites');await next();await fill('Next action','Review missing consent and identity');await next();await finish();return {identity:before.identity,consent:before.consent,stage:(await record('Maia Bennett')).stage};
 });
 await run('Browser Back loses unpublished settings without a warning',async()=>{
   await nav('Referrals');await nav('Settings');await tab('Intake');await tab('Configuration');const field=page.getByRole('textbox',{name:'Additional public question',exact:true}),before=await field.inputValue();await field.fill('Unsaved audit question');await page.goBack();const warned=await page.getByRole('dialog').isVisible();await nav('Settings');await tab('Intake');await tab('Configuration');return {warned,before,after:await field.inputValue()};
 });
 await run('Primary follow-up loses a selected time',async()=>{
   await open('Noah Clarke');await b('Work on next action').click();await page.getByRole('button',{name:/^Time \(optional\):/}).click();await b('Use time').click();const selected=await page.getByRole('button',{name:/^Time \(optional\):/}).innerText();await next();await fill('Outcome / reason','Fictional timed follow-up');await next();await finish('Follow-up saved');await b('Work on next action').click();const reopened=await page.getByRole('button',{name:/^Time \(optional\):/}).innerText();return {selected,reopened};
 });
 await run('Default hold reason is displayed but not saved',async()=>{
   await open('Noah Clarke');await page.getByText('Case management',{exact:true}).click();await b('Stage / hold / close').click();await select('Stage','On hold');const visibleReason=await page.getByRole('combobox',{name:'Hold reason',exact:true}).inputValue();await fill('Reason for this change','Waiting for suitable service capacity');await next();await next();await finish();const after=await record('Noah Clarke');return {visibleReason,stage:after.stage,storedHoldReason:after.holdReason??null};
 });
 await run('Retired stage hides pinned records from pipeline',async()=>{
   await page.evaluate(()=>{const key='oblivion-referrals-prototype-v1',d=JSON.parse(localStorage.getItem(key));d.records.find(r=>r.name==='Maia Bennett').stage='Previously published stage';localStorage.setItem(key,JSON.stringify(d))});await page.reload();await nav('Referrals');await page.getByRole('radio',{name:'Table',exact:true}).click();const inTable=await page.getByRole('table').getByText('Maia Bennett',{exact:true}).isVisible();await page.getByRole('radio',{name:'Pipeline',exact:true}).click();return {inTable,inPipeline:await page.getByRole('main').getByText('Maia Bennett',{exact:true}).isVisible()};
 });
 await run('Unassigned 36-hour-old referral excluded from more-than-one-day attention',async()=>{
   await page.evaluate(()=>{const key='oblivion-referrals-prototype-v1',d=JSON.parse(localStorage.getItem(key));d.records.forEach(r=>{r.owner='Emma Taylor'});const r=d.records.find(r=>r.name==='Maia Bennett');r.owner='Intake team';r.createdAt=new Date(Date.now()-36*3600000).toISOString();localStorage.setItem(key,JSON.stringify(d))});await page.reload();await nav('Reports');return {recordAgeHours:36,attentionText:(await page.getByRole('main').innerText()).slice(-8500)};
 });
 await reset();page.off('pageerror',onError);return {results,errors};
}
