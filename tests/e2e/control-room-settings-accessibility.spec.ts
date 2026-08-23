import AxeBuilder from '@axe-core/playwright';
import { expect, test, type Locator, type Page } from '@playwright/test';

import { collectConsoleErrors, expectNoConsoleErrors } from './helpers';
import {
    loginAsFixture,
    seedIncidentHandoverFixtures,
} from './incident-handover-helpers';

const viewports = [
    { width: 390, height: 844 },
    { width: 1024, height: 768 },
] as const;

async function expectMinimumTarget(locator: Locator) {
    const box = await locator.boundingBox();
    expect(box).not.toBeNull();
    if (!box) return;

    expect(box.width).toBeGreaterThanOrEqual(44);
    expect(box.height).toBeGreaterThanOrEqual(44);
}

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

    test('names every rule control and keeps the validation footer reachable', async ({
        page,
    }) => {
        const manifest = seedIncidentHandoverFixtures();
        const consoleErrors = collectConsoleErrors(page);
        await loginAsFixture(page, manifest.users.operator);

        for (const viewport of viewports) {
            await page.setViewportSize(viewport);
            await page.goto('/control-room/settings');
            await expect(
                page.getByRole('heading', { name: 'Settings', exact: true }),
            ).toBeVisible();

            const pageWidth = await page.evaluate(() => ({
                client: document.documentElement.clientWidth,
                scroll: document.documentElement.scrollWidth,
            }));
            expect(pageWidth.scroll).toBeLessThanOrEqual(pageWidth.client);
            await expectNoNameViolations(page);

            const createRule = page
                .getByRole('button', { name: 'Create Rule', exact: true })
                .first();
            await expectMinimumTarget(createRule);
            await createRule.focus();
            await expect(createRule).toBeFocused();
            await createRule.press('Enter');
            const dialog = page.getByRole('dialog', {
                name: 'Create Signal Rule',
            });
            await expect(dialog).toBeVisible();

            for (const name of [
                'Signal type',
                'Signal source',
                'Output severity',
                'Playbook',
            ]) {
                const control = dialog.getByRole('combobox', { name });
                await expect(control).toBeVisible();
                await expectMinimumTarget(control);
            }
            for (const name of [
                'Suppress during maintenance',
                'Deduplicate',
                'Rule active',
            ]) {
                await expect(
                    dialog.getByRole('switch', { name }),
                ).toBeVisible();
            }

            const ruleName = dialog.getByRole('textbox', {
                name: 'Rule name',
            });
            await expectMinimumTarget(ruleName);
            await ruleName.fill(
                'A deliberately long rule name that must reflow without hiding the actions',
            );
            const conditions = dialog.getByRole('textbox', {
                name: 'Conditions (JSON)',
            });
            await conditions.fill('{not valid JSON');
            await dialog
                .getByRole('button', { name: 'Create Rule', exact: true })
                .click();
            await expect(
                dialog
                    .getByRole('alert')
                    .filter({ hasText: 'Enter valid JSON' }),
            ).toBeVisible();
            await expect(conditions).toHaveAttribute(
                'aria-describedby',
                'rule-conditions-error',
            );

            await expectContained(dialog);
            for (const button of [
                dialog.getByRole('button', { name: 'Close' }),
                dialog.getByRole('button', { name: 'Cancel' }),
                dialog.getByRole('button', {
                    name: 'Create Rule',
                    exact: true,
                }),
            ]) {
                await expect(button).toBeVisible();
                await expectMinimumTarget(button);
            }

            await expectNoNameViolations(page, '[role="dialog"]');

            await page.keyboard.press('Escape');
            await expect(dialog).toBeHidden();
            await expect(createRule).toBeFocused();
        }

        expectNoConsoleErrors(consoleErrors);
    });

    test('gives each settings field a plain unique name', async ({ page }) => {
        const manifest = seedIncidentHandoverFixtures();
        const consoleErrors = collectConsoleErrors(page);
        await page.setViewportSize(viewports[0]);
        await loginAsFixture(page, manifest.users.operator);
        await page.goto('/control-room/settings');

        await page.getByRole('tab', { name: 'Triage Queues' }).click();
        const createQueue = page.getByRole('button', {
            name: 'Create Queue',
            exact: true,
        });
        await expect(createQueue).toBeVisible();
        await createQueue.focus();
        await createQueue.press('Enter');
        const queueDialog = page.getByRole('dialog', {
            name: 'Create Triage Queue',
        });
        await expect(queueDialog).toBeVisible();
        for (const name of ['Queue name', 'Queue code', 'Queue description']) {
            await expect(
                queueDialog.getByRole('textbox', { name, exact: true }),
            ).toHaveCount(1);
        }
        for (const name of ['Queue tier', 'Auto-escalate after (minutes)']) {
            await expect(
                queueDialog.getByRole('spinbutton', { name, exact: true }),
            ).toHaveCount(1);
        }
        for (const name of [
            'Handle low severity',
            'Handle fleet source',
            'Assign control room operator role',
        ]) {
            await expect(
                queueDialog.getByRole('checkbox', { name, exact: true }),
            ).toHaveCount(1);
        }
        await expect(
            queueDialog.getByRole('combobox', {
                name: 'Escalation queue',
                exact: true,
            }),
        ).toHaveCount(1);
        await expect(
            queueDialog.getByRole('switch', {
                name: 'Queue active',
                exact: true,
            }),
        ).toHaveCount(1);
        await expectContained(queueDialog);
        await expectNoNameViolations(page, '[role="dialog"]');
        await page.keyboard.press('Escape');
        await expect(createQueue).toBeFocused();

        await page.getByRole('tab', { name: 'Maintenance' }).click();
        const scheduleWindow = page.getByRole('button', {
            name: 'Schedule Window',
            exact: true,
        });
        await expect(scheduleWindow).toBeVisible();
        await scheduleWindow.click();
        const maintenanceDialog = page.getByRole('dialog', {
            name: 'Schedule Maintenance Window',
        });
        for (const name of [
            'Maintenance window name',
            'Maintenance window description',
        ]) {
            await expect(
                maintenanceDialog.getByRole('textbox', {
                    name,
                    exact: true,
                }),
            ).toHaveCount(1);
        }
        for (const name of ['Signal source', 'Site']) {
            await expect(
                maintenanceDialog.getByRole('combobox', {
                    name,
                    exact: true,
                }),
            ).toHaveCount(1);
        }
        await expectContained(maintenanceDialog);
        await expectNoNameViolations(page, '[role="dialog"]');
        await page.keyboard.press('Escape');

        await page.getByRole('tab', { name: 'Ticket Options' }).click();
        const addOption = page.getByRole('button', {
            name: 'Add Alert Categories option',
            exact: true,
        });
        await expect(addOption).toBeVisible();
        await addOption.click();
        const optionDialog = page.getByRole('dialog', { name: 'Add Option' });
        for (const name of [
            'Option value (slug)',
            'Display label',
            'Option colour (hex)',
            'Option description (optional)',
        ]) {
            await expect(
                optionDialog.getByRole('textbox', { name, exact: true }),
            ).toHaveCount(1);
        }
        await expectContained(optionDialog);
        await expectNoNameViolations(page, '[role="dialog"]');

        expectNoConsoleErrors(consoleErrors);
    });
});
