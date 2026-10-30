async (page) => {
    // Fictional report fixtures in the isolated preview; sign in normally first.
    const errors = [];
    page.on('pageerror', (error) => errors.push(error.message));
    await page.keyboard.press('Escape');
    await page.getByRole('dialog').waitFor({ state: 'hidden' });
    await page.setViewportSize({ width: 1440, height: 1000 });
    const scope = async (current, next) => {
        await page.getByRole('button', { name: current, exact: true }).click();
        await page.getByRole('dialog').getByRole('button', { name: next, exact: true }).click();
        await page.getByRole('dialog').waitFor({ state: 'hidden' });
        await page.getByRole('button', { name: next, exact: true }).waitFor();
    };
    const count = async (n) => page.getByRole('heading', { name: `Referrals · ${n} matching`, exact: true }).waitFor();
    await count(6);
    await scope('Received-date cohort', 'All current work');
    await count(7);
    await page.getByRole('link', { name: 'Fictional Older work', exact: true }).waitFor();
    await scope('All current work', 'Received-date cohort');
    await count(6);
    await page.getByRole('button', { name: 'Website: 2 referrals. View matching records', exact: true }).click();
    await count(2);
    if (!page.url().includes('source=website')) throw new Error('Source not persisted in URL');
    await page.reload();
    await count(2);
    if (await page.getByRole('tab', { name: 'Detailed reports', exact: true }).getAttribute('aria-selected') !== 'true') throw new Error('Details tab lost on reload');
    await page.getByRole('button', { name: 'Reset filters', exact: true }).click();
    await count(6);
    await page.getByRole('tab', { name: 'Overview', exact: true }).click();
    await page.getByRole('button', { name: 'Preview Author: 4 referrals. View matching records', exact: true }).click();
    await count(4);
    await page.getByRole('button', { name: 'Reset filters', exact: true }).click();
    await count(6);
    await page.getByRole('tab', { name: 'Overview', exact: true }).click();
    await page.getByText('View exact chart values', { exact: true }).click();
    await page.getByRole('columnheader', { name: 'Received date', exact: true }).waitFor();
    await page.getByText('View exact chart values', { exact: true }).click();
    const checks = [];
    for (const width of [1440, 390]) {
        await page.setViewportSize({ width, height: width === 390 ? 844 : 1000 });
        const overflow = await page.evaluate(() => document.documentElement.scrollWidth > document.documentElement.clientWidth + 1);
        if (overflow) throw new Error(`Report overflows at ${width}px`);
        await page.screenshot({ path: `output/playwright/referrals-reports-${width}.png`, fullPage: true });
        if (width === 390) await page.getByRole('button', { name: /Details & filters/ }).click();
        await page.getByRole('button', { name: 'Received dates', exact: true }).click();
        const dateDialog = page.getByRole('dialog');
        const bounds = await dateDialog.boundingBox();
        if (!bounds || bounds.x < 0 || bounds.y < 0 || bounds.x + bounds.width > width + 1 || bounds.y + bounds.height > (width === 390 ? 844 : 1000)) throw new Error('Date dialog escapes viewport');
        await dateDialog.getByRole('button', { name: 'Apply dates', exact: true }).click();
        await dateDialog.waitFor({ state: 'hidden' });
        checks.push({ width, overflow, dateDialogFits: true });
    }
    await page.getByRole('button', { name: 'Export', exact: true }).click();
    const exportDialog = page.getByRole('dialog');
    await exportDialog.getByRole('heading', { name: 'Export referral report', exact: true }).waitFor();
    const bounds = await exportDialog.boundingBox();
    if (!bounds || bounds.y < 0 || bounds.y + bounds.height > 844) throw new Error('Export dialog escapes phone viewport');
    await page.screenshot({ path: 'output/playwright/referrals-reports-export-mobile.png' });
    const downloadPending = page.waitForEvent('download');
    await exportDialog.getByRole('button', { name: 'Prepare download', exact: true }).click();
    const download = await downloadPending;
    if (await download.failure()) throw new Error('Summary download failed');
    await exportDialog.getByRole('status').waitFor();
    await exportDialog.getByRole('button', { name: 'Done', exact: true }).click();
    if (errors.length) throw new Error(errors.join('\n'));
    return { checks, cohort: 6, currentWork: 7, sourceDrill: 2, ownerDrill: 4, summaryDownload: download.suggestedFilename(), errors };
}
