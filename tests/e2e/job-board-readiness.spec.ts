import { expect, test } from '@playwright/test';

import {
    collectConsoleErrors,
    expectNoConsoleErrors,
    gotoMyDay,
    loginAsFrontlineDemoWorker,
} from './helpers';

test.describe('job board readiness', () => {
    test('worker can see eligibility feedback and switch to My claims', async ({
        page,
    }) => {
        const consoleErrors = collectConsoleErrors(page);

        await loginAsFrontlineDemoWorker(page);
        await page.goto('/operations/job-board');
        await page.waitForLoadState('domcontentloaded');

        await expect(
            page.getByRole('heading', { name: 'Job Board' }),
        ).toBeVisible();
        await expect(page.getByTestId('job-board-card').first()).toBeVisible();
        await expect(
            page.getByTestId('viewer-eligibility').first(),
        ).toBeVisible();
        await expect(
            page.getByTestId('job-board-claim-button').first(),
        ).toBeVisible();

        await page.getByTestId('job-board-my-claims-tab').click();
        await expect(page).toHaveURL(/\/operations\/job-board\?scope=mine/);
        await expect(page.getByTestId('job-board-card').first()).toBeVisible();
        await expect(page.getByText(/Claimed by:/).first()).toBeVisible();

        expectNoConsoleErrors(consoleErrors);
    });

    test('My Day links pending claims to the job board claims view', async ({
        page,
    }) => {
        const consoleErrors = collectConsoleErrors(page);

        await loginAsFrontlineDemoWorker(page);
        await gotoMyDay(page);

        const pendingClaimsLink = page.locator(
            '[data-test="pending-claims-link"]:visible',
        );

        await expect(pendingClaimsLink).toBeVisible();
        await expect(pendingClaimsLink).toContainText(/Pending claims \(\d+\)/);

        await pendingClaimsLink.click();
        await expect(page).toHaveURL(/\/operations\/job-board\?scope=mine/);

        expectNoConsoleErrors(consoleErrors);
    });

    test('worker can filter the board without seeing sensitive request copy', async ({
        page,
    }) => {
        const consoleErrors = collectConsoleErrors(page);

        await loginAsFrontlineDemoWorker(page);
        await page.goto('/operations/job-board');
        await page.waitForLoadState('domcontentloaded');

        await expect(page.getByTestId('job-board-skill-filter')).toBeVisible();
        await page.getByTestId('job-board-skill-filter').click();
        await page.getByRole('option', { name: 'NZSL' }).click();
        await expect(page).toHaveURL(/skill=NZSL/);

        await page.getByTestId('job-board-date-filter').click();
        await page.getByRole('option', { name: 'Next 7 Days' }).click();
        await expect(page).toHaveURL(/date_range=next_7_days/);

        const firstCard = page.getByTestId('job-board-card').first();
        await expect(firstCard).toBeVisible();
        await expect(firstCard).toContainText('NZSL');
        await expect(firstCard).not.toContainText(
            'Playwright open job board cover',
        );
        await expect(firstCard).not.toContainText('Covering for:');

        expectNoConsoleErrors(consoleErrors);
    });
});
