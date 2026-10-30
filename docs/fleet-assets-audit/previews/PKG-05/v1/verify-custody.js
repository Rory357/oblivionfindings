async(page)=>{
 await page.bringToFront();await page.emulateMedia({reducedMotion:'reduce'});page.setDefaultTimeout(8000);
 const out=[],b=n=>page.getByRole('button',{name:n,exact:true}),seen=async t=>page.getByText(t,{exact:false}).first().waitFor({state:'visible',timeout:5000});
 const scenario=async n=>{await page.goto('http://127.0.0.1:4395/');await b('Preview scenarios').click();await b(n).click();await page.waitForTimeout(150)};
 const shot=async n=>{await page.waitForTimeout(150);await page.screenshot({path:'screenshots/'+n+'.png'})};
 for(const s of ['Missing keys','Unexpected holder / site','Missing equipment']){
  await scenario(s);await b('Prepare for departure').click();await b('Review checkout Check before recording').click();await b('Record checkout').click();await seen('Custody needs reconciliation');await shot('blocked-'+s.toLowerCase().replaceAll(' ','-').replaceAll('/','-'));out.push(s+': checkout blocked, no custody receipt');
 }
 await scenario('Failed pre-use check');await b('Prepare for departure').click();await page.getByRole('checkbox',{name:/Review vehicle pre-use check/}).check();await b('Report failed check').click();await b('Link report to Maintenance').click();await seen('Simulated link response failed');await shot('14-maintenance-link-failed');await b('Retry the same report').click();await seen('M-318 linked');await b('Check duplicate report').click();await seen('No duplicate report created');await b('Return with linked issue').click();await seen('Maintenance hold');await b('Review checkout Check before recording').click();await b('Record checkout').click();await seen('Departure is blocked by a failed check');out.push('Failed check links M-318 after retained retry; duplicate report deduplicates; original checkout return path and hold retained');
 for(const s of ['Incomplete return','Medication unresolved','Return receipt retry']){
  await scenario(s);await b('Record return').click();await b('Continue').click();
  if(s!=='Incomplete return')await page.getByRole('checkbox',{name:/Keys and equipment received in full/}).check();
  await page.getByRole('checkbox',{name:/Passenger return confirmed in source/}).check();await b('Continue').click();await b('Record return receipt').click();
  if(s==='Return receipt retry'){await seen('Receipt acknowledgement was interrupted');await b('Check receipt RC-311').click()}
  await seen('Return receipt recorded');await b('Open record').click();await b('Complete journey').click();
  if(s==='Return receipt retry'){await seen('Journey completed in preview');out.push('Lost return response recovers RC-311, then resolved source confirmations allow completion')}
  else {await seen('Completion is blocked');await shot('blocked-'+s.toLowerCase().replaceAll(' ','-'));out.push(s+': observed receipt retained, completion blocked')}
 }
 await scenario('Disputed handover');await b('Dispute handover').click();await page.getByLabel('What does not match?').fill('Securement kit not received.');await b('Record dispute').click();await seen('receipt remains unresolved');await shot('15-disputed-handover');await b('Assign reconciliation').click();await b('Record exception').click();await seen('Mia Chen owns reconciliation');out.push('Named recipient disputes handover; owned reconciliation remains open');
 await scenario('Normal journey');await page.getByRole('row').filter({hasText:'TR-1042'}).click();await b('Checks & evidence').click();await b('Add supporting evidence').click();await b('Use synthetic sample file').click();await b('Simulate evidence submission').click();await seen('Simulated failure');await shot('16-evidence-failure');await b('Retry unresolved file').click();await seen('EV-92 v1');await b('Stage a new version').click();await seen('Staged locally');await b('Simulate evidence submission').click();await seen('Simulated failure');await b('Retry unresolved file').click();await seen('EV-92 v2');await shot('17-evidence-version');out.push('Synthetic evidence: local staging, failure, retained retry and v2 confirmation; original unchanged, no upload');
 return out;
}
