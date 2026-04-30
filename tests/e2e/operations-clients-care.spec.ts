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

async function openCareFromMyDay(page: Page) {
    await loginAsMedsDemoWorker(page);
    await page.goto('/my-day');

    const careAction = page
        .locator('[data-test="my-day-shift-care-action"]:visible')
        .first();
    await expect(careAction).toBeVisible();
    await careAction.click();

    await expect(page).toHaveURL(/\/operations\/clients\/\d+\/care/);
    await expect(page.getByTestId('client-care-page')).toBeVisible();
}

test.describe('operations client care readiness', () => {
    test.beforeEach(async ({ context }) => {
        resetMedicationReadinessFixtures();
        await context.setOffline(false);
    });

    test('care page loads core client, safety, risk, and PRN surfaces', async ({
        page,
    }) => {
        const consoleErrors = collectConsoleErrors(page);

        await openCareFromMyDay(page);

        await expect(
            page.getByRole('heading', { name: /PW Meds|Playwright Meds/i }),
        ).toBeVisible();
        await expect(
            page.locator('section[aria-label="Client safety information"]'),
        ).toBeVisible();
        await expect(page.getByText('Safety information')).toBeVisible();
        await expect(
            page.getByText('Risk: PW Meds active mobility risk'),
        ).toBeVisible();
        await expect(
            page.getByText('Use two-person support for transfers.'),
        ).toBeVisible();
        await expect(page.getByTestId('client-care-prn-button')).toBeEnabled();

        expectNoUnexpectedConsoleErrors(consoleErrors);
    });

    test('worker can open the care PRN sheet and submit a dose', async ({
        page,
    }) => {
        const consoleErrors = collectConsoleErrors(page);

        await openCareFromMyDay(page);

        await page.getByTestId('client-care-prn-button').click();
        await page
            .getByRole('button', {
                name: /Record as-needed dose of PW Meds PRN Paracetamol/i,
            })
            .click();
        await page.getByRole('radio', { name: 'Pain' }).click();
        await page.getByTestId('meds-prn-submit').click();

        await expect(page.getByRole('dialog')).toBeHidden({ timeout: 15_000 });

        expectNoUnexpectedConsoleErrors(consoleErrors);
    });

    test('my-day shift card preserves roster primary link and adds care action', async ({
        page,
    }) => {
        const consoleErrors = collectConsoleErrors(page);

        await loginAsMedsDemoWorker(page);
        await page.goto('/my-day');

        const careAction = page
            .locator('[data-test="my-day-shift-care-action"]:visible')
            .first();
        await expect(careAction).toHaveAttribute(
            'href',
            /\/operations\/clients\/\d+\/care/,
        );

        const card = careAction.locator(
            'xpath=ancestor::*[@data-test="my-day-shift-card"][1]',
        );
        await expect(
            card.getByTestId('my-day-shift-primary-link'),
        ).toHaveAttribute('href', /\/my-roster#shift-\d+/);

        await careAction.click();
        await expect(page).toHaveURL(/\/operations\/clients\/\d+\/care/);

        expectNoUnexpectedConsoleErrors(consoleErrors);
    });

    test('care page has no serious or critical axe violations', async ({
        page,
    }) => {
        const consoleErrors = collectConsoleErrors(page);

        await openCareFromMyDay(page);

        await expectNoBlockingAxeViolations(page);
        expectNoUnexpectedConsoleErrors(consoleErrors);
    });
});
