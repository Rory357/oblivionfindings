async (page) => {
    const errors = [];
    const onError = (error) => errors.push(error.message);
    page.on('pageerror', onError);
    const report = [];
    try {
        await page.setViewportSize({ width: 1440, height: 1000 });
        await page.goto(
            'http://127.0.0.1:8766/operations/clients/2?tab=onboarding',
        );
        await page
            .getByRole('button', { name: 'Prepare support', exact: true })
            .click();
        const dialog = page.getByRole('dialog');
        await dialog
            .getByRole('combobox', { name: 'Service', exact: true })
            .click();
        await page
            .getByRole('option', { name: 'Supported living', exact: true })
            .click();
        await dialog
            .getByRole('textbox', { name: 'Why is support being prepared?' })
            .fill(
                'Fictional browser verification: prepare daily living support with the existing person.',
            );
        await dialog
            .getByRole('button', { name: 'Continue', exact: true })
            .click();
        await dialog
            .getByRole('textbox', { name: 'Next action', exact: true })
            .fill('Discuss participation and consent with the person.');
        await dialog
            .getByRole('button', {
                name: 'Next action due date: Choose date',
                exact: true,
            })
            .click();
        await page
            .getByRole('button', { name: 'Mon 19 October 2026', exact: true })
            .click();
        await page
            .getByRole('button', { name: 'Use date', exact: true })
            .click();
        await dialog
            .getByRole('button', {
                name: 'Next action due time: Choose time',
                exact: true,
            })
            .click();
        await page
            .getByRole('button', { name: 'Use time', exact: true })
            .click();
        await dialog
            .getByRole('button', { name: 'Continue', exact: true })
            .click();
        for (const width of [1440, 390]) {
            await page.setViewportSize({
                width,
                height: width === 390 ? 844 : 1000,
            });
            await dialog
                .getByText('Fictional Internal preparation', { exact: true })
                .waitFor();
            const save = dialog.getByRole('button', {
                name: 'Open support case',
                exact: true,
            });
            await save.scrollIntoViewIfNeeded();
            const box = await save.boundingBox();
            if (
                !box ||
                box.height < 44 ||
                box.x < 0 ||
                box.x + box.width > width
            )
                throw new Error(`Save target does not fit at ${width}`);
            if (
                await page.evaluate(
                    () => document.documentElement.scrollWidth > innerWidth + 1,
                )
            )
                throw new Error(`Page overflows at ${width}`);
            if (
                await dialog.evaluate(
                    (node) => node.scrollWidth > node.clientWidth + 1,
                )
            )
                throw new Error(`Dialog overflows at ${width}`);
            await page.screenshot({
                path: `output/playwright/referrals-internal-review-${width}.png`,
            });
            report.push({ width, tapHeight: box.height, noOverflow: true });
        }
        await dialog.getByRole('button', { name: 'Back', exact: true }).click();
        await dialog
            .getByRole('textbox', { name: 'Next action', exact: true })
            .waitFor();
        await page.screenshot({
            path: 'output/playwright/referrals-internal-action-390.png',
        });
        await dialog
            .getByRole('button', { name: 'Continue', exact: true })
            .click();
        await dialog
            .getByRole('checkbox', { name: /I have checked existing cases/ })
            .check();
        await dialog
            .getByRole('button', { name: 'Open support case', exact: true })
            .click();
        await dialog
            .getByText('Support case opened', { exact: true })
            .waitFor();
        await dialog.getByText(/services have not started/).waitFor();
        const link = dialog.getByRole('link', {
            name: 'Open support case',
            exact: true,
        });
        const href = await link.getAttribute('href');
        await link.click();
        await page
            .getByRole('heading', {
                name: 'Fictional Internal preparation',
                exact: true,
            })
            .waitFor();
        if (errors.length) throw new Error(errors.join('\n'));
        return { report, saved: true, caseUrl: href, runtimeErrors: errors };
    } finally {
        page.off('pageerror', onError);
    }
};
