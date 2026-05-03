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
        const consoleErrors = collectConsoleErrors(page);

        resetRosteringReadinessFixtures({ assignmentShiftStatus: 'draft' });
        await loginAsStaff(page);
        await page.goto(
            '/operations/shifts?from=2026-05-11&to=2026-05-12&assigned=unassigned&q=Rostering',
        );

        await expect(
            page.getByRole('heading', { name: /Shifts/i }),
        ).toBeVisible();
        await page
            .getByRole('row', { name: /Rostering Publish.*Unassigned/i })
            .getByRole('link', { name: 'View' })
            .click();
        await expect(page).toHaveURL(/\/operations\/shifts\/9201/);
        await expect(page.getByText(/Unassigned/i).first()).toBeVisible();

        await page.getByRole('button', { name: /Assignment/i }).click();
        await expect(page.getByText(/Assignment coverage/i)).toBeVisible();

        const candidateCard = page
            .locator('.rounded-md.border')
            .filter({ hasText: 'Rostering E2E Candidate' })
            .first();
        await expect(candidateCard).toBeVisible();
        await expect(candidateCard).toContainText(/Eligible|Warning|Score/i);
        await candidateCard.getByRole('button', { name: /^Assign$/ }).click();

        await expect(page).toHaveURL(/\/operations\/shifts\/9201/);
        await expect(
            page.getByText('Rostering E2E Candidate').first(),
        ).toBeVisible();
        await expect(page.getByText(/Scheduled/i).first()).toBeVisible();

        await page.getByRole('button', { name: /Notes/i }).click();
        await expect(page.getByText(/Shift assigned/i).first()).toBeVisible();

        expectNoConsoleErrors(consoleErrors);
    });
});
