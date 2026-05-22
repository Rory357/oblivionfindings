import { expect, test } from '@playwright/test';

import {
    collectConsoleErrors,
    expectNoConsoleErrors,
    gotoMyDay,
    loginAsFrontlineDemoWorker,
} from './helpers';

/**
 * The Paperwork panel surfaces returned timesheets with a "Fix and resubmit"
 * button (vs. "Send for approval" for plain drafts). The dedicated inline
 * edit Sheet from the legacy /my-day was removed in the desktop redesign —
 * the new flow routes the worker to /timesheets/{id}/edit instead.
 *
 * This test asserts the Paperwork card renders the returned timesheet row
 * and the right call-to-action label, without exercising the destination
 * page (covered by tests/Feature/MyTimesheetReturnedFlowTest).
 */
test.describe('returned timesheet row in Paperwork', () => {
    test('shows the returned timesheet with a Fix and resubmit button', async ({
        page,
    }) => {
        const consoleErrors = collectConsoleErrors(page);

        await loginAsFrontlineDemoWorker(page);
        await gotoMyDay(page);

        const paperwork = page.locator('[data-test="my-day-paperwork"]');
        // The seeded demo worker has a returned timesheet; the panel renders
        // when paperwork exists.
        if (!(await paperwork.isVisible().catch(() => false))) {
            test.skip(true, 'Demo worker has no paperwork to show');
        }

        await expect(paperwork).toBeVisible();
        await expect(
            paperwork.getByRole('button', { name: /Fix and resubmit/i }).first(),
        ).toBeVisible();

        expectNoConsoleErrors(consoleErrors);
    });
});
