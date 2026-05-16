import { expect, test } from '@playwright/test';

import {
    collectConsoleErrors,
    expectNoConsoleErrors,
    loginAsStaff,
    runLaravelPhp,
} from './helpers';

function seedSiteGeofenceFixture(): { siteId: number } {
    const output = runLaravelPhp(`
\\App\\Models\\AssetGeofence::query()
    ->whereHas('site', fn ($query) => $query->where('name', 'Playwright Geofence House'))
    ->delete();

$site = \\App\\Models\\Site::query()->updateOrCreate(
    ['name' => 'Playwright Geofence House'],
    [
        'tenant_id' => 1,
        'type' => 'house',
        'address_line_1' => '1 Queen Street',
        'suburb' => 'Auckland Central',
        'city' => 'Auckland',
        'region' => 'Auckland',
        'postcode' => '1010',
        'country' => 'New Zealand',
        'latitude' => -36.8485,
        'longitude' => 174.7633,
        'phone' => '09 555 0100',
        'email' => 'geofence-house@example.test',
        'emergency_plan_location' => 'Kitchen folder',
        'medication_storage_location' => 'Medication cabinet',
        'is_active' => true,
    ],
);

\\App\\Models\\SiteContact::query()->updateOrCreate(
    ['site_id' => $site->id, 'type' => 'site_lead', 'name' => 'Geofence Site Lead'],
    [
        'tenant_id' => $site->tenant_id,
        'role' => 'Site Lead',
        'phone' => '021 555 010',
        'is_primary' => true,
    ],
);

\\App\\Models\\SiteContact::query()->updateOrCreate(
    ['site_id' => $site->id, 'type' => 'emergency', 'name' => 'Geofence After Hours'],
    [
        'tenant_id' => $site->tenant_id,
        'role' => 'After hours',
        'phone' => '0800 555 010',
    ],
);

foreach ([
    ['asset_tag' => 'GF-E2E-001', 'name' => 'Geofence Van'],
    ['asset_tag' => 'GF-E2E-002', 'name' => 'Geofence Car'],
] as $asset) {
    \\App\\Models\\Asset::query()->updateOrCreate(
        ['asset_tag' => $asset['asset_tag']],
        [
            'site_id' => $site->id,
            'name' => $asset['name'],
            'category' => 'Vehicle',
            'status' => 'active',
            'risk_level' => 'low',
            'location' => 'Driveway',
        ],
    );
}

echo json_encode(['siteId' => $site->id]);
`);

    return JSON.parse(output) as { siteId: number };
}

test('site readiness geofence flow saves a boundary and reuses the same dialog entry points', async ({
    page,
}) => {
    const { siteId } = seedSiteGeofenceFixture();
    const consoleErrors = collectConsoleErrors(page);

    await loginAsStaff(page);
    await page.goto(`/sites/${siteId}`, { waitUntil: 'domcontentloaded' });

    await page.getByTestId('site-readiness-tab').click();
    await expect(page.getByTestId('readiness-item-geofence')).toContainText(
        'Geofence configured',
    );

    await page.getByTestId('readiness-fix-geofence').click();
    await expect(page.getByTestId('site-geofence-dialog')).toBeVisible();

    await page
        .getByTestId('site-geofence-dialog')
        .locator('.leaflet-container')
        .click({ position: { x: 56, y: 220 } });
    await page.getByText('Geofence Van').click();
    await page.getByTestId('site-geofence-save').click();

    await expect(page.getByTestId('site-geofence-dialog')).toHaveCount(0);
    await page.getByTestId('site-readiness-tab').click();
    await expect(page.getByTestId('readiness-fix-geofence')).toHaveCount(0);

    await page.getByRole('tab', { name: /^Overview/ }).click();
    await expect(page.getByTestId('site-map-geofence-button')).toContainText(
        'Edit Site Geofence',
    );

    await page.getByTestId('site-edit-location-button').click();
    await expect(page.getByTestId('location-geofence-button')).toBeEnabled();
    await page.getByTestId('location-geofence-button').click();
    await expect(page.getByTestId('site-geofence-dialog')).toBeVisible();

    expectNoConsoleErrors(consoleErrors);
});
