<?php

namespace Tests\Feature\FleetAssets;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Asset;
use App\Models\AssetGeofence;
use App\Models\Client;
use App\Models\FleetResidentTransport;
use App\Models\FleetWorkOrder;
use App\Models\Permission;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class FleetPermissionBoundaryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\RbacSeeder::class);
    }

    public function test_read_only_fleet_user_can_view_work_orders_but_cannot_create_one(): void
    {
        $user = $this->makeUserWithPermissions(['fleet.viewAny']);
        $asset = Asset::factory()->vehicle()->create();

        $this->actingAs($user)
            ->get('/fleet-assets/maintenance/work-orders')
            ->assertOk();

        $this->actingAs($user)
            ->post('/fleet-assets/maintenance/work-orders', $this->workOrderPayload($asset))
            ->assertForbidden();

        $this->assertDatabaseCount('fleet_work_orders', 0);
    }

    public function test_read_only_fleet_user_can_view_geofences_but_cannot_mutate_them(): void
    {
        $user = $this->makeUserWithPermissions(['fleet.viewAny']);
        $geofence = AssetGeofence::query()->create($this->geofencePayload('Existing boundary'));

        $this->actingAs($user)
            ->get('/fleet-assets/geofences')
            ->assertOk();

        $this->actingAs($user)
            ->post('/fleet-assets/geofences', $this->geofencePayload('Forbidden boundary'))
            ->assertForbidden();

        $this->actingAs($user)
            ->put("/fleet-assets/geofences/{$geofence->id}", $this->geofencePayload('Forbidden rename'))
            ->assertForbidden();

        $this->actingAs($user)
            ->post("/fleet-assets/geofences/{$geofence->id}/toggle")
            ->assertForbidden();

        $this->actingAs($user)
            ->delete("/fleet-assets/geofences/{$geofence->id}")
            ->assertForbidden();

        $geofence->refresh();

        $this->assertSame('Existing boundary', $geofence->name);
        $this->assertTrue($geofence->is_active);
        $this->assertDatabaseCount('asset_geofences', 1);
    }

    public function test_each_work_order_management_permission_can_create_work_orders(): void
    {
        // Maintenance reports are Site-scoped and route to the Site's approved
        // Coordinator and backup.
        $site = Site::factory()->create();
        $asset = Asset::factory()->vehicle()->create(['site_id' => $site->id]);
        $coordinator = $this->makeUserWithPermissions([], $site);
        DB::table('fleet_maintenance_site_routes')->insert([
            'site_id' => $site->id,
            'coordinator_user_id' => $coordinator->id,
            'backup_user_id' => $this->makeUserWithPermissions([], $site)->id,
            'approved_by_user_id' => $coordinator->id,
            'approved_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        foreach (['fleet.maintenance.manage', 'fleet.manage'] as $permissionKey) {
            $user = $this->makeUserWithPermissions([$permissionKey], $site);

            $this->actingAs($user)
                ->post('/fleet-assets/maintenance/work-orders', $this->workOrderPayload(
                    $asset,
                    "Created with {$permissionKey}",
                ))
                ->assertRedirect();
        }

        $this->assertDatabaseCount('fleet_work_orders', 2);
    }

    public function test_each_geofence_management_permission_can_mutate_geofences(): void
    {
        foreach (['assets.geofences.manage', 'fleet.manage'] as $permissionKey) {
            $user = $this->makeUserWithPermissions([$permissionKey]);
            $name = "Boundary for {$permissionKey}";

            $this->actingAs($user)
                ->post('/fleet-assets/geofences', $this->geofencePayload($name))
                ->assertRedirect();

            $geofence = AssetGeofence::query()->where('name', $name)->firstOrFail();

            $this->actingAs($user)
                ->put("/fleet-assets/geofences/{$geofence->id}", $this->geofencePayload("Updated {$name}"))
                ->assertRedirect();

            $this->actingAs($user)
                ->post("/fleet-assets/geofences/{$geofence->id}/toggle")
                ->assertRedirect();

            $this->assertFalse($geofence->fresh()->is_active);

            $this->actingAs($user)
                ->delete("/fleet-assets/geofences/{$geofence->id}")
                ->assertRedirect();

            $this->assertDatabaseMissing('asset_geofences', ['id' => $geofence->id]);
        }
    }

    public function test_fleet_viewer_can_complete_a_transport_and_record_its_pre_check(): void
    {
        // Resident journeys are bound to the resident's Site and a vehicle at
        // that Site; a Fleet viewer may act on the journeys they drive there.
        $site = Site::factory()->create();
        $user = $this->makeUserWithPermissions(['fleet.viewAny'], $site);
        $asset = Asset::factory()->vehicle()->create(['site_id' => $site->id]);
        $resident = Client::factory()->create(['site_id' => $site->id, 'status' => 'active']);
        $transport = $this->makeTransport($asset, $user, $resident);

        $this->actingAs($user)
            ->post("/fleet-assets/transports/{$transport->id}/complete", [
                'arrived_at' => now()->toDateTimeString(),
                'notes' => 'Passenger arrived safely.',
            ])
            ->assertRedirect();

        $this->assertSame('completed', $transport->fresh()->status);

        $preCheckTransport = $this->makeTransport($asset, $user, $resident);

        $this->actingAs($user)
            ->get("/fleet-assets/transports/{$preCheckTransport->id}/pre-check")
            ->assertOk();

        $this->actingAs($user)
            ->post("/fleet-assets/transports/{$preCheckTransport->id}/pre-check", [
                'checks' => [
                    'vehicle_safe' => true,
                    'seatbelts_checked' => true,
                ],
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('fleet_transport_pre_checks', [
            'transport_id' => $preCheckTransport->id,
            'completed_by_user_id' => $user->id,
        ]);
    }

    /** @param array<int, string> $permissionKeys */
    private function makeUserWithPermissions(array $permissionKeys, ?Site $site = null): User
    {
        $user = User::factory()->create(['approved_at' => now()]);
        if ($site) {
            HrEmployeeProfile::factory()->create([
                'user_id' => $user->id,
                'primary_site_id' => $site->id,
                'secondary_site_ids' => [],
                'is_active' => true,
                'start_date' => today()->subMonth(),
            ]);
        }

        foreach ($permissionKeys as $permissionKey) {
            $permission = Permission::query()->firstOrCreate(
                ['key' => $permissionKey],
                [
                    'description' => str_replace('.', ' ', $permissionKey),
                    'group' => explode('.', $permissionKey)[0],
                ],
            );

            $user->permissionOverrides()->syncWithoutDetaching([
                $permission->id => ['allowed' => true],
            ]);
        }

        return $user;
    }

    /** @return array<string, mixed> */
    private function workOrderPayload(Asset $asset, string $title = 'Replace worn tyre'): array
    {
        return [
            'asset_id' => $asset->id,
            'title' => $title,
            'priority' => 'high',
            'description' => 'Rear tyre is below the safe tread threshold.',
            'request_key' => (string) Str::uuid(),
        ];
    }

    /** @return array<string, mixed> */
    private function geofencePayload(string $name): array
    {
        return [
            'name' => $name,
            'type' => 'circle',
            'scope' => 'vehicle',
            'shape' => [
                'center' => ['lat' => -36.8485, 'lng' => 174.7633],
                'radius_m' => 150,
            ],
            'breach_type' => 'both',
            'is_active' => true,
        ];
    }

    private function makeTransport(Asset $asset, User $driver, Client $resident): FleetResidentTransport
    {
        return FleetResidentTransport::query()->create([
            'journey_uuid' => (string) Str::uuid(),
            'asset_id' => $asset->id,
            'site_id' => $resident->site_id,
            'driver_user_id' => $driver->id,
            'resident_id' => $resident->id,
            'resident_name' => trim($resident->first_name.' '.$resident->last_name),
            'transport_type' => 'community',
            'departed_at' => now()->subHour(),
            'status' => 'in_progress',
            'version' => 1,
        ]);
    }
}
