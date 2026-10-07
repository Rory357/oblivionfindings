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
    // The fixture's declared availability and entered wall times are Auckland-local.
    test.use({ timezoneId: 'Pacific/Auckland' });
    test.skip(!rosteringFlagsEnabled, rosteringFlagSkipReason);
    test.skip(
        ({ viewport }) => !viewport || viewport.width < 1024,
        'Mutating rostering flow is desktop-only',
    );

    test('manager sees a dirty roster diff and republishes it', async ({
        page,
    }) => {
        // Two governed publishes, a persisted shift edit and several redirected
        // reads run against the same single-worker PHP server in CI.
        test.setTimeout(90_000);

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
        const savedShiftResponse = page.waitForResponse(
            (response) =>
                new URL(response.url()).pathname ===
                    '/operations/shifts/9101' &&
                response.request().method() === 'GET' &&
                response.request().headers()['x-inertia'] === 'true' &&
                (response.headers()['content-type'] ?? '').includes(
                    'application/json',
                ),
            { timeout: 30_000 },
        );
        await editDialog
            .getByRole('button', { name: /^Save changes$/ })
            .click();
        expect((await updateResponse).status()).toBe(303);
        const savedShift = await (await savedShiftResponse).json();
        expect(savedShift.component).toBe('operations/shifts/show');
        expect(savedShift.props.shift.id).toBe(9101);
        expect(savedShift.props.flash.success).toBe('Shift updated.');
        expect(new Date(savedShift.props.shift.starts_at).toISOString()).toBe(
            '2026-05-03T21:30:00.000Z',
        );
        expect(new Date(savedShift.props.shift.ends_at).toISOString()).toBe(
            '2026-05-04T00:30:00.000Z',
        );
        expect(savedShift.props.shift.publish_dirty_at).not.toBeNull();
        await expect(editDialog).not.toBeVisible();

        await expect(page).toHaveURL(/\/operations\/shifts\/9101(?:\?|$)/);
        await page.goto(
            `/operations/rostering?week=${ROSTERING_DEMO_PUBLISH_TARGET.week}&site_id=${ROSTERING_DEMO_PUBLISH_TARGET.siteId}`,
        );

        const publishPanel = page.getByTestId('rostering-publish-panel');
        await expect(publishPanel).toContainText(/changed after publish/i);
        await page.getByRole('link', { name: /View diff/i }).click();
        await expect(page).toHaveURL(
            /\/operations\/rostering\/periods\/\d+\/diff$/,
        );

        await expect(
            page.getByRole('heading', {
                name: 'Rostering E2E House',
                level: 1,
                exact: true,
            }),
        ).toBeVisible();
        await expect(
            page.getByRole('heading', {
                name: 'All changes',
                level: 2,
                exact: true,
            }),
        ).toBeVisible();
        await expect(page.getByText(/Changed/i).first()).toBeVisible();
        await expect(page.getByText(/Rostering/i).first()).toBeVisible();

        const republishResponse = page.waitForResponse(
            (response) =>
                /\/operations\/rostering\/periods\/\d+\/republish$/.test(
                    new URL(response.url()).pathname,
                ) && response.request().method() === 'POST',
        );
        await page.getByRole('button', { name: /Re-publish/i }).click();
        expect((await republishResponse).status()).toBe(302);
        await expect(page).toHaveURL(/\/operations\/rostering(?:\?|$)/);
        await expect(publishPanel).toContainText(/published/i);
        await expect(publishPanel).not.toContainText(/changed after publish/i);

        expectNoConsoleErrors(consoleErrors);
    });
});
