<?php

namespace Tests\Feature\FleetAssets;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Asset;
use App\Models\ControlRoomAlert;
use App\Models\FleetTrip;
use App\Models\FleetVehicleStateSnapshot;
use App\Models\Permission;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class LegacyMapAccessTest extends TestCase
{
    use RefreshDatabase;

    public static function assetPermissions(): iterable
    {
        yield 'assets view any' => ['assets.viewAny'];
        yield 'assets view assigned' => ['assets.viewAssigned'];
    }

    #[DataProvider('assetPermissions')]
    public function test_assets_only_actor_cannot_receive_restricted_fleet_positions(string $permissionKey): void
    {
        $site = Site::factory()->create(['is_active' => true, 'archived' => false]);
        $actor = User::factory()->create(['approved_at' => now(), 'role' => 'support_worker']);
        HrEmployeeProfile::factory()->create(['user_id' => $actor->id, 'primary_site_id' => $site->id, 'secondary_site_ids' => [], 'start_date' => today()->subYear(), 'end_date' => null, 'is_active' => true]);
        $permission = Permission::firstOrCreate(['key' => $permissionKey], ['description' => 'Synthetic probe', 'group' => 'Fleet', 'module' => 'Fleet']);
        $actor->permissionOverrides()->attach($permission->id, ['allowed' => true]);
        $this->assertFalse($actor->canDo('fleet.viewAny'));
        $this->assertFalse($actor->canDo('assets.geofences.manage'));
        $this->assertFalse($actor->canDo('controlRoom.alerts.view'));
        foreach (['blocked', 'personal'] as $privacy) {
            $vehicle = Asset::factory()->vehicle()->forSite($site)->create(['client_id' => null, 'status' => 'active']);
            FleetVehicleStateSnapshot::create(['asset_id' => $vehicle->id, 'last_seen_at' => now(), 'latitude' => -41.28, 'longitude' => 174.77, 'status' => 'online', 'consent_blocked' => $privacy === 'blocked']);
            if ($privacy === 'personal') {
                FleetTrip::create(['asset_id' => $vehicle->id, 'started_at' => now()->subHour(), 'ended_at' => null, 'status' => 'open', 'is_personal' => true, 'consent_blocked' => false]);
            }
            ControlRoomAlert::factory()->fromFleet()->open()->create(['site_id' => $site->id, 'asset_id' => $vehicle->id]);
        }
        $response = $this->actingAs($actor)->get('/fleet-assets/map');
        $response->assertForbidden()->assertDontSee('vehicle_markers')->assertDontSee('open_alerts');
    }

    public static function mapPermissions(): iterable
    {
        yield 'Fleet reader' => ['fleet.viewAny'];
        yield 'Geofence manager' => ['assets.geofences.manage'];
    }

    #[DataProvider('mapPermissions')]
    public function test_map_capable_actor_reaches_the_canonical_workspace(string $permissionKey): void
    {
        $site = Site::factory()->create(['is_active' => true, 'archived' => false]);
        $actor = User::factory()->create(['approved_at' => now(), 'role' => 'support_worker']);
        HrEmployeeProfile::factory()->create(['user_id' => $actor->id, 'primary_site_id' => $site->id, 'secondary_site_ids' => [], 'start_date' => today()->subYear(), 'end_date' => null, 'is_active' => true]);
        $permission = Permission::firstOrCreate(['key' => $permissionKey], ['description' => 'Synthetic map access', 'group' => 'Fleet', 'module' => 'Fleet']);
        $actor->permissionOverrides()->attach($permission->id, ['allowed' => true]);
        $this->actingAs($actor)->get(route('fleet-assets.map'))->assertRedirect('/fleet-assets/geofences?tab=map');
        $this->get('/fleet-assets/geofences?tab=map')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->component('fleet-assets/geofences/index'));
    }
}
