import { expect, test, type Page } from '@playwright/test';

import {
    collectConsoleErrors,
    expectNoConsoleErrors,
    loginAsStaff,
} from './helpers';

// [visited route, page heading, sidebar entry marked current, where the visit lands]
const destinations = [
    ['/security-devices', 'Security & devices', '/security-devices', '/security-devices'],
    ['/security-devices/devices', 'Devices', '/security-devices/devices', '/security-devices/devices'],
    ['/security-devices/sites', 'Sites', '/security-devices/sites', '/security-devices/sites'],
    ['/security-devices/access', 'Access', '/security-devices/access', '/security-devices/access'],
    ['/security-devices/alerts', 'Alerts', '/security-devices/alerts', '/security-devices/alerts'],
    ['/security-devices/setup', 'Setup', '/security-devices/setup', '/security-devices/setup'],
    // S&D rebuild C6: the old pages send people to their new home.
    ['/security-devices/monitoring', 'Alerts', '/security-devices/alerts', '/security-devices/alerts'],
    ['/security-devices/maintenance', 'Devices', '/security-devices/devices', '/security-devices/devices?tab=upkeep'],
    ['/security-devices/discovery', 'Setup', '/security-devices/setup', '/security-devices/setup/hubs?tab=networks'],
    ['/security-devices/integrations', 'Setup', '/security-devices/setup', '/security-devices/setup/services?tab=connected'],
    ['/security-devices/settings', 'Setup', '/security-devices/setup', '/security-devices/setup'],
] as const;

const sidebarItems = [
    'Overview',
    'Devices',
    'Sites',
    'Access',
    'Alerts',
    'Setup',
] as const;

function landsExactlyOn(pathAndQuery: string): RegExp {
    return new RegExp(
        `^https?://[^/]+${pathAndQuery.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')}$`,
    );
}

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
    test('keeps the six destinations ordered in the primary sidebar with the current one marked, and old links land on their new home', async ({
        page,
    }) => {
        test.setTimeout(150_000);
        const errors = collectConsoleErrors(page);

        await loginAsStaff(page);

        for (const [route, title, activeHref, landing] of destinations) {
            await page.goto(route);
            await expect(page).toHaveURL(landsExactlyOn(landing));
            await expect(
                page.getByRole('heading', { name: title, level: 1 }),
            ).toBeVisible();
            await expectInlineNavigation(page, activeHref);
            await expectNoPageOverflow(page);
        }

        expectNoConsoleErrors(errors);
    });
});
