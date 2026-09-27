async(page)=>{
 await page.bringToFront();await page.emulateMedia({reducedMotion:'reduce'});page.setDefaultTimeout(7000);await page.goto('http://127.0.0.1:4395/');
 const b=n=>page.getByRole('button',{name:n,exact:true});await b('Preview scenarios').click();await b('Access denied').click();await b('Maintenance').click();const dialog=await page.getByRole('dialog').innerText();await page.screenshot({path:'C:/Users/steph/.codex/worktrees/b9b9/oblivionfindings/docs/fleet-assets-audit/previews/PKG-05/audit-v1/screenshots/P01-denied-linked-maintenance.png'});await page.goto('http://127.0.0.1:4395/');return {id:'P01-denied-linked-maintenance',linkedRecordExposed:dialog.includes('M-318')&&dialog.includes('CHK-441'),dialog};
}
