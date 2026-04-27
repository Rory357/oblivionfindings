import { expect, test } from '@playwright/test';

import {
    collectConsoleErrors,
    expectNoConsoleErrors,
    loginAsStaff,
} from './helpers';

test.describe('frontline lifecycle shell', () => {
    test('my day exposes the lifecycle home without console errors', async ({
        page,
    }) => {
        const consoleErrors = collectConsoleErrors(page);

        await loginAsStaff(page);
        await page.goto('/my-day');
        await page.waitForLoadState('domcontentloaded');

        await expect(
            page
                .getByText(
                    /Active shift|Before you start|Previous shift|No shift today/i,
                )
                .first(),
        ).toBeVisible();
        await expect(page.getByText(/Open items/i)).toBeVisible();

        expectNoConsoleErrors(consoleErrors);
    });

    test('my roster renders the worker roster sections', async ({ page }) => {
        const consoleErrors = collectConsoleErrors(page);

        await loginAsStaff(page);
        await page.goto('/my-roster');
        await page.waitForLoadState('domcontentloaded');

        await expect(
            page.getByRole('heading', { name: /This week/i }),
        ).toBeVisible();
        await expect(
            page.getByRole('heading', { name: /^Today$/i }),
        ).toBeVisible();
        await expect(
            page.getByRole('heading', { name: /Upcoming/i }),
        ).toBeVisible();

        expectNoConsoleErrors(consoleErrors);
    });
});
