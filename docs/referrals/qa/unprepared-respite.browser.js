async (page) => {
    const errors = [];
    const report = [];
    const onError = error => errors.push(error.message);
    page.on('pageerror', onError);
    try {
        for (const width of [1440, 390]) {
            await page.setViewportSize({ width, height: width === 390 ? 844 : 1000 });
            await page.goto('http://127.0.0.1:8766/respite/stays/2');
            await page.getByRole('heading', { name: 'Fictional Unprepared respite', exact: true }).waitFor();
            await page.getByRole('button', { name: 'Check in', exact: true }).click();
            await page.getByText('Check-in not completed', { exact: true }).waitFor();
            await page.getByText(/Scheduled approval alone does not record actual commencement/).waitFor();
            const prepare = page.getByRole('button', { name: 'Prepare support', exact: true });
            await prepare.scrollIntoViewIfNeeded();
            const box = await prepare.boundingBox();
            if (!box || box.height < 44) throw new Error('Preparation target is too small');
            await page.screenshot({ path: `output/playwright/referrals-unprepared-respite-${width}.png` });
            await prepare.click();
            const dialog = page.getByRole('dialog');
            await dialog.getByRole('combobox', { name: 'Site', exact: true }).filter({ hasText: 'Referrals browser preview' }).waitFor();
            await dialog.getByRole('combobox', { name: 'Service setting', exact: true }).filter({ hasText: 'Fictional unprepared respite' }).waitFor();
            await dialog.getByText('Fictional Unprepared respite', { exact: true }).waitFor();
            await dialog.getByRole('button', { name: 'Continue', exact: true }).scrollIntoViewIfNeeded();
            if (await page.evaluate(() => document.documentElement.scrollWidth > innerWidth + 1)) throw new Error(`Page overflow at ${width}`);
            if (await dialog.evaluate(node => node.scrollWidth > node.clientWidth + 1)) throw new Error(`Dialog overflow at ${width}`);
            await page.screenshot({ path: `output/playwright/referrals-respite-preparation-${width}.png` });
            await dialog.getByRole('button', { name: 'Close', exact: true }).first().click();
            report.push({ width, deniedWithoutStart: true, bookingScopeSelected: true, tapHeight: box.height });
        }
        if (errors.length) throw new Error(errors.join('\n'));
        return { report, runtimeErrors: errors };
    } finally {
        page.off('pageerror', onError);
    }
}
