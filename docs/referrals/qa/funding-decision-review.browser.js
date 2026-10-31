async (page) => {
    if (new URL(page.url()).origin !== 'http://127.0.0.1:8766') throw new Error('Use the isolated fictional preview.');
    await page.reload();
    await page.getByRole('button', { name: 'Open user menu for Preview Author' }).waitFor();
    const funding = page.getByRole('tab', { name: /^Funding assistance/ });
    if (await funding.isVisible()) await funding.click();
    else {
        await page.getByRole('button', { name: /^More views/ }).click();
        await page.getByRole('dialog').getByRole('button', { name: /^Funding assistance/ }).click();
    }
    await page.getByRole('button', { name: /^DSS \/ NASC Supported living/ }).click();
    await page.getByRole('button', { name: 'Funding case actions', exact: true }).click();
    await page.getByRole('menuitem', { name: 'Record external decision', exact: true }).click();
    await page.getByRole('textbox', { name: 'Decision-maker / agency', exact: true }).fill('Fictional agency - simulated award only');
    await page.getByRole('textbox', { name: 'External decision reference', exact: true }).fill('FICTIONAL-QA-AWARD-001');
    const chooseDate = async (label, day, nextMonth = false) => {
        await page.getByRole('button', { name: `${label}: Choose date`, exact: true }).click();
        const picker = page.getByRole('dialog', { name: `${label} picker`, exact: true });
        if (nextMonth) await picker.getByRole('button', { name: 'Next month', exact: true }).click();
        await picker.getByRole('button', { name: day, exact: true }).click();
        await picker.getByRole('button', { name: 'Use date', exact: true }).click();
        await picker.waitFor({ state: 'hidden' });
    };
    await chooseDate('Decision date', 'Sun 18 October 2026');
    await chooseDate('Valid from', 'Sun 18 October 2026');
    await chooseDate('Valid until', 'Wed 18 November 2026', true);
    await chooseDate('Review date', 'Wed 11 November 2026', true);
    await page.getByRole('textbox', { name: 'Conditions and restrictions, including if none are stated', exact: true }).fill('Fictional test only. Eight hours of supported living each week; no real service or financial authorization.');
    await page.getByRole('textbox', { name: 'Source of any review deadline', exact: true }).fill('Fictional QA scenario: review one week before the simulated award ends.');
    await page.getByRole('textbox', { name: 'Original award quantity', exact: true }).fill('8');
    await page.getByRole('textbox', { name: 'Quantity allocated to this provider', exact: true }).fill('8');
    await page.getByRole('combobox', { name: 'Choose unit', exact: true }).click();
    console.log(await page.getByRole('listbox').ariaSnapshot());
}
