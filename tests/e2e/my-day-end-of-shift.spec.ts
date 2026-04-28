import { expect, test } from '@playwright/test';

import {
    collectConsoleErrors,
    expectNoConsoleErrors,
    gotoMyDay,
    loginAsFrontlineDemoWorker,
} from './helpers';

/**
 * F-3: end-of-shift checklist with embedded shift tasks and a live blocker
 * count that updates as tasks are ticked. Only runs when the demo worker is
 * already clocked in — otherwise the End shift button is not present.
 */
test.describe('end-of-shift checklist', () => {
    test('shows embedded shift tasks if the demo worker is on an active shift', async ({
        page,
    }) => {
        const consoleErrors = collectConsoleErrors(page);

        await loginAsFrontlineDemoWorker(page);
        await gotoMyDay(page);

        const endShiftButton = page
            .getByRole('button', { name: /^End shift$/i })
            .first();

        // If the worker isn't currently clocked in (the briefing shift hasn't
        // crossed its start time yet), skip — this scenario is timing-bound.
        if (!(await endShiftButton.isVisible().catch(() => false))) {
            test.skip(true, 'Demo worker is not currently clocked in');
        }

        await endShiftButton.click();

        await expect(
            page.getByRole('heading', { name: /End shift for/i }),
        ).toBeVisible();

        // Embedded shift tasks section + counter (F-3).
        await expect(page.getByText(/Shift tasks/i).first()).toBeVisible();
        await expect(
            page
                .getByText(/(All complete|of \d+ still to do)/i)
                .first(),
        ).toBeVisible();

        expectNoConsoleErrors(consoleErrors);
    });
});
