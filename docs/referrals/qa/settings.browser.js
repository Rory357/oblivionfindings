async (page) => {
    const errors = [];
    page.on('pageerror', (error) => errors.push(error.message));
    await page.getByRole('heading', { name: 'Referral settings', exact: true }).waitFor();
    const checks = [];
    const selectSection = async (section) => {
        const tab = page.getByRole('tab', { name: section, exact: true });
        if (await tab.isVisible()) await tab.click();
        else {
            await page.getByRole('button', { name: /^More views/ }).click();
            await page.getByRole('dialog').getByRole('button', { name: section, exact: true }).click();
            await page.getByRole('dialog').waitFor({ state: 'hidden' });
        }
        if (await tab.getAttribute('aria-selected') !== 'true') throw new Error(`${section} did not select`);
    };
    for (const width of [1440, 390]) {
        await page.setViewportSize({ width, height: width === 390 ? 844 : 1000 });
        await page.evaluate(async () => {
            await document.fonts.ready;
            await new Promise((resolve) => requestAnimationFrame(() => requestAnimationFrame(resolve)));
        });
        for (const section of ['Website', 'Workflow', 'Funding pathways', 'Approvals', 'Permissions', 'History']) {
            await selectSection(section);
            const overflow = await page.evaluate(() => document.documentElement.scrollWidth > document.documentElement.clientWidth + 1);
            if (overflow) throw new Error(`${section} overflows at ${width}`);
            checks.push({ width, section, overflow });
        }
        await selectSection('Website');
        await page.screenshot({ path: `output/playwright/referrals-settings-${width}.png`, fullPage: true });
    }
    if (errors.length) throw new Error(errors.join('\n'));
    return { checks, errors };
}
