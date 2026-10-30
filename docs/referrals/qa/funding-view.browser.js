async (page) => {
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    await page.reload();
    await page.getByRole('heading', { name: 'Fictional Website Journey', exact: true }).waitFor();
    await page.getByRole('button', { name: /^More views/ }).click();
    await page.getByRole('dialog').getByRole('button', { name: /^Funding assistance/ }).click();
    await page.getByRole('button', { name: /^DSS \/ NASC Supported living/ }).click();
    const dialog = page.getByRole('dialog');
    const checks = [];
    for (const width of [1440, 390]) {
        await page.setViewportSize({ width, height: width === 390 ? 844 : 1000 });
        await page.evaluate(async () => { await document.fonts.ready; await new Promise(resolve => requestAnimationFrame(() => requestAnimationFrame(resolve))); });
        for (const section of ['Assistance', 'Current funding', 'Decision history']) {
            await dialog.getByRole('button', { name: new RegExp(`^${section}`) }).click();
            if (section === 'Decision history') await dialog.getByText('No decision history yet', { exact: true }).waitFor();
            if (section === 'Current funding' && await dialog.getByText('No external decision recorded', { exact: true }).count() !== 1) throw new Error('Missing or repeated empty funding state.');
            const box = await dialog.boundingBox();
            if (!box || box.x < -1 || box.x + box.width > width + 1) throw new Error(`Funding dialog exceeds ${width}`);
            if (/Step \d+ of/.test(await dialog.innerText())) throw new Error('Read-only funding sections are shown as completion steps.');
            checks.push({ width, section });
        }
        await page.screenshot({ path: `output/playwright/referrals-funding-view-${width}.png` });
    }
    if (errors.length) throw new Error(errors.join('\n'));
    return { checks, errors };
}
