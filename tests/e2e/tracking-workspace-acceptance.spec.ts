import { expect, test, type Page } from '@playwright/test';

import {
    collectConsoleErrors,
    expectNoConsoleErrors,
    loginAsStaff,
} from './helpers';
import { seedTrackingWorkspaceReadinessFixtures } from './tracking-workspace-fixtures';

async function expectNoPageOverflow(page: Page) {
    const widths = await page.evaluate(() => ({
        client: document.documentElement.clientWidth,
        scroll: document.documentElement.scrollWidth,
    }));

    expect(widths.scroll).toBeLessThanOrEqual(widths.client);
}

test.describe('Tracking specialist workspace', () => {
    let fixture: ReturnType<typeof seedTrackingWorkspaceReadinessFixtures>;

    test.beforeAll(() => {
        fixture = seedTrackingWorkspaceReadinessFixtures();
    });

    test('purpose, consent, source links, geofences and history remain clear and privacy-safe', async ({
        page,
    }) => {
        test.setTimeout(180_000);
        const errors = collectConsoleErrors(page);

        await loginAsStaff(page);
        await page.goto('/security-devices/tracking');

        await expect(
            page.getByRole('heading', { name: 'Tracking', level: 1 }),
        ).toBeVisible();
        await expect(
            page.getByRole('heading', {
                name: 'Location access follows purpose',
                level: 2,
            }),
        ).toBeVisible();
        await expect(
            page.getByRole('heading', {
                name: 'Tracking at a glance',
                level: 2,
            }),
        ).toBeVisible();
        await expect(page.getByText(fixture.rawSentinel)).toHaveCount(0);
        await expectNoPageOverflow(page);

        const tabs = page.getByRole('navigation', {
            name: 'Tracking workspace tabs',
        });
        await tabs
            .getByRole('link', { name: 'Personal safety', exact: true })
            .click();

        const activeCard = page.getByRole('article', {
            name: fixture.activeDeviceName,
        });
        await expect(activeCard).toContainText(fixture.activeClientName);
        await expect(activeCard).toContainText('Consent active');
        await expect(activeCard).toContainText('Last location');
        const clientLocationLink = activeCard.getByRole('link', {
            name: 'Open client location',
        });
        await expect(clientLocationLink).toHaveAttribute(
            'href',
            `/operations/clients/${fixture.activeClientId}?tab=location`,
        );
        const deviceDetailHref = await activeCard
            .getByRole('link', { name: fixture.activeDeviceName })
            .getAttribute('href');
        expect(deviceDetailHref).not.toBeNull();
        const deviceDetailResponse = await page.request.get(
            deviceDetailHref as string,
        );
        expect(deviceDetailResponse.ok()).toBe(true);
        const deviceDetailPayload = await deviceDetailResponse.text();
        expect(deviceDetailPayload).not.toContain(fixture.rawSentinel);
        expect(deviceDetailPayload).not.toContain('-36.84850000');

        const withdrawnCard = page.getByRole('article', {
            name: fixture.withdrawnDeviceName,
        });
        await expect(withdrawnCard).toContainText(fixture.withdrawnClientName);
        await expect(withdrawnCard).toContainText('Consent withdrawn');
        await expect(withdrawnCard).not.toContainText('Last location');
        await expect(
            withdrawnCard.getByRole('link', { name: 'Open client location' }),
        ).toHaveCount(0);
        await expect(page.getByText(fixture.rawSentinel)).toHaveCount(0);
        await expectNoPageOverflow(page);

        await clientLocationLink.click();
        await expect(page).toHaveURL(
            new RegExp(
                `/operations/clients/${fixture.activeClientId}\\?tab=location$`,
            ),
        );
        const trackingWorkspaceLink = page.getByRole('link', {
            name: 'Open Tracking workspace',
        });
        await expect(trackingWorkspaceLink).toHaveAttribute(
            'href',
            '/security-devices/tracking?tab=personal-safety',
        );
        const deviceProfileLink = page.getByRole('link', {
            name: 'Open Device Profile',
        });
        await expect(deviceProfileLink).toHaveAttribute(
            'href',
            `/security-devices/devices/${fixture.activeDeviceId}`,
        );
        await expect(page.getByText(fixture.rawSentinel)).toHaveCount(0);
        await expectNoPageOverflow(page);

        await deviceProfileLink.click();
        await expect(page).toHaveURL(
            new RegExp(
                `/security-devices/devices/${fixture.activeDeviceId}(?:\\?.*)?$`,
            ),
        );
        await expect(
            page.getByRole('heading', {
                name: fixture.activeDeviceName,
                level: 1,
            }),
        ).toBeVisible();
        const clientReturnLink = page
            .locator('[data-testid="device-profile-header"]')
            .getByRole('link', {
                name: fixture.activeClientProfileName,
                exact: true,
            });
        await expect(clientReturnLink).toHaveAttribute(
            'href',
            `/operations/clients/${fixture.activeClientId}?tab=location`,
        );
        await expect(page.getByText(fixture.rawSentinel)).toHaveCount(0);
        await expectNoPageOverflow(page);

        await clientReturnLink.click();
        await expect(page).toHaveURL(
            new RegExp(
                `/operations/clients/${fixture.activeClientId}\\?tab=location$`,
            ),
        );
        await trackingWorkspaceLink.click();
        await expect(page).toHaveURL(
            /\/security-devices\/tracking\?tab=personal-safety$/,
        );
        await expect(
            page.getByRole('article', { name: fixture.activeDeviceName }),
        ).toBeVisible();
        await expect(page.getByText(fixture.rawSentinel)).toHaveCount(0);
        await expectNoPageOverflow(page);

        await tabs.getByRole('link', { name: 'Fleet', exact: true }).click();
        const fleetCard = page.getByRole('article', {
            name: fixture.vehicleDeviceName,
        });
        await expect(fleetCard).toContainText(fixture.vehicleName);
        await expect(fleetCard).toContainText('Operational access');
        await expect(
            fleetCard.getByRole('link', { name: 'Open vehicle in Fleet' }),
        ).toHaveAttribute('href', /\/fleet-assets\/vehicles\/\d+/);
        await expect(page.locator('.leaflet-container')).toBeVisible();
        await expectNoPageOverflow(page);

        await tabs.getByRole('link', { name: 'Assets', exact: true }).click();
        const assetCard = page.getByRole('article', {
            name: fixture.assetDeviceName,
        });
        await expect(assetCard).toContainText(fixture.assetName);
        await expect(
            assetCard.getByRole('link', { name: 'Open asset record' }),
        ).toHaveAttribute('href', /\/fleet-assets\/assets\/\d+/);
        await expectNoPageOverflow(page);

        await tabs
            .getByRole('link', { name: 'Geofences', exact: true })
            .click();
        await expect(page.getByText(fixture.geofenceName)).toBeVisible();
        await expect(
            page.getByRole('link', { name: 'Open Fleet geofences' }),
        ).toHaveAttribute('href', '/fleet-assets/geofences');
        await expect(page.locator('.leaflet-container')).toBeVisible();
        await expectNoPageOverflow(page);

        await tabs.getByRole('link', { name: 'History', exact: true }).click();
        await expect(
            page.getByRole('heading', {
                name: 'Retained location history',
                level: 2,
            }),
        ).toBeVisible();
        await expect(page.getByText('365-day retention window')).toBeVisible();
        await expect(
            page.getByText('Playwright Location Report'),
        ).toBeVisible();
        await expect(page.getByText(fixture.rawSentinel)).toHaveCount(0);
        await expectNoPageOverflow(page);

        expectNoConsoleErrors(errors);
    });
});
