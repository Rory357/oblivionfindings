import { expect, test } from '@playwright/test';

import {
    collectConsoleErrors,
    expectNoConsoleErrors,
    loginAsStaff,
} from './helpers';

test.describe('IT & Support service management navigation', () => {
    test.beforeEach(async ({ page }) => {
        await loginAsStaff(page);
    });

    test('keeps setup and service-delivery workspaces understandable on desktop and mobile', async ({
        page,
    }, testInfo) => {
        const errors = collectConsoleErrors(page);

        await page.goto('/it/setup');
        await expect(
            page.getByRole('heading', {
                name: 'Teams, queues & services',
                level: 1,
            }),
        ).toBeVisible();
        await expect(
            page.getByRole('button', { name: 'New team' }),
        ).toBeVisible();
        await expect(page.getByRole('button', { name: 'New queue' })).toHaveCount(0);
        await page.getByRole('tab', { name: 'Queues' }).click();
        await expect(
            page.getByRole('button', { name: 'New queue' }),
        ).toBeVisible();
        await page.getByRole('tab', { name: 'Services' }).click();
        await expect(
            page.getByRole('button', { name: 'New service' }),
        ).toBeVisible();
        await page.getByRole('tab', { name: 'Provisioning workflows' }).click();
        await expect(
            page.getByRole('heading', { name: 'Provisioning workflows', level: 1 }),
        ).toBeVisible();
        await expect(
            page.getByRole('heading', { name: 'Lifecycle workflow templates', level: 2 }),
        ).toBeVisible();
        await expect(page.getByText('Standard joiner')).toBeVisible();
        await expect(page.getByText('Verify and grant approved healthcare system access')).toBeVisible();
        await expect(page.getByRole('button', { name: 'New template' })).toBeVisible();
        await page.getByRole('button', { name: 'New template' }).click();
        const templateDialog = page.getByRole('dialog');
        await expect(templateDialog.getByRole('heading', { name: 'New lifecycle template' })).toBeVisible();
        await expect(templateDialog.getByText('Minimum employee details shown')).toBeVisible();
        await expect(templateDialog.getByText('Approval required')).toBeVisible();
        await expect(templateDialog.getByText('Evidence required')).toBeVisible();
        await templateDialog.getByRole('button', { name: 'Cancel' }).click();
        await page.getByRole('tab', { name: 'API identities' }).click();
        await expect(
            page.getByRole('heading', { name: 'API identities', level: 2 }),
        ).toBeVisible();
        await page.getByRole('button', { name: 'New API identity' }).click();
        await expect(
            page.getByRole('dialog').getByRole('heading', {
                name: 'New API identity',
            }),
        ).toBeVisible();
        await expect(page.getByLabel('Identity name')).toBeVisible();
        await expect(page.getByLabel('Execution account')).toBeVisible();
        await expect(
            page.getByText(
                'Require a timestamped HMAC signature on every request',
            ),
        ).toBeVisible();
        await page.getByRole('button', { name: 'Cancel' }).click();

        if (testInfo.project.name.includes('desktop')) {
            const sidebar = page.locator('aside').filter({
                has: page.getByRole('navigation', { name: 'IT & Support' }),
            });

            await expect(sidebar).toBeVisible();
            for (const group of [
                'Service Desk',
                'Service Delivery',
                'Operations',
                'Setup',
            ]) {
                await expect(
                    sidebar.getByText(group, { exact: true }),
                ).toBeVisible();
            }
            await expect(
                sidebar.getByRole('link', {
                    name: 'Teams, queues & services',
                }),
            ).toHaveAttribute('aria-current', 'page');
            await sidebar
                .getByRole('link', { name: 'Service catalogue' })
                .click();
        } else {
            const mobileMenu = page.locator('details').filter({
                hasText: 'IT & Support navigation',
            });
            await mobileMenu.getByText('IT & Support navigation').click();
            await expect(mobileMenu).toHaveAttribute('open', '');
            await expect(
                mobileMenu.getByText('Service Delivery', { exact: true }),
            ).toBeVisible();
            await mobileMenu
                .getByRole('link', { name: 'Service catalogue' })
                .click();
        }

        await expect(page).toHaveURL(/\/it\?.*tab=catalog/);
        await expect(
            page.getByRole('heading', { name: 'Service catalogue', level: 2 }),
        ).toBeVisible();
        expectNoConsoleErrors(errors);
    });
});
