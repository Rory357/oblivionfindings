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

test.describe('operations rostering — republish flow', () => {
    test.skip(!rosteringFlagsEnabled, rosteringFlagSkipReason);
    test.skip(
        ({ viewport }) => !viewport || viewport.width < 1024,
        'Mutating rostering flow is desktop-only',
    );

    test('manager sees a dirty roster diff and republishes it', async ({
        page,
    }) => {
        const consoleErrors = collectConsoleErrors(page);

        resetRosteringReadinessFixtures();
        await loginAsStaff(page);
        await publishCurrentWeek(page, ROSTERING_DEMO_PUBLISH_TARGET);

        await page.goto('/operations/shifts/9101');
        await page.getByRole('button', { name: /^Edit shift$/ }).click();
        await expect(
            page.getByRole('heading', { name: 'Edit shift', exact: true }),
        ).toBeVisible();

        const editDialog = page.getByRole('dialog', { name: /Edit shift/i });
        await editDialog.getByRole('button', { name: /^Schedule\b/i }).click();
        await editDialog.getByLabel(/^Start/).fill('2026-05-04T09:30');
        await editDialog.getByLabel(/^End/).fill('2026-05-04T12:30');
        await editDialog.getByRole('button', { name: /^Review\b/i }).click();
        const updateResponse = page.waitForResponse(
            (response) =>
                response.url().endsWith('/operations/shifts/9101') &&
                response.request().method() === 'PUT',
        );
        await editDialog
            .getByRole('button', { name: /^Save changes$/ })
            .click();
        expect((await updateResponse).status()).toBe(303);
        await expect(editDialog).not.toBeVisible();

        await expect(page).toHaveURL(/\/operations\/shifts\/9101(?:\?|$)/);
        await page.goto(
            `/operations/rostering?week=${ROSTERING_DEMO_PUBLISH_TARGET.week}&site_id=${ROSTERING_DEMO_PUBLISH_TARGET.siteId}`,
        );

        const publishPanel = page.getByTestId('rostering-publish-panel');
        await expect(publishPanel).toContainText(/changed after publish/i);
        await page.getByRole('link', { name: /View diff/i }).click();

        await expect(
            page.getByRole('heading', { name: /Publish diff/i }),
        ).toBeVisible();
        await expect(page.getByText(/Changed/i).first()).toBeVisible();
        await expect(page.getByText(/Rostering/i).first()).toBeVisible();

        await page.getByRole('button', { name: /Re-publish/i }).click();
        await expect(page).toHaveURL(/\/operations\/rostering(?:\?|$)/);
        await expect(publishPanel).toContainText(/published/i);
        await expect(publishPanel).not.toContainText(/changed after publish/i);

        expectNoConsoleErrors(consoleErrors);
    });
});
