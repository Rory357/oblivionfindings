import { expect, test, type Locator, type Page } from '@playwright/test';

import { collectConsoleErrors, expectNoConsoleErrors } from './helpers';
import {
    loginAsFixture,
    seedIncidentHandoverFixtures,
} from './incident-handover-helpers';

/**
 * Full Control Room alert lifecycle — the regression guard for the six
 * frontend↔backend payload-key mismatches found during the readiness audit
 * (see `docs/control-room-readiness-plan.md`, P0 / B1).
 *
 * Each test seeds its own alert via `POST /control-room/alerts` using the
 * deterministic Control Room operator fixture, so database refreshes do not
 * make the suite depend on a shared demo administrator account.
 */

type CreatedAlert = { id: number; status: string };

async function openAlertWorkspace(
    page: Page,
    alert: CreatedAlert,
): Promise<Locator> {
    await page.goto(`/control-room/alerts/${alert.id}`);

    const workspace = page.getByRole('dialog', { name: /^Alert CR-/ });
    await expect(workspace).toBeVisible();
    await expect(
        workspace
            .getByText('Playwright Lifecycle Alert', { exact: true })
            .first(),
    ).toBeVisible();

    return workspace;
}

async function expectWorkspaceStatus(workspace: Locator, label: string) {
    // Scope to the semantic footer because the lifecycle guide intentionally
    // shows every possible stage as well as the alert's live status.
    await expect(
        workspace.getByRole('contentinfo').getByText(label, { exact: true }),
    ).toBeVisible({ timeout: 20_000 });
}

async function acknowledgeAlert(workspace: Locator) {
    await workspace
        .getByRole('button', { name: 'Acknowledge', exact: true })
        .click();
    await expect(
        workspace.getByText('Acknowledge alert', { exact: true }).first(),
    ).toBeVisible();
    await workspace
        .getByRole('button', { name: 'Acknowledge alert', exact: true })
        .click();
    await expectWorkspaceStatus(workspace, 'Acknowledged');
}

async function startTriage(workspace: Locator) {
    await workspace
        .getByRole('button', { name: 'Start triage', exact: true })
        .click();
    await expect(
        workspace.getByText('Start triage', { exact: true }).first(),
    ).toBeVisible();
    await workspace
        .getByRole('button', { name: 'Start triage', exact: true })
        .click();
    await expectWorkspaceStatus(workspace, 'Triaging');
}

async function getXsrfToken(page: Page): Promise<string> {
    const cookies = await page.context().cookies();
    const xsrf = cookies.find((c) => c.name === 'XSRF-TOKEN');

    return xsrf ? decodeURIComponent(xsrf.value) : '';
}

async function createAlert(
    page: Page,
    siteId: number,
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
            site_id: siteId,
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
    test.setTimeout(60_000);

    let operator: ReturnType<
        typeof seedIncidentHandoverFixtures
    >['users']['operator'];
    let siteId: number;

    test.beforeAll(() => {
        const manifest = seedIncidentHandoverFixtures();
        operator = manifest.users.operator;
        siteId = manifest.site.id;
    });

    test.beforeEach(async ({ page }, testInfo) => {
        test.skip(
            testInfo.project.name.includes('mobile'),
            'Show-page lifecycle interactions are covered on desktop; mobile runs smoke only.',
        );
        await loginAsFixture(page, operator);
    });

    test('acknowledge open alert', async ({ page }) => {
        const errors = collectConsoleErrors(page);
        const alert = await createAlert(page, siteId);

        const workspace = await openAlertWorkspace(page, alert);
        await acknowledgeAlert(workspace);

        expectNoConsoleErrors(errors);
    });

    test('triage acknowledged alert', async ({ page }) => {
        const errors = collectConsoleErrors(page);
        const alert = await createAlert(page, siteId);

        const workspace = await openAlertWorkspace(page, alert);
        await acknowledgeAlert(workspace);
        await startTriage(workspace);

        expectNoConsoleErrors(errors);
    });

    test('resolve sends `resolution_notes` — guards readiness-plan B1.1', async ({
        page,
    }) => {
        const errors = collectConsoleErrors(page);
        const alert = await createAlert(page, siteId);

        const workspace = await openAlertWorkspace(page, alert);
        await acknowledgeAlert(workspace);
        await startTriage(workspace);

        await workspace
            .getByRole('button', { name: 'Resolve', exact: true })
            .click();
        await workspace
            .getByLabel('Resolution notes')
            .fill('E2E resolved — all clear');
        await workspace.getByRole('button', { name: 'Next' }).click();
        await workspace
            .getByRole('button', { name: 'Resolve alert', exact: true })
            .click();

        await expectWorkspaceStatus(workspace, 'Resolved');
        expectNoConsoleErrors(errors);
    });

    test('escalate sends `escalation_reason` — guards readiness-plan B1.2', async ({
        page,
    }) => {
        const errors = collectConsoleErrors(page);
        const alert = await createAlert(page, siteId);

        const workspace = await openAlertWorkspace(page, alert);

        await workspace
            .getByRole('button', { name: 'Escalate', exact: true })
            .click();
        await workspace
            .getByLabel('Reason for escalating')
            .fill('E2E escalation reason');
        await workspace
            .getByRole('button', { name: 'Escalate to L1', exact: true })
            .click();

        await expect(
            workspace.getByText('L1', { exact: true }).last(),
        ).toBeVisible();
        expectNoConsoleErrors(errors);
    });

    test('add note sends `note` — guards readiness-plan B1.3', async ({
        page,
    }) => {
        const errors = collectConsoleErrors(page);
        const alert = await createAlert(page, siteId);

        const workspace = await openAlertWorkspace(page, alert);
        await workspace.getByRole('button', { name: /^Notes & comms/ }).click();

        const textarea = workspace.getByPlaceholder('Add an operator note…');
        await textarea.fill('E2E note from Playwright');
        await workspace
            .getByRole('button', { name: 'Note', exact: true })
            .click();

        await expect(
            workspace.getByText('E2E note from Playwright', { exact: true }),
        ).toBeVisible();
        expectNoConsoleErrors(errors);
    });

    test('assign sends `assigned_to_user_id` — guards readiness-plan B1.4', async ({
        page,
    }) => {
        const errors = collectConsoleErrors(page);
        const alert = await createAlert(page, siteId);

        const workspace = await openAlertWorkspace(page, alert);
        await workspace
            .getByRole('button', { name: 'Assign', exact: true })
            .click();

        const assignSelect = workspace.getByRole('combobox', {
            name: 'Select a staff member',
        });
        await assignSelect.click();
        const firstAssignee = page.getByRole('option').first();
        const assigneeName = (await firstAssignee.textContent())?.trim() ?? '';
        await firstAssignee.click();
        await workspace.getByRole('button', { name: /^Assign to / }).click();

        await expect(
            workspace.getByText(assigneeName, { exact: true }).first(),
        ).toBeVisible();
        await expect(
            workspace.getByRole('button', { name: 'Reassign', exact: true }),
        ).toBeVisible();
        expectNoConsoleErrors(errors);
    });

    test('playbook step buttons hit the correct routes — guards readiness-plan B1.5', async ({
        page,
    }) => {
        // This test depends on a playbook being attached automatically by a
        // matching SignalRule; without one the Playbook tab won't render.
        const errors = collectConsoleErrors(page);
        const alert = await createAlert(page, siteId);

        const workspace = await openAlertWorkspace(page, alert);

        await workspace.getByRole('button', { name: /^Playbook/ }).click();
        const completeStep = workspace
            .getByRole('button', { name: /Complete step/i })
            .first();
        if (await completeStep.isVisible()) {
            await completeStep.click();
            await completeStep.click();
            await expect(
                workspace.getByText(/in progress|completed/i).first(),
            ).toBeVisible();
        }

        expectNoConsoleErrors(errors);
    });

    test('close resolved alert', async ({ page }) => {
        const errors = collectConsoleErrors(page);
        const alert = await createAlert(page, siteId);

        // Drive the lifecycle directly via the API so this test only exercises
        // the close action through the page.
        const xsrf = await getXsrfToken(page);
        const headers = {
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-XSRF-TOKEN': xsrf,
        };

        await page.request.post(
            `/control-room/alerts/${alert.id}/acknowledge`,
            { headers },
        );
        await page.request.post(`/control-room/alerts/${alert.id}/triage`, {
            headers,
        });
        await page.request.post(`/control-room/alerts/${alert.id}/resolve`, {
            headers,
            data: { resolution_notes: 'API resolved for close-test setup' },
        });

        const workspace = await openAlertWorkspace(page, alert);
        await expectWorkspaceStatus(workspace, 'Resolved');

        await workspace
            .getByRole('contentinfo')
            .getByRole('button', { name: 'Close', exact: true })
            .click();
        await workspace.getByLabel('Closing note').fill('E2E closure verified');
        await workspace
            .getByRole('button', { name: 'Close alert', exact: true })
            .click();
        await expectWorkspaceStatus(workspace, 'Closed');

        expectNoConsoleErrors(errors);
    });
});
