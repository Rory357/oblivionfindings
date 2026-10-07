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
    // These fixture dates and staff availability are in the house's NZ time.
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
        const eligibilityResponse = page.waitForResponse((response) => {
            const url = new URL(response.url());
            return (
                url.pathname === '/operations/shifts/eligibility-preview' &&
                Date.parse(url.searchParams.get('starts_at') ?? '') ===
                    Date.parse('2026-05-03T21:30:00Z') &&
                Date.parse(url.searchParams.get('ends_at') ?? '') ===
                    Date.parse('2026-05-04T00:30:00Z')
            );
        });
        await editDialog.getByLabel(/^Start/).fill('2026-05-04T09:30');
        await editDialog.getByLabel(/^End/).fill('2026-05-04T12:30');
        await editDialog.getByRole('button', { name: /^Review\b/i }).click();
        const eligibility = await eligibilityResponse;
        expect(eligibility.ok()).toBe(true);
        expect(await eligibility.json()).toMatchObject({
            is_allowed: true,
            blocked_reasons: [],
            warning_reasons: [],
        });
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
        // Reload and re-open from server props: a 303 alone is not a save.
        await page.reload();
        await page.getByRole('button', { name: /^Edit shift$/ }).click();
        await editDialog.getByRole('button', { name: /^Schedule\b/i }).click();
        await expect(editDialog.getByLabel(/^Start/)).toHaveValue(
            '2026-05-04T09:30',
        );
        await expect(editDialog.getByLabel(/^End/)).toHaveValue(
            '2026-05-04T12:30',
        );
        await editDialog.getByRole('button', { name: /^Cancel$/ }).click();
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
        // Publishing redirects through the governed roster read on CI's single PHP worker.
        await page.waitForURL(/\/operations\/rostering(?:\?|$)/, {
            timeout: 30_000,
        });
        await expect(publishPanel).toContainText(/published/i);
        await expect(publishPanel).not.toContainText(/changed after publish/i);

        expectNoConsoleErrors(consoleErrors);
    });

    test('a server eligibility warning keeps the unsaved edit open', async ({
        page,
    }) => {
        test.setTimeout(90_000);
        const consoleErrors = collectConsoleErrors(page);
        resetRosteringReadinessFixtures();
        await loginAsStaff(page);
        await page.goto('/operations/shifts/9101');
        await page.getByRole('button', { name: /^Edit shift$/ }).click();
        const editDialog = page.getByRole('dialog', { name: /Edit shift/i });
        await editDialog.getByRole('button', { name: /^Schedule\b/i }).click();

        // Simulate availability changing after a successful preview. The real
        // update endpoint must still reject this unacknowledged overnight warning.
        let stalePreviewSent = false;
        await page.route(
            '**/operations/shifts/eligibility-preview?**',
            async (route) => {
                const url = new URL(route.request().url());
                if (
                    !stalePreviewSent &&
                    Date.parse(url.searchParams.get('ends_at') ?? '') ===
                        Date.parse('2026-05-04T12:30:00Z')
                ) {
                    stalePreviewSent = true;
                    await route.fulfill({
                        json: {
                            is_allowed: true,
                            blocked_reasons: [],
                            warning_reasons: [],
                        },
                    });
                } else {
                    await route.continue();
                }
            },
        );
        await editDialog.getByLabel(/^Start/).fill('2026-05-04T21:30');
        await editDialog.getByLabel(/^End/).fill('2026-05-05T00:30');
        await editDialog.getByRole('button', { name: /^Review\b/i }).click();
        await expect(
            editDialog.getByRole('button', { name: /^Save changes$/ }),
        ).toBeEnabled();
        expect(stalePreviewSent).toBe(true);
        await editDialog
            .getByRole('button', { name: /^Save changes$/ })
            .click();
        await expect(editDialog.getByText('Fix before saving:')).toBeVisible();
        await expect(
            editDialog.getByText(/Staff not available/).first(),
        ).toBeVisible();
        await expect(
            editDialog.getByText('Staff eligibility warnings'),
        ).toBeVisible();
        await editDialog.getByRole('button', { name: /^Schedule\b/i }).click();
        await expect(editDialog.getByLabel(/^Start/)).toHaveValue(
            '2026-05-04T21:30',
        );
        await expect(editDialog.getByLabel(/^End/)).toHaveValue(
            '2026-05-05T00:30',
        );
        await editDialog.getByRole('button', { name: /^Cancel$/ }).click();

        await page.reload();
        await page.getByRole('button', { name: /^Edit shift$/ }).click();
        await editDialog.getByRole('button', { name: /^Schedule\b/i }).click();
        await expect(editDialog.getByLabel(/^Start/)).toHaveValue(
            '2026-05-04T09:00',
        );
        await expect(editDialog.getByLabel(/^End/)).toHaveValue(
            '2026-05-04T12:00',
        );
        await editDialog.getByRole('button', { name: /^Cancel$/ }).click();
        expectNoConsoleErrors(consoleErrors);
    });
});
