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
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class LegacyMapProjectionProbeTest extends TestCase
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
        if ($response->status() === 200) {
            $props = $response->viewData('page')['props'];
            $observation = ['permission' => $permissionKey, 'status' => 200, 'component' => $response->viewData('page')['component'], 'exposed_vehicle_count' => count($props['vehicle_markers'] ?? []), 'exposed_positions' => collect($props['vehicle_markers'] ?? [])->map(fn ($row) => ['lat' => $row['lat'], 'lng' => $row['lng'], 'consent_blocked' => $row['consent_blocked']])->all(), 'open_alerts' => $props['open_alerts'] ?? null];
            file_put_contents(getenv('CONSOLIDATION_PROBE_OUTPUT'), json_encode($observation, JSON_THROW_ON_ERROR).PHP_EOL, FILE_APPEND);
        }
        $response->assertForbidden();
    }
}
