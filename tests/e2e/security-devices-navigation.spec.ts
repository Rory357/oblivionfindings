import { expect, test, type Page } from '@playwright/test';

import {
    collectConsoleErrors,
    expectNoConsoleErrors,
    loginAsStaff,
} from './helpers';

const destinations = [
    ['/security-devices', 'Security & Devices estate', '/security-devices'],
    ['/security-devices/devices', 'All devices', '/security-devices/devices'],
    ['/security-devices/sites', 'Sites', '/security-devices/sites'],
    [
        '/security-devices/monitoring',
        'Monitoring',
        '/security-devices/monitoring',
    ],
    [
        '/security-devices/maintenance',
        'Maintenance',
        '/security-devices/monitoring',
    ],
    [
        '/security-devices/discovery',
        'Discovery & collectors',
        '/security-devices/discovery',
    ],
    [
        '/security-devices/integrations',
        'Integrations',
        '/security-devices/discovery',
    ],
    [
        '/security-devices/settings',
        'Settings & audit',
        '/security-devices/discovery',
    ],
] as const;

const sidebarItems = [
    'Overview',
    'Devices',
    'Sites',
    'Alerts & upkeep',
    'Setup',
] as const;

async function expectNoPageOverflow(page: Page) {
    const widths = await page.evaluate(() => ({
        client: document.documentElement.clientWidth,
        scroll: document.documentElement.scrollWidth,
    }));

    expect(widths.scroll).toBeLessThanOrEqual(widths.client);
}

async function expectInlineNavigation(page: Page, activeHref: string) {
    const navigation = page.getByRole('group', {
        name: 'Security & Devices navigation',
    });
    await expect(navigation).toBeVisible();

    for (const item of sidebarItems) {
        await expect(
            navigation.getByRole('link', { name: item, exact: true }),
        ).toBeVisible();
    }
    await expect(navigation.getByRole('link')).toHaveText([...sidebarItems]);
    await expect(navigation.locator(`a[href="${activeHref}"]`)).toHaveAttribute(
        'aria-current',
        'page',
    );
}

test.describe('Security & Devices inline navigation', () => {
    test('keeps the five destinations ordered in the primary sidebar with the current hub marked', async ({
        page,
    }) => {
        test.setTimeout(150_000);
        const errors = collectConsoleErrors(page);

        await loginAsStaff(page);

        for (const [route, title, activeHref] of destinations) {
            await page.goto(route);
            await expect(
                page.getByRole('heading', { name: title, level: 1 }),
            ).toBeVisible();
            await expectInlineNavigation(page, activeHref);
            await expectNoPageOverflow(page);
        }

        expectNoConsoleErrors(errors);
    });
});
