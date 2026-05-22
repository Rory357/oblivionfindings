import { expect, test } from '@playwright/test';

import {
    collectConsoleErrors,
    expectNoConsoleErrors,
    gotoMyDay,
    loginAsFrontlineDemoWorker,
} from './helpers';

/**
 * Tomorrow card — the seeded demo worker has a shift starting tomorrow at
 * 07:30. The TomorrowPanel renders the briefing bullets + next client name.
 *
 * If the seeded shift has already started by the time the test runs, the
 * page swaps into the active-shift hero — both branches are valid pre-shift
 * UX so we accept either.
 */
test.describe('pre-shift briefing card', () => {
    test('renders the Tomorrow panel for the demo worker', async ({ page }) => {
        const consoleErrors = collectConsoleErrors(page);

        await loginAsFrontlineDemoWorker(page);
        await gotoMyDay(page);

        // Either the Tomorrow panel renders (no active shift yet) OR the
        // active-shift hero renders (the seeded shift has started).
        await expect(
            page
                .locator(
                    '[data-test="my-day-tomorrow"], [data-test="my-day-whats-next"]',
                )
                .first(),
        ).toBeVisible();

        // The hero shows the worker name in the description regardless.
        await expect(
            page.getByText(/Kia ora/i).first(),
        ).toBeVisible();

        expectNoConsoleErrors(consoleErrors);
    });
});
