import { expect, test, type Page } from '@playwright/test';

import {
    collectConsoleErrors,
    expectNoConsoleErrors,
    loginAsStaff,
    seedSecurityDevicesOperationsReadinessFixtures,
} from './helpers';

// The S&D rebuild replaced the estate dashboard (C4), Sites (C3) and the
// device inventory (C1). Their screens are covered by OverviewScreenTest,
// SitesScreensTest and DevicesMenuPresenterTest; this journey keeps the pages
// opening together and the Site profile round trip exact.

async function expectNoPageOverflow(page: Page) {
    const widths = await page.evaluate(() => ({
        client: document.documentElement.clientWidth,
        scroll: document.documentElement.scrollWidth,
    }));

    expect(widths.scroll).toBeLessThanOrEqual(widths.client);
}

test.describe('Security & Devices estate operations', () => {
    let fixture: ReturnType<
        typeof seedSecurityDevicesOperationsReadinessFixtures
    >;

    test.beforeAll(() => {
        fixture = seedSecurityDevicesOperationsReadinessFixtures();
    });

    test('overview, Sites, the Site profile round trip and Devices remain understandable and actionable', async ({
        page,
    }) => {
        test.setTimeout(150_000);
        const errors = collectConsoleErrors(page);
        const siteHref = `/security-devices/sites/${fixture.siteId}`;

        await loginAsStaff(page);

        await page.goto('/security-devices');
        await expect(
            page.getByRole('heading', {
                name: 'Security & devices',
                level: 1,
                exact: true,
            }),
        ).toBeVisible();
        await expectNoPageOverflow(page);

        await page.goto(
            `/security-devices/sites?q=${encodeURIComponent(fixture.siteName)}`,
        );
        await expect(
            page.getByRole('heading', { name: 'Sites', level: 1, exact: true }),
        ).toBeVisible();
        await expect(page.locator(`a[href="${siteHref}"]`).first()).toBeVisible();
        await expectNoPageOverflow(page);

        await page.goto(siteHref);
        await expect(
            page.getByRole('heading', {
                name: fixture.siteName,
                level: 1,
                exact: true,
            }),
        ).toBeVisible();
        await expect(
            page.getByText(`Devices at ${fixture.siteName}`, { exact: true }),
        ).toBeVisible();
        await expectNoPageOverflow(page);

        await page.goto(`/sites/${fixture.siteId}?tab=technology`);
        await expect(
            page.getByRole('heading', {
                name: fixture.siteName,
                level: 1,
                exact: true,
            }),
        ).toBeVisible();

        const technology = page.getByTestId('site-technology-projection');
        await expect(technology).toBeVisible();
        await expect(
            technology.getByRole('heading', {
                name: 'Technology & monitoring',
                level: 2,
            }),
        ).toBeVisible();
        const canonicalDevice = technology.locator(
            `a[href="/security-devices/devices/${fixture.deviceId}"]`,
        );
        await expect(canonicalDevice).toHaveCount(1);
        await expect(canonicalDevice).toContainText(fixture.deviceName);
        await expect(canonicalDevice).toBeVisible();

        const openFullTechnology = technology.getByRole('link', {
            name: 'Open full technology view',
        });
        await expect(openFullTechnology).toHaveAttribute('href', siteHref);
        await openFullTechnology.click();
        await expect(page).toHaveURL(
            new RegExp(`/security-devices/sites/${fixture.siteId}$`),
        );
        await expect(
            page.getByRole('heading', {
                name: fixture.siteName,
                level: 1,
                exact: true,
            }),
        ).toBeVisible();
        await expectNoPageOverflow(page);

        await page.goto(
            `/security-devices/devices?site=${fixture.siteId}&q=${encodeURIComponent(fixture.deviceName)}`,
        );
        await expect(
            page.getByRole('heading', { name: 'Devices', level: 1, exact: true }),
        ).toBeVisible();
        await expect(
            page
                .locator(`a[href="/security-devices/devices/${fixture.deviceId}"]`)
                .first(),
        ).toBeVisible();

        await page.getByRole('button', { name: 'Export' }).click();
        await expect(
            page.getByRole('menuitem', { name: 'Device list (CSV)' }),
        ).toHaveAttribute('href', '/security-devices/exports/devices.csv');
        await page.keyboard.press('Escape');
        await expectNoPageOverflow(page);

        expectNoConsoleErrors(errors);
    });
});
