async (page) => {
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    const tab = page.getByRole('tab', { name: 'Communications', exact: true });
    if (await tab.isVisible()) await tab.click();
    else {
        await page.getByRole('button', { name: /^More views/ }).click();
        await page.getByRole('dialog').getByRole('button', { name: 'Communications', exact: true }).click();
        await page.getByRole('dialog').waitFor({ state: 'hidden' });
    }
    const mail = page.locator('[data-slot="card"]').filter({ hasText: 'Enquiry acknowledgement' });
    const intake = page.locator('[data-slot="card"]').filter({ hasText: 'Intake staff notice' });
    await mail.getByText('Cancelled', { exact: true }).waitFor();
    await intake.getByText('In-app notice delivered', { exact: true }).waitFor();
    const checks = [];
    for (const width of [1440, 390]) {
        await page.setViewportSize({ width, height: width === 390 ? 844 : 1000 });
        await page.evaluate(async () => { await document.fonts.ready; await new Promise(resolve => requestAnimationFrame(() => requestAnimationFrame(resolve))); });
        await page.getByRole('button', { name: 'Refresh status', exact: true }).click();
        await intake.getByText('In-app notice delivered', { exact: true }).waitFor();
        const overflow = await page.evaluate(() => document.documentElement.scrollWidth > document.documentElement.clientWidth + 1);
        if (overflow) throw new Error(`Communications overflows at ${width}`);
        await page.screenshot({ path: `output/playwright/referrals-communications-${width}.png`, fullPage: true });
        checks.push({ width, overflow });
    }
    if (errors.length) throw new Error(errors.join('\n'));
    return { obsoleteAcknowledgementCancelled: true, internalNoticeDelivered: true, checks, errors };
}
