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

test.describe('roster templates — apply preflight', () => {
    test.skip(!rosteringFlagsEnabled, rosteringFlagSkipReason);
    test.skip(
        ({ viewport }) => !viewport || viewport.width < 1024,
        'Mutating rostering flow is desktop-only',
    );

    test('blocks template rows that would create staff conflicts', async ({
        page,
    }) => {
        const consoleErrors = collectConsoleErrors(page);

        await loginAsStaff(page);
        await page.goto('/operations/rostering/templates/9001');

        await expect(page.getByTestId('template-apply-card')).toBeVisible();
        await page.locator('#week-start').fill('2026-05-25');
        await page.getByTestId('template-apply-submit').click();

        await expect(page.getByTestId('template-apply-blocks')).toBeVisible();
        await expect(
            page.getByText(/Template cannot be applied/i),
        ).toBeVisible();

        expectNoConsoleErrors(consoleErrors);
    });
});
