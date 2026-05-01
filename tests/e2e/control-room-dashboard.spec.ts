import { expect, test } from '@playwright/test';

import { collectConsoleErrors, expectNoConsoleErrors, loginAsStaff } from './helpers';

/**
 * Control Room dashboard — filtering, KPI navigation, and quick-stat drilldown.
 *
 * The dashboard is the highest-traffic operator surface. These specs guard the
 * filter chips, the severity-card drilldown, and the empty-state copy.
 */

test.describe('control room — dashboard interactions', () => {
    test.beforeEach(async ({ page }) => {
        await loginAsStaff(page);
        await page.goto('/control-room');
        await expect(
            page.getByRole('heading', { name: 'Control Room', level: 1 }),
        ).toBeVisible();
    });

    test('Critical KPI card links to the severity-filtered list', async ({ page }, testInfo) => {
        test.skip(
            !testInfo.project.name.includes('desktop'),
            'Hover/click drilldown is only meaningful on desktop layout.',
        );

        const errors = collectConsoleErrors(page);

        await page.getByRole('link', { name: /Critical/ }).first().click();
        await expect(page).toHaveURL(/severity=critical/);

        expectNoConsoleErrors(errors);
    });

    test('severity filter dropdown applies a query param', async ({ page }, testInfo) => {
        test.skip(
            !testInfo.project.name.includes('desktop'),
            'Filter dropdown only renders on desktop viewport.',
        );

        const errors = collectConsoleErrors(page);

        await page.getByRole('combobox').nth(1).click(); // status combobox is index 0; severity is index 1
        await page.getByRole('option', { name: 'High' }).click();

        await expect(page).toHaveURL(/severity=high/);

        expectNoConsoleErrors(errors);
    });

    test('search submits and persists in URL', async ({ page }) => {
        const errors = collectConsoleErrors(page);

        const searchInput = page.getByPlaceholder('Search alerts...');
        await searchInput.fill('fire');
        await searchInput.press('Enter');

        await expect(page).toHaveURL(/search=fire/);

        expectNoConsoleErrors(errors);
    });

    test('clear filters returns to clean URL', async ({ page }, testInfo) => {
        test.skip(
            !testInfo.project.name.includes('desktop'),
            'Clear button visibility is desktop-only.',
        );

        const errors = collectConsoleErrors(page);

        const searchInput = page.getByPlaceholder('Search alerts...');
        await searchInput.fill('test-query');
        await searchInput.press('Enter');
        await expect(page).toHaveURL(/search=test-query/);

        await page.getByRole('button', { name: /^Clear$/ }).click();
        await expect(page).toHaveURL(/\/control-room\/?$/);

        expectNoConsoleErrors(errors);
    });

    test('quick-stat row navigates back into filtered list', async ({ page }, testInfo) => {
        test.skip(
            !testInfo.project.name.includes('desktop'),
            'Quick-stat grid is desktop-first.',
        );

        const errors = collectConsoleErrors(page);

        await page.getByRole('button', { name: /Unassigned/ }).first().click();
        await expect(page).toHaveURL(/assigned_to=unassigned/);

        expectNoConsoleErrors(errors);
    });

    test('empty-alerts state renders when filters return zero rows', async ({ page }) => {
        const errors = collectConsoleErrors(page);

        const searchInput = page.getByPlaceholder('Search alerts...');
        await searchInput.fill('this-string-should-never-match-zzzz-9876');
        await searchInput.press('Enter');

        await expect(
            page.getByText(/No alerts found matching your filters\./i),
        ).toBeVisible();

        expectNoConsoleErrors(errors);
    });

    test('navigation buttons in the page header reach their target pages', async ({
        page,
    }, testInfo) => {
        test.skip(
            !testInfo.project.name.includes('desktop'),
            'Header chip buttons collapse on mobile.',
        );

        const errors = collectConsoleErrors(page);

        await page.getByRole('link', { name: /^Map$/ }).click();
        await expect(page).toHaveURL(/\/control-room\/map$/);
        await page.goBack();

        await page.getByRole('link', { name: /^Shifts$/ }).click();
        await expect(page).toHaveURL(/\/control-room\/shifts$/);
        await page.goBack();

        await page.getByRole('link', { name: /^Queues$/ }).click();
        await expect(page).toHaveURL(/\/control-room\/escalations$/);

        expectNoConsoleErrors(errors);
    });
});
