async (page) => {
    const dialog = page.getByRole('dialog');
    const choose = async (name, option) => {
        await dialog.getByRole('combobox', { name, exact: true }).click();
        await page.getByRole('option', { name: option, exact: true }).click();
    };
    await choose('Identity review', 'Verified');
    await choose('The person’s intention', 'Proceed');
    for (const [name, value] of [
        ['How identity was checked / what remains unknown', 'Fictional demonstration identity reviewed; no real identifying documents used.'],
        ['Person’s wishes and provider choice', 'Fictional demonstration: the person wishes to proceed with supported living preparation.'],
        ['Goals and what matters to the person', 'Fictional demonstration: build confidence with daily living and community activities.'],
        ['Participation, communication and chosen supporters', 'Fictional demonstration: use plain language and allow time for independent choices.'],
    ]) await dialog.getByRole('textbox', { name, exact: true }).fill(value);
    await dialog.getByRole('button', { name: 'Continue', exact: true }).click();
    return await dialog.innerText();
}
