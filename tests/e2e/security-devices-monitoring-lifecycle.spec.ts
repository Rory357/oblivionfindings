import { expect, test } from '@playwright/test';

import {
    chooseSelectOption,
    seedSecurityDevicesMutatingFixtures,
} from './security-devices-mutating-fixtures';
import {
    collectConsoleErrors,
    expectNoConsoleErrors,
    loginAsStaff,
} from './helpers';
import { seedNativeMonitoringRuntimeFixtures } from './native-monitoring-runtime-fixtures';

test.describe('Security & Devices native monitor lifecycle', () => {
    let fixture: ReturnType<typeof seedSecurityDevicesMutatingFixtures>;

    test.beforeAll(() => {
        seedNativeMonitoringRuntimeFixtures();
        fixture = seedSecurityDevicesMutatingFixtures();
    });

    test('creates, updates, and deactivates a native direct monitor', async ({
        page,
    }) => {
        test.setTimeout(240_000);
        const errors = collectConsoleErrors(page);
        const createdName = fixture.monitorName;
        const updatedName = `${fixture.monitorName} updated`;

        await loginAsStaff(page);
        await page.goto('/security-devices/monitoring');
        await expect(
            page.getByRole('heading', { name: 'Monitoring', level: 1 }),
        ).toBeVisible();

        await page.getByRole('button', { name: 'Create direct monitor' }).click();
        const createDialog = page.getByRole('dialog', {
            name: 'Create native direct monitor',
        });
        await expect(createDialog).toBeVisible();
        await chooseSelectOption(
            page,
            'Device',
            `${fixture.deviceName} · ${fixture.siteName}`,
        );
        await chooseSelectOption(
            page,
            'Policy profile',
            fixture.monitorProfileName,
        );
        await page.getByLabel('Monitor name').fill(createdName);
        await page.getByLabel('Approved target').fill(fixture.monitorTarget);
        await page.getByRole('button', { name: 'Create direct monitor' }).click();

        await expect(page.getByText(createdName).first()).toBeVisible({
            timeout: 30_000,
        });

        const createdRow = page.locator('article, div').filter({
            hasText: createdName,
        }).first();
        await createdRow.getByRole('button', { name: 'Update monitor' }).click();
        const updateDialog = page.getByRole('dialog', {
            name: new RegExp(`Update ${createdName}`),
        });
        await expect(updateDialog).toBeVisible();
        await page.getByLabel('Monitor name').fill(updatedName);
        await page.getByRole('button', { name: 'Apply monitor update' }).click();
        await expect(page.getByText(updatedName).first()).toBeVisible({
            timeout: 30_000,
        });

        const updatedRow = page.locator('article, div').filter({
            hasText: updatedName,
        }).first();
        await updatedRow.getByRole('button', { name: 'Deactivate' }).click();
        const deactivate = page.getByRole('dialog', {
            name: new RegExp(`Deactivate ${updatedName}`),
        });
        await expect(deactivate).toBeVisible();
        await chooseSelectOption(
            page,
            'Operational reason',
            'Replaced by an approved definition',
        );
        await page.getByRole('button', { name: 'Deactivate monitor' }).click();
        await expect(page.getByText(updatedName)).toHaveCount(0, {
            timeout: 30_000,
        });

        expectNoConsoleErrors(errors);
    });
});
