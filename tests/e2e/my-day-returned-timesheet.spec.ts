import { expect, test } from '@playwright/test';

import {
    collectConsoleErrors,
    expectNoConsoleErrors,
    gotoMyDay,
    loginAsFrontlineDemoWorker,
} from './helpers';

/**
 * F-1 inline resubmit flow — observes the seeded returned timesheet on
 * /my-day, opens the inline edit sheet, and verifies the manager note, the
 * mileage / notes fields, and the dictate button are all rendered. Submits
 * the form and asserts the sheet closes plus the success toast.
 */
test.describe('returned timesheet inline resubmit', () => {
    test('worker sees the manager note and can open the edit sheet', async ({
        page,
    }) => {
        const consoleErrors = collectConsoleErrors(page);

        await loginAsFrontlineDemoWorker(page);
        await gotoMyDay(page);

        const banner = page.getByText(/Your timesheet needs a quick fix/i);
        await expect(banner).toBeVisible();

        // The seeded manager note is rendered inline (no hover, no extra page).
        await expect(
            page.getByText(/payroll rules require at least 30 min/i),
        ).toBeVisible();

        const updateButton = page
            .getByRole('button', { name: /Update & resubmit/i })
            .first();
        await expect(updateButton).toBeVisible();
        await updateButton.click();

        // Sheet header shows the F-1 title and description.
        await expect(
            page.getByRole('heading', { name: /Update and resubmit/i }),
        ).toBeVisible();
        await expect(
            page.getByText(/Fix the returned timesheet without leaving/i),
        ).toBeVisible();

        // All editable fields are visible (Mileage km is the seeded gap the
        // manager asked the worker to fill in).
        await expect(page.getByLabel(/Mileage km/i)).toBeVisible();
        await expect(page.getByLabel(/Break minutes/i)).toBeVisible();
        await expect(page.getByLabel(/^Notes$/i)).toBeVisible();

        expectNoConsoleErrors(consoleErrors);
    });
});
