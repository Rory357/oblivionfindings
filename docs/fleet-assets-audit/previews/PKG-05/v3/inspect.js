async (page) => {
 await page.bringToFront();await page.emulateMedia({reducedMotion:'reduce'});await page.setViewportSize({width:1440,height:1000});
 await page.reload();await page.getByRole('heading',{name:'Charlie Brown · journey',exact:true}).waitFor();
 await page.screenshot({path:'C:/Users/steph/.codex/worktrees/b9b9/oblivionfindings/docs/fleet-assets-audit/previews/PKG-05/v3/journey-review.png',fullPage:true});
 await page.goto('http://127.0.0.1:4397/#/fleet-assets/transports/planner');
 await page.getByRole('region',{name:'Selected request'}).waitFor();
 await page.screenshot({path:'C:/Users/steph/.codex/worktrees/b9b9/oblivionfindings/docs/fleet-assets-audit/previews/PKG-05/v3/planner-review.png',fullPage:true});
 return {url:page.url(),body:(await page.locator('main').innerText()).slice(-6000),overflow:await page.evaluate(()=>document.documentElement.scrollWidth>innerWidth)};
}
