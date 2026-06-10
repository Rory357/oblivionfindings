import { expect, test, type Page } from '@playwright/test';

import {
    collectConsoleErrors,
    loginAsMedsDemoWorker,
    loginAsRestrictedMedsWorker,
    resetMedicationReadinessFixtures,
} from './helpers';

function expectNoUnexpectedConsoleErrors(errors: string[]) {
    expect(
        errors.filter(
            (error) =>
                !error.includes('net::ERR_INTERNET_DISCONNECTED') &&
                !error.includes(
                    'the server responded with a status of 403 (Forbidden)',
                ) &&
                error !== 'Network Error',
        ),
    ).toEqual([]);
}

/** Open the PRN wizard and advance past the "Choose med" step. */
async function openPrnSheetFor(
    page: Page,
    medicationName: string,
) {
    await page.getByTestId('meds-prn-button').click();
    await page
        .getByRole('button', {
            name: new RegExp(`Record as-needed dose of ${medicationName}`, 'i'),
        })
        .click();
    await page.getByTestId('meds-prn-continue').click();
}

/** Pick a reason chip, walk the remaining wizard steps, and sign. */
async function recordPrnReason(page: Page, reason: string) {
    await page.getByRole('button', { name: reason, exact: true }).click();
    await page.getByTestId('meds-prn-continue').click(); // → Dose & time
    await page.getByTestId('meds-prn-continue').click(); // → Review & sign
    await page.getByTestId('meds-prn-submit').click();
}

async function openGuidedRound(page: Page) {
    await loginAsMedsDemoWorker(page);
    await page.goto('/meds/today');

    await page
        .getByRole('link', {
            name: /(Start|Resume) PW Meds Readiness Round/i,
        })
        .first()
        .click();
    // The meds board also names fixture meds (overdue strip, schedule rows),
    // so wait for the guided page before asserting on medication names.
    await page.waitForURL(/\/emar\/rounds\/\d+\/guided/);
}

async function recordCurrentRoundItem(
    page: Page,
    action: 'given' | 'refused' | 'held',
    reason?: string,
) {
    const testId =
        action === 'given'
            ? 'meds-round-given'
            : action === 'refused'
              ? 'meds-round-refused'
              : 'meds-round-held';

    await page.getByTestId(testId).click();

    if (reason) {
        await page.getByRole('textbox').fill(reason);
    }

    await page.getByRole('button', { name: /^Confirm$/ }).click();
    await expect(page.getByRole('dialog')).toBeHidden({ timeout: 15_000 });
}

async function openMarFromMedsHome(page: Page) {
    await loginAsMedsDemoWorker(page);
    await page.goto('/meds/today');

    const marUrl = await page
        .getByTestId('meds-due-row')
        .filter({ hasText: 'PW Meds Morning Tablets' })
        .locator('a[href*="/emar/mar"]')
        .first()
        .getAttribute('href');

    expect(marUrl).toBeTruthy();
    await page.goto(marUrl!);
}

test.describe('meds readiness workflows', () => {
    test.beforeEach(async ({ context }) => {
        resetMedicationReadinessFixtures();
        await context.setOffline(false);
    });

    test('worker meds home exposes due rows, PRN, and guided round entry', async ({
        page,
    }, testInfo) => {
        const consoleErrors = collectConsoleErrors(page);

        await loginAsMedsDemoWorker(page);
        await page.goto('/meds/today');

        await expect(page.getByTestId('meds-due-row').first()).toBeVisible();
        // The med name can appear in both the overdue strip and the schedule
        // table — any visible mention satisfies this readiness check.
        await expect(
            page.getByText('PW Meds Morning Tablets').first(),
        ).toBeVisible();
        // The fixtures seed overdue doses, so the sidebar "Meds today" item
        // carries its critical overdue badge (sidebar is desktop chrome).
        if (!testInfo.project.name.includes('mobile')) {
            await expect(
                page.getByTestId('sidebar-badge-meds-today'),
            ).toBeVisible();
        }
        await expect(page.getByTestId('meds-prn-button')).toBeEnabled();
        await expect(
            page
                .getByRole('link', {
                    name: /(Start|Resume) PW Meds Readiness Round/i,
                })
                .first(),
        ).toBeVisible();

        expectNoUnexpectedConsoleErrors(consoleErrors);
    });

    test('worker can record a PRN online and see the count update', async ({
        page,
    }) => {
        const consoleErrors = collectConsoleErrors(page);

        await loginAsMedsDemoWorker(page);
        await page.goto('/meds/today');

        await openPrnSheetFor(page, 'PW Meds PRN Paracetamol');
        await recordPrnReason(page, 'Pain');

        await expect(page.getByRole('dialog')).toBeHidden({ timeout: 15_000 });

        await page.getByTestId('meds-prn-button').click();
        await expect(
            page
                .getByRole('button', {
                    name: /Record as-needed dose of PW Meds PRN Paracetamol/i,
                })
                .filter({ hasText: /1\/4 (today|in 24h)/i }),
        ).toBeVisible();

        expectNoUnexpectedConsoleErrors(consoleErrors);
    });

    test('worker can queue a PRN locally while offline', async ({
        page,
        context,
    }) => {
        const consoleErrors = collectConsoleErrors(page);

        await loginAsMedsDemoWorker(page);
        await page.goto('/meds/today');

        await openPrnSheetFor(page, 'PW Meds PRN Paracetamol');

        await context.setOffline(true);
        await expect(page.getByRole('status')).toHaveText(/offline/i);

        await recordPrnReason(page, 'Pain');

        await expect(page.getByRole('status')).toHaveText(
            /1 item will send|1 item waiting/i,
        );

        await context.setOffline(false);
        await page.evaluate(() => window.dispatchEvent(new Event('online')));

        await expect(
            page
                .locator('[role="status"]')
                .filter({ hasText: /offline|item|sending|syncing/i }),
        ).toBeHidden({ timeout: 20_000 });

        await page.reload();
        await page.getByTestId('meds-prn-button').click();
        await expect(
            page
                .getByRole('button', {
                    name: /Record as-needed dose of PW Meds PRN Paracetamol/i,
                })
                .filter({ hasText: /1\/4 (today|in 24h)/i }),
        ).toBeVisible();

        expectNoUnexpectedConsoleErrors(consoleErrors);
    });

    test('guided round shell is ready for recording', async ({ page }) => {
        const consoleErrors = collectConsoleErrors(page);

        await loginAsMedsDemoWorker(page);
        await page.goto('/meds/today');

        await page
            .getByRole('link', {
                name: /(Start|Resume) PW Meds Readiness Round/i,
            })
            .first()
            .click();
        await page.waitForURL(/\/emar\/rounds\/\d+\/guided/);

        await expect(page.getByText('PW Meds Morning Tablets')).toBeVisible();
        await expect(page.getByTestId('meds-round-given')).toBeVisible();
        await expect(page.getByTestId('meds-round-refused')).toBeVisible();
        await expect(page.getByTestId('meds-round-held')).toBeVisible();

        expectNoUnexpectedConsoleErrors(consoleErrors);
    });

    test('guided round can be walked to completion', async ({ page }) => {
        const consoleErrors = collectConsoleErrors(page);

        await openGuidedRound(page);

        await expect(page.getByText('PW Meds Morning Tablets')).toBeVisible();
        await recordCurrentRoundItem(page, 'given');

        await expect(page.getByText('PW Meds Vitamin D')).toBeVisible();
        await recordCurrentRoundItem(page, 'refused', 'Client declined.');

        await expect(page.getByText('PW Meds Eye Drops')).toBeVisible();
        await recordCurrentRoundItem(page, 'held', 'Held pending review.');

        await expect(
            page.getByRole('heading', { name: 'Round complete' }),
        ).toBeVisible();
        await expect(
            page.getByText('Every dose in this round has been recorded.'),
        ).toBeVisible();

        await page.getByRole('button', { name: /Finish round/i }).click();
        await expect(page.getByText('Completed').first()).toBeVisible({
            timeout: 15_000,
        });

        expectNoUnexpectedConsoleErrors(consoleErrors);
    });

    test('guided round queued item syncs after reconnect', async ({
        page,
        context,
    }) => {
        const consoleErrors = collectConsoleErrors(page);

        await openGuidedRound(page);

        await expect(page.getByText('PW Meds Morning Tablets')).toBeVisible();
        await recordCurrentRoundItem(page, 'given');

        await expect(page.getByText('PW Meds Vitamin D')).toBeVisible();
        await context.setOffline(true);
        await expect(page.getByRole('status')).toHaveText(/offline/i);

        await recordCurrentRoundItem(page, 'given');
        await expect(page.getByRole('status')).toHaveText(
            /1 item will send|1 item waiting/i,
        );

        await context.setOffline(false);
        await page.evaluate(() => window.dispatchEvent(new Event('online')));

        await expect(
            page
                .locator('[role="status"]')
                .filter({ hasText: /offline|item|sending|syncing/i }),
        ).toBeHidden({ timeout: 20_000 });

        await page.reload();
        await expect(page.getByText('2 of 3 done')).toBeVisible();
        await expect(page.getByText('PW Meds Eye Drops')).toBeVisible();

        expectNoUnexpectedConsoleErrors(consoleErrors);
    });

    test('canonical MAR supports row detail selection', async ({
        page,
    }, testInfo) => {
        const consoleErrors = collectConsoleErrors(page);

        await openMarFromMedsHome(page);

        if (testInfo.project.name.includes('mobile')) {
            const hasHorizontalScroll = await page.evaluate(
                () =>
                    document.documentElement.scrollWidth >
                    document.documentElement.clientWidth + 1,
            );
            expect(hasHorizontalScroll).toBe(false);
        }

        const firstRow = page
            .getByTestId('mar-row')
            .filter({ hasText: 'PW Meds Morning Tablets' })
            .first();
        await expect(firstRow).toBeVisible();
        await firstRow.click();

        await expect(
            page
                .getByTestId('mar-detail-pane')
                .filter({ hasText: /PW Meds/i })
                .last(),
        ).toBeVisible();

        if (!testInfo.project.name.includes('mobile')) {
            await firstRow.focus();
            await firstRow.press('ArrowDown');
            await expect(page.locator('[data-test="mar-row"]:focus')).toHaveCount(
                1,
            );
            await page.locator('[data-test="mar-row"]:focus').press('Enter');
            await expect(
                page.getByRole('dialog', { name: /Record Administration/i }),
            ).toBeVisible();
        }

        expectNoUnexpectedConsoleErrors(consoleErrors);
    });

    test('controlled PRN requires a witness before recording', async ({
        page,
    }) => {
        test.slow();

        const consoleErrors = collectConsoleErrors(page);

        await openMarFromMedsHome(page);

        const controlledRow = page
            .getByTestId('mar-row')
            .filter({ hasText: 'PW Meds Controlled PRN' })
            .first();
        await expect(controlledRow).toBeVisible();
        await controlledRow.getByRole('button', { name: /^Give$/ }).click();

        const dialog = page.getByTestId('record-administration-dialog');
        const submitButton = page.getByTestId('record-administration-submit');
        await expect(dialog).toBeVisible();
        await expect(submitButton).toBeDisabled();

        await dialog.getByTestId('record-administration-scan-code').click();
        await dialog.getByTestId('record-administration-scan-verify').click();
        await expect(
            dialog.getByText(/code verified for this medication/i),
        ).toBeVisible({ timeout: 15_000 });
        await expect(submitButton).toBeDisabled();

        await dialog.getByPlaceholder('Why is this PRN being given?').fill(
            'Severe pain',
        );
        await dialog.getByText('Select witness...').click();
        await page
            .getByRole('option', { name: /Medication Demo Witness/i })
            .click();
        // The second checker confirms with their own password before the
        // dose can be saved (EnhancedMarService witness credential rule).
        await dialog.locator('input[type="password"]').fill('password');
        await expect(submitButton).toBeEnabled();
        await submitButton.click();

        await expect(dialog).toBeHidden({ timeout: 15_000 });

        expectNoUnexpectedConsoleErrors(consoleErrors);
    });

    test('worker without med-record permission is denied', async ({ page }) => {
        const consoleErrors = collectConsoleErrors(page);

        await loginAsRestrictedMedsWorker(page);
        const response = await page.goto('/meds/today');

        expect(response?.status()).toBe(403);
        await expect(page.getByRole('heading', { name: '403' })).toBeVisible();

        expectNoUnexpectedConsoleErrors(consoleErrors);
    });
});
