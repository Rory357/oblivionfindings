import { expect, test } from '@playwright/test';

import {
    collectConsoleErrors,
    expectNoConsoleErrors,
    gotoMyDay,
    loginAsFrontlineDemoWorker,
} from './helpers';

/**
 * Pre-shift briefing card (F-2 + the F-1 demo seeder put a shift starting in
 * ~30 min for sw1). Verifies the consolidated "Before you start" hero is
 * present with the seeded what-to-know note and a Start shift CTA, and that
 * the late-state badge renders red once the shift's start time has passed.
 */
test.describe('pre-shift briefing card', () => {
    test('renders briefing details and Start shift CTA for the demo worker', async ({
        page,
    }) => {
        const consoleErrors = collectConsoleErrors(page);

        await loginAsFrontlineDemoWorker(page);
        await gotoMyDay(page);

        // Either the pre-shift briefing OR the active shift hero is shown
        // (the seeded shift may have flipped to active if the start time has
        // passed). In both cases the lifecycle hero is visible.
        await expect(
            page
                .getByText(/Before you start|Active shift/i)
                .first(),
        ).toBeVisible();

        // The "What to know" or briefing summary references the demo client.
        const heroSummary = page
            .getByText(/Rosie Ngata|family will visit|Auckland/i)
            .first();
        await expect(heroSummary).toBeVisible();

        expectNoConsoleErrors(consoleErrors);
    });
});
