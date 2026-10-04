import { expect, test, type Page } from '@playwright/test';

import {
    collectConsoleErrors,
    expectNoConsoleErrors,
    loginAsStaff,
} from './helpers';

const destinations = [
    ['/security-devices', 'Security & Devices estate'],
    ['/security-devices/sites', 'Sites'],
    ['/security-devices/devices', 'All devices'],
    ['/security-devices/network-it', 'Network & IT'],
    ['/security-devices/security', 'Security'],
    ['/security-devices/healthcare', 'Healthcare'],
    ['/security-devices/tracking', 'Tracking'],
    ['/security-devices/facilities-iot', 'Facilities & IoT'],
    ['/security-devices/monitoring', 'Monitoring'],
    ['/security-devices/maintenance', 'Maintenance'],
    ['/security-devices/discovery', 'Discovery & collectors'],
    ['/security-devices/integrations', 'Integrations'],
    ['/security-devices/settings', 'Settings & audit'],
] as const;

const groupedNavigation = [
    ['Overview', ['Estate overview', 'Sites', 'All devices']],
    [
        'Workspaces',
        [
            'Network & IT',
            'Security',
            'Healthcare',
            'Tracking',
            'Facilities & IoT',
        ],
    ],
    ['Operations', ['Monitoring', 'Maintenance']],
    ['Setup', ['Discovery & collectors', 'Integrations', 'Settings & audit']],
] as const;

async function expectNoPageOverflow(page: Page) {
    const widths = await page.evaluate(() => ({
        client: document.documentElement.clientWidth,
        scroll: document.documentElement.scrollWidth,
    }));

    expect(widths.scroll).toBeLessThanOrEqual(widths.client);
}

async function expectInlineNavigation(page: Page, route: string) {
    const navigation = page.getByRole('group', {
        name: 'Security & Devices navigation',
    });
    await expect(navigation).toBeVisible();

    // The shared shell keeps the group order but intentionally omits captions
    // (APP_SHELL_STYLE_GUIDE.md, section 3).
    for (const [, items] of groupedNavigation) {
        for (const item of items) {
            await expect(
                navigation.getByRole('link', { name: item, exact: true }),
            ).toBeVisible();
        }
    }
    await expect(navigation.getByRole('link')).toHaveText(
        groupedNavigation.flatMap(([, items]) => [...items]),
    );
    await expect(navigation.locator(`a[href="${route}"]`)).toHaveAttribute(
        'aria-current',
        'page',
    );
}

test.describe('Security & Devices inline navigation', () => {
    test('keeps all destinations ordered in the primary sidebar with the current page marked', async ({
        page,
    }) => {
        test.setTimeout(150_000);
        const errors = collectConsoleErrors(page);

        await loginAsStaff(page);

        for (const [route, title] of destinations) {
            await page.goto(route);
            await expect(
                page.getByRole('heading', { name: title, level: 1 }),
            ).toBeVisible();
            await expectInlineNavigation(page, route);
            await expectNoPageOverflow(page);
        }

        expectNoConsoleErrors(errors);
    });
});
