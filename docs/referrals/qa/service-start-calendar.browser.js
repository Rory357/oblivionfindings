async (page) => {
    if (new URL(page.url()).origin !== 'http://127.0.0.1:8766')
        throw new Error('Use the fictional local preview.');
    const errors = [];
    const onError = (error) => errors.push(error.message);
    page.on('pageerror', onError);
    const results = [];
    try {
        await page.reload();
        await page.getByRole('heading', { name: 'Fictional Website Journey', exact: true }).waitFor();
        for (const width of [390, 1440]) {
            await page.setViewportSize({ width, height: width === 390 ? 844 : 1000 });
            const tab = page.getByRole('tab', { name: /^Start support/ });
            if (await tab.isVisible()) await tab.click();
            else {
                await page.getByRole('button', { name: /^More views/ }).click();
                await page.getByRole('dialog').getByRole('button', { name: /^Start support/ }).click();
            }
            await page.getByRole('button', { name: 'Review and agree start', exact: true }).click();
            const dialog = page.getByRole('dialog', { name: 'Review and agree start', exact: true });
            await dialog.getByText(/No service agreement is available/).waitFor();
            await dialog.getByRole('button', { name: /^Agreed start date:/ }).click();
            const picker = page.getByRole('dialog', { name: 'Agreed start date picker', exact: true });
            const calendarBounds = await picker.boundingBox();
            await picker.getByRole('button', { name: 'Sun 18 October 2026', exact: true }).click();
            await picker.getByRole('button', { name: 'Use date', exact: true }).click();
            await picker.waitFor({ state: 'hidden' });
            await dialog.getByRole('button', { name: 'Agreed start date: 18 Oct 2026', exact: true }).waitFor();
            await dialog.getByRole('button', { name: 'Continue', exact: true }).click();
            await dialog.getByText('Complete the required fields and confirmations before continuing.').waitFor();
            const dialogBounds = await dialog.boundingBox();
            const overflow = await page.evaluate(() => document.documentElement.scrollWidth > innerWidth);
            for (const bounds of [calendarBounds, dialogBounds]) {
                if (!bounds || bounds.x < 0 || bounds.x + bounds.width > width + 1)
                    throw new Error(`Control outside ${width}px viewport`);
            }
            if (overflow) throw new Error('Horizontal page overflow');
            await page.screenshot({ path: `output/playwright/referrals-start-calendar-${width}.png` });
            results.push({ width, calendarBounds, dialogBounds, overflow, incompleteStartBlocked: true });
            await dialog.getByRole('contentinfo').getByRole('button', { name: 'Close', exact: true }).click();
            await page.getByRole('button', { name: 'Close review', exact: true }).click();
            await dialog.waitFor({ state: 'hidden' });
        }
        if (errors.length) throw new Error(errors.join('; '));
        return { results, pageErrors: errors };
    } finally {
        page.off('pageerror', onError);
    }
}
