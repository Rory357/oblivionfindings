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
} from './helpers';

test.describe('attendance readiness workflows', () => {
    test('frontline worker can clock in from My Day', async ({
        page,
    }, testInfo) => {
        const consoleErrors = collectConsoleErrors(page);

        await loginAsClockInCandidateWorker(page, testInfo);
        await gotoMyDay(page);

        const clockInButton = page.getByTestId('clock-in-button').last();
        if (await clockInButton.isDisabled()) {
            await page.getByRole('radio').first().click();
        }

        await clockInButton.click();

        await expect(
            page.getByRole('heading', { name: /Active shift/i }),
        ).toBeVisible();
        expectNoConsoleErrors(consoleErrors);
    });

    test('frontline worker can clock out cleanly with one atomic request', async ({
        page,
    }, testInfo) => {
        const consoleErrors = collectConsoleErrors(page);

        await loginAsClockOutCleanWorker(page, testInfo);
        await gotoMyDay(page);

        await page.getByTestId('clock-out-button').first().click();
        await expect(
            page.getByRole('heading', { name: /End shift for/i }),
        ).toBeVisible();

        await page.getByTestId('end-shift-submit').click();

        await expect(page.getByText(/Clocked out/i).first()).toBeVisible();
        await expect(page.getByText(/Active shift/i)).toHaveCount(0);
        expectNoConsoleErrors(consoleErrors);
    });

    test('checklist task ticks and handover are submitted with clock out', async ({
        page,
    }, testInfo) => {
        const consoleErrors = collectConsoleErrors(page);

        await loginAsChecklistWorker(page, testInfo);
        await gotoMyDay(page);

        await page.getByTestId('clock-out-button').first().click();
        await expect(page.getByText(/Finish shift tasks/i)).toBeVisible();
        await expect(page.getByText(/Write handover/i)).toBeVisible();

        await page.getByRole('checkbox').first().check();
        await page
            .getByLabel(/What should the next shift know/i)
            .fill('Checklist completed during atomic clock-out test.');
        await page.getByTestId('end-shift-submit').click();

        await expect(page.getByText(/Clocked out/i).first()).toBeVisible();
        expectNoConsoleErrors(consoleErrors);
    });

    test('blocked clock-out shows actionable blocker feedback', async ({
        page,
    }) => {
        const consoleErrors = collectConsoleErrors(page);

        await loginAsIncidentBlockerWorker(page);
        await gotoMyDay(page);

        await page.getByTestId('clock-out-button').first().click();

        await expect(page.getByText(/Submit draft incidents/i)).toBeVisible();
        await expect(
            page.getByTestId('end-shift-override-reason'),
        ).toBeVisible();
        expectNoConsoleErrors(consoleErrors);
    });
});

test.describe('timesheet approval readiness workflows', () => {
    test('manager can bulk approve submitted timesheets with stable selectors', async ({
        page,
    }) => {
        const consoleErrors = collectConsoleErrors(page);

        await loginAsStaff(page);
        await page.goto('/operations/timesheets/approvals');

        const firstRow = page.getByTestId('approvals-row').first();
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
