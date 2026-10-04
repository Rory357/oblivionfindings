import { expect, test } from '@playwright/test';

import {
    collectConsoleErrors,
    expectNoConsoleErrors,
    loginAsStaff,
    runLaravelJson,
} from './helpers';
import { seedNativeMonitoringRuntimeFixtures } from './native-monitoring-runtime-fixtures';
import {
    chooseSelectOption,
    seedSecurityDevicesMutatingFixtures,
} from './security-devices-mutating-fixtures';

test.describe('Security & Devices discovery and collector lifecycle', () => {
    let fixture: ReturnType<typeof seedSecurityDevicesMutatingFixtures>;

    test.beforeAll(() => {
        seedNativeMonitoringRuntimeFixtures();
        fixture = seedSecurityDevicesMutatingFixtures();
    });

    test('creates, updates, and applies a scope then enrols, revokes, and re-enrols a collector', async ({
        page,
    }) => {
        test.setTimeout(300_000);
        const errors = collectConsoleErrors(page);
        const stamp = Date.now();
        const scopeName = `Playwright mutating scope ${stamp}`;
        const updatedScopeName = `${scopeName} updated`;

        await loginAsStaff(page);
        await page.goto('/security-devices/discovery?tab=scopes');
        await expect(
            page.getByRole('heading', {
                name: 'Discovery & collectors',
                level: 1,
            }),
        ).toBeVisible();

        await page.getByRole('button', { name: 'Create direct scope' }).click();
        const createDialog = page.getByRole('dialog', {
            name: 'Create direct discovery scope',
        });
        await expect(createDialog).toBeVisible();
        if (
            await page
                .getByRole('combobox', { name: 'Site' })
                .isVisible()
                .catch(() => false)
        ) {
            await chooseSelectOption(page, 'Site', fixture.siteName);
        }
        await page.getByLabel('Scope name').fill(scopeName);
        // One loopback target is rejected by the unchanged egress guard before
        // transport. Exercise the real Redis lifecycle without network probes.
        await page.getByLabel('Approved networks').fill('127.0.0.1/32');
        await page.getByLabel('Maximum targets per run').fill('1');
        await page.getByLabel('Packets per second').fill('1');
        const createdResponse = page.waitForResponse(
            (response) =>
                response.url().endsWith('/security-devices/discovery/scopes') &&
                response.request().method() === 'POST',
        );
        await page.getByRole('button', { name: 'Create direct scope' }).click();
        const created = await createdResponse;
        expect(created.status()).toBe(201);
        const scopeId = Number((await created.json()).scope.id);
        expect(Number.isSafeInteger(scopeId) && scopeId > 0).toBe(true);

        const scopeCard = page.getByRole('article', {
            name: `Discovery scope ${scopeName}`,
        });
        await expect(scopeCard).toBeVisible({ timeout: 30_000 });
        await scopeCard.getByRole('button', { name: 'Update' }).click();
        const updateDialog = page.getByRole('dialog', {
            name: new RegExp(`Update ${scopeName}`),
        });
        await expect(updateDialog).toBeVisible();
        await page.getByLabel('Scope name').fill(updatedScopeName);
        await page.getByRole('button', { name: 'Apply scope update' }).click();

        const updatedCard = page.getByRole('article', {
            name: `Discovery scope ${updatedScopeName}`,
        });
        await expect(updatedCard).toBeVisible({ timeout: 30_000 });
        await updatedCard.getByRole('button', { name: 'Run now' }).click();
        const applyDialog = page.getByRole('dialog', {
            name: new RegExp(`Run ${updatedScopeName} now`),
        });
        await expect(applyDialog).toBeVisible();
        const runResponse = page.waitForResponse(
            (response) =>
                response
                    .url()
                    .endsWith(
                        `/security-devices/discovery/scopes/${scopeId}/apply`,
                    ) && response.request().method() === 'POST',
        );
        await applyDialog
            .getByRole('button', { name: 'Queue discovery run' })
            .click();
        const queued = await runResponse;
        expect(queued.status()).toBe(202);
        const runId = Number((await queued.json()).run.id);
        expect(Number.isSafeInteger(runId) && runId > 0).toBe(true);
        await expect(applyDialog).toBeHidden();

        // Active runs cannot be retired. Observe this exact run reaching its
        // real worker-completed state; never force its status in a fixture.
        await expect
            .poll(
                () =>
                    runLaravelJson(`
$run = \\App\\Domain\\Monitoring\\Discovery\\Models\\DiscoveryRun::query()
    ->whereKey(${runId})->where('discovery_scope_id', ${scopeId})
    ->whereHas('scope', fn ($query) => $query->where('site_id', ${fixture.siteId}))
    ->firstOrFail();
echo json_encode([
    'status' => $run->status,
    'completed' => $run->completed_at !== null,
    'planned' => (int) $run->planned_targets,
    'failed' => (int) $run->failed_count,
    'summary' => $run->failure_summary,
    'candidates' => $run->candidates()->count(),
    'results' => $run->results()->count(),
    'failedResults' => $run->results()->where('outcome', 'failed')->where('failure_code', 'egress_denied')->count(),
], JSON_THROW_ON_ERROR);
`),
                { timeout: 60_000, intervals: [1000, 2000] },
            )
            .toEqual({
                status: 'completed',
                completed: true,
                planned: 1,
                failed: 1,
                summary: 'egress_denied:1',
                candidates: 0,
                results: 1,
                failedResults: 1,
            });

        await updatedCard.getByRole('button', { name: 'Deactivate' }).click();
        const deactivateDialog = page.getByRole('dialog', {
            name: new RegExp(`Deactivate ${updatedScopeName}`),
        });
        await expect(deactivateDialog).toBeVisible();
        await chooseSelectOption(
            page,
            'Operational reason',
            'Approved network retired',
        );
        const deactivatedResponse = page.waitForResponse(
            (response) =>
                response
                    .url()
                    .endsWith(
                        `/security-devices/discovery/scopes/${scopeId}/deactivate`,
                    ) && response.request().method() === 'POST',
        );
        await page.getByRole('button', { name: 'Deactivate scope' }).click();
        const deactivated = await deactivatedResponse;
        expect(deactivated.status()).toBe(200);
        expect(await deactivated.json()).toEqual({
            scope: { id: scopeId, status: 'inactive' },
        });
        await expect(deactivateDialog).toBeHidden();

        await page.goto('/security-devices/discovery?tab=collectors');
        await expect(
            page.getByText(fixture.collectorName).first(),
        ).toBeVisible();

        await page
            .getByRole('button', { name: 'Enrol remote collector' })
            .click();
        const enrolDialog = page.getByRole('dialog');
        await expect(enrolDialog).toBeVisible();
        if (
            await page
                .getByRole('combobox', { name: /site/i })
                .isVisible()
                .catch(() => false)
        ) {
            await chooseSelectOption(page, 'Site', fixture.siteName);
        }
        await page
            .getByRole('button', { name: 'Issue one-time token' })
            .click();
        await expect(
            page.getByText(/not retained after this dialog closes/i),
        ).toBeVisible({
            timeout: 30_000,
        });
        await page
            .getByRole('button', { name: 'Close and clear token' })
            .click();

        const collectorCard = page.getByRole('article', {
            name: `Collector ${fixture.collectorName}`,
        });
        await expect(collectorCard).toBeVisible();
        await collectorCard
            .getByRole('button', { name: 'Revoke collector' })
            .click();
        const revokeDialog = page.getByRole('dialog', {
            name: new RegExp(`Revoke ${fixture.collectorName}`),
        });
        await expect(revokeDialog).toBeVisible();
        await page
            .getByLabel('Operational reason')
            .fill(
                'Playwright replacement after a confirmed collector host failure.',
            );
        await page.getByRole('button', { name: 'Revoke collector' }).click();

        await expect(
            collectorCard.getByRole('button', { name: 'Re-enrol collector' }),
        ).toBeVisible({ timeout: 30_000 });
        await collectorCard
            .getByRole('button', { name: 'Re-enrol collector' })
            .click();
        await page
            .getByRole('button', { name: 'Issue one-time token' })
            .click();
        await expect(
            page.getByText(/not retained after this dialog closes/i),
        ).toBeVisible({
            timeout: 30_000,
        });
        await expect(
            page.getByText(
                /former certificate and signing key remain revoked/i,
            ),
        ).toBeVisible();
        await page
            .getByRole('button', { name: 'Close and clear token' })
            .click();

        expectNoConsoleErrors(errors);
    });
});
