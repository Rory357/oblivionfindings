import { expect, test, type Page } from '@playwright/test';

import {
    collectConsoleErrors,
    expectNoConsoleErrors,
    loginAsStaff,
} from './helpers';
import { seedTrackingWorkspaceReadinessFixtures } from './tracking-workspace-fixtures';

// S&D rebuild C6 retired the Tracking workspace: trackers are Fleet's and
// read-only here, on Devices › Trackers and each tracker's own page. The old
// Fleet, Assets, Geofences and History tabs live on Fleet's own pages.

async function expectNoPageOverflow(page: Page) {
    const widths = await page.evaluate(() => ({
        client: document.documentElement.clientWidth,
        scroll: document.documentElement.scrollWidth,
    }));

    expect(widths.scroll).toBeLessThanOrEqual(widths.client);
}

async function expectTrackersTab(page: Page) {
    await expect(
        page.getByRole('heading', { name: 'Devices', level: 1, exact: true }),
    ).toBeVisible();
    await expect(
        page
            .getByRole('tablist', { name: 'Kinds of device' })
            .getByRole('tab', { name: /^Trackers/ }),
    ).toHaveAttribute('aria-selected', 'true');
}

test.describe('Tracking on Security & Devices', () => {
    let fixture: ReturnType<typeof seedTrackingWorkspaceReadinessFixtures>;

    test.beforeAll(() => {
        fixture = seedTrackingWorkspaceReadinessFixtures();
    });

    test('consent, source links and the client round trip remain clear and privacy-safe', async ({
        page,
    }) => {
        test.setTimeout(180_000);
        const errors = collectConsoleErrors(page);

        await loginAsStaff(page);
        await page.goto(
            `/security-devices/devices?tab=trackers&q=${encodeURIComponent(fixture.activeDeviceName)}`,
        );
        await expectTrackersTab(page);
        await expect(
            page.getByText(fixture.activeDeviceName, { exact: true }).first(),
        ).toBeVisible();
        await expect(page.getByText(fixture.rawSentinel)).toHaveCount(0);
        await expectNoPageOverflow(page);

        // Active consent: the tracker names its client, and its page never carries
        // the provider envelope or another unit's location.
        const activeDeviceHref = `/security-devices/devices/${fixture.activeDeviceId}`;
        await page.goto(activeDeviceHref);
        await expect(
            page.getByRole('heading', {
                name: fixture.activeDeviceName,
                level: 1,
                exact: true,
            }),
        ).toBeVisible();
        const linkedClient = page.locator(
            `a[href="/clients/${fixture.activeClientId}"]`,
        );
        await expect(linkedClient).toContainText(fixture.activeClientName);
        await expect(
            linkedClient.getByText('Consent active', { exact: true }),
        ).toBeVisible();
        await expect(page.getByText(fixture.rawSentinel)).toHaveCount(0);
        const activePayload = await (
            await page.request.get(activeDeviceHref)
        ).text();
        expect(activePayload).not.toContain(fixture.rawSentinel);
        expect(activePayload).not.toContain('-36.84850000');
        await expectNoPageOverflow(page);

        // Withdrawn consent: no location, and the page says what still works.
        const withdrawnDeviceHref = `/security-devices/devices/${fixture.withdrawnDeviceId}`;
        await page.goto(withdrawnDeviceHref);
        await expect(
            page.getByRole('heading', {
                name: fixture.withdrawnDeviceName,
                level: 1,
                exact: true,
            }),
        ).toBeVisible();
        await expect(
            page.getByText('Consent not active', { exact: true }),
        ).toBeVisible();
        await expect(
            page.getByText('What still works without consent'),
        ).toBeVisible();
        await expect(
            page.getByText('Consent active', { exact: true }),
        ).toHaveCount(0);
        await expect(page.getByText(fixture.rawSentinel)).toHaveCount(0);
        const withdrawnPayload = await (
            await page.request.get(withdrawnDeviceHref)
        ).text();
        expect(withdrawnPayload).not.toContain(fixture.rawSentinel);
        await expectNoPageOverflow(page);

        // The client's location tab links to the tracker and to Devices › Trackers.
        await page.goto(
            `/operations/clients/${fixture.activeClientId}?tab=location`,
        );
        const trackersLink = page.getByRole('link', {
            name: 'Open Tracking workspace',
        });
        await expect(trackersLink).toHaveAttribute(
            'href',
            '/security-devices/devices?tab=trackers',
        );
        const deviceProfileLink = page.getByRole('link', {
            name: 'Open Device Profile',
        });
        await expect(deviceProfileLink).toHaveAttribute(
            'href',
            activeDeviceHref,
        );
        await expect(page.getByText(fixture.rawSentinel)).toHaveCount(0);
        await expectNoPageOverflow(page);

        const deviceProfileUrl = new RegExp(
            `/security-devices/devices/${fixture.activeDeviceId}(?:\\?.*)?$`,
        );
        // Wait for the actual Inertia navigation before checking its rendered state.
        await Promise.all([
            page.waitForURL(deviceProfileUrl, { timeout: 30_000 }),
            deviceProfileLink.click(),
        ]);
        await expect(
            page.getByRole('heading', {
                name: fixture.activeDeviceName,
                level: 1,
                exact: true,
            }),
        ).toBeVisible();
        await expect(page.getByText(fixture.rawSentinel)).toHaveCount(0);

        await page.goto(
            `/operations/clients/${fixture.activeClientId}?tab=location`,
        );
        await Promise.all([
            page.waitForURL(/\/security-devices\/devices\?tab=trackers$/, {
                timeout: 30_000,
            }),
            trackersLink.click(),
        ]);
        await expectTrackersTab(page);
        await expect(page.getByText(fixture.rawSentinel)).toHaveCount(0);
        await expectNoPageOverflow(page);

        expectNoConsoleErrors(errors);
    });
});
