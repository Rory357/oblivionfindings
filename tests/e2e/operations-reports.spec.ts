import { expect, test } from '@playwright/test';

import {
    collectConsoleErrors,
    expectNoConsoleErrors,
    loginAsStaff,
    runLaravelPhp,
} from './helpers';

test.describe('operations reports', () => {
    test.skip(
        ({ viewport }) => !viewport || viewport.width < 1024,
        'Operations reports are manager desktop surfaces',
    );

    test.beforeEach(() => {
        runLaravelPhp(`
            $site = \\App\\Models\\Site::factory()->create(['name' => 'Reports Playwright Site']);
            $client = \\App\\Models\\Client::factory()->create([
                'site_id' => $site->id,
                'first_name' => 'Reports',
                'last_name' => 'Client',
                'status' => 'active',
            ]);
            $staff = \\App\\Models\\User::factory()->create([
                'name' => 'Reports Staff',
            ]);
            \\App\\Models\\BillingEntry::create([
                'client_id' => $client->id,
                'site_id' => $site->id,
                'staff_id' => $staff->id,
                'service_date' => '2026-04-10',
                'hours' => 4,
                'rate' => 100,
                'amount' => 400,
                'rate_type' => 'standard',
                'status' => 'approved',
                'site_name_snapshot' => $site->name,
                'client_name_snapshot' => 'Reports Client',
                'staff_name_snapshot' => 'Reports Staff',
            ]);
            echo 'ok';
        `);
    });

    test('manager can drill into shift operations and billing reports', async ({
        page,
    }) => {
        const consoleErrors = collectConsoleErrors(page);

        await loginAsStaff(page);
        await page.goto('/operations/reports');

        await page.getByRole('link', { name: /Shift Operations/i }).click();
        await expect(page).toHaveURL(/\/operations\/reports\/shifts/);
        await expect(
            page.getByRole('heading', {
                name: 'Shift operations',
                level: 1,
                exact: true,
            }),
        ).toBeVisible();
        // Start from a bookmarked period, then change both ends through the
        // visible date control while retaining the same report/export journey.
        await page.goto(
            '/operations/reports/shifts?date_from=2026-04-02&date_to=2026-04-29',
        );
        await page
            .getByRole('button', { name: 'Custom dates', exact: true })
            .click();
        const period = page.getByRole('dialog', {
            name: 'Custom report period',
            exact: true,
        });
        await period
            .getByRole('button', { name: 'Wed 1 April 2026', exact: true })
            .click();
        await period
            .getByRole('button', { name: 'Thu 30 April 2026', exact: true })
            .click();
        await period
            .getByRole('button', { name: 'Apply period', exact: true })
            .click();
        await expect(page).toHaveURL(/date_from=2026-04-01/);

        const downloadPromise = page.waitForEvent('download');
        await page
            .getByRole('button', { name: /Export Risk Summary CSV/i })
            .click();
        const download = await downloadPromise;
        expect(download.suggestedFilename()).toContain('risk-summary');

        await page.goto(
            '/operations/reports/billing?date_from=2026-04-01&date_to=2026-04-30',
        );
        await expect(page.getByTestId('operations-report-chart')).toBeVisible();
        await expect(page.getByText(/Billing by Status/i)).toBeVisible();

        expectNoConsoleErrors(consoleErrors);
    });
});
