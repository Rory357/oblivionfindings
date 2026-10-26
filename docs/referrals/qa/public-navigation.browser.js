async (page) => {
    // Read-only clicks through links observed in the public mobile navigation.
    await page.setViewportSize({ width: 390, height: 844 });
    await page.goto('http://127.0.0.1:8766/accessibility');
    const results = [];
    const entries = [
        ['Our support', '/services'],
        ['Funding help', '/funding'],
        ['About us', '/about'],
        ['Contact', '/contact'],
        ['Explore support', '/referrals/new?purpose=support'],
    ];
    for (const [name, destination] of entries) {
        await page.getByRole('button', { name: 'Open navigation', exact: true }).click();
        const menu = page.getByRole('navigation', { name: 'Mobile navigation', exact: true });
        const link = menu.getByRole('link', { name, exact: true });
        const bounds = await link.boundingBox();
        if (!bounds || bounds.height < 44) throw new Error(`${name} is below the mobile target size.`);
        await link.click();
        await page.waitForURL(`http://127.0.0.1:8766${destination}`);
        await page.getByRole('heading', { level: 1 }).waitFor();
        await menu.waitFor({ state: 'hidden' });
        const overflow = await page.evaluate(() => document.documentElement.scrollWidth > document.documentElement.clientWidth + 1);
        if (overflow) throw new Error(`Horizontal overflow after ${name}.`);
        results.push({ name, destination, targetHeight: bounds.height });
    }
    // No form fields were entered: leave no unsent referral behind.
    await page.getByRole('link', { name: 'Oblivion Care — preview home', exact: true }).click();
    await page.waitForURL('http://127.0.0.1:8766/');
    await page.getByRole('button', { name: 'Open navigation', exact: true }).click();
    await page.getByRole('navigation', { name: 'Mobile navigation', exact: true }).getByRole('link', { name: 'Our support', exact: true }).focus();
    await page.keyboard.press('Escape');
    await page.getByRole('navigation', { name: 'Mobile navigation', exact: true }).waitFor({ state: 'hidden' });
    const returnedFocus = await page.getByRole('button', { name: 'Open navigation', exact: true }).evaluate((element) => element === document.activeElement);
    if (!returnedFocus) throw new Error('Closing the mobile menu did not return keyboard focus.');
    return { results, returnedFocus, finalUrl: page.url() };
}
