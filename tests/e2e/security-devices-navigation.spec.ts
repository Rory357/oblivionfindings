import { expect, test, type Page } from '@playwright/test';

import {
    collectConsoleErrors,
    expectNoConsoleErrors,
    loginAsStaff,
} from './helpers';

const destinations = [
    ['/security-devices', 'Security & Devices'],
    ['/security-devices/sites', 'Sites'],
    ['/security-devices/devices', 'Devices'],
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

async function expectGroupedNavigation(page: Page) {
    const navigation = page.locator(
        'nav[aria-label="Security & Devices"]:visible',
    );
    await expect(navigation).toBeVisible();

    for (const [group, items] of groupedNavigation) {
        await expect(
            navigation.getByRole('heading', { name: group, exact: true }),
        ).toBeVisible();

        for (const item of items) {
            await expect(
                navigation.getByRole('link', { name: item, exact: true }),
            ).toBeVisible();
        }
    }
}

test.describe('Security & Devices grouped navigation', () => {
    test('desktop keeps the grouped secondary navigation visible across every destination', async ({
        page,
    }, testInfo) => {
        test.skip(!testInfo.project.name.includes('desktop'), 'Desktop only.');
        test.setTimeout(150_000);
        const errors = collectConsoleErrors(page);

        await loginAsStaff(page);

        for (const [route, title] of destinations) {
            await page.goto(route);
            await expect(
                page.getByRole('heading', { name: title, level: 1 }),
            ).toBeVisible();
            await expectGroupedNavigation(page);
            await expectNoPageOverflow(page);
        }

        expectNoConsoleErrors(errors);
    });

    test('mobile uses one understandable expandable menu across every destination', async ({
        page,
    }, testInfo) => {
        test.skip(!testInfo.project.name.includes('mobile'), 'Mobile only.');
        test.setTimeout(150_000);
        const errors = collectConsoleErrors(page);

        await loginAsStaff(page);

        for (const [route, title] of destinations) {
            await page.goto(route);
            await expect(
                page.getByRole('heading', { name: title, level: 1 }),
            ).toBeVisible();
            await page
                .getByText('Security & Devices navigation', { exact: true })
                .click();
            await expectGroupedNavigation(page);
            await expectNoPageOverflow(page);
        }

        expectNoConsoleErrors(errors);
    });
});
