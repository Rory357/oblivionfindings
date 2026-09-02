import { expect, test } from '@playwright/test';

import {
    chooseSelectOption,
    seedSecurityDevicesMutatingFixtures,
} from './security-devices-mutating-fixtures';
import {
    collectConsoleErrors,
    expectNoConsoleErrors,
    loginAsStaff,
    seedSecurityDevicesOperationsReadinessFixtures,
} from './helpers';

test.describe('Security & Devices device registry CRUD', () => {
    test.beforeAll(() => {
        seedSecurityDevicesOperationsReadinessFixtures();
        seedSecurityDevicesMutatingFixtures();
    });

    test('registers a device from the estate dashboard and edits it from inventory', async ({
        page,
    }) => {
        test.setTimeout(240_000);
        const errors = collectConsoleErrors(page);
        const stamp = Date.now();
        const deviceName = `Playwright registry dome ${stamp}`;
        const editedName = `Playwright registry dome edited ${stamp}`;

        await loginAsStaff(page);

        await page.goto('/security-devices');
        await expect(
            page.getByRole('heading', {
                name: 'Security & Devices estate',
                level: 1,
            }),
        ).toBeVisible();
        await page.getByRole('button', { name: 'Register device' }).first().click();

        const wizard = page.getByRole('dialog', { name: /register device/i });
        await expect(wizard).toBeVisible();
        await wizard.getByLabel('Device name').fill(deviceName);
        await chooseSelectOption(wizard, 'Workspace', 'Security');
        await chooseSelectOption(wizard, 'Device category', 'Cctv');
        await chooseSelectOption(wizard, 'Device type', 'Dome Camera');
        await wizard.getByRole('button', { name: 'Continue' }).click();
        await wizard.getByLabel('Manufacturer').fill('Oblivion Demo');
        await wizard.getByLabel('Model').fill('Playwright Dome');
        await wizard.getByRole('button', { name: 'Continue' }).click();
        await wizard.getByRole('button', { name: 'Continue' }).click();
        await wizard.getByRole('button', { name: 'Register device' }).click();

        const profileHeading = page.getByRole('heading', {
            name: deviceName,
            level: 1,
        });
        const registeredPane = page.getByRole('heading', {
            name: 'Device registered',
        });
        await expect(profileHeading.or(registeredPane)).toBeVisible({
            timeout: 30_000,
        });

        if (await registeredPane.isVisible().catch(() => false)) {
            await page
                .getByRole('button', { name: /return to devices|return to profile/i })
                .click();
            await page.goto('/security-devices/devices');
            await page
                .getByPlaceholder(/search name, uid, serial/i)
                .fill(deviceName);
            await page
                .getByPlaceholder(/search name, uid, serial/i)
                .press('Enter');
            await page.getByRole('link', { name: deviceName }).first().click();
        }

        await expect(
            page.getByRole('heading', { name: deviceName, level: 1 }),
        ).toBeVisible();

        await page.getByRole('button', { name: 'Edit' }).click();
        const editor = page.getByRole('dialog', { name: /edit device/i });
        await expect(editor).toBeVisible();
        await editor.getByLabel('Device name').fill(editedName);
        await editor.getByRole('button', { name: 'Continue' }).click();
        await editor.getByRole('button', { name: 'Continue' }).click();
        await editor.getByRole('button', { name: 'Continue' }).click();
        await editor.getByRole('button', { name: 'Save changes' }).click();

        const updatedHeading = page.getByRole('heading', {
            name: editedName,
            level: 1,
        });
        const updatedPane = page.getByRole('heading', { name: 'Device updated' });
        await expect(updatedHeading.or(updatedPane)).toBeVisible({
            timeout: 30_000,
        });
        if (await updatedPane.isVisible().catch(() => false)) {
            await page.getByRole('button', { name: 'Return to profile' }).click();
        }
        await expect(
            page.getByRole('heading', { name: editedName, level: 1 }),
        ).toBeVisible();

        expectNoConsoleErrors(errors);
    });
});
