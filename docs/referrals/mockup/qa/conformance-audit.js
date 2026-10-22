async(page)=>{
 page.setDefaultTimeout(8000);
 const passed=[],errors=[],taskName='Calendar audit '+Date.now();
 const errorListener=e=>errors.push(String(e));page.on('pageerror',errorListener);
 const b=name=>page.getByRole('button',{name,exact:true});
 const nav=name=>page.getByRole('complementary').getByRole('button',{name,exact:true}).click();
 const tab=name=>page.getByRole('tab',{name,exact:true}).click();
 const next=()=>b('Continue').click();
 const done=async()=>{await b('Save').click();await page.getByRole('heading',{name:'Saved successfully'}).waitFor();await b('Done').click();};
 const assert=(v,m)=>{if(!v)throw Error(m)};
 const scan=async(name)=>{await page.addScriptTag({path:'C:/Users/steph/Herd/oblivionfindings/node_modules/axe-core/axe.min.js'});const v=await page.evaluate(async()=>(await axe.run(document,{runOnly:{type:'tag',values:['wcag2a','wcag2aa']}})).violations.map(v=>({id:v.id,nodes:v.nodes.map(n=>({target:n.target,why:n.failureSummary}))})));await page.screenshot({path:'docs/referrals/mockup/qa/'+name+'.png',fullPage:false});return {name,violations:v,width:await page.evaluate(()=>({viewport:innerWidth,content:document.documentElement.scrollWidth}))};};
 const scans=[];
 try{
  await page.setViewportSize({width:1440,height:1000});await page.emulateMedia({reducedMotion:'reduce'});await page.reload();
  await page.getByRole('radio',{name:'Cards',exact:true}).click();await b('Actions for Maia Bennett').click();await page.getByText('Assign owner',{exact:true}).waitFor();await page.keyboard.press('Escape');
  await page.locator('[data-slot=card]').filter({has:page.getByRole('link',{name:'Maia Bennett',exact:true})}).click({button:'right',position:{x:8,y:8}});await page.getByText('Assign owner',{exact:true}).waitFor();await page.keyboard.press('Escape');scans.push(await scan('canonical-cards'));
  await page.getByRole('radio',{name:'Table',exact:true}).click();await page.getByRole('table').waitFor();scans.push(await scan('canonical-table'));
  await nav('Funding assistance');await page.getByRole('table').waitFor();await page.getByRole('radio',{name:'Cards',exact:true}).click();await nav('Follow-ups');await page.getByRole('radio',{name:'Table',exact:true}).click();await page.getByRole('table').waitFor();passed.push('Shared Cards/Table toggle works across referrals, funding and follow-ups; both card action menus work');

  await nav('Calendar');await page.getByRole('heading',{name:'Referral calendar'}).waitFor();await b('Next period').click();assert((await b('Jump to date').innerText()).includes('November 2026'),'Next period did not change date anchor');await b('Previous period').click();
  for(const name of ['Week','Day','Agenda','Timeline','Month'])await tab(name);
  await b('Follow-up').click();await b('Follow-up').click();passed.push('Actual shared calendar loads all five views, period navigation and source filters');scans.push(await scan('canonical-calendar'));
  await b('Today').click();await b('New entry').click();await page.getByRole('combobox',{name:'Referral',exact:true}).click();await page.getByRole('listbox').getByRole('option',{name:'REF-1042 · Maia Bennett',exact:true}).click();await page.getByRole('textbox',{name:'What needs to happen?',exact:false}).fill(taskName);await next();await next();await done();
  await tab('Day');await page.getByText('Maia Bennett · '+taskName,{exact:true}).filter({visible:true}).first().click();await b('Reschedule').click();await page.getByRole('button',{name:/New date:/}).click();await page.getByRole('dialog',{name:'New date picker',exact:true}).getByRole('button',{name:'Tue 20 October 2026',exact:true}).click();await b('Use date').click();await page.getByRole('textbox',{name:'Reason for changing the date',exact:false}).fill('Fictional scheduling check');await next();await done();
  await b('Undo reschedule').click();const state=await page.evaluate(()=>JSON.parse(localStorage.getItem('oblivion-referrals-prototype-v1')));assert(state.records.find(r=>r.id==='REF-1042').tasks.some(t=>t.label===taskName&&t.due==='2026-10-15'),'Undo did not restore original date');passed.push('Calendar creation, entry detail, real date-picker reschedule and undo update referral tasks');

  await page.getByRole('combobox',{name:'Preview role'}).selectOption('Receptionist');assert(await b('New entry').count()===0,'Read-only role can create calendar entries');assert(await b('Funding review').count()===0,'Sensitive funding source exposed');await b('More options').click();assert(await page.getByRole('menuitem',{name:'Export this period (.ics)',exact:true}).isDisabled(),'Unauthorized calendar export enabled');await page.keyboard.press('Escape');
  await page.getByRole('combobox',{name:'Preview role'}).selectOption('Support worker');await page.getByRole('heading',{name:'Calendar access is restricted'}).waitFor();passed.push('Calendar view/create/sensitive-source/export permissions are enforced in the mockup');
  await page.getByRole('combobox',{name:'Preview role'}).selectOption('Referral manager');await nav('Settings');await b('Review intake & forms').waitFor();scans.push(await scan('canonical-settings'));
  await page.getByRole('tablist',{name:'Views',exact:true}).getByRole('tab',{name:'Intake',exact:true}).click();await b('Review intake & forms').click();await page.getByRole('textbox',{name:'Additional public question'}).waitFor();
  await page.getByRole('tablist',{name:'Views',exact:true}).getByRole('tab',{name:'Workflow',exact:true}).click();await b('Review routing & follow-up').click();await tab('Notifications');assert(await page.getByRole('switch',{name:'Owner assignment email',exact:true}).count()===1,'Email notification control missing');passed.push('Settings now opens overview review cards before configuration and uses actual channel switches');
  await page.setViewportSize({width:390,height:844});scans.push(await scan('canonical-settings-phone'));await nav('Calendar');scans.push(await scan('canonical-calendar-phone'));await nav('Referrals');scans.push(await scan('canonical-cards-phone'));
  return {result:scans.some(s=>s.violations.length||s.width.content>s.width.viewport)||errors.length?'FINDINGS':'PASS',passed,scans,errors};
 }catch(error){return {result:'FAIL',passed,scans,errors,error:String(error),screen:await page.locator('body').innerText()};}finally{page.off('pageerror',errorListener)}
}
