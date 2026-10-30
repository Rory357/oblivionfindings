import { expect, test } from '@playwright/test';

import {
    collectConsoleErrors,
    expectNoConsoleErrors,
    loginAsStaff,
    resetRosteringReadinessFixtures,
} from './helpers';

test.describe('operations shifts — detail assignment flow', () => {
    test.skip(
        ({ viewport }) => !viewport || viewport.width < 1024,
        'Shift assignment management is desktop-only',
    );

    test('manager opens an unassigned shift and assigns an eligible worker', async ({
        page,
    }) => {
        // Fixture setup, sign-in and the assignment redirect share the single
        // PHP worker in CI. Keep individual UI assertions at their normal limit.
        test.setTimeout(60_000);
        const consoleErrors = collectConsoleErrors(page);

        resetRosteringReadinessFixtures({ assignmentShiftStatus: 'draft' });
        await loginAsStaff(page);
        await page.goto(
            '/operations/shifts?from=2026-05-11&to=2026-05-12&assigned=unassigned&q=Rostering',
        );

        await expect(
            page.getByRole('heading', {
                name: 'Shifts',
                level: 1,
                exact: true,
            }),
        ).toBeVisible();
        await page.getByTestId('shift-row-9201').click();
        const quickView = page.getByRole('dialog', {
            name: /Rostering Publish/i,
        });
        await expect(quickView).toBeVisible();
        await quickView.getByRole('link', { name: /Open full view/i }).click();
        await expect(page).toHaveURL(/\/operations\/shifts\/9201/);
        await expect(page.getByText(/Unassigned/i).first()).toBeVisible();

        await page.getByRole('tab', { name: /Assignment/i }).click();
        await expect(page.getByText(/Suggested cover/i)).toBeVisible();

        const candidateCard = page
            .getByText('Rostering E2E Candidate', { exact: true })
            .locator(
                'xpath=ancestor::div[contains(concat(" ", normalize-space(@class), " "), " rounded-lg ")][1]',
            );
        await expect(candidateCard).toBeVisible();
        await expect(candidateCard).toContainText(/Eligible|Warning|Score/i);
        const assignmentPath = '/operations/shifts/9201/assign';
        const detailPath = '/operations/shifts/9201';
        const [assignmentResponse, refreshedShift] = await Promise.all([
            page.waitForResponse(
                (response) =>
                    new URL(response.url()).pathname === assignmentPath &&
                    response.request().method() === 'POST',
            ),
            page.waitForResponse((response) => {
                const request = response.request();
                const redirectedFrom = request.redirectedFrom();

                return (
                    new URL(response.url()).pathname === detailPath &&
                    request.method() === 'GET' &&
                    redirectedFrom?.method() === 'POST' &&
                    new URL(redirectedFrom.url()).pathname === assignmentPath
                );
            }),
            candidateCard.getByRole('button', { name: /^Assign$/ }).click(),
        ]);
        expect(assignmentResponse.status()).toBe(302);
        expect(refreshedShift.status()).toBe(200);
        const { props } = await refreshedShift.json();
        expect(props.errors).toEqual({});
        expect(props.shift).toMatchObject({
            id: 9201,
            status: 'scheduled',
            staff: { name: 'Rostering E2E Candidate' },
        });

        await expect(page).toHaveURL(/\/operations\/shifts\/9201/);
        await expect(
            page.getByText('Rostering E2E Candidate').first(),
        ).toBeVisible();
        await expect(page.getByText(/Scheduled/i).first()).toBeVisible();

        await page.getByRole('tab', { name: /Audit history/i }).click();
        await expect(
            page.getByText('Shift assigned', { exact: true }),
        ).toBeVisible();

        expectNoConsoleErrors(consoleErrors);
    });
});
