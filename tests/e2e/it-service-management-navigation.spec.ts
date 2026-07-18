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
        await expect(
            page.getByRole('button', { name: 'New queue' }),
        ).toBeVisible();
        await expect(
            page.getByRole('button', { name: 'New service' }),
        ).toBeVisible();

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
