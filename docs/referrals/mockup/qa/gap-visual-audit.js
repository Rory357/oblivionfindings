async(page)=>{
 const b=name=>page.getByRole('button',{name,exact:true});
 const nav=name=>page.getByRole('complementary').getByRole('button',{name,exact:true}).click();
 const scans=[];
 const scan=async(name)=>{
  if(await page.getByRole('status').filter({hasText:'Fictional examples restored'}).count())await page.getByRole('status').filter({hasText:'Fictional examples restored'}).waitFor({state:'hidden'});
  await page.addScriptTag({path:'C:/Users/steph/Herd/oblivionfindings/node_modules/axe-core/axe.min.js'});
  const violations=await page.evaluate(async()=>(await axe.run(document,{runOnly:{type:'tag',values:['wcag2a','wcag2aa']}})).violations.map(v=>({id:v.id,impact:v.impact,targets:v.nodes.map(n=>n.target)})));
  await page.screenshot({path:'output/playwright/referrals-review-'+name+'.png',fullPage:false});
  scans.push({name,violations,overflow:await page.evaluate(()=>document.documentElement.scrollWidth>innerWidth)});
 };
 await page.keyboard.press('Escape');if(await b('Discard changes').isVisible())await b('Discard changes').click();if(await b('Staff workspace').isVisible())await b('Staff workspace').click();await b('Walkthroughs').click();await b('Reset fictional examples').click();await b('Reset examples').click();
 await page.setViewportSize({width:1440,height:1000});await page.getByRole('radio',{name:'Cards',exact:true}).click();await scan('cards-desktop');
 await nav('Settings');await b('Review routing & follow-up').click();await scan('settings-desktop');await page.setViewportSize({width:390,height:844});await scan('settings-phone');
 await nav('Calendar');await scan('calendar-phone');await page.setViewportSize({width:1440,height:1000});await scan('calendar-desktop');
 const clock=await page.evaluate(()=>({browserZone:Intl.DateTimeFormat().resolvedOptions().timeZone,now:new Date().toISOString(),header:document.querySelector('.global-date')?.textContent}));
 await nav('Referrals');await page.getByRole('radio',{name:'Table',exact:true}).click();await page.getByRole('table').getByText('Hana Reed',{exact:true}).click();await page.setViewportSize({width:390,height:844});await scan('case-phone');
 const result={scans,clock};await page.evaluate(r=>localStorage.setItem('referrals-gap-visual-evidence',JSON.stringify(r)),result);return result;
}
