import { expect, test, type Page } from '@playwright/test';

import {
    collectConsoleErrors,
    expectNoConsoleErrors,
    loginAs,
    loginAsStaff,
    seedGovernancePrivacyConsentsReadinessFixtures,
} from './helpers';

async function selectOption(
    page: Page,
    triggerTestId: string,
    optionName: RegExp,
) {
    await page.getByTestId(triggerTestId).click();
    await page.getByRole('option', { name: optionName }).click();
}

async function clearSession(page: Page) {
    await page.context().clearCookies();
    await page.evaluate(() => {
        localStorage.clear();
        sessionStorage.clear();
    });
}

test.describe('operations client consent-request readiness', () => {
    test.setTimeout(90_000);

    test('staff creates a consent request and the portal recipient approves it', async ({
        page,
    }) => {
        const fixture = seedGovernancePrivacyConsentsReadinessFixtures();
        const consoleErrors = collectConsoleErrors(page);

        await loginAsStaff(page);
        await page.goto(
            `/operations/clients/${fixture.clientId}/consent-requests`,
        );

        await expect(page.getByTestId('consent-requests-index')).toBeVisible();
        await page.getByTestId('consent-request-create-link').click();

        await expect(
            page.getByTestId('consent-request-create-form'),
        ).toBeVisible();
        await selectOption(
            page,
            'consent-type-select',
            /Playwright Location Tracking Consent/i,
        );
        await selectOption(
            page,
            'consent-recipient-select',
            /Playwright Consent Guardian/i,
        );
        await selectOption(
            page,
            'consent-relationship-select',
            /Welfare Guardian/i,
        );
        await page
            .getByTestId('consent-purpose-input')
            .fill(
                'Playwright readiness flow for a personal tracker consent request.',
            );
        await page
            .getByTestId('consent-data-scope-input')
            .fill('Care team and on-call coordinator');
        await page.getByTestId('consent-retention-days-input').fill('180');
        await page
            .getByTestId('consent-staff-notes-input')
            .fill('Created by Playwright readiness coverage.');
        await page.getByTestId('consent-request-submit').click();

        await expect(page).toHaveURL(
            new RegExp(
                `/operations/clients/${fixture.clientId}/consent-requests$`,
            ),
        );
        await expect(page.getByTestId('consent-request-row')).toContainText(
            'Playwright Location Tracking Consent',
        );
        await expect(page.getByTestId('consent-request-status')).toContainText(
            'pending',
        );

        await clearSession(page);
        await loginAs(page, fixture.portalEmail, 'password');
        await page.goto(`/portal/clients/${fixture.clientId}/dashboard`);

        await expect(
            page.getByTestId('portal-consent-requests-card'),
        ).toBeVisible();
        await page
            .getByTestId('portal-consent-request-row')
            .getByRole('link', { name: 'Review' })
            .click();

        await expect(
            page.getByTestId('portal-consent-request-show'),
        ).toBeVisible();
        await page.getByTestId('portal-consent-approve-open').click();
        await page
            .getByTestId('portal-consent-approve-notes')
            .fill('Approved through Playwright readiness coverage.');
        await page.getByTestId('portal-consent-authority-checkbox').click();
        await page.getByTestId('portal-consent-approve-submit').click();

        await expect(page).toHaveURL(
            new RegExp(`/portal/clients/${fixture.clientId}/dashboard$`),
        );
        await expect(
            page.getByTestId('portal-consent-requests-card'),
        ).toHaveCount(0);

        await clearSession(page);
        await loginAsStaff(page);
        await page.goto(
            `/operations/clients/${fixture.clientId}/consent-requests`,
        );

        await expect(page.getByTestId('consent-request-status')).toContainText(
            'approved',
        );
        const showHref = await page
            .getByTestId('consent-request-view-link')
            .getAttribute('href');
        expect(showHref).toMatch(
            new RegExp(
                `/operations/clients/${fixture.clientId}/consent-requests/\\d+$`,
            ),
        );
        await page.goto(showHref ?? '');
        await expect(page.getByTestId('consent-request-show')).toBeVisible();
        await expect(
            page.getByTestId('consent-request-show-status'),
        ).toContainText('approved');
        await expect(
            page.getByRole('link', { name: /View consent record/i }),
        ).toBeVisible();

        expectNoConsoleErrors(consoleErrors);
    });
});
