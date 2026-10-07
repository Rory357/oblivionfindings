import { expect, test } from '@playwright/test';

import {
    collectConsoleErrors,
    expectNoConsoleErrors,
    expectPostRedirect,
    loginAsStaff,
    resetRosteringReadinessFixtures,
    ROSTERING_DEMO_SUGGESTION_TARGET,
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
        test.setTimeout(90_000);
        const consoleErrors = collectConsoleErrors(page);

        resetRosteringReadinessFixtures();
        await loginAsStaff(page);
        await page.goto(
            `/operations/rostering?week=${ROSTERING_DEMO_SUGGESTION_TARGET.week}&site_id=${ROSTERING_DEMO_SUGGESTION_TARGET.siteId}`,
        );

        await expect(
            page.getByTestId('rostering-suggest-assignments'),
        ).toBeEnabled();
        await expectPostRedirect(
            page,
            /^\/operations\/rostering\/auto-schedule$/,
            () => page.getByTestId('rostering-suggest-assignments').click(),
        );

        await expect(page).toHaveURL(
            /\/operations\/rostering\/suggestions\/\d+(?:\?|$)/,
            { timeout: 30_000 },
        );
        await expect(page.getByTestId('roster-suggestions-page')).toBeVisible();
        await expect(page.getByTestId('suggestion-accept').first()).toBeEnabled(
            {
                timeout: 30_000,
            },
        );

        const firstSuggestion = page.getByTestId('suggestion-row').first();
        await firstSuggestion.getByTestId('suggestion-accept').click();
        await expect(firstSuggestion).toHaveAttribute(
            'data-status',
            'accepted',
        );
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
        await expect(firstSuggestion).toHaveAttribute('data-status', 'applied');
        await page.reload();
        await expect(firstSuggestion).toHaveAttribute('data-status', 'applied');

        expectNoConsoleErrors(consoleErrors);
    });
});
