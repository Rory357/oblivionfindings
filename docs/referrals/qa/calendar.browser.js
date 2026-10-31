async (page) => {
    // Start on the actual staff calendar after signing in to the disposable preview.
    const errors = [];
    page.on('pageerror', (error) => errors.push(error.message));
    await page.getByRole('heading', { name: 'Referral calendar', exact: true }).waitFor();
    await page.keyboard.press('Escape');
    const selectView = async (view) => {
        const tab = page.getByRole('tab', { name: view, exact: true });
        if (await tab.isVisible()) await tab.click();
        else {
            await page.getByRole('button', { name: /^More views/ }).click();
            await page.getByRole('dialog').getByRole('button', { name: view, exact: true }).click();
        }
        await page.waitForFunction(() => !document.querySelector('[aria-busy="true"]'));
        if (await tab.getAttribute('aria-selected') !== 'true') throw new Error(`${view} did not select`);
    };
    const checks = [];
    for (const width of [1440, 390]) {
        await page.setViewportSize({ width, height: width === 390 ? 844 : 1000 });
        for (const view of ['Month', 'Week', 'Day', 'Agenda', 'Timeline']) {
            await selectView(view);
            const overflow = await page.evaluate(() => document.documentElement.scrollWidth > document.documentElement.clientWidth + 1);
            if (overflow) throw new Error(`${view} overflows at ${width}px`);
            checks.push({ width, view, overflow });
        }
        await selectView(width === 390 ? 'Agenda' : 'Month');
        await page.waitForFunction(() => !document.querySelector('[aria-busy="true"]'));
        await page.screenshot({ path: `output/playwright/referrals-calendar-${width}.png`, fullPage: true });
    }
    await page.getByRole('button', { name: 'New entry', exact: true }).click();
    const dialog = page.getByRole('dialog');
    await dialog.getByRole('button', { name: /Fictional Calendar/ }).waitFor();
    const bounds = await dialog.boundingBox();
    if (!bounds || bounds.y < 0 || bounds.y + bounds.height > 844) throw new Error('Mobile picker escapes viewport');
    await page.screenshot({ path: 'output/playwright/referrals-calendar-picker-mobile.png' });
    await dialog.getByRole('button', { name: 'Close', exact: true }).click();
    if (errors.length) throw new Error(errors.join('\n'));
    return { checks, pickerFits: true, errors };
}
