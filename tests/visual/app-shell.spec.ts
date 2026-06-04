import { expect, test } from '@playwright/test';

const email = process.env.VISUAL_EMAIL ?? 'admin@demo.test';
const password = process.env.VISUAL_PASSWORD ?? 'password';

const authenticatedPages = [
    { name: 'dashboard', path: '/dashboard' },
    { name: 'settings-appearance', path: '/settings/appearance' },
    { name: 'settings-branding', path: '/settings/branding' },
    { name: 'settings-notifications', path: '/settings/notifications' },
    { name: 'control-room-broadcasts', path: '/control-room/broadcast' },
    { name: 'finance-dashboard', path: '/finance/dashboard' },
    { name: 'finance-accounts', path: '/finance/accounts' },
    { name: 'governance-policies', path: '/governance/policies' },
    { name: 'health-clinical-events', path: '/health-clinical/events' },
    { name: 'incident-create', path: '/incidents/create' },
    { name: 'checklists', path: '/checklists' },
    { name: 'notifications', path: '/notifications' },
] as const;

test.beforeEach(async ({ page }) => {
    await page.goto('/login');
    await page.locator('#email').fill(email);
    await page.locator('#password').fill(password);
    await page.getByTestId('login-button').click();
    await expect(page).not.toHaveURL(/\/login$/);
});

for (const target of authenticatedPages) {
    test(`${target.name} visual baseline`, async ({ page }) => {
        await page.goto(target.path);
        await page.waitForLoadState('networkidle');
        const main = page.locator('#main-content');
        await expect(main).toBeVisible();
        await expect(main).toContainText(/[A-Za-z0-9]/);
        await expect(page).toHaveScreenshot(`${target.name}.png`, {
            fullPage: true,
        });
    });
}
