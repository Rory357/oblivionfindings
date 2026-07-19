import { expect, test, type Page } from '@playwright/test';

import {
    collectConsoleErrors,
    expectNoConsoleErrors,
    loginAsStaff,
    seedSecurityDevicesOperationsReadinessFixtures,
} from './helpers';

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

    test('estate, site technology and inventory remain understandable and actionable', async ({
        page,
    }) => {
        test.setTimeout(150_000);
        const errors = collectConsoleErrors(page);

        await loginAsStaff(page);

        await page.goto('/security-devices');
        await expect(
            page.getByRole('heading', {
                name: 'Security & Devices estate',
                level: 1,
            }),
        ).toBeVisible();
        await expect(
            page.getByRole('heading', {
                name: 'What needs attention',
                level: 2,
            }),
        ).toBeVisible();
        await expect(
            page
                .getByRole('progressbar', { name: 'Monitoring coverage' })
                .first(),
        ).toBeVisible();
        await expect(
            page.getByRole('link', { name: fixture.siteName }).first(),
        ).toBeVisible();
        await expectNoPageOverflow(page);

        await page.goto('/security-devices/sites');
        await expect(
            page.getByRole('heading', { name: 'Sites', level: 1 }),
        ).toBeVisible();
        await expect(
            page.getByRole('link', { name: fixture.siteName }).first(),
        ).toBeVisible();
        await expectNoPageOverflow(page);

        await page.goto(`/security-devices/sites/${fixture.siteId}`);
        await expect(
            page.getByRole('heading', {
                name: fixture.siteName,
                level: 1,
                exact: true,
            }),
        ).toBeVisible();
        await expect(
            page.getByText('WAN and topology', { exact: true }),
        ).toBeVisible();
        await expect(
            page.getByRole('heading', {
                name: 'Monitoring coverage',
                level: 2,
            }),
        ).toBeVisible();
        await expect(page.getByText(fixture.deviceName).first()).toBeVisible();
        await expectNoPageOverflow(page);

        await page.goto('/security-devices/devices');
        await expect(
            page.getByRole('heading', { name: 'All devices', level: 1 }),
        ).toBeVisible();
        await expect(
            page.getByRole('heading', { name: 'Saved views', level: 2 }),
        ).toBeVisible();
        await expect(
            page.getByRole('link', { name: fixture.deviceName }).first(),
        ).toBeVisible();

        await page
            .getByText(`Select ${fixture.deviceName}`, { exact: true })
            .click();
        const selectedExport = page.getByRole('link', {
            name: 'Export selected',
        });
        await expect(selectedExport).toHaveAttribute(
            'href',
            new RegExp(`ids=${fixture.deviceId}(?:$|&)`),
        );
        await expectNoPageOverflow(page);

        expectNoConsoleErrors(errors);
    });
});
