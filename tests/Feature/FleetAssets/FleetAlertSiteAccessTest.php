<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Asset;
use App\Models\AssetAlert;
use App\Models\Client;
use App\Models\ControlRoomAlert;
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

it('scopes canonical and archived Fleet alerts to the viewer Sites', function () {
    $localSite = Site::factory()->create(['name' => 'Local alert Site']);
    $otherSite = Site::factory()->create(['name' => 'Other alert Site']);
    $localAsset = fleetAlertAssetAt($localSite, 'Local response vehicle');
    $otherAsset = fleetAlertAssetAt($otherSite, 'Other response vehicle');
    $localAlert = fleetAlertFor($localSite, $localAsset);
    fleetAlertFor($otherSite, $otherAsset);
    $localArchived = archivedFleetAlertFor($localAsset);
    archivedFleetAlertFor($otherAsset);
    $viewer = fleetAlertViewerAt($localSite, ['assets.alerts.view']);

    $this->actingAs($viewer)
        ->get('/fleet-assets/alerts')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('fleet-assets/alerts/index')
            ->where('control_room_alerts.meta.total', 1)
            ->where('control_room_alerts.data.0.id', $localAlert->id)
            ->has('archived_asset_alerts', 1)
            ->where('archived_asset_alerts.0.id', $localArchived->id));

    $this->actingAs($viewer)
        ->get('/fleet-assets/alerts?asset_id='.$otherAsset->id)
        ->assertForbidden();
});

it('allows explicit Fleet management to review alerts across active Sites', function () {
    $localSite = Site::factory()->create(['name' => 'Fleet manager home Site']);
    $otherSite = Site::factory()->create(['name' => 'Fleet manager remote Site']);
    $localAsset = fleetAlertAssetAt($localSite, 'Manager local vehicle');
    $otherAsset = fleetAlertAssetAt($otherSite, 'Manager remote vehicle');
    fleetAlertFor($localSite, $localAsset);
    fleetAlertFor($otherSite, $otherAsset);
    archivedFleetAlertFor($localAsset);
    archivedFleetAlertFor($otherAsset);
    $manager = fleetAlertViewerAt($localSite, ['assets.alerts.view', 'fleet.manage']);

    $this->actingAs($manager)
        ->get('/fleet-assets/alerts')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('fleet-assets/alerts/index')
            ->where('control_room_alerts.meta.total', 2)
            ->has('archived_asset_alerts', 2));

    $this->actingAs($manager)
        ->get('/fleet-assets/alerts?asset_id='.$otherAsset->id)
        ->assertOk();
});

function fleetAlertAssetAt(Site $site, string $name): Asset
{
    $client = Client::factory()->create(['site_id' => $site->id]);

    return Asset::factory()->vehicle()->create([
        'site_id' => $site->id,
        'home_site_id' => $site->id,
        'client_id' => $client->id,
        'name' => $name,
        'status' => 'active',
    ]);
}

function fleetAlertFor(Site $site, Asset $asset): ControlRoomAlert
{
    return ControlRoomAlert::factory()->fromFleet()->open()->create([
        'site_id' => $site->id,
        'asset_id' => $asset->id,
    ]);
}

function archivedFleetAlertFor(Asset $asset): AssetAlert
{
    return AssetAlert::query()->create([
        'asset_id' => $asset->id,
        'alert_type' => 'archived_vehicle_alert',
        'severity' => 'medium',
        'status' => 'open',
        'triggered_at' => now(),
    ]);
}

/** @param list<string> $permissionKeys */
function fleetAlertViewerAt(Site $site, array $permissionKeys): User
{
    $viewer = User::factory()->create(['approved_at' => now()]);
    HrEmployeeProfile::factory()->create([
        'user_id' => $viewer->id,
        'primary_site_id' => $site->id,
        'secondary_site_ids' => [],
        'is_active' => true,
    ]);
    $role = Role::query()->create([
        'name' => 'fleet_alerts_'.str()->uuid(),
        'label' => 'Fleet alerts test role',
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
