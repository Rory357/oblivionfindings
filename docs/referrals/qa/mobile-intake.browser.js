async (page) => {
    await page.setViewportSize({ width: 390, height: 844 });
    await page.goto('http://127.0.0.1:8766/referrals/new?purpose=funding');
    await page.getByRole('button', { name: 'Start an enquiry' }).click();
    await page.getByRole('button', { name: 'Continue', exact: true }).click();
    await page
        .getByRole('textbox', { name: 'Your name *', exact: true })
        .fill('Fictional receipt-copy preview');
    await page
        .getByRole('textbox', { name: 'Phone number *', exact: true })
        .fill('0200000000');
    await page
        .getByRole('textbox', { name: 'Safe time to contact you' })
        .fill('Weekdays, no voicemail');
    await page.getByRole('button', { name: 'Continue', exact: true }).click();
    await page.getByRole('combobox', { name: 'Funding situation' }).click();
    await page
        .getByRole('option', {
            name: 'I need help getting funding',
            exact: true,
        })
        .click();
    await page.getByRole('button', { name: 'Continue', exact: true }).click();
    if (
        await page
            .getByRole('button', { name: 'Send enquiry', exact: true })
            .isEnabled()
    )
        throw new Error(
            'Submission was enabled before notice and contact confirmation.',
        );
    await page
        .getByRole('checkbox', {
            name: 'You may contact me about this enquiry using my safe contact preferences.',
        })
        .check();
    await page
        .getByRole('checkbox', {
            name: 'I have read the information collection notice.',
        })
        .check();
    await page
        .getByRole('button', { name: 'Send enquiry', exact: true })
        .click();
    await page
        .getByRole('heading', { name: 'Your enquiry is saved', exact: true })
        .waitFor();
    const reference = await page.getByText(/^Reference: /).innerText();
    await page
        .getByRole('button', { name: 'Copy reference', exact: true })
        .click();
    await page
        .getByRole('status')
        .filter({ hasText: 'Reference copied.' })
        .waitFor();
    await page.screenshot({
        path: 'output/playwright/referrals-mobile-receipt-copy.png',
    });
    await page.getByRole('button', { name: 'Done', exact: true }).click();
    await page
        .getByRole('button', { name: 'View your receipt', exact: true })
        .click();
    if ((await page.getByText(/^Reference: /).innerText()) !== reference)
        throw new Error('Reopened receipt changed.');
    return { reference, viewport: '390 × 844', copied: true, reopened: true };
}
