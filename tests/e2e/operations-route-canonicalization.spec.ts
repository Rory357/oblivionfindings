import { expect, test, type Page } from '@playwright/test';

import {
    collectConsoleErrors,
    expectNoConsoleErrors,
    loginAsStaff,
} from './helpers';

async function gotoCanonical(page: Page, url: string) {
    await page.goto(url, { waitUntil: 'commit' });
}

test.describe('operations route canonicalization', () => {
    test('legacy manager GET surfaces redirect to operations URLs', async ({
        page,
    }) => {
        const consoleErrors = collectConsoleErrors(page);

        await loginAsStaff(page);

        await gotoCanonical(page, '/shifts');
        await expect(page).toHaveURL(/\/operations\/shifts(?:\?|$)/);

        await gotoCanonical(page, '/timesheets/approvals');
        await expect(page).toHaveURL(
            /\/operations\/timesheets\/approvals(?:\?|$)/,
        );

        await gotoCanonical(page, '/rostering');
        await expect(page).toHaveURL(/\/operations\/rostering(?:\?|$)/);

        expectNoConsoleErrors(consoleErrors);
    });

    test('attendance remains a canonical frontline surface', async ({ page }) => {
        const consoleErrors = collectConsoleErrors(page);

        await loginAsStaff(page);
        await gotoCanonical(page, '/attendance');
        await expect(page).toHaveURL(/\/attendance(?:\?|$)/);

        expectNoConsoleErrors(consoleErrors);
    });
});
