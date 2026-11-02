import { expect, test, type Page } from '@playwright/test';

import {
    collectConsoleErrors,
    expectNoConsoleErrors,
    loginAsStaff,
    seedSecurityDevicesOperationsReadinessFixtures,
} from './helpers';

// S&D rebuild C6 retired the five specialist workspaces and the old category
// links. Their addresses now send people to the matching new page, keeping the
// device, Site and search they carried (RetiredPagesController).

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

async function expectDevicesTab(page: Page, tabName: RegExp) {
    await expect(
        page.getByRole('heading', { name: 'Devices', level: 1 }),
    ).toBeVisible();
    await expect(
        page
            .getByRole('tablist', { name: 'Kinds of device' })
            .getByRole('tab', { name: tabName }),
    ).toHaveAttribute('aria-selected', 'true');
}

test.describe('Security & Devices old workspace links', () => {
    let fixture: ReturnType<
        typeof seedSecurityDevicesOperationsReadinessFixtures
    >;

    test.beforeAll(() => {
        fixture = seedSecurityDevicesOperationsReadinessFixtures();
    });

    test('old workspace and category links land on the matching new page with their context kept', async ({
        page,
    }) => {
        test.setTimeout(150_000);
        const errors = collectConsoleErrors(page);

        await loginAsStaff(page);

        // A category link keeps its search on the matching Devices tab.
        await page.goto(
            '/security-devices/cctv?search=camera&device_id=44&status=offline',
        );
        await expect(page).toHaveURL(
            landsExactlyOn('/security-devices/devices?tab=cameras&q=camera'),
        );
        await expectDevicesTab(page, /^Cameras/);
        await expectNoPageOverflow(page);

        // A workspace tab with a closer home goes there.
        await page.goto('/security-devices/security?tab=access-control');
        await expect(page).toHaveURL(landsExactlyOn('/security-devices/access'));
        await expect(
            page.getByRole('heading', { name: 'Access', level: 1 }),
        ).toBeVisible();

        await page.goto('/security-devices/network-it?tab=traffic-capacity');
        await expect(page).toHaveURL(
            landsExactlyOn('/security-devices/devices?tab=network'),
        );
        await expectDevicesTab(page, /^Network/);
        await expectNoPageOverflow(page);

        // The global map becomes each Site's Network map.
        await page.goto(
            `/security-devices/network-it?tab=map&site_id=${fixture.siteId}`,
        );
        await expect(page).toHaveURL(
            landsExactlyOn(
                `/security-devices/sites/${fixture.siteId}?tab=network`,
            ),
        );
        await expect(
            page.getByRole('heading', {
                name: fixture.siteName,
                level: 1,
                exact: true,
            }),
        ).toBeVisible();

        // A Site filter is kept.
        await page.goto(`/security-devices/alarms?site_id=${fixture.siteId}`);
        await expect(page).toHaveURL(
            landsExactlyOn(
                `/security-devices/devices?tab=alarms_doors&site=${fixture.siteId}`,
            ),
        );
        await expectDevicesTab(page, /^Alarms & doors/);

        // A workspace link to one device opens that device's page.
        await page.goto(
            `/security-devices/tracking?device_id=${fixture.deviceId}`,
        );
        await expect(page).toHaveURL(
            landsExactlyOn(`/security-devices/devices/${fixture.deviceId}`),
        );
        await expect(
            page.getByRole('heading', {
                name: fixture.deviceName,
                level: 1,
                exact: true,
            }),
        ).toBeVisible();
        await expectNoPageOverflow(page);

        expectNoConsoleErrors(errors);
    });
});
