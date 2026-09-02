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

test.describe('Security & Devices discovery and collector lifecycle', () => {
    let fixture: ReturnType<typeof seedSecurityDevicesMutatingFixtures>;

    test.beforeAll(() => {
        seedNativeMonitoringRuntimeFixtures();
        fixture = seedSecurityDevicesMutatingFixtures();
    });

    test('creates, updates, and applies a scope then enrols, revokes, and re-enrols a collector', async ({
        page,
    }) => {
        test.setTimeout(300_000);
        const errors = collectConsoleErrors(page);
        const stamp = Date.now();
        const scopeName = `Playwright mutating scope ${stamp}`;
        const updatedScopeName = `${scopeName} updated`;

        await loginAsStaff(page);
        await page.goto('/security-devices/discovery?tab=scopes');
        await expect(
            page.getByRole('heading', {
                name: 'Discovery & collectors',
                level: 1,
            }),
        ).toBeVisible();

        await page.getByRole('button', { name: 'Create direct scope' }).click();
        const createDialog = page.getByRole('dialog', {
            name: 'Create direct discovery scope',
        });
        await expect(createDialog).toBeVisible();
        if (
            await page.getByRole('combobox', { name: 'Site' }).isVisible().catch(() => false)
        ) {
            await chooseSelectOption(page, 'Site', fixture.siteName);
        }
        await page.getByLabel('Scope name').fill(scopeName);
        await page.getByLabel('Approved networks').fill('203.0.113.0/24');
        await page.getByRole('button', { name: 'Create direct scope' }).click();

        const scopeCard = page.getByRole('article', {
            name: `Discovery scope ${scopeName}`,
        });
        await expect(scopeCard).toBeVisible({ timeout: 30_000 });
        await scopeCard.getByRole('button', { name: 'Update' }).click();
        const updateDialog = page.getByRole('dialog', {
            name: new RegExp(`Update ${scopeName}`),
        });
        await expect(updateDialog).toBeVisible();
        await page.getByLabel('Scope name').fill(updatedScopeName);
        await page.getByRole('button', { name: 'Apply scope update' }).click();

        const updatedCard = page.getByRole('article', {
            name: `Discovery scope ${updatedScopeName}`,
        });
        await expect(updatedCard).toBeVisible({ timeout: 30_000 });
        await updatedCard.getByRole('button', { name: 'Run now' }).click();
        const applyDialog = page.getByRole('dialog', {
            name: new RegExp(`Run ${updatedScopeName} now`),
        });
        await expect(applyDialog).toBeVisible();
        await applyDialog.getByRole('button', { name: 'Queue discovery run' }).click();

        await updatedCard.getByRole('button', { name: 'Deactivate' }).click();
        const deactivateDialog = page.getByRole('dialog', {
            name: new RegExp(`Deactivate ${updatedScopeName}`),
        });
        await expect(deactivateDialog).toBeVisible();
        await chooseSelectOption(
            page,
            'Operational reason',
            'Approved network retired',
        );
        await page.getByRole('button', { name: 'Deactivate scope' }).click();

        await page.goto('/security-devices/discovery?tab=collectors');
        await expect(page.getByText(fixture.collectorName).first()).toBeVisible();

        await page.getByRole('button', { name: 'Enrol remote collector' }).click();
        const enrolDialog = page.getByRole('dialog');
        await expect(enrolDialog).toBeVisible();
        if (
            await page.getByRole('combobox', { name: /site/i }).isVisible().catch(() => false)
        ) {
            await chooseSelectOption(page, 'Site', fixture.siteName);
        }
        await page.getByRole('button', { name: 'Issue one-time token' }).click();
        await expect(page.getByText(/not retained after this dialog closes/i)).toBeVisible({
            timeout: 30_000,
        });
        await page.getByRole('button', { name: 'Close and clear token' }).click();

        const collectorCard = page.getByRole('article', {
            name: `Collector ${fixture.collectorName}`,
        });
        await expect(collectorCard).toBeVisible();
        await collectorCard.getByRole('button', { name: 'Revoke collector' }).click();
        const revokeDialog = page.getByRole('dialog', {
            name: new RegExp(`Revoke ${fixture.collectorName}`),
        });
        await expect(revokeDialog).toBeVisible();
        await page.getByLabel('Operational reason').fill(
            'Playwright replacement after a confirmed collector host failure.',
        );
        await page.getByRole('button', { name: 'Revoke collector' }).click();

        await expect(
            collectorCard.getByRole('button', { name: 'Re-enrol collector' }),
        ).toBeVisible({ timeout: 30_000 });
        await collectorCard.getByRole('button', { name: 'Re-enrol collector' }).click();
        await page.getByRole('button', { name: 'Issue one-time token' }).click();
        await expect(page.getByText(/not retained after this dialog closes/i)).toBeVisible({
            timeout: 30_000,
        });
        await expect(
            page.getByText(/former certificate and signing key remain revoked/i),
        ).toBeVisible();
        await page.getByRole('button', { name: 'Close and clear token' }).click();

        expectNoConsoleErrors(errors);
    });
});

