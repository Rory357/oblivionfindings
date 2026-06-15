<?php

use App\Models\Asset;
use App\Models\AssetGeofence;
use App\Models\FleetGeofenceState;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Services\AssetGeofenceEvaluator;
use App\Services\Fleet\FleetGeofenceService;
use App\Services\Sites\SiteReadinessService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(\Database\Seeders\RbacSeeder::class);
});

function siteGeofenceTestUser(string $roleName = 'admin'): User
{
    $user = User::factory()->create([
        'role' => $roleName,
        'approved_at' => now(),
    ]);

    $role = Role::query()->where('name', $roleName)->first();
    if ($role) {
        $user->roles()->syncWithoutDetaching([$role->id]);
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

test('site geofence can be created with assigned assets and completes readiness item', function () {
    $user = siteGeofenceTestUser();
    $site = Site::factory()->create([
        'name' => 'Kauri House',
        'type' => 'house',
        'latitude' => -36.8485,
        'longitude' => 174.7633,
        'is_active' => true,
    ]);
    $assets = Asset::factory()->count(2)->forSite($site)->create();

    $this->actingAs($user)
        ->post("/sites/{$site->id}/geofence", [
            'name' => 'Kauri House Geofence',
            'type' => 'circle',
            'shape' => siteGeofenceCircleShape(),
            'breach_type' => 'both',
            'is_active' => true,
            'asset_ids' => $assets->pluck('id')->all(),
        ])
        ->assertRedirect();

    $geofence = AssetGeofence::query()
        ->where('site_id', $site->id)
        ->first();

    expect($geofence)->not->toBeNull()
        ->and($geofence->asset_id)->toBeNull()
        ->and($geofence->scope)->toBe('house')
        ->and($geofence->breach_type)->toBe('both');

    foreach ($assets as $asset) {
        $this->assertDatabaseHas('asset_geofence_assignments', [
            'asset_geofence_id' => $geofence->id,
            'asset_id' => $asset->id,
        ]);
    }

    $readiness = app(SiteReadinessService::class)->evaluate($site->fresh());
    $geofenceItem = collect($readiness['recommended'])->firstWhere('key', 'geofence');

    expect($geofenceItem['done'])->toBeTrue();
});

test('site geofence update syncs assigned assets', function () {
    $user = siteGeofenceTestUser();
    $site = Site::factory()->create();
    [$keptAsset, $removedAsset, $addedAsset] = Asset::factory()
        ->count(3)
        ->forSite($site)
        ->create()
        ->all();

    $this->actingAs($user)
        ->post("/sites/{$site->id}/geofence", [
            'name' => 'Original boundary',
            'type' => 'circle',
            'shape' => siteGeofenceCircleShape(),
            'breach_type' => 'enter',
            'is_active' => true,
            'asset_ids' => [$keptAsset->id, $removedAsset->id],
        ])
        ->assertRedirect();

    $geofence = AssetGeofence::query()->where('site_id', $site->id)->firstOrFail();

    $this->actingAs($user)
        ->put("/sites/{$site->id}/geofence/{$geofence->id}", [
            'name' => 'Updated boundary',
            'type' => 'circle',
            'shape' => siteGeofenceCircleShape(-36.85, 174.76),
            'breach_type' => 'exit',
            'is_active' => true,
            'asset_ids' => [$keptAsset->id, $addedAsset->id],
        ])
        ->assertRedirect();

    $geofence->refresh();

    expect($geofence->name)->toBe('Updated boundary')
        ->and($geofence->breach_type)->toBe('exit');

    $this->assertDatabaseHas('asset_geofence_assignments', [
        'asset_geofence_id' => $geofence->id,
        'asset_id' => $keptAsset->id,
    ]);
    $this->assertDatabaseHas('asset_geofence_assignments', [
        'asset_geofence_id' => $geofence->id,
        'asset_id' => $addedAsset->id,
    ]);
    $this->assertDatabaseMissing('asset_geofence_assignments', [
        'asset_geofence_id' => $geofence->id,
        'asset_id' => $removedAsset->id,
    ]);
});

test('site geofence delete removes assignments and fleet geofence state', function () {
    $user = siteGeofenceTestUser();
    $site = Site::factory()->create();
    $asset = Asset::factory()->forSite($site)->create();

    $this->actingAs($user)
        ->post("/sites/{$site->id}/geofence", [
            'name' => 'Exit boundary',
            'type' => 'circle',
            'shape' => siteGeofenceCircleShape(),
            'breach_type' => 'both',
            'is_active' => true,
            'asset_ids' => [$asset->id],
        ])
        ->assertRedirect();

    $geofence = AssetGeofence::query()->where('site_id', $site->id)->firstOrFail();

    FleetGeofenceState::create([
        'asset_id' => $asset->id,
        'geofence_id' => $geofence->id,
        'status' => 'inside',
        'last_changed_at' => now(),
    ]);

    $this->actingAs($user)
        ->delete("/sites/{$site->id}/geofence/{$geofence->id}")
        ->assertRedirect();

    $this->assertDatabaseMissing('asset_geofences', [
        'id' => $geofence->id,
    ]);
    $this->assertDatabaseMissing('asset_geofence_assignments', [
        'asset_geofence_id' => $geofence->id,
    ]);
    $this->assertDatabaseMissing('fleet_geofence_states', [
        'geofence_id' => $geofence->id,
    ]);
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

    $breaches = app(AssetGeofenceEvaluator::class)->evaluate($assignedAsset, -36.9, 174.9);

    expect($breaches)->toHaveCount(1)
        ->and($breaches[0]->id)->toBe($geofence->id);
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
