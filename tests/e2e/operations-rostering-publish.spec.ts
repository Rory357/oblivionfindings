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

test.describe('operations rostering — publish flow', () => {
    test.skip(!rosteringFlagsEnabled, rosteringFlagSkipReason);
    test.skip(
        ({ viewport }) => !viewport || viewport.width < 1024,
        'Mutating rostering flow is desktop-only',
    );

    test('manager reviews and publishes a roster period', async ({ page }) => {
        const consoleErrors = collectConsoleErrors(page);

        await loginAsStaff(page);
        await page.goto('/operations/rostering?week=2026-05-04&site_id=9001');

        await expect(page.getByTestId('rostering-publish-panel')).toBeVisible();
        await page.getByTestId('rostering-review-publish').click();

        await expect(page.getByTestId('publish-review-page')).toBeVisible();
        await expect(page.getByText(/Publish review/i).first()).toBeVisible();
        await expect(page.getByTestId('publish-review-confirm')).toBeEnabled();

        await page.getByTestId('publish-review-confirm').click();
        await expect(page).toHaveURL(/\/operations\/rostering(?:\?|$)/);
        await expect(page.getByTestId('rostering-publish-panel')).toContainText(
            /published/i,
        );

        expectNoConsoleErrors(consoleErrors);
    });
});
