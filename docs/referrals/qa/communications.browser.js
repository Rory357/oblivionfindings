async (page) => {
    const errors = [];
    page.on('pageerror', (error) => errors.push(error.message));
    const mail = page.locator('[data-slot="card"]').filter({ hasText: 'Enquiry acknowledgement' });
    const intake = page.locator('[data-slot="card"]').filter({ hasText: 'Intake staff notice' });
    await mail.getByRole('button', { name: 'Cancel queued communication', exact: true }).click();
    let dialog = page.getByRole('dialog');
    await dialog.getByRole('textbox', { name: /Reason for this decision/ }).fill('Fictional verification: cancel this old acknowledgement after the safe channel changed.');
    await dialog.getByRole('button', { name: 'Cancel communication', exact: true }).click();
    await dialog.waitFor({ state: 'hidden' });
    await mail.getByText('Cancelled', { exact: true }).waitFor();
    await intake.getByRole('button', { name: 'Review retry', exact: true }).click();
    dialog = page.getByRole('dialog');
    await dialog.getByRole('textbox', { name: /Reason for this decision/ }).fill('Fictional verification: the accountable owner now has current approved access.');
    await dialog.getByRole('button', { name: 'Queue reviewed retry', exact: true }).click();
    await dialog.waitFor({ state: 'hidden' });
    await intake.getByText('Queued', { exact: true }).first().waitFor();
    await page.getByRole('button', { name: 'Refresh status', exact: true }).click();
    await intake.getByText('Queued', { exact: true }).first().waitFor();
    if (errors.length) throw new Error(errors.join('\n'));
    return { cancelledOldAcknowledgement: true, internalNoticeRequeued: true, errors };
}
