import AxeBuilder from '@axe-core/playwright';
import { expect, test, type Page } from '@playwright/test';

import {
    collectConsoleErrors,
    loginAsMedsDemoWorker,
    resetMedicationReadinessFixtures,
} from './helpers';

function expectNoUnexpectedConsoleErrors(errors: string[]) {
    expect(
        errors.filter(
            (error) =>
                !error.includes('net::ERR_INTERNET_DISCONNECTED') &&
                error !== 'Network Error',
        ),
    ).toEqual([]);
}

async function expectNoBlockingAxeViolations(page: Page) {
    const results = await new AxeBuilder({ page })
        .withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'])
        .analyze();

    const blocking = results.violations.filter((violation) =>
        ['serious', 'critical'].includes(violation.impact ?? ''),
    );

    expect(
        blocking,
        `axe found blocking violations:\n${blocking
            .map(
                (v) =>
                    `  - [${v.impact}] ${v.id}: ${v.help} (${v.nodes.length} nodes)`,
            )
            .join('\n')}`,
    ).toEqual([]);
}

async function openClientProfileFromMyDay(page: Page) {
    await loginAsMedsDemoWorker(page);
    await page.goto('/my-day');

    const careAction = page
        .locator('[data-test="my-day-shift-care-action"]:visible')
        .first();
    await expect(careAction).toBeVisible();
    await expect(careAction).toHaveAttribute('href', /\/clients\/\d+$/);
    await careAction.click();

    await page.waitForURL(/\/clients\/\d+(?:\?.*)?$/, { timeout: 30_000 });
    await expect(
        page.getByRole('heading', { name: 'Playwright Meds', level: 1 }),
    ).toBeVisible();
}

test.describe('canonical client profile care readiness', () => {
    // Fixture setup, sign-in and canonical redirects precede the care/axe checks.
    // CI traces show correct results arriving at the previous whole-test limit.
    test.setTimeout(60_000);
    test.beforeEach(async ({ context }) => {
        resetMedicationReadinessFixtures();
        await context.setOffline(false);
    });

    test('assigned worker reaches the canonical client profile from My Day', async ({
        page,
    }) => {
        const consoleErrors = collectConsoleErrors(page);

        await openClientProfileFromMyDay(page);

        await expect(
            page.getByRole('heading', { name: 'Playwright Meds', level: 1 }),
        ).toBeVisible();

        expectNoUnexpectedConsoleErrors(consoleErrors);
    });

    test('assigned worker can review the client risk record without a duplicate care store', async ({
        page,
    }) => {
        const consoleErrors = collectConsoleErrors(page);

        await openClientProfileFromMyDay(page);
        const profileUrl = new URL(page.url());
        await page.goto(`${profileUrl.pathname}?tab=risk_management`);

        const riskPanel = page.getByRole('tabpanel', {
            name: 'Risk Management',
        });
        await expect(riskPanel).toBeVisible();
        await expect(riskPanel.getByText('Active risks')).toBeVisible();
        await expect(
            riskPanel.getByText('PW Meds active mobility risk'),
        ).toBeVisible();
        await expect(
            riskPanel.getByText('Use two-person support for transfers.'),
        ).toBeVisible();

        expectNoUnexpectedConsoleErrors(consoleErrors);
    });

    test('client profile keeps medication care on the canonical MAR surface', async ({
        page,
    }) => {
        const consoleErrors = collectConsoleErrors(page);

        await openClientProfileFromMyDay(page);
        const profileUrl = new URL(page.url());
        const clientId = profileUrl.pathname.split('/').at(-1)!;
        const profileReturn = `${profileUrl.pathname}?tab=mar`;
        const dayResponse = page.waitForResponse(
            (response) =>
                new URL(response.url()).pathname ===
                    `/emar/clients/${clientId}/day` && response.ok(),
        );
        await page.goto(profileReturn);
        const day = await (await dayResponse).json();

        await expect(
            page.getByRole('heading', { name: 'Medication', exact: true }),
        ).toBeVisible();
        await expect(
            page.getByText('PW Meds Morning Tablets', { exact: true }).first(),
        ).toBeVisible();
        const chartLink = page.getByRole('link', {
            name: 'Open MAR chart',
            exact: true,
        });
        await expect(chartLink).toBeVisible();
        const chartUrl = new URL(
            (await chartLink.getAttribute('href'))!,
            page.url(),
        );
        expect(chartUrl.origin).toBe(profileUrl.origin);
        expect(chartUrl.pathname).toBe('/emar/mar');
        expect(Object.fromEntries(chartUrl.searchParams)).toEqual({
            client_id: clientId,
            date: day.date,
            return_to: profileReturn,
        });
        await chartLink.click();
        await expect(page).toHaveURL(chartUrl.href);
        await page.getByRole('link', { name: 'Back', exact: true }).click();
        await expect(page).toHaveURL(new URL(profileReturn, profileUrl).href);
        await expect(
            page.getByRole('heading', { name: 'Medication', exact: true }),
        ).toBeVisible();

        expectNoUnexpectedConsoleErrors(consoleErrors);
    });

    test('care page has no serious or critical axe violations', async ({
        page,
    }) => {
        const consoleErrors = collectConsoleErrors(page);

        await openClientProfileFromMyDay(page);

        await expectNoBlockingAxeViolations(page);
        expectNoUnexpectedConsoleErrors(consoleErrors);
    });
});
