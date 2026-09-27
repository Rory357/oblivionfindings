async(page)=>{await page.bringToFront();await page.emulateMedia({reducedMotion:'reduce'});await page.waitForTimeout(300);return await page.locator('[role=dialog]').allTextContents()}
