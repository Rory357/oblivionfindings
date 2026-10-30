async (page) => {
    const errors = [];
    const onError = error => errors.push(error.message);
    page.on('pageerror', onError);
    try {
        await page.setViewportSize({ width: 1440, height: 1000 });
        await page.goto('http://127.0.0.1:8766/operations/clients/2?tab=onboarding');
        await page.getByRole('button', { name: 'Edit profile', exact: true }).click();
        const dialog = page.getByRole('dialog');
        await dialog.getByText(/Support becomes active through the separate service-start review/).waitFor();
        if (await dialog.getByRole('button', { name: 'Active', exact: true }).count()) throw new Error('Onboarding profile offers direct activation');
        await dialog.getByRole('button', { name: 'Onboarding', exact: true }).waitFor();
        await page.screenshot({ path: 'output/playwright/referrals-profile-start-status-1440.png' });
        await dialog.getByRole('button', { name: 'Cancel', exact: true }).click();
        if (errors.length) throw new Error(errors.join('\n'));
        return { onboardingProfile: true, directActivationHidden: true, runtimeErrors: errors };
    } finally {
        page.off('pageerror', onError);
    }
}
