async (page) => {
 const results=[];
 const scan=async(name)=>{await page.addScriptTag({path:'C:/Users/steph/Herd/oblivionfindings/node_modules/axe-core/axe.min.js'});const a=await page.evaluate(async()=>await axe.run(document,{runOnly:{type:'tag',values:['wcag2a','wcag2aa']}}));const width=await page.evaluate(()=>({viewport:innerWidth,content:document.documentElement.scrollWidth}));results.push({name,width,violations:a.violations.map(v=>({id:v.id,impact:v.impact,nodes:v.nodes.slice(0,8).map(n=>({target:n.target,summary:n.failureSummary}))}))});await page.screenshot({path:'docs/referrals/mockup/qa/'+name+'.png',fullPage:true});};
 try {
  await page.emulateMedia({reducedMotion:'reduce'});await page.setViewportSize({width:1440,height:1000});await page.reload();await page.evaluate(()=>document.fonts.ready);await scan('queue-desktop');
  await page.getByRole('button',{name:'Settings',exact:true}).click();await scan('settings-desktop');
  await page.getByRole('button',{name:'Configure workflow',exact:true}).click();await page.setViewportSize({width:390,height:844});await scan('workflow-phone');
  await page.getByRole('button',{name:/More views/}).click();await page.getByRole('button',{name:'Access',exact:true}).click();await scan('permissions-phone');await page.getByRole('combobox',{name:'Role to configure',exact:true}).click();await scan('role-picker-phone');await page.keyboard.press('Escape');
  await page.getByRole('button',{name:'Website entry',exact:true}).click();await scan('public-phone');
  await page.getByRole('button',{name:'Quick enquiry',exact:true}).click();await scan('intake-wizard-phone');
  await page.getByRole('button',{name:'Cancel',exact:true}).click();await page.getByRole('button',{name:'Staff workspace',exact:true}).click();await page.setViewportSize({width:1440,height:1000});await page.getByRole('cell',{name:'Maia Bennett REF-1042 · Website',exact:true}).click();await scan('case-desktop');
  await page.setViewportSize({width:390,height:844});await scan('case-phone');
  await page.getByRole('button',{name:'Use dark theme',exact:true}).click();await scan('case-phone-dark');await page.getByRole('button',{name:'Use light theme',exact:true}).click();
  return {result:results.some(r=>r.violations.length)?'FINDINGS':'PASS',results};
 }catch(error){return {result:'ERROR',error:String(error),results,screen:await page.locator('body').innerText()};}
}
