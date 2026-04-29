import { expect, test } from '@playwright/test';

import {
    collectConsoleErrors,
    expectNoConsoleErrors,
    loginAsStaff,
} from './helpers';
import {
    rosteringFlagsEnabled,
    rosteringFlagSkipReason,
} from './rostering-flags';

test.describe('operations rostering — suggestions flow', () => {
    test.skip(!rosteringFlagsEnabled, rosteringFlagSkipReason);
    test.skip(
        ({ viewport }) => !viewport || viewport.width < 1024,
        'Mutating rostering flow is desktop-only',
    );

    test('manager generates, accepts, and applies roster suggestions', async ({
        page,
    }) => {
        const consoleErrors = collectConsoleErrors(page);

        await loginAsStaff(page);
        await page.goto('/operations/rostering?week=2026-05-11&site_id=9001');

        await expect(
            page.getByTestId('rostering-suggest-assignments'),
        ).toBeEnabled();
        await page.getByTestId('rostering-suggest-assignments').click();

        await expect(page.getByTestId('roster-suggestions-page')).toBeVisible();
        await expect(page.getByTestId('suggestion-accept').first()).toBeEnabled(
            {
                timeout: 30_000,
            },
        );

        await page.getByTestId('suggestion-accept').first().click();
        await expect(
            page.getByTestId('suggestions-apply-accepted'),
        ).toBeEnabled();
        await page.getByTestId('suggestions-apply-accepted').click();

        // The success toast can render twice (Sonner shows the toast and the
        // accessible live-region announcer mirrors it). Use `.first()` so the
        // selector is non-strict.
        await expect(
            page.getByText(/Applied \d+ accepted suggestions/i).first(),
        ).toBeVisible();

        expectNoConsoleErrors(consoleErrors);
    });
});
