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

/** Use the same date/time controls as staff, with explicit NZ wall time. */
async function chooseCheckTime(page: Page, label: string) {
    const date = new Date(Date.now() + 30 * 60_000);
    const parts = Object.fromEntries(
        new Intl.DateTimeFormat('en-NZ', {
            timeZone: 'Pacific/Auckland',
            weekday: 'short',
            day: 'numeric',
            month: 'long',
            year: 'numeric',
            hour: '2-digit',
            minute: '2-digit',
            hourCycle: 'h23',
        })
            .formatToParts(date)
            .map((p) => [p.type, p.value]),
    );
    await page
        .getByRole('button', { name: new RegExp('^' + label + ' date:') })
        .click();
    const dayName =
        parts.weekday +
        ' ' +
        Number(parts.day) +
        ' ' +
        parts.month +
        ' ' +
        parts.year;
    const day = page.getByRole('button', { name: new RegExp('^' + dayName) });
    if (!(await day.isVisible()))
        await page
            .getByRole('button', { name: 'Next month', exact: true })
            .click();
    await day.click();
    await page.getByRole('button', { name: 'Use date', exact: true }).click();
    await page
        .getByRole('button', { name: new RegExp('^' + label + ' time:') })
        .click();
    await page
        .getByLabel(label + ' time hour', { exact: true })
        .fill(String(Number(parts.hour) % 12 || 12));
    await page
        .getByLabel(label + ' time minute', { exact: true })
        .fill(parts.minute);
    await page
        .getByRole('group', { name: label + ' time AM or PM', exact: true })
        .getByRole('button', {
            name: Number(parts.hour) >= 12 ? 'PM' : 'AM',
            exact: true,
        })
        .click();
    await page.getByRole('button', { name: 'Use time', exact: true }).click();
}

async function selectRounds(page: Page) {
    await expect(
        page.getByRole('tablist', { name: 'Views', exact: true }),
    ).toBeVisible();
    const tab = page.getByRole('tab', { name: /^Rounds/ });
    if (await tab.isVisible()) await tab.click();
    else {
        await page.getByRole('button', { name: /More views/ }).click();
        await page
            .getByRole('dialog')
            .getByRole('button', { name: 'Rounds', exact: true })
            .click();
    }
    await expect(page.getByRole('tab', { name: /^Rounds/ })).toHaveAttribute(
        'aria-selected',
        'true',
    );
}

async function openPrnSheetFor(page: Page, medicationName: string) {
    await page
        .getByRole('button', { name: 'Record as-needed dose', exact: true })
        .click();
    await page
        .getByRole('option', { name: new RegExp(medicationName) })
        .click();
    await expect(
        page
            .getByRole('dialog')
            .getByText('Step 1 of 3 · Safety checks', { exact: true }),
    ).toBeVisible();
    await page.getByRole('button', { name: /^Continue/ }).click();
}

async function recordPrnReason(page: Page, reason: string, offline = false) {
    await page.getByRole('button', { name: /^Given/ }).click();
    await page.getByRole('button', { name: new RegExp('^' + reason) }).click();
    await chooseCheckTime(page, 'Check by');
    const stock = page.getByLabel('Given from stock for this dose', {
        exact: false,
    });
    if (await stock.isVisible()) await stock.fill('1');
    await page.getByRole('button', { name: /^Continue/ }).click();
    await page
        .getByRole('button', { name: 'Record outcome', exact: true })
        .click();
    if (!offline) {
        await expect(
            page.getByRole('heading', { name: 'Recorded', exact: true }),
        ).toBeVisible();
        await page.getByRole('button', { name: 'Done', exact: true }).click();
    }
}

async function openGuidedRound(page: Page) {
    await loginAsMedsDemoWorker(page);
    await page.goto('/meds/today');
    await selectRounds(page);

    await page
        .getByRole('link', {
            name: /(Start|Resume) PW Meds Readiness Round/i,
        })
        .first()
        .click();
    // Frontline workers stay on their own board when entering a round.
    await page.waitForURL(
        (url) =>
            url.pathname === '/meds/today' &&
            url.searchParams.get('view') === 'rounds' &&
            /^\d+$/.test(url.searchParams.get('round') ?? ''),
    );
    await expect(
        page.getByRole('heading', {
            name: /Guided round · PW Meds Readiness Round/i,
        }),
    ).toBeVisible();
}

async function recordCurrentRoundItem(
    page: Page,
    action: 'given' | 'refused' | 'held',
    reason?: string,
    offline = false,
) {
    const start = page.getByRole('button', { name: /^(Start|Resume) round$/ });
    if (await start.isVisible()) await start.click();
    await page.getByRole('button', { name: /^Record next:/ }).click();
    await page.getByRole('button', { name: /^Continue/ }).click();
    await page
        .getByRole('button', {
            name:
                action === 'given'
                    ? /^Given/
                    : action === 'refused'
                      ? /^Refused/
                      : /^Withheld/,
        })
        .click();
    if (action === 'refused') await chooseCheckTime(page, 'Follow up by');
    if (action === 'held') {
        await page.getByRole('combobox', { name: /reason/i }).click();
        await page.getByRole('option', { name: /Safety concern/ }).click();
    }
    if (action === 'given') {
        const late = page.getByRole('combobox', {
            name: /outside the dose window/,
        });
        if (await late.isVisible()) {
            await late.click();
            await page
                .getByRole('option', {
                    name: 'Person was out or asleep at the time',
                    exact: true,
                })
                .click();
        }
    }
    if (reason)
        await page.getByLabel('What happened', { exact: true }).fill(reason);
    await page.getByRole('button', { name: /^Continue/ }).click();
    await page
        .getByRole('button', { name: 'Record outcome', exact: true })
        .click();
    if (!offline)
        await page.getByRole('button', { name: 'Done', exact: true }).click();
}

async function openMarFromMedsHome(page: Page) {
    await loginAsMedsDemoWorker(page);
    await page.goto('/meds/today');

    const row = page
        .locator('[role="row"], li')
        .filter({ hasText: 'PW Meds Morning Tablets', visible: true })
        .first();
    await row.getByRole('button', { name: /Actions for/ }).click();
    await page
        .getByRole('menuitem', { name: /Open .*medication record/ })
        .click();
    await page.waitForURL(/\/emar\/mar\?.*client_id=\d+/);
}

test.describe('meds readiness workflows', () => {
    test.describe.configure({ timeout: 90_000 });

    test.beforeEach(async ({ context }) => {
        resetMedicationReadinessFixtures();
        await context.setOffline(false);
    });

    test('worker meds home exposes due rows, PRN, and guided round entry', async ({
        page,
    }) => {
        const consoleErrors = collectConsoleErrors(page);

        await loginAsMedsDemoWorker(page);
        await page.goto('/meds/today');

        await expect(
            page
                .locator('[role="row"], li')
                .filter({ hasText: 'PW Meds Morning Tablets', visible: true })
                .first(),
        ).toBeVisible();
        // The med name can appear in both the overdue strip and the schedule
        // table — any visible mention satisfies this readiness check.
        await expect(
            page.getByText('PW Meds Morning Tablets').first(),
        ).toBeVisible();
        // The initial slot is due but still within the configured late window.
        // The backend fixture regression advances past that real window and
        // verifies overdue projection separately; do not invent an overdue badge.
        await expect(
            page.getByRole('button', {
                name: 'Record as-needed dose',
                exact: true,
            }),
        ).toBeEnabled();
        await selectRounds(page);
        const roundDoses = page.getByRole('list', {
            name: 'PW Meds Readiness Round doses',
            exact: true,
        });
        await expect(roundDoses.getByRole('listitem')).toHaveCount(3);
        for (const medicine of [
            'PW Meds Morning Tablets',
            'PW Meds Vitamin D',
            'PW Meds Eye Drops',
        ]) {
            await expect(roundDoses).toContainText(medicine);
        }
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

        await page
            .getByRole('button', { name: 'Record as-needed dose', exact: true })
            .click();
        await expect(
            page
                .getByRole('option', { name: /PW Meds PRN Paracetamol/i })
                .filter({ hasText: /1 of 4 in the last 24 hours/i }),
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

        await recordPrnReason(page, 'Pain', true);

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
        await page
            .getByRole('button', { name: 'Record as-needed dose', exact: true })
            .click();
        await expect(
            page
                .getByRole('option', { name: /PW Meds PRN Paracetamol/i })
                .filter({ hasText: /1 of 4 in the last 24 hours/i }),
        ).toBeVisible();

        expectNoUnexpectedConsoleErrors(consoleErrors);
    });

    test('guided round shell is ready for recording', async ({ page }) => {
        const consoleErrors = collectConsoleErrors(page);
        await openGuidedRound(page);
        await expect(
            page.getByText('PW Meds Morning Tablets').first(),
        ).toBeVisible();
        const start = page.getByRole('button', {
            name: /^(Start|Resume) round$/,
        });
        if (await start.isVisible()) await start.click();
        await page.getByRole('button', { name: /^Record next:/ }).click();
        await expect(
            page
                .getByRole('dialog')
                .getByText('Step 1 of 3 · Safety checks', { exact: true }),
        ).toBeVisible();
        await page.getByRole('button', { name: /^Continue/ }).click();
        await expect(
            page.getByRole('button', { name: /^Given/ }),
        ).toBeVisible();
        await expect(
            page.getByRole('button', { name: /^Refused/ }),
        ).toBeVisible();
        await expect(
            page.getByRole('button', { name: /^Withheld/ }),
        ).toBeVisible();
        expectNoUnexpectedConsoleErrors(consoleErrors);
    });

    test('guided round can be walked to completion', async ({ page }) => {
        const consoleErrors = collectConsoleErrors(page);

        await openGuidedRound(page);

        await expect(
            page.getByText('PW Meds Morning Tablets').first(),
        ).toBeVisible();
        await recordCurrentRoundItem(page, 'given');

        await expect(page.getByText('PW Meds Vitamin D').first()).toBeVisible();
        await recordCurrentRoundItem(page, 'refused', 'Client declined.');

        await expect(page.getByText('PW Meds Eye Drops').first()).toBeVisible();
        await recordCurrentRoundItem(page, 'held', 'Held pending review.');

        await expect(
            page.getByRole('button', { name: /Finish round/i }),
        ).toBeEnabled();

        await page.getByRole('button', { name: /Finish round/i }).click();
        await expect(page.getByText('Complete', { exact: true })).toBeVisible({
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

        await expect(
            page.getByText('PW Meds Morning Tablets').first(),
        ).toBeVisible();
        await recordCurrentRoundItem(page, 'given');

        await expect(page.getByText('PW Meds Vitamin D').first()).toBeVisible();
        await context.setOffline(true);
        await expect(page.getByRole('status')).toHaveText(/offline/i);

        await recordCurrentRoundItem(page, 'given', undefined, true);
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
        await expect(page.getByText('PW Meds Eye Drops').first()).toBeVisible();

        expectNoUnexpectedConsoleErrors(consoleErrors);
    });

    test('canonical MAR opens the safety-gated dose workflow', async ({
        page,
    }) => {
        const consoleErrors = collectConsoleErrors(page);

        await openMarFromMedsHome(page);

        const doseCell = page
            .getByRole('button', {
                name: /PW Meds Morning Tablets, .*: .*record this dose/i,
            })
            .first();
        await expect(doseCell).toBeVisible();
        await doseCell.click();

        const dialog = page.getByRole('dialog', { name: /Record dose/i });
        await expect(dialog).toBeVisible();
        await expect(
            dialog.getByText('Step 1 of 3 · Safety checks', { exact: true }),
        ).toBeVisible();
        await expect(
            dialog.getByRole('region', { name: 'Person', exact: true }),
        ).toBeVisible();
        await expect(
            dialog.getByRole('region', { name: 'Medicine', exact: true }),
        ).toContainText('PW Meds Morning Tablets');
        await expect(
            dialog.getByText(/No allergies recorded for PW Meds/),
        ).toBeVisible();
        await expect(dialog.getByText(/Give with water/)).toBeVisible();

        expectNoUnexpectedConsoleErrors(consoleErrors);
    });

    test('controlled PRN requires a current witness and PIN before review', async ({
        page,
    }) => {
        const consoleErrors = collectConsoleErrors(page);
        await loginAsMedsDemoWorker(page);
        await page.goto('/meds/today');
        await openPrnSheetFor(page, 'PW Meds Controlled PRN');
        await page.getByRole('button', { name: /^Given/ }).click();
        await page.getByRole('button', { name: /^Severe pain/ }).click();
        await chooseCheckTime(page, 'Check by');
        const stock = page.getByLabel('Taken from the stock for this dose', {
            exact: true,
        });
        if (await stock.isVisible()) await stock.fill('1');
        await page.getByLabel(/Balance left/).fill('11');
        await page.getByRole('button', { name: /^Continue/ }).click();
        await expect(
            page.getByText(/Choose who is witnessing/).first(),
        ).toBeVisible();
        await page
            .getByRole('combobox', { name: /^Witnessed by\s*\*?$/ })
            .click();
        await page
            .getByRole('option', { name: /Medication Demo Witness/ })
            .click();
        await page.getByLabel(/^Witness’s 6-digit PIN\s*\*?$/).fill('593027');
        await page.getByRole('button', { name: /^Continue/ }).click();
        await expect(
            page.getByRole('button', { name: 'Record outcome', exact: true }),
        ).toBeVisible();
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
