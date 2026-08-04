<?php

namespace Tests\Feature\Fleet;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Monitoring\Enums\MonitorState;
use App\Domain\Monitoring\Models\Monitor;
use App\Domain\SecurityDevices\Enums\DeviceStatus;
use App\Domain\SecurityDevices\Enums\LinkType;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssetLink;
use App\Domain\SecurityDevices\Models\DeviceMaintenanceRecord;
use App\Http\Middleware\HandleInertiaRequests;
use App\Models\Asset;
use App\Models\ItTicket;
use App\Models\ItTicketLink;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SecurityDevicesPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FleetVehicleTechnologyProjectionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
        $this->seed(SecurityDevicesPermissionsSeeder::class);
    }

    public function test_vehicle_profile_lazily_projects_only_canonical_installed_technology(): void
    {
        $site = $this->site();
        $vehicle = Asset::factory()->vehicle()->create([
            'site_id' => $site->id,
            'home_site_id' => $site->id,
            'name' => 'Community van 12',
        ]);
        $otherVehicle = Asset::factory()->vehicle()->create([
            'site_id' => $site->id,
            'home_site_id' => $site->id,
        ]);
        $viewer = $this->admin();
        $device = Device::factory()->tracking()->create([
            'name' => 'Van 12 telematics gateway',
            'status' => DeviceStatus::Offline,
            'firmware_version' => '1.2.0',
            'battery_level' => 48,
            'last_seen_at' => now()->subMinutes(20),
            'config' => [
                'observed' => [
                    'configuration_hash' => 'observed-safe-hash',
                    'configuration_at' => now()->subHour()->toIso8601String(),
                ],
                'desired' => [
                    'configuration_hash' => 'desired-safe-hash',
                    'firmware_version' => '1.3.0',
                ],
                'raw_provider_payload' => 'RAW-PROVIDER-SENTINEL',
            ],
            'meta' => [
                'private_driver_location' => 'PRIVATE-LOCATION-SENTINEL',
            ],
        ]);
        $outsideDevice = Device::factory()->security()->create([
            'name' => 'OTHER-VEHICLE-DEVICE-SENTINEL',
        ]);
        $this->install($device, $vehicle);
        $this->install($outsideDevice, $otherVehicle);

        Monitor::factory()->create([
            'device_id' => $device->id,
            'name' => 'Vehicle gateway availability',
            'current_state' => MonitorState::Failed,
            'effective_state' => MonitorState::Failed,
            'is_enabled' => true,
            'last_observation_at' => now()->subMinutes(2),
            'target' => 'PROBE-TARGET-SENTINEL',
            'config' => ['secret' => 'MONITOR-SECRET-SENTINEL'],
        ]);
        DeviceMaintenanceRecord::query()->create([
            'device_id' => $device->id,
            'type' => 'firmware_update',
            'status' => 'scheduled',
            'scheduled_for' => today()->subDay(),
            'description' => 'Update the telematics gateway',
            'notes' => 'MAINTENANCE-NOTES-SENTINEL',
        ]);
        $ticket = ItTicket::factory()->create([
            'requester_user_id' => $viewer->id,
            'site_id' => $site->id,
            'title' => 'Restore van gateway',
            'status' => 'open',
        ]);
        ItTicketLink::query()->create([
            'ticket_id' => $ticket->id,
            'relationship' => 'affected_device',
            'linkable_type' => Device::class,
            'linkable_id' => $device->id,
            'context' => ['secret' => 'TICKET-CONTEXT-SENTINEL'],
            'created_by_user_id' => $viewer->id,
        ]);

        $this->actingAs($viewer)
            ->get("/fleet-assets/vehicles/{$vehicle->id}?tab=technology")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('fleet-assets/vehicles/show')
                ->where('can.view_vehicle_technology', true)
                ->missing('vehicle_technology'));

        $response = $this->partialTechnology($viewer, $vehicle)
            ->assertOk()
            ->assertHeader('X-Inertia', 'true')
            ->assertJsonPath('component', 'fleet-assets/vehicles/show')
            ->assertJsonPath('props.vehicle_technology.summary.total', 1)
            ->assertJsonPath('props.vehicle_technology.summary.attention', 1)
            ->assertJsonPath('props.vehicle_technology.summary.monitor_alerts', 1)
            ->assertJsonPath('props.vehicle_technology.summary.configuration_drift', 1)
            ->assertJsonPath('props.vehicle_technology.summary.firmware_updates', 1)
            ->assertJsonPath('props.vehicle_technology.summary.overdue_maintenance', 1)
            ->assertJsonPath('props.vehicle_technology.summary.open_it_work', 1)
            ->assertJsonPath('props.vehicle_technology.devices.0.id', $device->id)
            ->assertJsonPath('props.vehicle_technology.devices.0.name', 'Van 12 telematics gateway')
            ->assertJsonPath('props.vehicle_technology.devices.0.monitoring.states.0.name', 'Vehicle gateway availability')
            ->assertJsonPath('props.vehicle_technology.devices.0.configuration.state', 'drifted')
            ->assertJsonPath('props.vehicle_technology.devices.0.firmware.desired_version', '1.3.0')
            ->assertJsonPath('props.vehicle_technology.devices.0.it_work.items.0.id', $ticket->id)
            ->assertJsonPath('props.vehicle_technology.links.tracking', '/security-devices/tracking?tab=fleet');

        $payload = json_encode($response->json('props.vehicle_technology'), JSON_THROW_ON_ERROR);
        foreach ([
            'OTHER-VEHICLE-DEVICE-SENTINEL',
            'RAW-PROVIDER-SENTINEL',
            'PRIVATE-LOCATION-SENTINEL',
            'PROBE-TARGET-SENTINEL',
            'MONITOR-SECRET-SENTINEL',
            'MAINTENANCE-NOTES-SENTINEL',
            'TICKET-CONTEXT-SENTINEL',
        ] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $payload);
        }
        $projectedDevice = $response->json('props.vehicle_technology.devices.0');
        $this->assertArrayNotHasKey('config', $projectedDevice);
        $this->assertArrayNotHasKey('meta', $projectedDevice);
    }

    public function test_source_permission_and_site_access_fail_closed(): void
    {
        $site = $this->site();
        $outsideSite = $this->site();
        $vehicle = Asset::factory()->vehicle()->create([
            'site_id' => $site->id,
            'home_site_id' => $site->id,
        ]);
        $outsideVehicle = Asset::factory()->vehicle()->create([
            'site_id' => $outsideSite->id,
            'home_site_id' => $outsideSite->id,
        ]);
        $viewer = User::factory()->create(['approved_at' => now()]);
        HrEmployeeProfile::factory()->create([
            'user_id' => $viewer->id,
            'primary_site_id' => $site->id,
            'is_active' => true,
            'start_date' => today()->subYear(),
        ]);
        $this->grant($viewer, ['fleet.viewAny', 'securityDevices.devices.view']);

        $this->actingAs($viewer)
            ->get("/fleet-assets/vehicles/{$vehicle->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('can.view_vehicle_technology', true)
                ->missing('vehicle_technology'));
        $this->actingAs($viewer)
            ->get("/fleet-assets/vehicles/{$outsideVehicle->id}")
            ->assertNotFound();

        $permission = Permission::query()->where('key', 'securityDevices.devices.view')->firstOrFail();
        $viewer->permissionOverrides()->updateExistingPivot($permission->id, ['allowed' => false]);
        $viewer->unsetRelation('permissionOverrides');

        $this->actingAs($viewer->fresh())
            ->get("/fleet-assets/vehicles/{$vehicle->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('can.view_vehicle_technology', false)
                ->missing('vehicle_technology'));
        $this->partialTechnology($viewer->fresh(), $vehicle)
            ->assertOk()
            ->assertJsonPath('props.vehicle_technology', null);
    }

    private function site(): Site
    {
        return Site::factory()->create([
            'is_active' => true,
            'archived' => false,
            'archived_at' => null,
        ]);
    }

    private function admin(): User
    {
        $viewer = User::factory()->create(['approved_at' => now()]);
        $viewer->roles()->attach(Role::query()->where('name', 'admin')->firstOrFail());

        return $viewer;
    }

    private function install(Device $device, Asset $vehicle): void
    {
        DeviceAssetLink::query()->create([
            'device_id' => $device->id,
            'asset_id' => $vehicle->id,
            'link_type' => LinkType::InstalledIn,
            'linked_at' => now(),
        ]);
    }

    /** @param list<string> $keys */
    private function grant(User $viewer, array $keys): void
    {
        $permissions = Permission::query()->whereIn('key', $keys)->get();
        $this->assertCount(count($keys), $permissions);
        $viewer->permissionOverrides()->syncWithoutDetaching(
            $permissions->mapWithKeys(fn (Permission $permission): array => [
                $permission->id => ['allowed' => true],
            ])->all(),
        );
    }

    private function partialTechnology(User $viewer, Asset $vehicle)
    {
        $version = app(HandleInertiaRequests::class)->version(request());

        return $this->actingAs($viewer)->get("/fleet-assets/vehicles/{$vehicle->id}?tab=technology", [
            'X-Inertia' => 'true',
            'X-Inertia-Version' => $version,
            'X-Inertia-Partial-Component' => 'fleet-assets/vehicles/show',
            'X-Inertia-Partial-Data' => 'vehicle_technology',
        ]);
    }
}
