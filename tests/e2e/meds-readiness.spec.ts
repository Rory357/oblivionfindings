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
async function openPrnSheetFor(page: Page, medicationName: string) {
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
    // The canonical desktop round workspace opens its safety-gated guided
    // dialog through the rounds query rather than the retired standalone page.
    await page.waitForURL(/\/emar\/rounds\?.*guided=\d+/);
    await expect(
        page.getByRole('dialog', {
            name: /Guided round · PW Meds Readiness Round/i,
        }),
    ).toBeVisible();
}

async function recordCurrentRoundItem(
    page: Page,
    action: 'given' | 'refused' | 'held',
    reason?: string,
) {
    await page
        .getByRole('checkbox', { name: /Right resident, right medication/i })
        .check();
    await page
        .getByRole('button', {
            name:
                action === 'given'
                    ? 'Given'
                    : action === 'refused'
                      ? 'Refused'
                      : 'Held',
            exact: true,
        })
        .click();

    if (action !== 'given') {
        await page.getByRole('combobox', { name: 'Select reason…' }).click();
        await page
            .getByRole('option', {
                name: action === 'refused' ? 'Refused' : 'Withheld',
                exact: true,
            })
            .click();
        if (reason) {
            await page.getByPlaceholder('Add a note').fill(reason);
        }
    }

    const confirm = page.getByRole('button', { name: /^Confirm$/ });
    await confirm.click();
    await expect(confirm).toBeHidden({ timeout: 15_000 });
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
    test.describe.configure({ timeout: 90_000 });

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
        await page.waitForURL(/\/emar\/rounds\?.*guided=\d+/);

        const guidedDialog = page.getByRole('dialog', {
            name: /Guided round · PW Meds Readiness Round/i,
        });
        await expect(
            guidedDialog.getByText('PW Meds Morning Tablets'),
        ).toBeVisible();
        await expect(
            guidedDialog.getByRole('button', { name: 'Given', exact: true }),
        ).toBeVisible();
        await expect(
            guidedDialog.getByRole('button', { name: 'Refused', exact: true }),
        ).toBeVisible();
        await expect(
            guidedDialog.getByRole('button', { name: 'Held', exact: true }),
        ).toBeVisible();

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
            page.getByText(
                /Every dose in PW Meds Readiness Round has been recorded/,
            ),
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
        await expect(page.getByText('2 of 3 recorded')).toBeVisible();
        await expect(page.getByText('PW Meds Eye Drops')).toBeVisible();

        expectNoUnexpectedConsoleErrors(consoleErrors);
    });

    test('canonical MAR opens the safety-gated dose workflow', async ({
        page,
    }) => {
        const consoleErrors = collectConsoleErrors(page);

        await openMarFromMedsHome(page);

        const doseCell = page
            .getByRole('button', {
                name: /PW Meds Morning Tablets, (Due|Overdue).*record dose/i,
            })
            .first();
        await expect(doseCell).toBeVisible();
        await doseCell.click();

        const dialog = page.getByRole('dialog', { name: /Record dose/i });
        await expect(dialog).toBeVisible();
        await expect(
            dialog.getByRole('heading', { name: 'Safety checks' }),
        ).toBeVisible();
        await expect(
            dialog.getByText('The five rights', { exact: true }),
        ).toBeVisible();

        expectNoUnexpectedConsoleErrors(consoleErrors);
    });

    test('controlled PRN requires a witness before recording', async ({
        page,
    }) => {
        test.slow();

        const consoleErrors = collectConsoleErrors(page);

        await openMarFromMedsHome(page);

        await page.getByRole('tab', { name: 'PRN', exact: true }).click();
        const controlledPrn = page
            .locator('li')
            .filter({ hasText: 'PW Meds Controlled PRN' });
        await expect(controlledPrn).toBeVisible();
        await controlledPrn.getByRole('button', { name: 'Give' }).click();

        const dialog = page.getByRole('dialog', {
            name: /Give as-needed med/i,
        });
        await dialog.getByTestId('meds-prn-continue').click();
        await dialog
            .getByRole('button', { name: 'Severe pain', exact: true })
            .click();
        await dialog.getByTestId('meds-prn-continue').click();
        await dialog.getByPlaceholder('e.g. 1').fill('1');

        await dialog.getByTestId('meds-prn-continue').click();
        await expect(
            dialog.getByText('A witness is required for this medication'),
        ).toBeVisible();

        await dialog
            .getByRole('combobox', { name: 'Choose a witness…' })
            .click();
        await page
            .getByRole('option', { name: /Medication Demo Witness/i })
            .click();
        await dialog.locator('input[type="password"]').fill('password');
        await dialog.getByTestId('meds-prn-continue').click();

        await expect(
            dialog.getByRole('heading', { name: 'Review & sign' }),
        ).toBeVisible();
        await expect(dialog.getByTestId('meds-prn-submit')).toBeVisible();

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
