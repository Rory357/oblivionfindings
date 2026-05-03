import { expect, test } from '@playwright/test';

import {
    collectConsoleErrors,
    expectNoConsoleErrors,
    loginAsStaff,
    publishCurrentWeek,
    resetRosteringReadinessFixtures,
    ROSTERING_DEMO_PUBLISH_TARGET,
} from './helpers';
import {
    rosteringFlagsEnabled,
    rosteringFlagSkipReason,
} from './rostering-flags';

test.describe('operations rostering — publish flow', () => {
    test.skip(!rosteringFlagsEnabled, rosteringFlagSkipReason);
    test.skip(
        ({ viewport }) => !viewport || viewport.width < 1024,
        'Mutating rostering flow is desktop-only',
    );

    test('manager reviews and publishes a roster period', async ({ page }) => {
        const consoleErrors = collectConsoleErrors(page);

        resetRosteringReadinessFixtures();
        await loginAsStaff(page);
        await publishCurrentWeek(page, ROSTERING_DEMO_PUBLISH_TARGET);

        await expect(
            page.getByTestId('rostering-published-report-link'),
        ).toBeVisible();
        await page
            .getByTestId('rostering-published-report-link')
            .getByRole('link')
            .click();
        await expect(page).toHaveURL(/\/operations\/reports\/shifts/);
        await expect(
            page.getByRole('heading', { name: /Shift Operations Reports/i }),
        ).toBeVisible();

        expectNoConsoleErrors(consoleErrors);
    });
});
