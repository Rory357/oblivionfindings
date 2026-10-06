import { expect, test } from '@playwright/test';

import {
    collectConsoleErrors,
    expectNoConsoleErrors,
    loginAsStaff,
} from './helpers';

test.describe('frontline lifecycle shell', () => {
    test('my day exposes the desktop hero without console errors', async ({
        page,
    }) => {
        const consoleErrors = collectConsoleErrors(page);

        await loginAsStaff(page);
        await page.goto('/my-day');
        await page.waitForLoadState('domcontentloaded');

        // Current My Day header and shared page navigation.
        await expect(
            page.getByRole('heading', { name: 'My Day', level: 1 }),
        ).toBeVisible();
        await expect(
            page.getByRole('button', {
                name: 'Search or jump to a page',
                exact: true,
            }),
        ).toBeVisible();

        // Hero + body sections. We can't guarantee the worker has an active
        // shift in CI fixtures, so we assert on the headings that always
        // render regardless of payload state.
        await expect(
            page.getByRole('heading', {
                name: 'What needs to happen today',
                exact: true,
            }),
        ).toBeVisible();

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
