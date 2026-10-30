<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Asset;
use App\Models\AssetGeofence;
use App\Models\BoundaryVersion;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Services\Fleet\FleetGeofenceService;
use App\Services\Sites\SiteReadinessService;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RbacSeeder::class);
});

function siteGeofenceTestUser(string $roleName = 'admin', ?Site $site = null): User
{
    $user = User::factory()->create([
        'role' => $roleName,
        'approved_at' => now(),
    ]);

    $role = Role::query()->where('name', $roleName)->first();
    if ($role) {
        $user->roles()->syncWithoutDetaching([$role->id]);
    }
    if ($site) {
        HrEmployeeProfile::factory()->create(['user_id' => $user->id, 'primary_site_id' => $site->id,
            'secondary_site_ids' => [], 'is_active' => true, 'start_date' => today()->subYear(), 'end_date' => null]);
    }

    return $user;
}

function siteGeofenceCircleShape(float $lat = -36.8485, float $lng = 174.7633): array
{
    return [
        'center' => ['lat' => $lat, 'lng' => $lng],
        'radius_m' => 250,
    ];
}

function siteBoundaryPayload(Site $site): array
{
    return ['name' => 'Shared QA area', 'address' => 'Verified site entrance', 'site_id' => $site->id,
        'geometry' => ['type' => 'circle', ...siteGeofenceCircleShape()], 'uses' => ['Vehicles', 'Assets'],
        'verified' => true, 'reason' => 'Reviewed shared area.', 'request_key' => (string) Str::uuid()];
}

test('site boundary creation stays inactive and completes geometry readiness', function () {
    $site = Site::factory()->create(['is_active' => true]);
    $user = siteGeofenceTestUser('admin', $site);
    $data = siteBoundaryPayload($site);
    $this->actingAs($user)->post("/sites/{$site->id}/geofence", $data)->assertRedirect();
    $boundary = AssetGeofence::where('site_id', $site->id)->firstOrFail();
    expect($boundary->is_active)->toBeFalse()->and($boundary->scope)->toBe('site');
    $this->assertDatabaseCount('asset_geofence_assignments', 0);
    expect(BoundaryVersion::where('boundary_id', $boundary->id)->count())->toBe(1);
    $readiness = app(SiteReadinessService::class)->evaluate($site->fresh());
    expect(collect($readiness['recommended'])->firstWhere('key', 'geofence')['done'])->toBeTrue();
});

test('explicit site boundary edits preserve purpose settings and reject stale writes', function () {
    $site = Site::factory()->create();
    $user = siteGeofenceTestUser('admin', $site);
    $first = AssetGeofence::create(['site_id' => $site->id, 'name' => 'First area', 'type' => 'circle', 'scope' => 'vehicle', 'shape' => siteGeofenceCircleShape(), 'is_active' => false]);
    $boundary = AssetGeofence::create(['site_id' => $site->id, 'name' => 'Second area', 'type' => 'circle', 'scope' => 'vehicle', 'shape' => siteGeofenceCircleShape(), 'is_active' => false, 'breach_type' => 'enter', 'alert_config' => ['priority' => 'high'], 'time_rules' => ['weekdays' => [1]]]);
    $asset = Asset::factory()->forSite($site)->create();
    $boundary->assignedAssets()->attach($asset->id);
    $data = [...siteBoundaryPayload($site), 'name' => 'Renamed second area', 'expected_revision' => 1];
    $this->actingAs($user)->put("/sites/{$site->id}/geofence/{$boundary->id}", $data)->assertRedirect();
    $boundary->refresh();
    expect($first->fresh()->name)->toBe('First area')->and($boundary->name)->toBe('Renamed second area')
        ->and($boundary->breach_type)->toBe('enter')->and($boundary->alert_config)->toBe(['priority' => 'high'])
        ->and($boundary->time_rules)->toBe(['weekdays' => [1]]);
    $this->assertDatabaseHas('asset_geofence_assignments', ['asset_geofence_id' => $boundary->id, 'asset_id' => $asset->id]);
    $data['request_key'] = (string) Str::uuid();
    $this->putJson("/sites/{$site->id}/geofence/{$boundary->id}", $data)->assertConflict();
});

test('site retirement retains evidence and blocks linked dependencies without fake crossings', function () {
    $site = Site::factory()->create();
    $user = siteGeofenceTestUser('admin', $site);
    $asset = Asset::factory()->forSite($site)->create();
    $this->actingAs($user)->post("/sites/{$site->id}/geofence", siteBoundaryPayload($site))->assertRedirect();
    $boundary = AssetGeofence::where('site_id', $site->id)->firstOrFail();
    $boundary->assignedAssets()->attach($asset->id);
    $this->deleteJson("/sites/{$site->id}/geofence/{$boundary->id}", ['expected_revision' => 1, 'reason' => 'Unused area'])->assertConflict();
    $this->postJson("/fleet-assets/geofences/{$boundary->id}/legacy-links", ['expected_revision' => 1, 'reason' => 'Remove unused legacy links', 'action' => 'unlink'])->assertOk();
    $this->delete("/sites/{$site->id}/geofence/{$boundary->id}", ['expected_revision' => 2, 'reason' => 'Unused area'])->assertRedirect();
    expect($boundary->fresh()->retired_at)->not->toBeNull();
    expect(BoundaryVersion::where('boundary_id', $boundary->id)->count())->toBe(3);
    $this->assertDatabaseCount('fleet_signals', 0);
});

test('assigned site geofence is evaluated for assigned fleet assets', function () {
    $site = Site::factory()->create();
    $assignedAsset = Asset::factory()->forSite($site)->create();
    $unassignedAsset = Asset::factory()->forSite($site)->create();

    $geofence = AssetGeofence::create([
        'site_id' => $site->id,
        'asset_id' => null,
        'name' => 'Shared vehicle boundary',
        'type' => 'circle',
        'scope' => 'vehicle',
        'shape' => siteGeofenceCircleShape(),
        'breach_type' => 'both',
        'is_active' => true,
    ]);
    $geofence->assignedAssets()->sync([$assignedAsset->id]);

    app(FleetGeofenceService::class)->evaluate($assignedAsset, -36.8485, 174.7633, now());
    app(FleetGeofenceService::class)->evaluate($unassignedAsset, -36.8485, 174.7633, now());

    $this->assertDatabaseHas('fleet_geofence_states', [
        'asset_id' => $assignedAsset->id,
        'geofence_id' => $geofence->id,
        'status' => 'inside',
    ]);
    $this->assertDatabaseMissing('fleet_geofence_states', [
        'asset_id' => $unassignedAsset->id,
        'geofence_id' => $geofence->id,
    ]);

    // Moving outside the boundary flips the assigned asset's state to a breach.
    app(FleetGeofenceService::class)->evaluate($assignedAsset, -36.9, 174.9, now());

    $this->assertDatabaseHas('fleet_geofence_states', [
        'asset_id' => $assignedAsset->id,
        'geofence_id' => $geofence->id,
        'status' => 'outside',
    ]);
    $this->assertDatabaseMissing('fleet_geofence_states', [
        'asset_id' => $unassignedAsset->id,
        'geofence_id' => $geofence->id,
    ]);
});

test('geofence containment treats invalid or incomplete shapes as outside', function () {
    $service = app(FleetGeofenceService::class);

    $circle = new AssetGeofence([
        'type' => 'circle',
        'shape' => siteGeofenceCircleShape(),
    ]);

    expect($service->isInside($circle, -36.8485, 174.7633))->toBeTrue()
        ->and($service->isInside($circle, -36.9, 174.9))->toBeFalse();

    // A circle missing its centre/radius can never contain a point.
    $invalidCircle = new AssetGeofence([
        'type' => 'circle',
        'shape' => ['radius_m' => 250],
    ]);

    expect($service->isInside($invalidCircle, -36.8485, 174.7633))->toBeFalse();

    $polygon = new AssetGeofence([
        'type' => 'polygon',
        'shape' => [
            'coordinates' => [
                ['lat' => -36.84, 'lng' => 174.75],
                ['lat' => -36.84, 'lng' => 174.78],
                ['lat' => -36.86, 'lng' => 174.765],
            ],
        ],
    ]);

    expect($service->isInside($polygon, -36.847, 174.765))->toBeTrue()
        ->and($service->isInside($polygon, -36.9, 174.9))->toBeFalse();

    // Fewer than 3 points is not a polygon — treated as outside.
    $degeneratePolygon = new AssetGeofence([
        'type' => 'polygon',
        'shape' => [
            'coordinates' => [
                ['lat' => -36.84, 'lng' => 174.75],
                ['lat' => -36.86, 'lng' => 174.765],
            ],
        ],
    ]);

    expect($service->isInside($degeneratePolygon, -36.85, 174.76))->toBeFalse();
});

test('site geofence routes require geofence management permission', function () {
    $user = siteGeofenceTestUser('team_lead');
    $site = Site::factory()->create();

    $this->actingAs($user)
        ->post("/sites/{$site->id}/geofence", [
            'name' => 'Blocked boundary',
            'type' => 'circle',
            'shape' => siteGeofenceCircleShape(),
            'breach_type' => 'both',
            'is_active' => true,
            'asset_ids' => [],
        ])
        ->assertForbidden();
});
