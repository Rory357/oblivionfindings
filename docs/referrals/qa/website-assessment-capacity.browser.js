async (page) => {
    const dialog = page.getByRole('dialog');
    for (const [name, option] of [['Provider suitability', 'Suitable'], ['Service capacity', 'Available']]) {
        await dialog.getByRole('combobox', { name, exact: true }).click();
        await page.getByRole('option', { name: option, exact: true }).click();
    }
    for (const [name, value] of [
        ['Suitability review and evidence', 'Fictional demonstration: reviewed daily living needs match the offered support.'],
        ['Capacity basis and remaining actions', 'Fictional demonstration only: capacity is available; named staffing must be confirmed before commencement.'],
        ['Known safety needs and information still required', 'Fictional demonstration: review the agreed support and current care plan before any service begins.'],
        ['Evidence and reason for this review', 'Fictional website journey verification. This is a provider assessment, not a funding decision or approval to start.'],
    ]) await dialog.getByRole('textbox', { name, exact: true }).fill(value);
    await dialog.getByRole('button', { name: 'Continue', exact: true }).click();
    await page.screenshot({ path: 'output/playwright/referrals-website-assessment-review-mobile.png', fullPage: true });
    return await dialog.innerText();
}
