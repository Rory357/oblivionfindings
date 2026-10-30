<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Asset;
use App\Models\AssetGeofence;
use App\Models\ControlRoomAlert;
use App\Models\FleetSignal;
use App\Models\FleetTelemetryEvent;
use App\Models\FleetTrip;
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

it('redirects the legacy map and scopes current resources Sites and alert projections', function () {
    $localSite = Site::factory()->create(['name' => 'Local operations Site', 'is_active' => true, 'archived' => false, 'type' => 'house', 'latitude' => -41.28, 'longitude' => 174.77]);
    $otherSite = Site::factory()->create(['name' => 'Other operations Site', 'is_active' => true, 'archived' => false, 'type' => 'house', 'latitude' => -36.84, 'longitude' => 174.76]);
    $localVehicle = mapVehicleAt($localSite, 'Local vehicle');
    $otherVehicle = mapVehicleAt($otherSite, 'Other vehicle');
    foreach ([$localVehicle, $otherVehicle] as $vehicle) {
        $boundary = AssetGeofence::create(['name' => 'Synthetic map boundary', 'site_id' => $vehicle->site_id, 'scope' => 'site', 'type' => 'circle', 'is_active' => false, 'shape' => ['center' => ['lat' => -41.28, 'lng' => 174.77], 'radius_m' => 180]]);
        $source = $vehicle->fleetState->lastEvent;
        $signal = FleetSignal::create(['asset_id' => $vehicle->id, 'geofence_id' => $boundary->id, 'source_event_id' => $source->id, 'signal_type' => 'geofence_enter', 'occurred_at' => $source->occurred_at, 'idempotency_key' => (string) str()->uuid(), 'payload' => []]);
        ControlRoomAlert::factory()->fromFleet()->open()->create(['site_id' => $vehicle->site_id, 'asset_id' => $vehicle->id, 'fleet_signal_id' => $signal->id]);
    }
    $viewer = mapViewerAt($localSite, ['fleet.viewAny']);
    $this->actingAs($viewer)->get('/fleet-assets/map')->assertRedirect('/fleet-assets/geofences?tab=map');
    $this->get('/fleet-assets/geofences?tab=map')->assertOk()->assertInertia(fn (Assert $page) => $page->component('fleet-assets/geofences/index'));
    $this->getJson('/fleet-assets/geofences/resources')->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.id', $localVehicle->id)->assertJsonPath('data.0.position.lat', -41.28);
    $this->getJson('/fleet-assets/geofences/resources?id='.$otherVehicle->id)->assertOk()->assertJsonCount(0, 'data');
    $this->getJson('/fleet-assets/geofences/sites')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $localSite->id);
    $this->getJson('/fleet-assets/geofences/sites?id='.$otherSite->id)->assertOk()->assertJsonCount(0, 'data');
    $this->getJson('/fleet-assets/geofences/summary')->assertOk()->assertJsonPath('follow_up', 0);
    $permission = Permission::query()->where('key', 'controlRoom.alerts.view')->firstOrFail();
    $viewer->permissionOverrides()->attach($permission->id, ['allowed' => true]);
    $this->actingAs($viewer->fresh())->getJson('/fleet-assets/geofences/summary')->assertOk()->assertJsonPath('follow_up', 1);
    $localVehicle->fleetState->lastEvent->update(['consent_blocked' => true]);
    $this->getJson('/fleet-assets/geofences/resources')->assertOk()->assertJsonPath('data.0.position', null);
    $this->getJson('/fleet-assets/geofences/summary')->assertOk()->assertJsonPath('follow_up', 0);
    $localVehicle->fleetState->lastEvent->update(['consent_blocked' => false]);
    FleetTrip::create(['asset_id' => $localVehicle->id, 'started_at' => now()->subHour(), 'ended_at' => null, 'status' => 'open', 'is_personal' => true, 'consent_blocked' => false]);
    $this->getJson('/fleet-assets/geofences/resources')->assertOk()->assertJsonPath('data.0.position', null);
    $this->getJson('/fleet-assets/geofences/summary')->assertOk()->assertJsonPath('follow_up', 0);
});

it('requires approved Sites even for fleet managers and includes an approved secondary Site', function () {
    $localSite = Site::factory()->create(['name' => 'Manager home Site', 'is_active' => true, 'archived' => false, 'type' => 'house', 'latitude' => -41.28, 'longitude' => 174.77]);
    $otherSite = Site::factory()->create(['name' => 'Remote managed Site', 'is_active' => true, 'archived' => false, 'type' => 'house', 'latitude' => -36.84, 'longitude' => 174.76]);
    $localVehicle = mapVehicleAt($localSite, 'Manager local vehicle');
    $otherVehicle = mapVehicleAt($otherSite, 'Manager remote vehicle');
    $manager = mapViewerAt($localSite, ['fleet.viewAny', 'fleet.manage']);
    $this->actingAs($manager)->get('/fleet-assets/map')->assertRedirect('/fleet-assets/geofences?tab=map');
    $this->get('/fleet-assets/geofences?tab=map')->assertOk()->assertInertia(fn (Assert $page) => $page->component('fleet-assets/geofences/index'));
    $this->getJson('/fleet-assets/geofences/resources')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $localVehicle->id);
    $this->getJson('/fleet-assets/geofences/sites')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $localSite->id);
    HrEmployeeProfile::query()->where('user_id', $manager->id)->firstOrFail()->update(['secondary_site_ids' => [$otherSite->id]]);
    $this->actingAs($manager->fresh());
    $resources = $this->getJson('/fleet-assets/geofences/resources')->assertOk()->json('data');
    expect(collect($resources)->pluck('id')->sort()->values()->all())->toBe(collect([$localVehicle->id, $otherVehicle->id])->sort()->values()->all());
    $sites = $this->getJson('/fleet-assets/geofences/sites')->assertOk()->json('data');
    expect(collect($sites)->pluck('id')->sort()->values()->all())->toBe(collect([$localSite->id, $otherSite->id])->sort()->values()->all());
});

function mapVehicleAt(Site $site, string $name): Asset
{
    $vehicle = Asset::factory()->vehicle()->create([
        'site_id' => $site->id,
        'home_site_id' => $site->id,
        'name' => $name,
        'status' => 'active',
        'client_id' => null,
    ]);
    $event = FleetTelemetryEvent::create(['asset_id' => $vehicle->id, 'vendor' => 'synthetic-map', 'occurred_at' => now(), 'received_at' => now(), 'latitude' => $site->latitude, 'longitude' => $site->longitude, 'accuracy_m' => 15, 'event_type' => 'position', 'idempotency_key' => (string) str()->uuid(), 'consent_blocked' => false]);
    FleetVehicleStateSnapshot::query()->create([
        'last_event_id' => $event->id,
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
        'start_date' => today()->subYear(),
        'end_date' => null,
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
