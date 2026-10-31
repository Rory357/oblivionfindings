import AxeBuilder from '@axe-core/playwright';
import { expect, test, type Locator, type Page } from '@playwright/test';

import { collectConsoleErrors, expectNoConsoleErrors } from './helpers';
import {
    loginAsFixture,
    seedIncidentHandoverFixtures,
} from './incident-handover-helpers';

// Control Room is desktop web only (DESIGN.md) — 24 Oct 2026 rebuild on the
// pop-up kit: every settings change opens a kit pop-up.
const viewports = [
    { width: 1440, height: 900 },
    { width: 1024, height: 768 },
] as const;

async function expectContained(locator: Locator) {
    const width = await locator.evaluate((element) => ({
        client: element.clientWidth,
        scroll: element.scrollWidth,
    }));
    expect(width.scroll).toBeLessThanOrEqual(width.client);
}

async function expectNoNameViolations(page: Page, include?: string) {
    let scan = new AxeBuilder({ page }).withRules([
        'aria-input-field-name',
        'button-name',
        'label',
        'select-name',
    ]);
    if (include) scan = scan.include(include);

    const result = await scan.analyze();
    expect(result.violations).toEqual([]);
}

test.describe('Control Room settings accessibility and reflow', () => {
    test.describe.configure({ timeout: 120_000 });

    test('names every routing rule control and keeps bad JSON in the form', async ({
        page,
    }) => {
        const manifest = seedIncidentHandoverFixtures();
        const consoleErrors = collectConsoleErrors(page);
        await loginAsFixture(page, manifest.users.operator);

        for (const viewport of viewports) {
            await page.setViewportSize(viewport);
            await page.goto('/control-room/settings?tab=rules');
            await expect(
                page.getByRole('heading', { level: 1, name: 'Settings' }),
            ).toBeVisible();

            const pageWidth = await page.evaluate(() => ({
                client: document.documentElement.clientWidth,
                scroll: document.documentElement.scrollWidth,
            }));
            expect(pageWidth.scroll).toBeLessThanOrEqual(pageWidth.client);
            await expectNoNameViolations(page);

            const addRule = page.getByRole('button', { name: 'Add rule', exact: true });
            await addRule.click();
            const dialog = page.getByRole('dialog', { name: 'Add routing rule' });
            await expect(dialog).toBeVisible();

            for (const name of ['Signal type', 'Signal source', 'Severity', 'Response procedure']) {
                await expect(dialog.getByRole('combobox', { name })).toBeVisible();
            }
            for (const name of [
                'Hold during maintenance',
                'Join repeats to the open alert',
                'Rule is on',
            ]) {
                await expect(dialog.getByRole('switch', { name })).toBeVisible();
            }

            await dialog
                .getByRole('textbox', { name: /Rule name/ })
                .fill('A deliberately long rule name that must reflow without hiding the actions');
            await dialog
                .getByRole('textbox', { name: 'Only when the signal has (JSON)' })
                .fill('{not valid JSON');
            await dialog.getByRole('button', { name: 'Save rule', exact: true }).click();
            await expect(dialog.getByText(/Enter valid JSON/)).toBeVisible();

            await expectContained(dialog);
            for (const name of ['Close', 'Cancel', 'Save rule']) {
                await expect(dialog.getByRole('button', { name, exact: true })).toBeVisible();
            }
            await expectNoNameViolations(page, '[role="dialog"]');

            // Typing was done, so closing asks the one discard question.
            await page.keyboard.press('Escape');
            const discard = page.getByRole('alertdialog', { name: 'Discard this draft?' });
            await expect(discard).toBeVisible();
            await discard.getByRole('button', { name: 'Discard draft' }).click();
            await expect(dialog).toBeHidden();
        }

        expectNoConsoleErrors(consoleErrors);
    });

    test('gives each settings field a plain unique name', async ({ page }) => {
        const manifest = seedIncidentHandoverFixtures();
        const consoleErrors = collectConsoleErrors(page);
        await page.setViewportSize(viewports[1]);
        await loginAsFixture(page, manifest.users.operator);

        await page.goto('/control-room/settings?tab=queues');
        await page.getByRole('button', { name: 'Add queue', exact: true }).click();
        const queueDialog = page.getByRole('dialog', { name: 'Add queue' });
        await expect(queueDialog).toBeVisible();
        for (const name of [/Queue name/, /Queue code/, /Description/]) {
            await expect(queueDialog.getByRole('textbox', { name })).toHaveCount(1);
        }
        for (const name of [/^Level/, /Move on after/]) {
            await expect(queueDialog.getByRole('spinbutton', { name })).toHaveCount(1);
        }
        for (const name of ['Low', 'Fleet', 'Control room operator']) {
            await expect(queueDialog.getByRole('button', { name, exact: true })).toHaveCount(1);
        }
        await expect(queueDialog.getByRole('combobox', { name: 'Move on to' })).toHaveCount(1);
        await expect(queueDialog.getByRole('switch', { name: 'Queue is on' })).toHaveCount(1);
        await expectContained(queueDialog);
        await expectNoNameViolations(page, '[role="dialog"]');
        await page.keyboard.press('Escape');

        await page.goto('/control-room/settings?tab=maintenance');
        await page.getByRole('button', { name: 'Schedule maintenance', exact: true }).click();
        const maintenanceDialog = page.getByRole('dialog', { name: 'Schedule maintenance' });
        for (const name of [/^Name/, /^Notes/]) {
            await expect(maintenanceDialog.getByRole('textbox', { name })).toHaveCount(1);
        }
        for (const name of ['Signal source', 'House']) {
            await expect(maintenanceDialog.getByRole('combobox', { name })).toHaveCount(1);
        }
        await expectContained(maintenanceDialog);
        await expectNoNameViolations(page, '[role="dialog"]');
        await page.keyboard.press('Escape');

        await page.goto('/control-room/settings?tab=ticket-options');
        await page.getByRole('button', { name: 'Add to alert categories', exact: true }).click();
        const optionDialog = page.getByRole('dialog', { name: 'Add option' });
        for (const name of [/^Name/, /^Short code/, /^Colour/, /^Description/]) {
            await expect(optionDialog.getByRole('textbox', { name })).toHaveCount(1);
        }
        await expectContained(optionDialog);
        await expectNoNameViolations(page, '[role="dialog"]');

        expectNoConsoleErrors(consoleErrors);
    });
});
