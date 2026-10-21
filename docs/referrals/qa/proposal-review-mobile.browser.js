async (page) => {
    if (new URL(page.url()).origin !== 'http://127.0.0.1:8766') throw new Error('Use the isolated fictional preview.');
    await page.setViewportSize({ width: 390, height: 844 });
    await page.reload();
    await page.getByRole('heading', { name: 'Fictional Website Journey', exact: true }).waitFor();
    const proposals = page.getByRole('tab', { name: 'Support proposals', exact: true });
    if (await proposals.isVisible()) await proposals.click();
    else {
        await page.getByRole('button', { name: /^More views/ }).click();
        await page.getByRole('dialog').getByRole('button', { name: 'Support proposals', exact: true }).click();
    }
    await page.getByRole('button', { name: 'Revise proposal', exact: true }).click();
    const dialog = page.getByRole('dialog', { name: 'Revise support proposal', exact: true });
    await dialog.getByRole('button', { name: 'Continue', exact: true }).click();
    await dialog.getByText(/No active tax treatments are available/).waitFor();
    await dialog.getByRole('button', { name: 'Continue', exact: true }).click();
    await dialog.getByRole('button', { name: 'Continue', exact: true }).click();
    await dialog.getByText('18 Oct 2026', { exact: true }).waitFor();
    await dialog.getByText('18 Nov 2026', { exact: true }).waitFor();
    await dialog.getByText('DSS / NASC · FICTIONAL-QA-AWARD-001 · hours / Week', { exact: true }).waitFor();
    const bounds = await dialog.evaluate(el => ({ left: el.getBoundingClientRect().left, right: el.getBoundingClientRect().right, width: el.clientWidth, scroll: el.scrollWidth }));
    if (bounds.left < 0 || bounds.right > 390 || bounds.scroll > bounds.width) throw new Error('Mobile proposal dialog clips horizontally.');
    await page.screenshot({ path: 'output/playwright/referrals-proposal-expanded-review-mobile.png' });
    const result = { bounds, overflow: await page.evaluate(() => document.documentElement.scrollWidth > innerWidth), review: await dialog.ariaSnapshot() };
    await dialog.getByRole('button', { name: 'Close', exact: true }).click();
    await page.getByRole('alertdialog').getByRole('button', { name: 'Discard draft', exact: true }).click();
    await dialog.waitFor({ state: 'hidden' });
    return result;
}
