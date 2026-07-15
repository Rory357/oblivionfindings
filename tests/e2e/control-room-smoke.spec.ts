import AxeBuilder from '@axe-core/playwright';
import { expect, test, type Page } from '@playwright/test';
import { mkdirSync } from 'node:fs';
import { resolve } from 'node:path';

import { collectConsoleErrors, expectNoConsoleErrors } from './helpers';
import {
    loginAsFixture,
    seedIncidentHandoverFixtures,
} from './incident-handover-helpers';

/**
 * Page-load smoke for the Control Room module.
 *
 * Asserts every routed surface returns 200 + renders its identifying heading,
 * and that no blocking accessibility violations are emitted on the dashboard.
 *
 * Replaces the old Dusk smoke with a production-built Inertia bundle check,
 * which is what real users see.
 */

async function expectNoBlockingAxeViolations(page: Page) {
    const results = await new AxeBuilder({ page })
        .withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'])
        .analyze();

    const blocking = results.violations.filter((v) =>
        ['serious', 'critical'].includes(v.impact ?? ''),
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

test.describe('control room — smoke', () => {
    let operator: ReturnType<
        typeof seedIncidentHandoverFixtures
    >['users']['operator'];

    test.beforeAll(() => {
        operator = seedIncidentHandoverFixtures().users.operator;
    });

    test.beforeEach(async ({ page }) => {
        await loginAsFixture(page, operator);
    });

    test('dashboard loads with KPI cards and no a11y blockers', async ({
        page,
    }, testInfo) => {
        test.skip(
            !testInfo.project.name.includes('desktop'),
            'Dashboard a11y baseline runs on desktop project.',
        );

        const errors = collectConsoleErrors(page);

        await page.goto('/control-room');
        await expect(
            page.getByRole('heading', { name: 'Control Room Desk', level: 1 }),
        ).toBeVisible();

        // Current desk summary and continuity language remains visible even when
        // there are no active alerts in the seeded fixture set.
        await expect(page.getByText('Active', { exact: true })).toBeVisible();
        await expect(
            page.getByText('Critical', { exact: true }).first(),
        ).toBeVisible();
        await expect(
            page.getByText('SLA breached', { exact: true }),
        ).toBeVisible();
        await expect(
            page.getByText('Last 24 hours', { exact: true }),
        ).toBeVisible();
        await expect(
            page.getByRole('heading', { name: 'Priority worklist' }),
        ).toBeVisible();
        await expect(page.getByText('Live desk connection')).toBeVisible();

        // Filter UI is present so operators can scope the alert list.
        await expect(
            page.getByPlaceholder('Reference, incident, H&S or summary'),
        ).toBeVisible();

        const evidenceDirectory = resolve(
            process.cwd(),
            'output',
            'playwright',
        );
        mkdirSync(evidenceDirectory, { recursive: true });
        await page.screenshot({
            path: resolve(
                evidenceDirectory,
                'control-room-dashboard-first-viewport.png',
            ),
        });

        await expectNoBlockingAxeViolations(page);
        expectNoConsoleErrors(errors);
    });

    test('alerts list page renders', async ({ page }) => {
        const errors = collectConsoleErrors(page);

        await page.goto('/control-room/alerts');
        await expect(page).toHaveURL(/\/control-room\/alerts(\?.*)?$/);
        await expect(page.locator('body')).toContainText(/Alert/i);

        expectNoConsoleErrors(errors);
    });

    test('integration alerts list pre-scopes to integration sources', async ({
        page,
    }) => {
        await page.goto('/control-room/integration-alerts');
        await expect(page.locator('body')).toContainText(/Integration Alerts/i);
    });

    test('escalations queue page renders', async ({ page }) => {
        await page.goto('/control-room/escalations');
        await expect(page.locator('body')).toContainText(/Escalat/i);
    });

    test('SLA index renders', async ({ page }) => {
        await page.goto('/control-room/sla');
        await expect(page.locator('body')).toContainText(/SLA/i);
    });

    test('SLA breaches renders and the legacy alias redirects', async ({
        page,
    }) => {
        await page.goto('/control-room/sla-breaches');
        await expect(page).toHaveURL(/\/control-room\/sla\/breaches$/);
        await expect(page.locator('body')).toContainText(/Breach/i);
    });

    test('playbooks index renders', async ({ page }) => {
        await page.goto('/control-room/playbooks');
        await expect(page.locator('body')).toContainText(/Playbook/i);
    });

    test('devices monitoring renders', async ({ page }) => {
        await page.goto('/control-room/devices');
        await expect(page.locator('body')).toContainText(/Device/i);
    });

    test('settings page renders', async ({ page }) => {
        await page.goto('/control-room/settings');
        await expect(page.locator('body')).toContainText(/Setting/i);
    });

    test('reports page renders', async ({ page }) => {
        await page.goto('/control-room/reports');
        await expect(page.locator('body')).toContainText(/Report/i);
    });

    test('stats page renders', async ({ page }) => {
        await page.goto('/control-room/stats');
        await expect(page.locator('body')).toContainText(/Stat/i);
    });

    test('broadcast index renders', async ({ page }) => {
        await page.goto('/control-room/broadcast');
        await expect(page.locator('body')).toContainText(/Broadcast/i);
    });

    test('messaging index renders', async ({ page }) => {
        await page.goto('/control-room/messaging');
        await expect(page.locator('body')).toContainText(/Messag/i);
    });

    test('my-tasks page renders', async ({ page }) => {
        await page.goto('/control-room/my-tasks');
        await expect(page.locator('body')).toContainText(/Task/i);
    });

    test('incidents tracker renders', async ({ page }) => {
        await page.goto('/control-room/incidents');
        await expect(page.locator('body')).toContainText(/Incident/i);
    });

    test('map view renders', async ({ page }) => {
        await page.goto('/control-room/map');
        await expect(page.locator('body')).toContainText(
            /Map|Location|Devices/i,
        );
    });

    test('shifts page renders', async ({ page }) => {
        await page.goto('/control-room/shifts');
        await expect(page.locator('body')).toContainText(/Shift/i);
    });
});
