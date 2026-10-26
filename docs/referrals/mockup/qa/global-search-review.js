async (page) => {
    const b = (name) => page.getByRole('button', { name, exact: true });
    await page.setViewportSize({ width: 1440, height: 1000 });
    await page.goto('http://127.0.0.1:5186/#route=Reports');
    await b('Search referrals globally').click();
    await page
        .getByRole('textbox', { name: 'Find a referral', exact: true })
        .fill('Noah');
    if (
        (await page
            .getByRole('dialog')
            .getByRole('button', { name: /Noah Clarke/ })
            .count()) !== 1
    )
        throw Error('Global search did not find the scoped record');
    await page.keyboard.press('Escape');
    await page.keyboard.press('Control+k');
    await page
        .getByRole('heading', { name: 'Find in Referrals', exact: true })
        .waitFor();
    await page.keyboard.press('Escape');
    await page
        .getByRole('heading', { name: 'Referral reports', exact: true })
        .waitFor();
    if (
        (await page
            .getByRole('searchbox', { name: 'Search report referrals' })
            .inputValue()) !== ''
    )
        throw Error('Global search altered report filters');
    await page.addScriptTag({
        path: 'C:/Users/steph/Herd/oblivionfindings/node_modules/axe-core/axe.min.js',
    });
    const violations = await page.evaluate(async () =>
        (
            await axe.run(document, {
                runOnly: { type: 'tag', values: ['wcag2a', 'wcag2aa'] },
            })
        ).violations.map((v) => ({
            id: v.id,
            targets: v.nodes.map((n) => n.target),
        })),
    );
    await page.screenshot({
        path: 'output/playwright/referrals-reports-final-desktop.png',
        fullPage: true,
    });
    return {
        searchAndShortcut: true,
        independentReportFilters: true,
        violations,
    };
}
