async (page) => {
    // Destinations observed in the provider site's own navigation and service links.
    const paths = [
        '/',
        '/services',
        '/services/supported_living',
        '/funding',
        '/about',
        '/contact',
        '/privacy-policy',
        '/terms',
        '/accessibility',
    ];
    const results = [];
    const errors = [];
    page.on('pageerror', (error) => errors.push(error.message));
    await page.setViewportSize({ width: 390, height: 844 });
    for (const path of paths) {
        const response = await page.goto(`http://127.0.0.1:8766${path}`, {
            waitUntil: 'domcontentloaded',
        });
        await page.getByRole('heading', { level: 1 }).waitFor();
        const geometry = await page.evaluate(() => ({
            width: document.documentElement.clientWidth,
            content: document.documentElement.scrollWidth,
        }));
        results.push({
            path,
            status: response.status(),
            heading: await page.getByRole('heading', { level: 1 }).innerText(),
            overflow: geometry.content > geometry.width + 1,
        });
        if (response.status() !== 200 || geometry.content > geometry.width + 1)
            throw new Error(
                `Public page failed: ${JSON.stringify(results.at(-1))}`,
            );
    }
    if (errors.length)
        throw new Error('A public page had a JavaScript runtime error.');
    return { results, errors };
}
