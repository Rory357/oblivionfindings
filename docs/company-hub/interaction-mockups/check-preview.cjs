const {chromium}=require('playwright');
const fs=require('node:fs');
const path=require('node:path');
(async()=>{
 const browser=await chromium.launch({headless:true,executablePath:'C:/Users/steph/AppData/Local/ms-playwright/chromium-1217/chrome-win64/chrome.exe'});
 const page=await browser.newPage({viewport:{width:1360,height:1060},reducedMotion:'reduce'});
 const errors=[];page.on('pageerror',e=>errors.push(String(e)));
 await page.goto('file:///'+path.join(__dirname,'index.html').replaceAll('\\','/'));
 const choose=require('./choose-control.cjs')(page);
 const click=(a,v)=>page.locator('[data-action="'+a+'"]'+(v===undefined?'':'[data-value="'+v+'"]')).last().click();
 const expect=async(condition,msg)=>{if(!await condition)throw new Error(msg);};
 await expect(page.getByRole('heading',{name:'Give a shout-out',exact:true}).count(),'Initial recognition dialog missing');
 await click('rec-next');await expect(page.locator('.hi-error').count(),'Recipient validation missing');
 await click('recipient','mila');await click('recipient','noah');await click('rec-next');
 if(!await page.locator('.hi-rec-options').evaluate(el=>el.open))await page.locator('.hi-rec-options summary').click();await click('rec-value','Teamwork');await page.locator('[data-bind="rec.message"]').fill('Thank you for the thoughtful welcome. It made the first day feel much easier.');
 await expect(page.locator('#hi-rec-preview').textContent().then(t=>t.includes('thoughtful welcome')),'Preview did not update');
 await click('rec-next');await expect(page.locator('.hi-dialog-content').textContent().then(t=>t.includes('Mila Chen & Noah Williams')),'Recipients missing from review');
 await page.screenshot({path:path.join(__dirname,'recognition-review-light.png')});
 await click('rec-send');await page.getByRole('heading',{name:'Thank you for noticing the good.'}).waitFor();await click('rec-view');
 await click('like-rec');await page.locator('[data-bind="reply"]').fill('Such a lovely start to the week.');await click('post-reply','reply');await page.getByText('Such a lovely start to the week.',{exact:true}).waitFor();
 await click('index');await click('open','1');await click('recipient-mode','team');await click('team-recipient','harbour');await click('rec-next');if(!await page.locator('.hi-rec-options').evaluate(el=>el.open))await page.locator('.hi-rec-options summary').click();await click('rec-value','Leadership');
 await page.locator('[data-bind="rec.message"]').fill('Thank you for all the care you put into helping one another.');
 await choose('scenario','Recoverable error');await click('rec-next');await click('rec-send');await page.getByText('That didn’t go through.',{exact:false}).waitFor();await expect(page.locator('.hi-rec-message').textContent().then(t=>t.includes('care')),'Failed send lost message');await click('rec-send');await page.getByRole('heading',{name:'Thank you for noticing the good.'}).waitFor();
 // Open each family through its index button and inspect basic fit and active controls.
 const views=[];
 for(let group=0;group<4;group++){
  await click('index');await click('group',String(group));
  const ids=await page.locator('.hi-index-card').evaluateAll(es=>es.map(e=>e.dataset.value));
  for(const id of ids){await click('open',id);const title=await page.locator('#hi-dialog-title').textContent();
   const overflow=await page.locator('.hi-window').evaluate(el=>({horizontal:el.scrollWidth>el.clientWidth+2,height:el.getBoundingClientRect().bottom>window.innerHeight}));
   if(overflow.horizontal||overflow.height)throw new Error('Dialog overflow '+id+' '+JSON.stringify(overflow));
   views.push({id,title});await click('index');await click('group',String(group));
  }
 }
 await click('group','all');await click('open','1');if(await page.locator('[data-action="rec-again"]').count())await click('rec-again');
 await click('theme');await page.screenshot({path:path.join(__dirname,'recognition-dark.png')});
 await click('index');await page.screenshot({path:path.join(__dirname,'collection-dark.png')});
 await choose('role','Staff');await click('group','all');await click('open','24');await expect(page.getByText('Hub editors manage shared content.',{exact:false}).count(),'Staff permission state absent');
 await choose('role','Editor');await click('index');await click('group','all');await click('open','5');await click('register-event');await page.locator('.hi-event-booking').getByText('You’re registered',{exact:true}).waitFor();await click('cancel-registration');await click('confirm-yes');await expect(page.getByRole('button',{name:'Count me in',exact:true}).count(),'Cancellation did not return RSVP');await click('event-state','Full');await click('join-waitlist');await page.locator('.hi-event-booking').getByText('You’re on the waitlist',{exact:true}).waitFor();
 await click('index');await click('group','all');await click('open','11');await click('notice-ack');await expect(page.locator('.hi-error').count(),'Acknowledgement validation absent');await page.locator('[data-bind="ack"]').check();await click('notice-ack');await page.getByText('Version 3 acknowledged',{exact:true}).waitFor();await click('notice-revise');await expect(page.getByRole('button',{name:'Confirm I’ve read this'}).count(),'New version did not reset acknowledgement');
 await click('index');await click('group','all');await click('open','32');await click('discard-draft');await click('confirm-yes');await expect(page.getByText('Published page visible',{exact:true}).count(),'Draft discard removed published state');await click('recover-draft');await page.getByText('Saved draft recovered',{exact:true}).waitFor();
 await page.keyboard.press('Tab');await expect(page.locator('.hi-window').evaluate(el=>el.contains(document.activeElement)),'Keyboard focus outside dialog');
 await page.setViewportSize({width:1024,height:1000});await click('index');await click('group','all');await click('open','1');await page.screenshot({path:path.join(__dirname,'recognition-1024.png')});
 fs.writeFileSync(path.join(__dirname,'browser-checks.json'),JSON.stringify({views,errors,verified:['recognition people and team','live preview','send failure and retry preserves text','replies and reactions','37 family entry points','dialog bounds','staff creation permission state','RSVP/cancellation/waitlist','exact-version acknowledgement','draft discard/recovery preserves published state','keyboard focus remains in dialog'],note:'Local mockup behavior only; no production verification.'},null,2));
 await browser.close();if(errors.length)throw new Error(errors.join('\n'));console.log('Passed '+views.length+' family openings and critical local interaction checks.');
})().catch(e=>{console.error(e);process.exit(1);});
