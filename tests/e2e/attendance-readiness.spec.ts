import { expect, test } from '@playwright/test';

import {
    collectConsoleErrors,
    expectNoConsoleErrors,
    gotoMyDay,
    loginAsChecklistWorker,
    loginAsClockInCandidateWorker,
    loginAsClockOutCleanWorker,
    loginAsIncidentBlockerWorker,
    loginAsStaff,
    resetFrontlineLifecycleReadinessFixtures,
} from './helpers';

test.describe('attendance readiness workflows', () => {
    test.beforeEach(() => {
        resetFrontlineLifecycleReadinessFixtures();
    });

    test('frontline worker can clock in from My Day', async ({
        page,
    }, testInfo) => {
        const consoleErrors = collectConsoleErrors(page);

        await loginAsClockInCandidateWorker(page, testInfo);
        await gotoMyDay(page);

        const clockInButton = page.getByRole('button', {
            name: 'Clock in',
            exact: true,
        });
        await expect(clockInButton).toBeEnabled();
        await clockInButton.click();

        await expect(
            page.getByRole('button', { name: 'Finish shift', exact: true }),
        ).toBeVisible();
        expectNoConsoleErrors(consoleErrors);
    });

    test('frontline worker can clock out cleanly with one atomic request', async ({
        page,
    }, testInfo) => {
        // Allow the persisted clock-out and redirected My Day load to finish
        // after fixture setup and sign-in on CI's single PHP worker.
        test.setTimeout(60_000);
        const consoleErrors = collectConsoleErrors(page);

        await loginAsClockOutCleanWorker(page, testInfo);
        await gotoMyDay(page);

        await page
            .getByRole('button', { name: 'Finish shift', exact: true })
            .click();
        await expect(
            page.getByRole('heading', {
                name: 'End shift at Playwright Attendance House',
                exact: true,
            }),
        ).toBeVisible();

        const clockOutResponse = page.waitForResponse(
            (response) =>
                new URL(response.url()).pathname === '/attendance/clock-out' &&
                response.request().method() === 'POST',
        );
        await page.getByTestId('end-shift-submit').click();
        expect((await clockOutResponse).status()).toBe(302);

        await expect(
            page.getByRole('button', { name: 'Clock in', exact: true }),
        ).toBeVisible();
        await expect(
            page.getByRole('button', { name: 'Finish shift', exact: true }),
        ).toHaveCount(0);
        expectNoConsoleErrors(consoleErrors);
    });

    test('checklist task ticks and handover are submitted with clock out', async ({
        page,
    }, testInfo) => {
        const consoleErrors = collectConsoleErrors(page);

        await loginAsChecklistWorker(page, testInfo);
        await gotoMyDay(page);

        await page
            .getByRole('button', { name: 'Finish shift', exact: true })
            .click();
        const dialog = page.getByRole('dialog', {
            name: 'End shift at Playwright Attendance House',
            exact: true,
        });
        await expect(dialog.getByText(/Finish shift tasks/i)).toBeVisible();
        await expect(dialog.getByText(/Write handover/i)).toBeVisible();

        await dialog
            .getByRole('checkbox', { name: 'Playwright checklist task' })
            .check();
        await dialog
            .getByLabel(
                'What should the next worker know about Playwright Attendance?',
                { exact: true },
            )
            .fill('Checklist completed during atomic clock-out test.');
        const submit = dialog.getByTestId('end-shift-submit');
        await expect(submit).toBeEnabled();
        await submit.click();

        await expect(
            page.getByRole('button', { name: 'Clock in', exact: true }),
        ).toBeVisible();
        expectNoConsoleErrors(consoleErrors);
    });

    test('blocked clock-out shows actionable blocker feedback', async ({
        page,
    }) => {
        const consoleErrors = collectConsoleErrors(page);

        await loginAsIncidentBlockerWorker(page);
        await gotoMyDay(page);

        await page
            .getByRole('button', { name: 'Finish shift', exact: true })
            .click();

        await expect(page.getByText(/Submit draft incidents/i)).toBeVisible();
        await expect(
            page.getByTestId('end-shift-override-reason'),
        ).toBeVisible();
        expectNoConsoleErrors(consoleErrors);
    });
});

test.describe('timesheet approval readiness workflows', () => {
    test.beforeEach(() => {
        resetFrontlineLifecycleReadinessFixtures();
    });

    test('manager can bulk approve submitted timesheets with stable selectors', async ({
        page,
    }) => {
        const consoleErrors = collectConsoleErrors(page);

        await loginAsStaff(page);
        await page.goto('/operations/timesheets/approvals');

        const firstRow = page
            .getByTestId('approvals-row')
            .filter({ hasText: 'Playwright Attendance' })
            .first();
        await expect(firstRow).toBeVisible();

        await firstRow.getByTestId('approvals-row-checkbox').check();
        await page
            .getByTestId('approvals-decision-notes')
            .fill('Playwright readiness approval.');
        await page.getByTestId('approvals-bulk-approve').click();

        await expect(
            page.getByText(/Selected timesheets approved/i).first(),
        ).toBeVisible();
        expectNoConsoleErrors(consoleErrors);
    });

    test('frontline worker cannot open the approvals queue', async ({
        page,
    }) => {
        await loginAsIncidentBlockerWorker(page);
        await page.goto('/operations/timesheets/approvals');

        await expect(page).not.toHaveURL(
            /\/operations\/timesheets\/approvals$/,
        );
    });
});
