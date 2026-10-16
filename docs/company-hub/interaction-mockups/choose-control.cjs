module.exports=page=>async(key,value)=>{
  const inline=page.locator('[data-choice="'+key+'"][data-value="'+value+'"]');
  if(await inline.count())return inline.click();
  await page.locator('[data-picker="'+key+'"]').click();
  await page.locator('.hi-picker-menu [data-option="'+value+'"]').click();
};
