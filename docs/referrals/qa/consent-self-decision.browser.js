async (page) => {
    if (new URL(page.url()).origin !== 'http://127.0.0.1:8766') throw new Error('Use the fictional local preview.');
    await page.reload();
    await page.getByRole('heading', { name: 'Fictional Website Journey', exact: true }).waitFor();
    // This mutating QA journey intentionally refuses to create duplicate decisions.
    await page.getByRole('heading', { name: 'No consent records', exact: true }).waitFor();
    await page.setViewportSize({ width: 390, height: 844 });
    await page.getByRole('button', { name: 'Record consent', exact: true }).first().click();
    const dialog = page.getByRole('dialog', { name: 'Record Consent', exact: true });
    await dialog.getByRole('combobox', { name: 'Consent Type *', exact: true }).click();
    await page.getByRole('option', { name: /^Fictional QA initial support consent/ }).click();
    await dialog.getByText('Fictional agreement to the initial daily-living and community support plan for 18–24 October 2026.', { exact: true }).waitFor();
    await dialog.getByRole('combobox', { name: 'Method *', exact: true }).click();
    await page.getByRole('option', { name: 'Verbal', exact: true }).click();
    await dialog.getByRole('checkbox', { name: /I identified Fictional Website Journey/ }).check();
    await dialog.getByRole('textbox', { name: 'Notes', exact: true }).fill('FICTIONAL QA ONLY. Simulated self-decision by fictional person 2 for the stated initial daily-living and community support purpose, 18-24 October 2026. The purpose was reviewed in plain language and the fictional response is represented as agreement. This is not real consent or authority to provide actual care.');
    await dialog.getByRole('button', { name: /^Expiry Date:/ }).click();
    const picker = page.getByRole('dialog', { name: 'Expiry Date picker', exact: true });
    await picker.getByRole('button', { name: 'Sun 25 October 2026', exact: true }).click();
    await picker.getByRole('button', { name: 'Use date', exact: true }).click();
    await picker.waitFor({ state: 'hidden' });
    const bounds = await dialog.boundingBox();
    if (!bounds || bounds.x < 0 || bounds.y < 0 || bounds.x + bounds.width > 391 || bounds.y + bounds.height > 845) throw new Error('Consent dialog outside phone viewport');
    const overflow = await page.evaluate(() => document.documentElement.scrollWidth > innerWidth);
    if (overflow) throw new Error('Horizontal overflow');
    await dialog.getByRole('textbox', { name: 'Notes', exact: true }).scrollIntoViewIfNeeded();
    const layout = await dialog.evaluate((element) => {
        const header = element.querySelector('[data-slot="dialog-header"]').getBoundingClientRect();
        const footer = element.querySelector('[data-slot="dialog-footer"]').getBoundingClientRect();
        const body = element.querySelector('fieldset').parentElement.getBoundingClientRect();
        return { headerBottom: header.bottom, bodyTop: body.top, bodyBottom: body.bottom, footerTop: footer.top };
    });
    if (layout.headerBottom > layout.bodyTop || layout.bodyBottom > layout.footerTop) throw new Error('Scrollable form overlaps its header or actions');
    await page.screenshot({ path: 'output/playwright/referrals-consent-mobile.png' });
    const response = page.waitForResponse((r) => r.request().method() === 'POST' && r.url().endsWith('/clients/2/consents'));
    await dialog.getByRole('button', { name: 'Record Consent', exact: true }).click();
    const saved = await response;
    await dialog.waitFor({ state: 'hidden' });
    // Verify the rendered saved record. An Inertia request without the current
    // asset version can return an empty 409 response instead of page JSON.
    await page.getByRole('button', { name: 'View active consents', exact: true }).getByText('100%').waitFor();
    await page.getByText(/Fictional QA initial support consent Given Given:/).waitFor();
    return { savedStatus: saved.status(), bounds, overflow, savedView: await page.getByRole('main').ariaSnapshot() };
}
