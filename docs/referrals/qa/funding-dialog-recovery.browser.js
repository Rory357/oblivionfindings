async (page) => {
    if (new URL(page.url()).origin !== 'http://127.0.0.1:8766') throw new Error('Use the fictional local preview.');
    const errors = [];
    const onError = error => errors.push(error.message);
    page.on('pageerror', onError);
    try {
        await page.reload();
        await page.getByRole('heading', { name: 'Fictional Website Journey', exact: true }).waitFor();
        const results = [];
        for (const width of [390, 1440]) {
            await page.setViewportSize({ width, height: width === 390 ? 844 : 1000 });
            const funding = page.getByRole('tab', { name: /^Funding assistance/ });
            if (await funding.isVisible()) await funding.click();
            else {
                await page.getByRole('button', { name: /^More views/ }).click();
                await page.getByRole('dialog').getByRole('button', { name: /^Funding assistance/ }).click();
            }
            await page.getByRole('button', { name: /^DSS \/ NASC Supported living/ }).click();
            await page.getByRole('button', { name: 'Funding case actions', exact: true }).click();
            await page.getByRole('menuitem', { name: 'Prepare assistance', exact: true }).click();
            await page.getByText(/No checklist was configured for this pathway version/).waitFor();
            await page.getByRole('dialog').getByRole('button', { name: 'Cancel', exact: true }).click();
            await page.getByRole('alertdialog').getByRole('button', { name: 'Discard draft', exact: true }).click();
            await page.getByRole('dialog').getByRole('contentinfo').getByRole('button', { name: 'Close', exact: true }).click();
            await page.getByRole('dialog').waitFor({ state: 'hidden' });
            await page.waitForFunction(() => getComputedStyle(document.body).pointerEvents !== 'none', undefined, { timeout: 3000 });
            // A real click, not a forced click or CSS reset, proves the page recovered.
            await page.getByRole('button', { name: 'Assign', exact: true }).click();
            await page.getByRole('dialog', { name: 'Assign referral', exact: true }).waitFor();
            await page.getByRole('dialog').getByRole('button', { name: 'Cancel', exact: true }).click();
            const discard = page.getByRole('alertdialog');
            if (await discard.isVisible()) await discard.getByRole('button', { name: /Discard/ }).click();
            await page.getByRole('dialog').waitFor({ state: 'hidden' });
            results.push({ width, pageRecovered: true });
        }
        if (errors.length) throw new Error(errors.join('\n'));
        return { results, errors };
    } finally {
        page.off('pageerror', onError);
    }
}
