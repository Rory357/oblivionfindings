const {chromium}=require('playwright');const fs=require('node:fs'),path=require('node:path');
(async()=>{
 const browser=await chromium.launch({headless:true,executablePath:'C:/Users/steph/AppData/Local/ms-playwright/chromium-1217/chrome-win64/chrome.exe'});
 const page=await browser.newPage({viewport:{width:1360,height:1060},reducedMotion:'reduce',colorScheme:'light'});page.setDefaultTimeout(10000);
 const errors=[];page.on('pageerror',e=>errors.push(String(e)));
 const assert=(yes,message)=>{if(!yes)throw new Error(message);};
 const click=(action,value)=>page.locator('[data-action="'+action+'"]'+(value===undefined?'':'[data-value="'+value+'"]')).last().click();
 const choose=require('./choose-control.cjs')(page);
 const open=async id=>{await click('index');await click('open',String(id));};
 const capture=name=>page.screenshot({path:path.join(__dirname,'clarity-'+name+'.png')});
 await page.goto('file:///'+path.join(__dirname,'index.html').replaceAll('\\','/'));
 assert(await page.locator('#hi-rec-preview').count()===0,'Empty preview still shown');
 assert((await page.locator('.hi-window').boundingBox()).width<=650,'First step not compact');await capture('people-light');
 await click('recipient','mila');await click('rec-next');assert(!await page.locator('.hi-rec-options').evaluate(el=>el.open),'Optional details open by default');
 const message='Thank you for explaining things with such patience. You helped our new colleague feel at home.';
 await page.locator('[data-bind="rec.message"]').fill(message);await capture('message-light');await click('theme');await capture('message-dark');await click('theme');
 await click('rec-prompt');assert(await page.locator('[data-bind="rec.message"]').inputValue()===message,'Writing help overwrote existing message');
 await click('rec-next');assert((await page.locator('#hi-dialog-title').textContent()).includes('Give a shout-out'),'Optional value blocked review');assert(!(await page.locator('.hi-dialog-content').textContent()).includes('VALUE & IMPACT'),'Rating copy remains in review');assert(!await page.locator('#hi-rec-preview .hi-badge').count(),'Unselected value was fabricated');
 await click('rec-step','1');const summary=page.locator('.hi-rec-options summary');await summary.focus();await page.keyboard.press('Enter');await page.keyboard.press('Tab');assert(await page.locator('.hi-rec-options').evaluate(el=>el.contains(document.activeElement)),'Keyboard cannot enter optional details');
 await click('rec-value','Teamwork');await choose('rec.impact','Exceptional');assert((await page.locator('#hi-rec-preview').textContent()).includes('Above and beyond'),'Warm heading missing');assert(await page.locator('[data-bind="rec.message"]').inputValue()===message,'Optional details lost draft');await capture('details-light');
 await click('rec-clear-value');assert(!await page.locator('#hi-rec-preview .hi-badge').count(),'Remove optional value failed');await summary.click();await summary.focus();await page.keyboard.press('Tab');assert(await page.locator('.hi-window').evaluate(el=>el.contains(document.activeElement)),'Collapsed summary escaped focus containment');
 await click('rec-next');await click('rec-send');await page.getByRole('heading',{name:'Thank you for noticing the good.'}).waitFor();
 // Global search finds journeys outside the selected category and offers recovery.
 await click('index');assert(await page.locator('.hi-index-card').count()===37,'Index lost a family');await capture('journeys-light');await click('group','3');const input=page.locator('[data-search="index"]');await input.fill('birthday');await page.getByText('1 result across all journeys',{exact:true}).waitFor();assert(await page.locator('.hi-index-card[data-value="3"]').count()===1,'Search was limited to selected group');await capture('search-light');await input.fill('zznomatchingjourney');await page.getByText('No journeys match that search',{exact:true}).waitFor();await page.locator('.hi-empty [data-action="journeys-clear"]').click();assert(await input.inputValue()==='','Clear-search recovery failed');
 // Share an idea remains a focused task; editors get the page workspace.
 await open(8);await capture('idea-light');
 await open(6);assert(await page.locator('.hi-page-surface').count()===1,'Event editor did not use page workspace');await capture('event-editor-light');
 await open(25);await page.locator('[data-bind="hero.title"]').fill('Keep this thoughtful welcome');await page.getByRole('button',{name:'Back to Hub',exact:true}).click();await page.getByRole('heading',{name:'Leave these changes?'}).waitFor();await click('confirm-cancel');assert(await page.locator('[data-bind="hero.title"]').inputValue()==='Keep this thoughtful welcome','Page exit lost unsaved draft');
 await open(27);await capture('designer-light');await click('theme');await capture('designer-dark');await click('theme');
 await open(22);assert(await page.locator('.hi-task-drawer').count()===1,'Preferences did not use side panel');await capture('preferences-light');await page.locator('[data-bind="notifyNews"]').check();await click('notifications-save');await page.getByText('Notification preferences saved in this preview',{exact:true}).waitFor();await open(22);assert(await page.locator('[data-bind="notifyNews"]').isChecked(),'Drawer lost saved preference');
 // All entries remain reachable and fit the supported smaller desktop width.
 await page.setViewportSize({width:1024,height:1000});await click('index');const ids=await page.locator('.hi-index-card').evaluateAll(elements=>elements.map(el=>el.dataset.value));assert(new Set(ids).size===37,'Duplicate or missing journey');
 const measurements=[];for(const id of ids){await click('open',id);const geometry=await page.locator('.hi-window').evaluate(el=>{const r=el.getBoundingClientRect(),content=el.querySelector('.hi-dialog-content');return {left:r.left,right:r.right,bottom:r.bottom,overflow:content.scrollWidth>content.clientWidth+2};});assert(geometry.left>=0&&geometry.right<=1024&&geometry.bottom<=1000&&!geometry.overflow,'1024px overflow in '+id+': '+JSON.stringify(geometry));measurements.push({id,...geometry});await click('index');}
 await open(27);await capture('designer-1024');await open(1);await click('recipient','noah');await click('rec-next');await capture('message-1024');
 assert(errors.length===0,errors.join('\n'));fs.writeFileSync(path.join(__dirname,'clarity-checks.json'),JSON.stringify({errors,measurements,verified:['compact people selection with no empty preview','message-only recognition succeeds','optional details keyboard access and clear value','warm presentation labels','draft retention and nondestructive writing help','37 unique journeys','search across categories and no-match recovery','full-page editor exit guard','side-panel preferences persist','all entries fit 1024px desktop','light and dark inspection'],scope:'Fictional local preview only'},null,2));
 await browser.close();console.log('Passed task-simplicity checks and 37-family desktop layout scan.');
})().catch(error=>{console.error(error);process.exit(1);});
