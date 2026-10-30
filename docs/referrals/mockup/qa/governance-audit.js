async (page) => {
 const passed=[];
 page.setDefaultTimeout(8000);
 const b=(name)=>page.getByRole('button',{name,exact:true});
 const t=(name)=>page.getByRole('tab',{name,exact:true});
 const text=(name)=>page.getByRole('textbox',{name,exact:false});
 const role=async(name)=>page.getByRole('combobox',{name:'Preview role'}).selectOption(name);
 const next=()=>b('Continue').click();
 const done=async(label='Save')=>{await b(label).click();await page.getByRole('heading',{name:'Saved successfully',exact:true}).waitFor();await b('Done').click();};
 const choose=async(label,value)=>{await page.getByRole('combobox',{name:label,exact:true}).click();await page.getByRole('listbox',{name:'Suggestions'}).getByRole('option',{name:value,exact:true}).click();};
 const state=()=>page.evaluate(()=>JSON.parse(localStorage.getItem('oblivion-referrals-prototype-v1')));
 const assert=(value,message)=>{if(!value)throw new Error(message)};
 const publish=async()=>{await b('Review changes').first().click();await next();await text('Reason and approval reference').fill('Fictional review for final prototype audit.');await next();await done('Publish version');};
 const scenario=async(name)=>{await b('Walkthroughs').click();await b(name).click();};
 try {
  await page.setViewportSize({width:1440,height:1000});page.once('dialog', d=>d.accept());await page.reload();
  await b('Walkthroughs').click();await b('Reset fictional examples').click();await b('Reset examples').click();
  await role('Coordinator');await scenario('Maia · website enquiry, no funding');
  await page.getByRole('heading',{name:'You don’t have access to this referral'}).waitFor();
  assert(!(await page.locator('main').innerText()).includes('Maia Bennett'),'Denied record leaked identity');
  await role('Support worker');await page.getByRole('complementary').getByRole('button',{name:'Referrals',exact:true}).click();await page.getByRole('heading',{name:'This workspace is restricted'}).waitFor();
  await role('Settings administrator');await b('Settings').click();await page.getByRole('heading',{name:'Referral settings'}).waitFor();
  await page.getByRole('complementary').getByRole('button',{name:'Referrals',exact:true}).click();await page.getByRole('heading',{name:'This workspace is restricted'}).waitFor();
  passed.push('Central intake denied by direct scenario; support worker denied; settings access does not grant referral access');

  await role('Referral manager');await b('Settings').click();await t('Intake').click();
  await page.getByRole('spinbutton',{name:'Sample retention review period (years)'}).fill('0');await b('Review changes').first().click();
  await page.getByText('Retention review must be a whole number between 1 and 30 years.').waitFor();
  await page.getByRole('spinbutton',{name:'Sample retention review period (years)'}).fill('7');
  await text('Additional public question').fill('What matters most to you?');
  await page.getByRole('complementary').getByRole('button',{name:'Referrals',exact:true}).click();await page.getByRole('dialog',{name:'Unpublished settings changes'}).waitFor();await b('Keep editing').click();
  assert(await text('Additional public question').inputValue()==='What matters most to you?','Unsaved settings lost');
  await t('Funding').click();await text('New preparation requirement').fill('Authority to assist recorded');assert(await b('Add requirement').isDisabled(),'Duplicate requirement permitted');
  await text('New preparation requirement').fill('Person’s preferred assessment format confirmed');await b('Add requirement').click();await publish();
  let s=await state();assert(s.config.version===2,'Settings version missing');assert(s.records.every(r=>r.version===1),'Existing cases silently migrated');
  passed.push('Settings validation, unsaved-navigation recovery, duplicate prevention and version pinning');
  await t('History').click();await b('Review open-case migration').click();await page.getByRole('checkbox',{name:'Maia Bennett · v1 → v2',exact:true}).check();await next();await text('Reason and approval reference').fill('Review new preparation requirement with Maia.');await next();await done('Apply migration');
  s=await state();const maia=s.records.find(r=>r.id==='REF-1042');assert(maia.version===2 && maia.funding[0].checks.some(c=>c.label==='Person’s preferred assessment format confirmed'&&!c.done),'Migration did not add incomplete requirement');assert(s.records.find(r=>r.id==='REF-1043').version===1,'Unselected case migrated');
  passed.push('Controlled migration changes only selected cases and leaves new requirements incomplete');

  await t('Access').click();await choose('Role to configure','Receptionist');await page.getByRole('switch',{name:'View central intake',exact:true}).click();await publish();
  await role('Receptionist');await page.getByRole('complementary').getByRole('button',{name:'Referrals',exact:true}).click();
  assert(!(await page.locator('main').innerText()).includes('Maia Bennett'),'Central intake still exposed after grant revoked');
  await scenario('Maia · website enquiry, no funding');await page.getByRole('heading',{name:'You don’t have access to this referral'}).waitFor();
  await scenario('Hana · IF partial approval and gap');await page.getByRole('heading',{name:'Funding details are restricted',exact:true}).waitFor();
  await role('Referral manager');await b('Settings').click();await t('Access').click();await t('Record scope').click();await choose('Role to configure','Coordinator');await page.getByRole('switch',{name:'Allow Auckland',exact:true}).click();await publish();
  await role('Coordinator');await scenario('Eli · existing person, extra support');await page.getByRole('heading',{name:'You don’t have access to this referral'}).waitFor();await b('Supported people').click();assert(!(await page.locator('main').innerText()).includes('Eli Carter'),'Person preview ignores site scope');
  passed.push('Published grants apply immediately; sensitive funding hidden; site changes restrict both referral and supported-person previews');

  await role('Referral manager');await page.getByRole('complementary').getByRole('button',{name:'Referrals',exact:true}).click();
  for(const name of ['Draft one','Draft two']){await b('New referral').click();await text('Person’s full name').fill(name);await b('Save draft & close').click();}
  await b('Choose a draft').click();await b('Resume Draft one').click();assert(await text('Person’s full name').inputValue()==='Draft one','Wrong draft resumed');await b('Cancel').click();
  await role('Receptionist');assert(await b('Choose a draft').count()===0,'Other role can see draft selector');
  await role('Referral manager');await scenario('Maia · website enquiry, no funding');
  await b('Walkthroughs').click();await page.getByRole('combobox',{name:'Recovery state to preview'}).selectOption('Save failure');await b('Close').first().click();await b('Add follow-up').click();await text('What needs to happen?').fill('Preserved retry task');await next();await b('Save').click();await page.getByText('The save did not complete. Your entries are preserved. Retry when ready.').waitFor();await done();
  await b('Walkthroughs').click();await page.getByRole('combobox',{name:'Recovery state to preview'}).selectOption('Conflict');await b('Close').first().click();await b('Add follow-up').click();await text('What needs to happen?').fill('Reviewed conflict task');await next();await b('Save').click();await page.getByText(/Simulated conflict: your entries are preserved/).waitFor();await b('Back').click();assert(await text('What needs to happen?').inputValue()==='Reviewed conflict task','Conflict lost entries');await next();await done();
  passed.push('Multiple drafts are selectable and role-scoped; failed save and conflict preserve entries through review and retry');

  await t('Funding').click();await b('Add pathway').click();await text('Person’s preference / assistance requested').fill('Person requests a separate assessment review.');await next();await done();
  s=await state();const added=s.records.find(r=>r.id==='REF-1042').funding.at(-1);assert(added.version===s.config.version && added.checks.some(c=>c.label==='Person’s preferred assessment format confirmed'&&!c.done),'New funding case did not use published template');
  await b('Walkthroughs').click();await page.getByRole('combobox',{name:'Recovery state to preview'}).selectOption('Quarantined document');await b('Close').first().click();await t('Evidence').click();await b('Add evidence').click();await b('Use synthetic example').click();await b('Add to prototype').click();assert(await b('View').isDisabled(),'Quarantined evidence can be opened');
  await b('Walkthroughs').click();await page.getByRole('combobox',{name:'Recovery state to preview'}).selectOption('Delivery failure');await b('Close').first().click();await t('Activity').click();await b('Preview request').click();await next();await next();await done('Simulate delivery');await b('Retry delivery preview').click();await b('Simulate retry').click();
  s=await state();assert(s.records.find(r=>r.id==='REF-1042').notes[0].delivery==='Simulated delivery','Delivery retry did not update local preview');
  passed.push('New cases use published checklist; quarantined evidence stays blocked; failed message preview can retry without losing follow-up');

  await scenario('Eli · existing person, extra support');await b('Review handover').click();await page.getByRole('checkbox',{name:'I checked identity and possible duplicates'}).check();await next();await page.getByRole('checkbox',{name:'I reviewed the information to transfer'}).check();await page.getByRole('checkbox',{name:'Acknowledge pending funding — onboarding only'}).check();await next();await done('Confirm handover');
  s=await state();assert(s.people.length===1 && s.people[0].status==='Active','Existing active person duplicated or downgraded');assert(s.records.find(r=>r.id==='REF-1049').personId==='SP-0204','Canonical person link missing');assert(await b('Approve service start').isDisabled(),'Pending additional service can start without funding');
  passed.push('Existing-person handover preserves canonical active record; pending additional service remains blocked');
  return {result:'PASS',passed};
 } catch(error){return {result:'FAIL',passed,error:String(error),screen:await page.locator('body').innerText()};}
}





