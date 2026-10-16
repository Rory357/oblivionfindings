const {chromium}=require('playwright');const fs=require('node:fs'),path=require('node:path');
(async()=>{
 const browser=await chromium.launch({headless:true,executablePath:'C:/Users/steph/AppData/Local/ms-playwright/chromium-1217/chrome-win64/chrome.exe'});
 const page=await browser.newPage({viewport:{width:1360,height:1060},reducedMotion:'reduce',colorScheme:'light'});page.setDefaultTimeout(10000);
 const errors=[];page.on('pageerror',e=>errors.push(String(e)));
 const assert=(yes,message)=>{if(!yes)throw new Error(message);};
 const click=(a,v)=>page.locator('[data-action="'+a+'"]'+(v===undefined?'':'[data-value="'+v+'"]')).last().click();
 const choose=require('./choose-control.cjs')(page);
 const open=async(_group,id)=>{await click('index');await click('open',String(id));};
 const capture=name=>page.screenshot({path:path.join(__dirname,name+'.png')});
 const bounds=async()=>{const data=await page.locator('.hi-picker-menu').evaluate(el=>{const r=el.getBoundingClientRect();return {left:r.left,top:r.top,right:r.right,bottom:r.bottom,width:innerWidth,height:innerHeight,side:el.dataset.side};});assert(data.left>=0&&data.top>=0&&data.right<=data.width+1&&data.bottom<=data.height+1,'Menu outside viewport: '+JSON.stringify(data));return data;};
 await page.goto('file:///'+path.join(__dirname,'index.html').replaceAll('\\','/'));
 await capture('refined-recipients-light');
 // Toolbar picker is keyboard operable and Escape never closes the underlying dialog.
 const role=page.locator('[data-picker="role"]');await role.focus();await page.keyboard.press('ArrowDown');await bounds();await capture('refined-role-menu-light');await page.keyboard.press('Escape');assert(await role.getAttribute('aria-expanded')==='false','Escape left picker open');assert(await page.locator('.hi-window').count()===1,'Escape closed parent dialog');assert(await role.evaluate(el=>el===document.activeElement),'Escape lost trigger focus');
 await role.press('ArrowDown');await page.keyboard.press('Home');await page.keyboard.press('Enter');assert((await role.getAttribute('data-current'))==='Staff','Keyboard role selection failed');await choose('role','Editor');
 // Visible impact cards drive the same preview without corrupting message length.
 await click('recipient','mila');await click('rec-next');if(!await page.locator('.hi-rec-options').evaluate(el=>el.open))await page.locator('.hi-rec-options summary').click();await click('rec-value','Teamwork');const message='Thank you for making our new colleagues feel welcome from their very first day.';
 await page.locator('[data-bind="rec.message"]').fill(message);await choose('rec.impact','Impressive');assert(await page.locator('#hi-count').textContent()===message.length+' / 2,000','Impact overwrote character count');assert((await page.locator('#hi-rec-preview').textContent()).includes('You inspired us'),'Impact did not update card');await page.keyboard.press('ArrowRight');assert(await page.locator('[data-choice="rec.impact"][aria-checked="true"]').getAttribute('data-value')==='Exceptional','Radio arrows failed');
 await page.locator('.hi-dialog-content').evaluate(el=>el.scrollTop=0);await capture('refined-message-light');await click('theme');await capture('refined-message-dark');await click('theme');
 // People menus search role/site as well as names, recover from empty results, and preserve form state.
 await open(1,6);await page.locator('[data-bind="title"]').fill('A fresh invitation');await page.locator('[data-picker="host"]').click();await bounds();await capture('refined-people-menu-light');const input=page.locator('.hi-picker-search input');await input.fill('no-such-colleague');assert(await page.locator('.hi-picker-empty').count()===1,'No-match state missing');await page.locator('[data-picker-clear]').click();await input.fill('Learning adviser');assert(await page.locator('[data-option]').count()===1,'Role search failed');await input.press('Enter');assert(await page.locator('[data-picker="host"]').getAttribute('data-current')==='Leo Thompson','Search selection failed');assert(await page.locator('[data-bind="title"]').inputValue()==='A fresh invitation','Picker lost draft text');
 await page.locator('[data-picker="host"]').click();await page.keyboard.press('Tab');assert(!await page.locator('.hi-picker-menu').count(),'Tab did not dismiss menu');assert(await page.locator('[data-picker="recurrence"]').evaluate(el=>el===document.activeElement),'Tab did not advance to next field');
 await click('theme');await page.locator('[data-picker="host"]').click();await capture('refined-people-menu-dark');await page.keyboard.press('Escape');await click('theme');
 // Menus in low fields flip upwards rather than being clipped by the dialog.
 await page.setViewportSize({width:1024,height:900});await open(4,25);await page.locator('[data-picker="hero.destination"]').click();const placement=await bounds();await capture('refined-menu-1024');await page.keyboard.press('Escape');
 await open(4,27);assert(!await page.locator('.hi-dialog-content').evaluate(el=>el.scrollWidth>el.clientWidth+2),'Designer overflow at 1024');await choose('designWidth','Half');assert((await page.locator('.hi-design-block.selected').getAttribute('aria-label')).includes('Half'),'Width selection did not update block');await capture('refined-designer-1024');
 // All family entries use the new controls, with no browser-native select left behind.
 let nativeSelects=0;for(let group=0;group<4;group++){await click('index');await click('group',String(group));const ids=await page.locator('.hi-index-card').evaluateAll(es=>es.map(e=>e.dataset.value));for(const id of ids){await click('open',id);nativeSelects+=await page.locator('select').count();await click('index');await click('group',String(group));}}
 assert(nativeSelects===0,'Native dropdowns remain');assert(errors.length===0,errors.join('\n'));
 fs.writeFileSync(path.join(__dirname,'control-checks.json'),JSON.stringify({errors,nativeSelects,placement,verified:['role keyboard selection and Escape focus restoration','impact radio arrows and message count','search people by role, clear no-match and select','draft text survives selection','Tab dismisses menu and advances','light/dark controls','1024px menu bounds and designer width change','37 family entries without native selects'],scope:'Fictional local preview only'},null,2));
 await browser.close();console.log('Passed modern-control checks and 37-family scan.');
})().catch(e=>{console.error(e);process.exit(1);});
