async (page) => {
    if (new URL(page.url()).origin !== 'http://127.0.0.1:8766') throw new Error('Use the isolated fictional preview.');
    const dialog = page.getByRole('dialog', { name: 'Prepare support proposal', exact: true });
    await dialog.getByRole('textbox', { name: 'Proposal title', exact: true }).fill('Fictional supported living proposal');
    await dialog.getByRole('button', { name: 'Proposed end: Choose date', exact: true }).click();
    const picker = page.getByRole('dialog', { name: 'Proposed end picker', exact: true });
    await picker.getByRole('button', { name: 'Next month', exact: true }).click();
    await picker.getByRole('button', { name: 'Wed 18 November 2026', exact: true }).click();
    await picker.getByRole('button', { name: 'Use date', exact: true }).click();
    await picker.waitFor({ state: 'hidden' });
    await dialog.getByRole('textbox', { name: 'Support rationale and goals', exact: true }).fill('Fictional QA: eight hours each week supporting daily living, confidence and community participation.');
    await dialog.getByRole('textbox', { name: 'Person’s participation and choices', exact: true }).fill('Fictional person selected weekday visits and community activities in this simulation; confirm their acceptance separately.');
    await dialog.getByRole('textbox', { name: 'Staffing and service capacity plan', exact: true }).fill('Fictional capacity plan: named team to arrange appropriately trained staff before actual commencement. No care is authorized by this fixture.');
    await dialog.getByRole('button', { name: 'Continue', exact: true }).click();
    return await dialog.ariaSnapshot();
}
