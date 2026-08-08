import { expect, test, type Page } from '@playwright/test';

import { seedDeviceProfileReadinessFixtures } from './device-profile-fixtures';
import {
    collectConsoleErrors,
    expectNoConsoleErrors,
    loginAsStaff,
} from './helpers';

async function expectNoPageOverflow(page: Page) {
    const layout = await page.evaluate(() => ({
        client: document.documentElement.clientWidth,
        scroll: document.documentElement.scrollWidth,
    }));
    expect(layout.scroll).toBeLessThanOrEqual(layout.client);
}

test.describe('Capability-driven device profile', () => {
    let fixture: ReturnType<typeof seedDeviceProfileReadinessFixtures>;

    test.beforeAll(() => {
        fixture = seedDeviceProfileReadinessFixtures();
        expect(fixture.controlRoomAllowed).toBe(true);
        expect(fixture.controlRoomAlertCount).toBe(1);
    });

    test('keeps monitoring, technical, operational and record context clear without exposing raw evidence', async ({
        page,
    }) => {
        test.setTimeout(180_000);
        const errors = collectConsoleErrors(page);
        await loginAsStaff(page);
        await page.goto(`/security-devices/devices/${fixture.deviceId}`);

        await expect(
            page.getByRole('heading', {
                name: fixture.deviceName,
                level: 1,
            }),
        ).toBeVisible();
        await expect(page.getByText(fixture.siteName).first()).toBeVisible();
        await expect(
            page.getByText('Investigate this device', { exact: true }),
        ).toBeVisible();
        await expect(page.getByText(fixture.rawSentinel)).toHaveCount(0);

        await page.getByTestId('device-profile-section-monitors').click();
        await expect(page.getByText(fixture.monitorName).first()).toBeVisible();

        await page.getByTestId('device-profile-group-technical').click();
        await page
            .getByTestId('device-profile-section-interfaces-sensors')
            .click();
        await expect(
            page.getByText(fixture.interfaceName).first(),
        ).toBeVisible();

        await page.getByTestId('device-profile-group-operations').click();
        await page.getByTestId('device-profile-section-tickets').click();
        await expect(page.getByText(fixture.ticketTitle)).toBeVisible();

        await page.getByTestId('device-profile-section-events').click();
        await expect(
            page.getByText(fixture.controlRoomReference),
        ).toBeVisible();
        await expect(
            page.locator('a[href^="/control-room/alerts/"]', {
                hasText: fixture.controlRoomReference,
            }),
        ).toBeVisible();
        await expect(
            page.locator(
                `a[href="/security-devices/monitoring?device_id=${fixture.deviceId}"]`,
            ),
        ).toBeVisible();

        await page.getByTestId('device-profile-group-records').click();
        await page.getByTestId('device-profile-section-audit').click();
        await expect(
            page.getByText(fixture.auditAction, { exact: true }),
        ).toBeVisible();
        await expect(page.getByText(fixture.rawSentinel)).toHaveCount(0);
        await expect(
            page.getByRole('button', { name: /restart|reboot|push|upgrade/i }),
        ).toHaveCount(0);
        await expectNoPageOverflow(page);
        expectNoConsoleErrors(errors);
    });
});
