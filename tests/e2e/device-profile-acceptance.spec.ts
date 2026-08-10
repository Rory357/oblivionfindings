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

    test('keeps HR equipment history non-actionable while the canonical current Device round trip stays exact', async ({
        page,
    }) => {
        test.setTimeout(180_000);
        const errors = collectConsoleErrors(page);
        await loginAsStaff(page);
        await page.goto(`/hr/people/${fixture.employeeProfileId}?tab=assets`);

        await expect(
            page.getByRole('heading', {
                name: fixture.employeeName,
                level: 1,
            }),
        ).toBeVisible();
        await expect(
            page.getByRole('tab', { name: /equipment & access/i }),
        ).toHaveAttribute('data-state', 'active');

        const currentDevice = page.locator(
            `a[href="/security-devices/devices/${fixture.hrDeviceId}"]`,
            { hasText: fixture.hrDeviceName },
        );
        await expect(currentDevice).toBeVisible();
        await expect(
            page.getByText(fixture.historicalEquipmentName, { exact: true }),
        ).toBeVisible();
        await expect(
            page.getByRole('link', {
                name: fixture.historicalEquipmentName,
                exact: true,
            }),
        ).toHaveCount(0);
        await expect(
            page.getByText('History only', { exact: true }),
        ).toBeVisible();
        await expect(
            page.getByText('Historical HR record — no action available', {
                exact: true,
            }),
        ).toBeVisible();

        await currentDevice.click();
        await expect(
            page.getByRole('heading', {
                name: fixture.hrDeviceName,
                level: 1,
            }),
        ).toBeVisible();
        const employeeReturn = page.locator(
            `a[href="/hr/people/${fixture.employeeProfileId}?tab=assets"]`,
            { hasText: fixture.employeeName },
        );
        await expect(employeeReturn).toBeVisible();

        await employeeReturn.click();
        await expect(page).toHaveURL(
            new RegExp(`/hr/people/${fixture.employeeProfileId}\\?tab=assets$`),
        );
        await expect(currentDevice).toBeVisible();
        await expectNoPageOverflow(page);
        expectNoConsoleErrors(errors);
    });

    test('keeps Fleet vehicle and Asset technology handoffs on the same canonical Device in both directions', async ({
        page,
    }) => {
        test.setTimeout(180_000);
        const errors = collectConsoleErrors(page);
        await loginAsStaff(page);

        await page.goto(
            `/fleet-assets/vehicles/${fixture.vehicleId}?tab=technology`,
        );
        await expect(
            page.getByRole('heading', {
                name: fixture.vehicleName,
                level: 1,
            }),
        ).toBeVisible();
        await expect(
            page.getByRole('tab', { name: 'Vehicle technology' }),
        ).toHaveAttribute('data-state', 'active');
        const vehicleDevice = page.locator(
            `a[href="/security-devices/devices/${fixture.vehicleDeviceId}"]`,
            { hasText: fixture.vehicleDeviceName },
        );
        await expect(vehicleDevice).toBeVisible();
        await vehicleDevice.click();
        await expect(
            page.getByRole('heading', {
                name: fixture.vehicleDeviceName,
                level: 1,
            }),
        ).toBeVisible();
        const vehicleReturn = page.locator(
            `a[href="/fleet-assets/vehicles/${fixture.vehicleId}?tab=technology"]`,
            { hasText: fixture.vehicleName },
        );
        await expect(vehicleReturn).toBeVisible();
        await vehicleReturn.click();
        await expect(page).toHaveURL(
            new RegExp(
                `/fleet-assets/vehicles/${fixture.vehicleId}\\?tab=technology$`,
            ),
        );
        await expect(vehicleDevice).toBeVisible();

        await page.goto(
            `/fleet-assets/assets/${fixture.assetId}?tab=technology`,
        );
        await expect(
            page
                .getByRole('heading', {
                    name: fixture.assetName,
                    level: 1,
                })
                .first(),
        ).toBeVisible();
        await expect(
            page.getByRole('tab', { name: /technology & finance/i }),
        ).toHaveAttribute('data-state', 'active');
        const assetDevice = page.locator(
            `a[href="/security-devices/devices/${fixture.assetDeviceId}"]`,
            { hasText: fixture.assetDeviceName },
        );
        await expect(assetDevice).toBeVisible();
        await assetDevice.click();
        await expect(
            page.getByRole('heading', {
                name: fixture.assetDeviceName,
                level: 1,
            }),
        ).toBeVisible();
        await page.getByTestId('device-profile-group-operations').click();
        await page.getByTestId('device-profile-section-assignments').click();
        const assetReturn = page.locator(
            `a[href="/fleet-assets/assets/${fixture.assetId}?tab=technology"]`,
            { hasText: fixture.assetName },
        );
        await expect(assetReturn).toBeVisible();
        await assetReturn.click();
        await expect(page).toHaveURL(
            new RegExp(
                `/fleet-assets/assets/${fixture.assetId}\\?tab=technology$`,
            ),
        );
        await expect(assetDevice).toBeVisible();
        await expectNoPageOverflow(page);
        expectNoConsoleErrors(errors);
    });

    test('keeps Control Room, IT and the canonical Device as one permission-safe round trip', async ({
        page,
    }) => {
        test.setTimeout(180_000);
        const errors = collectConsoleErrors(page);
        await loginAsStaff(page);
        await page.goto(`/security-devices/devices/${fixture.deviceId}`);

        await page.getByTestId('device-profile-group-operations').click();
        await page.getByTestId('device-profile-section-tickets').click();
        const ticketLink = page.locator(
            `a[href="/it/tickets/${fixture.ticketId}"]`,
            { hasText: fixture.ticketTitle },
        );
        await expect(ticketLink).toBeVisible();
        await ticketLink.click();
        await expect(page).toHaveURL(
            new RegExp(`/it/tickets/${fixture.ticketId}$`),
        );

        const deviceFromTicket = page.locator(
            `a[href$="/security-devices/devices/${fixture.deviceId}"]`,
            { hasText: fixture.deviceName },
        );
        const alertFromTicket = page.locator(
            `a[href$="/control-room/alerts/${fixture.controlRoomAlertId}"]`,
            { hasText: fixture.controlRoomReference },
        );
        await expect(deviceFromTicket).toBeVisible();
        await expect(alertFromTicket).toBeVisible();

        await alertFromTicket.click();
        await expect(page).toHaveURL(
            new RegExp(`/control-room/alerts/${fixture.controlRoomAlertId}$`),
        );
        await page.getByRole('button', { name: /linked records/i }).click();

        const deviceFromAlert = page.locator(
            `a[href="/security-devices/devices/${fixture.deviceId}"]`,
            { hasText: 'Canonical Device' },
        );
        const ticketFromAlert = page.locator(
            `a[href$="/it/tickets/${fixture.ticketId}"]`,
            { hasText: 'IT incident work' },
        );
        await expect(deviceFromAlert).toContainText(fixture.deviceName);
        await expect(ticketFromAlert).toContainText(fixture.ticketReference);

        await deviceFromAlert.click();
        await expect(
            page.getByRole('heading', {
                name: fixture.deviceName,
                level: 1,
            }),
        ).toBeVisible();
        await page.getByTestId('device-profile-group-operations').click();
        await page.getByTestId('device-profile-section-events').click();
        await expect(
            page.locator(
                `a[href="/control-room/alerts/${fixture.controlRoomAlertId}"]`,
                { hasText: fixture.controlRoomReference },
            ),
        ).toBeVisible();

        await expectNoPageOverflow(page);
        expectNoConsoleErrors(errors);
    });
});
