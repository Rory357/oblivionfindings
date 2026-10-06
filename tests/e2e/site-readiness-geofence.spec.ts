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
        'role' => 'Site Lead',
        'phone' => '021 555 010',
        'is_primary' => true,
    ],
);

\\App\\Models\\SiteContact::query()->updateOrCreate(
    ['site_id' => $site->id, 'type' => 'emergency', 'name' => 'Geofence After Hours'],
    [
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
    test.setTimeout(90_000);
    const { siteId } = seedSiteGeofenceFixture();
    const consoleErrors = collectConsoleErrors(page);

    await loginAsStaff(page);
    await page.goto(`/sites/${siteId}`, { waitUntil: 'domcontentloaded' });

    await page.getByTestId('site-profile-tab-readiness').click();
    await expect(
        page.getByTestId('site-profile-tab-readiness'),
    ).toHaveAttribute('aria-selected', 'true');
    await expect(page.getByTestId('readiness-item-geofence')).toContainText(
        'Geofence configured',
    );

    await page.getByTestId('readiness-fix-geofence').click();
    const siteBoundaries = page.getByRole('dialog', {
        name: 'Shared boundaries · Playwright Geofence House',
        exact: true,
    });
    await expect(siteBoundaries).toBeVisible();
    await siteBoundaries
        .getByRole('link', { name: 'Create another boundary', exact: true })
        .click();
    await page.waitForURL(
        (url) =>
            url.pathname === '/fleet-assets/geofences' &&
            url.searchParams.get('site_id') === String(siteId) &&
            url.searchParams.get('new') === '1',
    );
    const wizard = page.getByRole('dialog', {
        name: 'Create boundary',
        exact: true,
    });
    await expect(
        wizard.getByRole('combobox', { name: 'Owning site', exact: true }),
    ).toContainText('Playwright Geofence House');
    await expect(wizard.getByLabel('Latitude', { exact: true })).toHaveValue(
        '-36.8485',
    );
    await expect(wizard.getByLabel('Longitude', { exact: true })).toHaveValue(
        '174.7633',
    );
    await wizard.getByRole('button', { name: 'Continue', exact: true }).click();
    await wizard.getByLabel('Radius in metres', { exact: false }).fill('120');
    await wizard
        .getByRole('checkbox', {
            name: 'I have verified the position, shape and radius or corners',
        })
        .check();
    await wizard.getByRole('button', { name: 'Continue', exact: true }).click();
    await wizard
        .getByLabel('Boundary name', { exact: false })
        .fill('Playwright House Boundary');
    await wizard
        .getByRole('checkbox', { name: 'Vehicles', exact: true })
        .check();
    await wizard
        .getByLabel('Reason for saving', { exact: false })
        .fill(
            'Verified fictional site boundary for the readiness browser check.',
        );
    await wizard.getByRole('button', { name: 'Continue', exact: true }).click();
    await wizard
        .getByRole('checkbox', {
            name: 'I reviewed the area, permitted uses and impact',
        })
        .check();
    await wizard
        .getByRole('button', { name: 'Save boundary', exact: true })
        .click();
    await expect(
        page.getByRole('heading', { name: 'Boundary saved', exact: true }),
    ).toBeVisible();
    await expect(page.getByText(/No new monitoring has started/)).toBeVisible();

    // Site entry points now share the retained boundary library. Saving an
    // area does not silently create a vehicle or personal monitoring rule.
    const boundaryId = Number(
        runLaravelPhp(
            `echo \\App\\Models\\AssetGeofence::query()->where('site_id', ${siteId})->where('name', 'Playwright House Boundary')->sole()->id;`,
        ),
    );
    expect(boundaryId).toBeGreaterThan(0);
    await page.goto(`/sites/${siteId}`);
    await page.getByTestId('site-profile-tab-readiness').click();
    await expect(page.getByTestId('readiness-fix-geofence')).toHaveCount(0);

    await page.getByTestId('site-profile-tab-overview').click();
    await expect(page.getByTestId('site-map-geofence-button')).toContainText(
        'Edit Site Geofence',
    );
    await page.getByTestId('site-map-geofence-button').click();
    const boundaryLink = siteBoundaries.getByRole('link', {
        name: /Playwright House Boundary/,
    });
    await expect(boundaryLink).toHaveAttribute(
        'href',
        `/fleet-assets/geofences?site_id=${siteId}&selected=${boundaryId}&tab=boundaries&edit=${boundaryId}`,
    );
    await siteBoundaries.press('Escape');

    await page.getByTestId('site-edit-location-button').click();
    await expect(page.getByTestId('location-geofence-button')).toBeEnabled();
    await page.getByTestId('location-geofence-button').click();
    await expect(siteBoundaries).toBeVisible();
    await expect(boundaryLink).toHaveAttribute(
        'href',
        `/fleet-assets/geofences?site_id=${siteId}&selected=${boundaryId}&tab=boundaries&edit=${boundaryId}`,
    );

    expectNoConsoleErrors(consoleErrors);
});
