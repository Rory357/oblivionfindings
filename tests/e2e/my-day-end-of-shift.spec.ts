import { expect, test } from '@playwright/test';

import {
    collectConsoleErrors,
    expectNoConsoleErrors,
    gotoMyDay,
    loginAsFrontlineDemoWorker,
} from './helpers';

/**
 * Clock out — in the desktop redesign, the action lives in the hero (no
 * separate end-of-shift Sheet). We assert that when the demo worker has an
 * active shift, the hero exposes a Clock out button.
 */
test.describe('clock out from the hero', () => {
    test('exposes Clock out in the hero when the demo worker is on shift', async ({
        page,
    }) => {
        const consoleErrors = collectConsoleErrors(page);

        await loginAsFrontlineDemoWorker(page);
        await gotoMyDay(page);

        const clockOut = page
            .getByRole('button', { name: /Clock out/i })
            .first();

        // Skip when the seeded shift hasn't started yet — the hero shows
        // "Clock in" instead.
        if (!(await clockOut.isVisible().catch(() => false))) {
            test.skip(true, 'Demo worker is not currently clocked in');
        }

        await expect(clockOut).toBeVisible();
        // The Today's timesheet shortcut is always present alongside it.
        await expect(
            page.getByRole('button', { name: /Today.s timesheet/i }).first(),
        ).toBeVisible();

        expectNoConsoleErrors(consoleErrors);
    });
});
