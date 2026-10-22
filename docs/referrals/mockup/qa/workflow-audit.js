async (page) => {
  const passed=[];
  const button=(name)=>page.getByRole('button',{name,exact:true});
  const fill=(name,value)=>page.getByRole('textbox',{name,exact:false}).fill(value);
  const next=()=>button('Continue').click();
  const done=async(label='Save')=>{await button(label).click();await page.getByRole('heading',{name:'Saved successfully',exact:true}).waitFor();await button('Done').click();};
  const choose=async(label,value)=>{await page.getByRole('combobox',{name:label,exact:true}).click();await page.getByRole('option',{name:value,exact:true}).click();};
  const check=(name)=>page.getByRole('checkbox',{name,exact:true}).check();
  try {
    await page.setViewportSize({width:1440,height:1000});await page.reload();
    await button('Walkthroughs').click();await button('Reset fictional examples').click();await button('Reset examples').click();
    await button('Website entry').click();await button('Quick enquiry').click();
    await next();await page.getByRole('alert').waitFor();passed.push('Empty public submission stays in form with validation');
    await fill('Your name / person seeking support','Taylor Demo');await fill('Safe phone number','021 000 0099');await fill('When and how is it safe','Phone after midday');await next();
    await fill('What support would you like','Help to live independently and find the right funding pathway.');
    await page.getByRole('combobox',{name:'Your funding situation',exact:true}).selectOption('I need help to get funding');
    await page.getByRole('combobox',{name:'Where is support needed?',exact:true}).selectOption('Auckland');await next();
    await check('I understand how these details will be used for this enquiry');await check('It is safe to contact me using the details provided');await next();await done('Send enquiry to prototype');
    await button('Preview duplicate retry').click();await page.getByText(/Retrying this submission did not create another referral/).waitFor();passed.push('Website intake and duplicate-retry receipt');
    await page.getByRole('button',{name:/Follow this enquiry in the staff prototype/}).click();
    await page.getByRole('heading',{name:'Taylor Demo',exact:true}).waitFor();passed.push('Website identity and safe contact reach staff workspace');
    await button('Assign a coordinator').click();await choose('Approved site','Auckland');await choose('Named owner','Emma Taylor');await next();await fill('Assignment note','Demo coordinator accepted responsibility.');await next();await done();
    await button('Review checks').click();await next();
    for(const label of ['Sharing permission / lawful basis recorded','Person chooses this provider','Identity checked','Requested support is suitable','Capacity is available','Service agreement accepted','Safe start plan agreed'])await check(label);
    await fill('Authority basis','Person confirmed choices and consent in fictional discussion.');await fill('Review note / evidence','Fictional assessment reviewed with Taylor.');await next();await done();passed.push('Assignment and person-centred assessment save');
    await page.getByRole('tab',{name:'Funding',exact:true}).click();await button('Work on case').click();
    for(const label of ['Authority to assist recorded','NASC contact confirmed','Assessment information ready'])await check(label);
    await next();await page.getByRole('combobox',{name:'Case status',exact:true}).selectOption('Submitted externally');await fill('External reference / payer agreement','DEMO-NASC-001');await fill('Evidence or submission record','Fictional submission receipt checked for this walkthrough.');await next();await fill('Next action','Follow up external assessment decision');await next();await done();
    await button('Work on case').click();await next();await page.getByRole('combobox',{name:'Case status',exact:true}).selectOption('Decision recorded');await page.getByRole('combobox',{name:'External decision',exact:true}).selectOption('Full approval');await page.getByRole('spinbutton',{name:'Confirmed weekly service contribution (NZD)'}).fill('1200');await fill('Conditions, scope and validity period','Supported living from 19 October 2026; sample scope confirmed.');await next();await next();await done();
    await page.getByRole('combobox',{name:'Preview role'}).selectOption('Finance reviewer');await button('Verify decision').click();await check('I checked the external evidence and delegated authority');await check('Scope, conditions and validity are confirmed');await fill('Verification note','Fictional decision, scope and validity reviewed by finance.');await next();await done();passed.push('Preparation, external submission, decision and separate finance verification');
    await page.getByRole('combobox',{name:'Preview role'}).selectOption('Referral manager');await page.getByRole('tab',{name:'Proposal',exact:true}).click();await button('Edit proposal').click();await fill('Primary payer / host','DSS sample contract');await check('Person / payer accepts this proposal');await next();await fill('Support, frequency and agreement evidence','Weekly support proposal agreed in the fictional walkthrough.');await next();await done();
    await button('Review internal approval').click();await check('Scope, rate and capacity reviewed');await fill('Approval rationale','Within sample service scope and available capacity.');await next();await done();
    await button('Review handover').click();await check('I checked identity and possible duplicates');await next();await check('I reviewed the information to transfer');await next();await done('Confirm handover');passed.push('Proposal and handover create one onboarding person');
    await page.getByRole('tab',{name:'Handover',exact:true}).click();await button('Approve service start').click();await fill('Approval / safe-start handover','Safe-start plan and agreed date confirmed in prototype.');await next();await done();
    await button('Supported people').click();await page.getByRole('heading',{name:'Taylor Demo',exact:true}).waitFor();passed.push('Separate safe-start gate activates the linked person');
    await button('Follow-ups').click();await page.getByRole('radio',{name:'Table',exact:true}).check();await page.getByRole('table').getByText('Taylor Demo',{exact:true}).waitFor();passed.push('Post-conversion work remains visible in follow-up queue');
    return {passed,result:'PASS'};
  } catch(error){return {passed,result:'FAIL',error:String(error),screen:await page.locator('body').innerText()};}
}
