import { expect, test } from '@playwright/test';

import { collectConsoleErrors, expectNoConsoleErrors } from './helpers';
import {
    loginAsFixture,
    seedIncidentHandoverFixtures,
} from './incident-handover-helpers';

/**
 * Control Room dashboard — filtering, KPI navigation, and quick-stat drilldown.
 *
 * The dashboard is the highest-traffic operator surface. These specs guard the
 * filter chips, the severity-card drilldown, and the empty-state copy.
 */

test.describe('control room — dashboard interactions', () => {
    let operator: ReturnType<
        typeof seedIncidentHandoverFixtures
    >['users']['operator'];

    test.beforeAll(() => {
        operator = seedIncidentHandoverFixtures().users.operator;
    });

    test.beforeEach(async ({ page }) => {
        await loginAsFixture(page, operator);
        await page.goto('/control-room');
        await expect(
            page.getByRole('heading', { name: 'Control Room Desk', level: 1 }),
        ).toBeVisible();
    });

    test('continuity summary opens the H&S handover register', async ({
        page,
    }) => {
        const errors = collectConsoleErrors(page);

        await page.getByRole('link', { name: /H&S waiting/ }).click();
        await expect(page).toHaveURL(/\/health-safety\/events/);

        expectNoConsoleErrors(errors);
    });

    test('severity filter applies a query param', async ({ page }) => {
        const errors = collectConsoleErrors(page);

        await page.getByLabel('Severity').selectOption('high');
        await page.getByRole('button', { name: 'Apply' }).click();

        await expect(page).toHaveURL(/severity=high/);

        expectNoConsoleErrors(errors);
    });

    test('search submits and persists in URL', async ({ page }) => {
        const errors = collectConsoleErrors(page);

        const searchInput = page.getByPlaceholder(
            'Reference, incident, H&S or summary',
        );
        await searchInput.fill('fire');
        await searchInput.press('Enter');

        await expect(page).toHaveURL(/q=fire/);

        expectNoConsoleErrors(errors);
    });

    test('clearing the search returns to the live desk URL', async ({
        page,
    }) => {
        const errors = collectConsoleErrors(page);

        const searchInput = page.getByPlaceholder(
            'Reference, incident, H&S or summary',
        );
        await searchInput.fill('test-query');
        await searchInput.press('Enter');
        await expect(page).toHaveURL(/q=test-query/);

        await searchInput.fill('');
        await searchInput.press('Enter');
        await expect(page).toHaveURL(/\/control-room\?period=7d$/);

        expectNoConsoleErrors(errors);
    });

    test('owner filter scopes the priority worklist to unassigned alerts', async ({
        page,
    }) => {
        const errors = collectConsoleErrors(page);

        await page.getByLabel('Owner').selectOption('unassigned');
        await page.getByRole('button', { name: 'Apply' }).click();
        await expect(page).toHaveURL(/assigned_to=unassigned/);

        expectNoConsoleErrors(errors);
    });

    test('empty-alerts state renders when filters return zero rows', async ({
        page,
    }) => {
        const errors = collectConsoleErrors(page);

        const searchInput = page.getByPlaceholder(
            'Reference, incident, H&S or summary',
        );
        await searchInput.fill('this-string-should-never-match-zzzz-9876');
        await searchInput.press('Enter');

        await expect(
            page.getByText(/No priority work matches these filters/i),
        ).toBeVisible();

        expectNoConsoleErrors(errors);
    });

    test('workspace navigation reaches the operational destinations', async ({
        page,
    }) => {
        test.setTimeout(60_000);
        const errors = collectConsoleErrors(page);
        const workspace = page.getByRole('navigation', {
            name: 'Control Room workspace',
        });

        await workspace.getByRole('link', { name: /^Active alerts/ }).click();
        await expect(page).toHaveURL(/\/control-room\/alerts$/);
        await page.goBack();

        await workspace.getByRole('link', { name: /^Shifts/ }).click();
        await expect(page).toHaveURL(/\/control-room\/shifts$/);
        await page.goBack();

        await workspace.getByRole('link', { name: /^Escalations/ }).click();
        await expect(page).toHaveURL(/\/control-room\/escalations$/);

        expectNoConsoleErrors(errors);
    });
});
