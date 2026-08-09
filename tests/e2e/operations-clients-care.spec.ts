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

    await expect(page).toHaveURL(/\/clients\/\d+(?:\?.*)?$/);
    await expect(
        page.getByRole('heading', { name: 'Playwright Meds', level: 1 }),
    ).toBeVisible();
}

test.describe('canonical client profile care readiness', () => {
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

        await expect(page.getByText('Active risks')).toBeVisible();
        await expect(
            page.getByText('PW Meds active mobility risk'),
        ).toBeVisible();
        await expect(
            page.getByText('Use two-person support for transfers.'),
        ).toBeVisible();

        expectNoUnexpectedConsoleErrors(consoleErrors);
    });

    test('client profile keeps medication care on the canonical MAR surface', async ({
        page,
    }) => {
        const consoleErrors = collectConsoleErrors(page);

        await openClientProfileFromMyDay(page);
        const profileUrl = new URL(page.url());
        await page.goto(`${profileUrl.pathname}?tab=mar`);

        await expect(page.getByText('Medication administration')).toBeVisible();
        await expect(page.getByText('PW Meds Morning Tablets')).toBeVisible();
        await expect(
            page.getByRole('link', { name: 'Full MAR chart' }).first(),
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
