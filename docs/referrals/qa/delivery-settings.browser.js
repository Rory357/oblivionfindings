async (page) => {
    if (new URL(page.url()).origin !== 'http://127.0.0.1:8766') throw new Error('Use the fictional local preview.');
    const errors = [];
    const onError = error => errors.push(error.message);
    page.on('pageerror', onError);
    try {
        await page.getByRole('button', { name: 'Create revised draft', exact: true }).click();
        await page.getByRole('button', { name: 'Delivery settings · Any available', exact: true }).click();
        await page.getByRole('switch', { name: 'Limit Supported living to selected setting types', exact: true }).check();
        await page.getByRole('checkbox', { name: 'Home & Community Support', exact: true }).check();
        await page.getByRole('textbox', { name: 'Reason for this change' }).fill('Fictional preview: restrict supported living to home and community support for delivery mapping verification.');
        const results = [];
        for (const width of [390, 1440]) {
            await page.setViewportSize({ width, height: width === 390 ? 844 : 1000 });
            await page.getByRole('checkbox', { name: 'Home & Community Support', exact: true }).scrollIntoViewIfNeeded();
            const geometry = await page.getByRole('group', { name: 'Choose at least one delivery setting type', exact: true }).evaluate(el => ({
                overflow: document.documentElement.scrollWidth > innerWidth + 1,
                labelHeight: el.querySelector('label').getBoundingClientRect().height,
            }));
            if (geometry.overflow || geometry.labelHeight < 44) throw new Error(JSON.stringify({ width, ...geometry }));
            await page.screenshot({ path: 'output/playwright/referrals-delivery-settings-' + width + '.png', animations: 'disabled' });
            results.push({ width, ...geometry });
        }
        await page.getByRole('button', { name: 'Review draft', exact: true }).click();
        if (errors.length) throw new Error(errors.join('\n'));
        return { results, errors };
    } finally {
        page.off('pageerror', onError);
    }
}
