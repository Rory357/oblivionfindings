async (page) => {
    await page.getByRole('option', { name: 'Referrals browser preview', exact: true }).click();
    await page.getByRole('textbox', { name: 'Responsible team', exact: true }).fill('Supported living intake');
    await page.getByRole('textbox', { name: 'Reason for this change', exact: true }).fill('Fictional walkthrough: route the website funding enquiry to the approved supported living team.');
    await page.getByRole('button', { name: 'Continue', exact: true }).click();
    return await page.getByRole('dialog').innerText();
}
