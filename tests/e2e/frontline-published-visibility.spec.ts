import { expect, test } from '@playwright/test';

import {
    collectConsoleErrors,
    expectNoConsoleErrors,
    gotoMyRoster,
    loginAs,
    loginAsStaff,
    publishCurrentWeek,
    resetRosteringReadinessFixtures,
    ROSTERING_DEMO_FRONTLINE_TARGET,
} from './helpers';
import {
    rosteringFlagsEnabled,
    rosteringFlagSkipReason,
} from './rostering-flags';

test.describe('frontline roster — published visibility', () => {
    test.skip(!rosteringFlagsEnabled, rosteringFlagSkipReason);
    test.skip(
        ({ viewport }) => !viewport || viewport.width < 1024,
        'Mutating rostering flow is desktop-only',
    );

    test('draft assigned shifts stay hidden until the manager publishes', async ({
        page,
    }) => {
        test.setTimeout(90_000);

        const consoleErrors = collectConsoleErrors(page);

        resetRosteringReadinessFixtures();
        await loginAs(page, 'roster-e2e-frontline@demo.test', 'password');
        await gotoMyRoster(page);
        const publishedShift = page.getByRole('button', {
            name: /Rostering Frontline - Rostering E2E Frontline House/i,
        });
        await expect(publishedShift).toBeHidden();

        await page.context().clearCookies();
        await loginAsStaff(page);
        await publishCurrentWeek(page, ROSTERING_DEMO_FRONTLINE_TARGET);

        await page.context().clearCookies();
        await loginAs(page, 'roster-e2e-frontline@demo.test', 'password');
        await gotoMyRoster(page);
        await expect(publishedShift).toBeVisible();

        expectNoConsoleErrors(consoleErrors);
    });
});
