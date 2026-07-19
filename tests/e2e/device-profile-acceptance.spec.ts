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

        const mobile = (page.viewportSize()?.width ?? 1440) < 768;
        if (mobile) {
            const selector = page.getByTestId('device-profile-mobile-select');
            await expect(selector).toBeVisible();
            await selector.click();
            await page.getByRole('option', { name: /Monitors/ }).click();
        } else {
            await page.getByTestId('device-profile-section-monitors').click();
        }
        await expect(page.getByText(fixture.monitorName).first()).toBeVisible();

        if (mobile) {
            const selector = page.getByTestId('device-profile-mobile-select');
            await selector.click();
            await page
                .getByRole('option', { name: 'Interfaces & sensors' })
                .click();
        } else {
            await page.getByTestId('device-profile-group-technical').click();
            await page
                .getByTestId('device-profile-section-interfaces-sensors')
                .click();
        }
        await expect(
            page.getByText(fixture.interfaceName).first(),
        ).toBeVisible();

        if (mobile) {
            const selector = page.getByTestId('device-profile-mobile-select');
            await selector.click();
            await page.getByRole('option', { name: /Tickets/ }).click();
        } else {
            await page.getByTestId('device-profile-group-operations').click();
            await page.getByTestId('device-profile-section-tickets').click();
        }
        await expect(page.getByText(fixture.ticketTitle)).toBeVisible();

        if (mobile) {
            const selector = page.getByTestId('device-profile-mobile-select');
            await selector.click();
            await page.getByRole('option', { name: /Events/ }).click();
        } else {
            await page.getByTestId('device-profile-section-events').click();
        }
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

        if (mobile) {
            const selector = page.getByTestId('device-profile-mobile-select');
            await selector.click();
            await page.getByRole('option', { name: /Audit/ }).click();
        } else {
            await page.getByTestId('device-profile-group-records').click();
            await page.getByTestId('device-profile-section-audit').click();
        }
        await expect(
            page.getByText(fixture.auditAction, { exact: true }),
        ).toBeVisible();
        if (mobile) {
            for (const section of [
                'Health',
                'Topology',
                'Configuration',
                'Assignments',
                'Maintenance',
                'Documents',
            ]) {
                const selector = page.getByTestId(
                    'device-profile-mobile-select',
                );
                await selector.click();
                await page
                    .getByRole('option', { name: new RegExp(section) })
                    .click();
                await expectNoPageOverflow(page);
            }
        }
        await expect(page.getByText(fixture.rawSentinel)).toHaveCount(0);
        await expect(
            page.getByRole('button', { name: /restart|reboot|push|upgrade/i }),
        ).toHaveCount(0);
        await expectNoPageOverflow(page);
        expectNoConsoleErrors(errors);
    });
});
