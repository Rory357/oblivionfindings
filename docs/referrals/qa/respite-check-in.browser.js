async (page) => {
    const errors = [];
    const report = [];
    const onError = error => errors.push(error.message);
    page.on('pageerror', onError);
    try {
        for (const width of [1440, 390]) {
            await page.setViewportSize({ width, height: width === 390 ? 844 : 1000 });
            await page.goto('http://127.0.0.1:8766/respite/stays/1');
            await page.getByRole('heading', { name: 'Fictional Respite check-in', exact: true }).waitFor();
            await page.evaluate(() => document.fonts.ready);
            await page.getByText('Checked in', { exact: true }).waitFor();
            if (await page.getByRole('button', { name: 'Extend', exact: true }).count()) throw new Error('An admitted stay offers an invalid extension action');
            const button = page.getByRole('button', { name: 'Check in', exact: true });
            await button.click();
            await page.getByText('Check-in not completed', { exact: true }).waitFor();
            await page.getByRole('alert').filter({ hasText: 'Ask the responsible manager' }).waitFor();
            const review = page.getByRole('link', { name: 'Review service start', exact: true });
            const url = await review.getAttribute('href');
            if (!url?.endsWith('?tab=service-start')) throw new Error('Missing service-start recovery link');
            await button.scrollIntoViewIfNeeded();
            const box = await button.boundingBox();
            if (!box || box.height < 44 || box.x < 0 || box.x + box.width > width) throw new Error('Check-in tap target does not fit');
            const overflow = await page.evaluate(() => document.documentElement.scrollWidth > innerWidth + 1);
            if (overflow) throw new Error(`Horizontal page overflow at ${width}`);
            await page.screenshot({ path: `output/playwright/referrals-respite-blocked-${width}.png` });
            await review.click();
            await page.getByRole('heading', { name: 'Start support', exact: true }).waitFor();
            await page.getByText('Prepare a support proposal before reviewing a start.', { exact: true }).waitFor();
            if (!page.url().endsWith('?tab=service-start')) throw new Error('Review did not open its own tab');
            report.push({ width, blockedCheckIn: true, recoveryLink: true, tapHeight: box.height });
        }
        if (errors.length) throw new Error(errors.join('\n'));
        return report;
    } finally {
        page.off('pageerror', onError);
    }
}
