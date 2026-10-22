async (page) => {
    const initial = new URL(page.url());
    if (initial.origin !== 'http://127.0.0.1:8766' || !/^\/operations\/clients\/\d+$/.test(initial.pathname)) {
        throw new Error('Open the fictional preview person before running this check.');
    }
    const errors = [];
    const onError = error => errors.push(error.message);
    page.on('pageerror', onError);
    const results = [];
    try {
        for (const width of [1440, 390]) {
            await page.setViewportSize({ width, height: width === 390 ? 844 : 1000 });
            await page.goto(initial.origin + initial.pathname + '?tab=onboarding');
            await page.getByRole('button', { name: 'Edit profile', exact: true }).click();
            const dialog = page.getByRole('dialog');
            const careStep = dialog.getByRole('button', { name: /Care setup.*Risk, key worker/ });
            if (await careStep.isVisible()) {
                await careStep.click();
            } else {
                for (let step = 0; step < 8 && !await dialog.getByText('Actual service start', { exact: true }).isVisible(); step++) {
                    await dialog.getByRole('button', { name: 'Continue', exact: true }).click();
                }
            }
            await dialog.getByText('Actual service start', { exact: true }).waitFor();
            await dialog.getByText(/Recorded through the separate service-start review/).waitFor();
            await dialog.getByText('Not recorded', { exact: true }).waitFor();
            if (await dialog.locator('input[type="date"]').count()) throw new Error('Care setup still has an editable start date');
            const overflow = await dialog.evaluate(el => el.scrollWidth > el.clientWidth + 1);
            if (overflow) throw new Error('Profile dialog overflows horizontally at ' + width);
            await page.screenshot({ path: 'output/playwright/referrals-profile-start-date-' + width + '.png', animations: 'disabled' });
            await dialog.getByRole('button', { name: 'Cancel', exact: true }).click();
            results.push({ width, actualDateReadOnly: true, horizontalOverflow: false });
        }
        if (errors.length) throw new Error(errors.join('\n'));
        return { results, runtimeErrors: errors };
    } finally {
        page.off('pageerror', onError);
    }
}
