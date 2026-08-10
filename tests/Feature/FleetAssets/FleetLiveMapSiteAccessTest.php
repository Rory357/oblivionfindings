<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Asset;
use App\Models\ControlRoomAlert;
use App\Models\FleetVehicleStateSnapshot;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RbacSeeder::class);
});

it('uses canonical Site access for map vehicles houses and alert counts', function () {
    $localSite = Site::factory()->create([
        'name' => 'Local operations Site',
        'type' => 'house',
        'latitude' => -41.28,
        'longitude' => 174.77,
    ]);
    $otherSite = Site::factory()->create([
        'name' => 'Other operations Site',
        'type' => 'house',
        'latitude' => -36.84,
        'longitude' => 174.76,
    ]);
    $localVehicle = mapVehicleAt($localSite, 'Local vehicle');
    $otherVehicle = mapVehicleAt($otherSite, 'Other vehicle');
    ControlRoomAlert::factory()->fromFleet()->open()->create([
        'site_id' => $localSite->id,
        'asset_id' => $localVehicle->id,
    ]);
    ControlRoomAlert::factory()->fromFleet()->open()->create([
        'site_id' => $otherSite->id,
        'asset_id' => $otherVehicle->id,
    ]);

    $viewer = mapViewerAt($localSite, ['fleet.viewAny']);

    $this->actingAs($viewer)
        ->get('/fleet-assets/map')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('fleet-assets/map')
            ->where('vehicle_markers', fn ($rows) => collect($rows)->pluck('id')->all() === [$localVehicle->id])
            ->where('house_markers', fn ($rows) => collect($rows)->pluck('id')->all() === [$localSite->id])
            ->where('open_alerts', 1));
});

it('allows explicit fleet management to operate across active Sites', function () {
    $localSite = Site::factory()->create([
        'name' => 'Manager home Site',
        'type' => 'house',
        'latitude' => -41.28,
        'longitude' => 174.77,
    ]);
    $otherSite = Site::factory()->create([
        'name' => 'Remote managed Site',
        'type' => 'house',
        'latitude' => -36.84,
        'longitude' => 174.76,
    ]);
    $localVehicle = mapVehicleAt($localSite, 'Manager local vehicle');
    $otherVehicle = mapVehicleAt($otherSite, 'Manager remote vehicle');
    $manager = mapViewerAt($localSite, ['fleet.viewAny', 'fleet.manage']);

    $this->actingAs($manager)
        ->get('/fleet-assets/map')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('fleet-assets/map')
            ->where('vehicle_markers', fn ($rows) => collect($rows)
                ->pluck('id')
                ->sort()
                ->values()
                ->all() === collect([$localVehicle->id, $otherVehicle->id])->sort()->values()->all())
            ->where('house_markers', fn ($rows) => collect($rows)
                ->pluck('id')
                ->sort()
                ->values()
                ->all() === collect([$localSite->id, $otherSite->id])->sort()->values()->all()));
});

function mapVehicleAt(Site $site, string $name): Asset
{
    $vehicle = Asset::factory()->vehicle()->create([
        'site_id' => $site->id,
        'home_site_id' => $site->id,
        'name' => $name,
        'status' => 'active',
    ]);
    FleetVehicleStateSnapshot::query()->create([
        'asset_id' => $vehicle->id,
        'last_seen_at' => now(),
        'latitude' => (float) $site->latitude,
        'longitude' => (float) $site->longitude,
        'status' => 'online',
    ]);

    return $vehicle;
}

/** @param list<string> $permissionKeys */
function mapViewerAt(Site $site, array $permissionKeys): User
{
    $viewer = User::factory()->create(['approved_at' => now()]);
    HrEmployeeProfile::factory()->create([
        'user_id' => $viewer->id,
        'primary_site_id' => $site->id,
        'secondary_site_ids' => [],
        'is_active' => true,
    ]);
    $role = Role::query()->create([
        'name' => 'fleet_map_'.str()->uuid(),
        'label' => 'Fleet map test role',
        'level' => 50,
        'type' => 'custom',
    ]);
    foreach ($permissionKeys as $key) {
        Permission::query()->firstOrCreate(
            ['key' => $key],
            ['description' => $key, 'group' => 'Fleet', 'module' => 'Fleet'],
        );
    }
    $role->permissions()->sync(Permission::query()->whereIn('key', $permissionKeys)->pluck('id'));
    $viewer->roles()->attach($role->id);

    return $viewer;
}
