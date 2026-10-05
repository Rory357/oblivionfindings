import { expect, test } from '@playwright/test';

import {
    collectConsoleErrors,
    expectNoConsoleErrors,
    loginAsStaff,
    resetRosteringReadinessFixtures,
} from './helpers';
import {
    rosteringFlagsEnabled,
    rosteringFlagSkipReason,
} from './rostering-flags';

test.describe('roster templates — apply preflight', () => {
    test.skip(!rosteringFlagsEnabled, rosteringFlagSkipReason);
    test.skip(
        ({ viewport }) => !viewport || viewport.width < 1024,
        'Mutating rostering flow is desktop-only',
    );

    test('blocks template rows that would create staff conflicts', async ({
        page,
    }) => {
        test.setTimeout(60_000);
        resetRosteringReadinessFixtures();

        const consoleErrors = collectConsoleErrors(page);

        await loginAsStaff(page);
        // Templates are now a tab inside the Rostering workspace; open the detail
        // pop-up for the seeded template, which carries the apply panel.
        await page.goto('/operations/rostering?tab=templates');
        await page.getByTestId('template-card-9001').click();

        await expect(page.getByTestId('template-apply-card')).toBeVisible();
        await page.locator('#week-start').fill('2026-05-25');
        const applyResponse = page.waitForResponse(
            (response) =>
                new URL(response.url()).pathname ===
                    '/operations/rostering/templates/9001/apply' &&
                response.request().method() === 'POST',
        );
        await page.getByTestId('template-apply-submit').click();
        expect((await applyResponse).status()).toBe(302);

        await expect(page.getByTestId('template-apply-blocks')).toBeVisible();
        await expect(
            page.getByText(/Template cannot be applied/i),
        ).toBeVisible();

        expectNoConsoleErrors(consoleErrors);
    });
});
