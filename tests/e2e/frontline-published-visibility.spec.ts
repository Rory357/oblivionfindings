import { expect, test } from '@playwright/test';

import {
    collectConsoleErrors,
    expectNoConsoleErrors,
    gotoMyRoster,
    loginAs,
    loginAsStaff,
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
        const consoleErrors = collectConsoleErrors(page);

        await loginAs(page, 'roster-e2e-frontline@demo.test', 'password');
        await gotoMyRoster(page);
        await expect(page.getByText(/Rostering Frontline/i)).toBeHidden();

        await page.context().clearCookies();
        await loginAsStaff(page);
        await page.goto('/operations/rostering?week=2026-05-04&site_id=9002');
        await page.getByTestId('rostering-review-publish').click();
        await expect(page.getByTestId('publish-review-page')).toBeVisible();
        await page.getByTestId('publish-review-confirm').click();
        await expect(page).toHaveURL(/\/operations\/rostering(?:\?|$)/);

        await page.context().clearCookies();
        await loginAs(page, 'roster-e2e-frontline@demo.test', 'password');
        await gotoMyRoster(page);
        await expect(page.getByText(/Rostering Frontline/i)).toBeVisible();

        expectNoConsoleErrors(consoleErrors);
    });
});
