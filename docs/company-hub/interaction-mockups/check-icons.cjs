const {chromium}=require('playwright'),fs=require('node:fs'),path=require('node:path');
(async()=>{
 const browser=await chromium.launch({headless:true,executablePath:'C:/Users/steph/AppData/Local/ms-playwright/chromium-1217/chrome-win64/chrome.exe'});
 const page=await browser.newPage({viewport:{width:1360,height:1060},reducedMotion:'reduce',colorScheme:'light'});page.setDefaultTimeout(10000);
 const errors=[];page.on('pageerror',error=>errors.push(String(error)));const assert=(yes,message)=>{if(!yes)throw new Error(message);};
 const click=(action,value)=>page.locator('[data-action="'+action+'"]'+(value===undefined?'':'[data-value="'+value+'"]')).last().click();
 const open=async id=>{await click('index');await click('open',String(id));};
 await page.goto('file:///'+path.join(__dirname,'index.html').replaceAll('\\','/'));
 assert(await page.locator('#hi-dialog-title [data-hub-icon="appreciation"]').count()===1,'Recognition heading lacks original mark');
 await click('recipient','mila');await click('rec-next');await page.locator('[data-bind="rec.message"]').fill('Thank you for making everyone feel welcome and included.');
 assert(await page.locator('.hi-rec-cover [data-hub-icon="appreciation"]').count()===1,'Recognition card has old symbol');await page.screenshot({path:path.join(__dirname,'icons-recognition-light.png')});
 await open(43);assert(await page.locator('.hi-icon-specimen').count()===12,'Icon family incomplete');
 await page.locator('.hi-dialog-content').evaluate(el=>{el.style.maxHeight='none';el.style.overflow='visible';});
 await page.locator('.hi-window').evaluate(el=>{el.style.maxHeight='none';});
 await page.locator('#hub-interactions').evaluate(el=>el.style.height='1150px');await page.setViewportSize({width:1360,height:1190});await page.screenshot({path:path.join(__dirname,'icons-family-light.png')});
 await click('theme');await page.locator('.hi-dialog-content').evaluate(el=>{el.style.maxHeight='none';el.style.overflow='visible';});await page.locator('.hi-window').evaluate(el=>el.style.maxHeight='none');await page.screenshot({path:path.join(__dirname,'icons-family-dark.png')});
 await page.reload();await page.setViewportSize({width:1024,height:1000});const screens=[];
 for(const theme of ['light','dark']){if(theme==='dark')await click('theme');for(let id=1;id<=37;id++){await open(id);assert(await page.locator('.lucide-sparkles,.lucide-star,[data-lucide="sparkles"],[data-lucide="star"]').count()===0,'Generic sparkle on journey '+id);const dims=await page.locator('.hi-dialog-content').evaluate(el=>[el.scrollWidth,el.clientWidth]);assert(dims[0]<=dims[1]+2,'Overflow on journey '+id);screens.push({id,theme,icons:await page.locator('[data-hub-icon]').count()});}await open(43);await page.screenshot({path:path.join(__dirname,'icons-family-'+theme+'-1024.png')});}
 await open(25);const choose=require('./choose-control.cjs')(page);await choose('hero.icon','sparkles');assert(await page.locator('#hi-hero-preview [data-hub-icon="appreciation"]').count()===1,'Hero still uses old sparkle');assert(!(await page.locator('.hi-dialog-content').textContent()).includes('Sparkles'),'Old sparkle label remains');
 assert(errors.length===0,errors.join('\n'));fs.writeFileSync(path.join(__dirname,'icon-checks.json'),JSON.stringify({errors,originalIcons:12,screens,verified:['custom recognition heading and card','all 12 family glyphs','zero generic sparkle/star glyphs across 37 journeys','hero picker label and preview','all layouts at 1024px in both themes'],scope:'Fictional local mockup only'},null,2));await browser.close();console.log('Passed original-icon checks across 37 journeys in both themes.');
})().catch(error=>{console.error(error);process.exit(1);});
