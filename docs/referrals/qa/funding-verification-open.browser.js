async (page) => {
    if (new URL(page.url()).origin !== 'http://127.0.0.1:8766') throw new Error('Use the isolated fictional preview.');
    await page.reload();
    await page.getByRole('heading', { name: 'Fictional Website Journey', exact: true }).waitFor();
    const funding = page.getByRole('tab', { name: /^Funding assistance/ });
    if (await funding.isVisible()) await funding.click();
    else {
        await page.getByRole('button', { name: /^More views/ }).click();
        await page.getByRole('dialog').getByRole('button', { name: /^Funding assistance/ }).click();
    }
    await page.getByRole('button', { name: /^DSS \/ NASC Supported living/ }).click();
    await page.getByRole('button', { name: 'Funding case actions', exact: true }).click();
    await page.getByRole('menuitem', { name: 'Independently verify decision', exact: true }).click();
    const dialog = page.getByRole('dialog', { name: 'Independently verify funding', exact: true });
    await dialog.getByRole('heading', { name: 'Details and evidence', exact: true }).waitFor();
    return { continueDisabled: await dialog.getByRole('button', { name: 'Continue', exact: true }).isDisabled(), form: await dialog.ariaSnapshot() };
}
