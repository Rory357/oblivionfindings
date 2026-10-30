async(page)=>{
 const results=[],errors=[];page.setDefaultTimeout(5000);page.on('pageerror',e=>errors.push(String(e)));await page.goto('http://127.0.0.1:5186/');await page.setViewportSize({width:1440,height:1000});await page.emulateMedia({reducedMotion:'reduce'});
 const b=n=>page.getByRole('button',{name:n,exact:true}),nav=n=>page.getByRole('complementary').getByRole('button',{name:n,exact:true}).click();
 await b('Walkthroughs').click();await b('Reset fictional examples').click();await b('Reset examples').click();
 for(const route of ['Referrals','Funding assistance','Follow-ups','Calendar','Reports','Settings','Supported people']){await nav(route);await page.waitForTimeout(250);results.push({route,heading:await page.getByRole('main').getByRole('heading').first().innerText(),overflow:await page.evaluate(()=>document.documentElement.scrollWidth>innerWidth)});}
 await nav('Referrals');await page.getByRole('table').getByText('Noah Clarke',{exact:true}).click();
 for(const tab of ['Overview','Contacts','Funding','Tasks','Proposal','Evidence','Activity','Handover']){await page.getByRole('tab',{name:tab,exact:true}).click();results.push({tab,body:(await page.getByRole('main').innerText()).slice(-150)});}
 await b('Website entry').click();for(const name of ['About us','Privacy','Accessibility']){await b(name).click();results.push({public:name,dialog:await page.getByRole('dialog').innerText()});await page.keyboard.press('Escape');}
 await page.setViewportSize({width:390,height:844});await page.screenshot({path:'output/playwright/referrals-revision-public-mobile.png',fullPage:true});results.push({mobileOverflow:await page.evaluate(()=>document.documentElement.scrollWidth>innerWidth)});
 return {results,errors};
}
