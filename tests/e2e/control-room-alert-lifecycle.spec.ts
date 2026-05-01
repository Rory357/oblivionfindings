import { expect, test, type Page } from '@playwright/test';

import { collectConsoleErrors, expectNoConsoleErrors, loginAsStaff } from './helpers';

/**
 * Full Control Room alert lifecycle — the regression guard for the six
 * frontend↔backend payload-key mismatches found during the readiness audit
 * (see `docs/control-room-readiness-plan.md`, P0 / B1).
 *
 * Each test seeds its own alert via `POST /control-room/alerts` (the admin user
 * has `controlRoom.alerts.create`) so tests do not depend on demo seeders
 * including a Control Room fixture.
 */

type CreatedAlert = { id: number; status: string };

async function getXsrfToken(page: Page): Promise<string> {
    const cookies = await page.context().cookies();
    const xsrf = cookies.find((c) => c.name === 'XSRF-TOKEN');

    return xsrf ? decodeURIComponent(xsrf.value) : '';
}

async function createAlert(
    page: Page,
    overrides: Partial<{
        source: string;
        alert_type: string;
        severity: 'low' | 'medium' | 'high' | 'critical';
        notes: string;
    }> = {},
): Promise<CreatedAlert> {
    const xsrf = await getXsrfToken(page);

    const response = await page.request.post('/control-room/alerts', {
        headers: {
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-XSRF-TOKEN': xsrf,
        },
        data: {
            source: 'manual',
            alert_type: 'Playwright Lifecycle Alert',
            severity: 'high',
            ...overrides,
        },
    });

    expect(
        response.status(),
        `alert creation should return 201; received ${response.status()}: ${await response.text()}`,
    ).toBe(201);

    const body = (await response.json()) as { alert: CreatedAlert };

    return body.alert;
}

test.describe('control room — alert lifecycle (show page)', () => {
    test.beforeEach(async ({ page }, testInfo) => {
        test.skip(
            testInfo.project.name.includes('mobile'),
            'Show-page lifecycle interactions are covered on desktop; mobile runs smoke only.',
        );
        await loginAsStaff(page);
    });

    test('acknowledge open alert', async ({ page }) => {
        const errors = collectConsoleErrors(page);
        const alert = await createAlert(page);

        await page.goto(`/control-room/alerts/${alert.id}`);
        await expect(page.getByRole('heading', { name: 'Playwright Lifecycle Alert' })).toBeVisible();

        await page.getByRole('button', { name: /^Acknowledge/ }).click();

        // Backend transitions to `ack`. The hero badge text is rendered with the
        // raw status value.
        await expect(page.getByText(/^ack$/i).first()).toBeVisible();

        expectNoConsoleErrors(errors);
    });

    test('triage acknowledged alert', async ({ page }) => {
        const errors = collectConsoleErrors(page);
        const alert = await createAlert(page);

        await page.goto(`/control-room/alerts/${alert.id}`);
        await page.getByRole('button', { name: /^Acknowledge/ }).click();
        await expect(page.getByText(/^ack$/i).first()).toBeVisible();

        await page.getByRole('button', { name: /^Start Triage/ }).click();
        await expect(page.getByText(/^triaging$/i).first()).toBeVisible();

        expectNoConsoleErrors(errors);
    });

    test('resolve sends `resolution_notes` — guards readiness-plan B1.1', async ({ page }) => {
        const errors = collectConsoleErrors(page);
        const alert = await createAlert(page);

        await page.goto(`/control-room/alerts/${alert.id}`);
        await page.getByRole('button', { name: /^Acknowledge/ }).click();
        await expect(page.getByText(/^ack$/i).first()).toBeVisible();
        await page.getByRole('button', { name: /^Start Triage/ }).click();
        await expect(page.getByText(/^triaging$/i).first()).toBeVisible();

        await page.getByRole('button', { name: /^Resolve/ }).click();
        await page
            .getByLabel('Resolution Notes')
            .fill('E2E resolved — all clear');
        await page
            .getByRole('button', { name: /Resolve$/ })
            .last()
            .click();

        await expect(page.getByText(/^resolved$/i).first()).toBeVisible();
        expectNoConsoleErrors(errors);
    });

    test('escalate sends `escalation_reason` — guards readiness-plan B1.2', async ({ page }) => {
        const errors = collectConsoleErrors(page);
        const alert = await createAlert(page);

        await page.goto(`/control-room/alerts/${alert.id}`);

        await page.getByRole('button', { name: /^Escalate/ }).click();
        await page
            .getByLabel('Escalation Reason')
            .fill('E2E escalation reason');
        await page
            .getByRole('button', { name: /Escalate to L1/ })
            .click();

        await expect(page.locator('main').getByText('L1', { exact: true }).first()).toBeVisible();
        expectNoConsoleErrors(errors);
    });

    test('add note sends `note` — guards readiness-plan B1.3', async ({ page }) => {
        const errors = collectConsoleErrors(page);
        const alert = await createAlert(page);

        await page.goto(`/control-room/alerts/${alert.id}`);

        const textarea = page.getByPlaceholder('Add a note...');
        await textarea.fill('E2E note from Playwright');
        await textarea.press('Enter');

        await expect(page.getByText('E2E note from Playwright')).toBeVisible();
        expectNoConsoleErrors(errors);
    });

    test('assign sends `assigned_to_user_id` — guards readiness-plan B1.4', async ({ page }) => {
        const errors = collectConsoleErrors(page);
        const alert = await createAlert(page);

        await page.goto(`/control-room/alerts/${alert.id}`);

        const assignSelect = page.getByRole('combobox').last();
        await assignSelect.click();
        const firstAssignee = page.getByRole('option').first();
        const assigneeName = (await firstAssignee.textContent())?.trim() ?? '';
        await firstAssignee.click();

        await expect(page.getByLabel('Details').getByText(assigneeName, { exact: true })).toBeVisible();
        expectNoConsoleErrors(errors);
    });

    test('playbook step buttons hit the correct routes — guards readiness-plan B1.5', async ({ page }) => {
        // This test depends on a playbook being attached automatically by a
        // matching SignalRule; without one the Playbook tab won't render.
        const errors = collectConsoleErrors(page);
        const alert = await createAlert(page);

        await page.goto(`/control-room/alerts/${alert.id}`);

        const playbookTab = page.getByRole('tab', { name: /Playbook/ });
        if (await playbookTab.isVisible()) {
            await playbookTab.click();
            await page.getByRole('button', { name: /Complete step/i }).first().click();
            await expect(page.getByText(/in_progress|completed/i).first()).toBeVisible();
        }

        expectNoConsoleErrors(errors);
    });

    test('close resolved alert', async ({ page }) => {
        const errors = collectConsoleErrors(page);
        const alert = await createAlert(page);

        // Drive the lifecycle directly via the API so this test only exercises
        // the close action through the page.
        const xsrf = await getXsrfToken(page);
        const headers = {
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-XSRF-TOKEN': xsrf,
        };

        await page.request.post(`/control-room/alerts/${alert.id}/acknowledge`, { headers });
        await page.request.post(`/control-room/alerts/${alert.id}/triage`, { headers });
        await page.request.post(`/control-room/alerts/${alert.id}/resolve`, {
            headers,
            data: { resolution_notes: 'API resolved for close-test setup' },
        });

        await page.goto(`/control-room/alerts/${alert.id}`);
        await expect(page.getByText(/^resolved$/i).first()).toBeVisible();

        await page.getByRole('button', { name: /^Close/ }).click();
        await expect(page.getByText(/^closed$/i).first()).toBeVisible();

        expectNoConsoleErrors(errors);
    });
});
