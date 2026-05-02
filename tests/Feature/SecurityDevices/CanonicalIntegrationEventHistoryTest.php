<?php

namespace Tests\Feature\SecurityDevices;

use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Models\Client;
use App\Models\Integration\IntegrationEvent;
use App\Models\LocationHardware;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SecurityDevicesPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class CanonicalIntegrationEventHistoryTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $portalUser;
    private Site $site;
    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
        $this->seed(SecurityDevicesPermissionsSeeder::class);

        $this->admin = User::factory()->create(['role' => 'admin']);
        $this->admin->roles()->attach(Role::where('name', 'admin')->first());

        $this->portalUser = User::factory()->create();
        $this->site = Site::factory()->create();
        // Note: `clients` has no tenant_id column — use `organization_id`.
        $this->client = Client::factory()->create([
            'status' => 'active',
            'site_id' => $this->site->id,
        ]);

        $this->portalUser->portalClients()->attach($this->client->id, [
            'relation' => 'family',
        ]);
    }

    public function test_client_location_history_reads_canonical_integration_event_identity(): void
    {
        $device = $this->assignTrackingDevice();

        $this->createIntegrationEvent($device, [
            'canonical_device_id' => $device->id,
            'hardware_id' => null,
            'raw_payload' => [
                'latitude' => -36.8485,
                'longitude' => 174.7633,
                'speed' => 14,
                'battery' => 82,
            ],
        ]);

        $response = $this->actingAs($this->admin)
            ->get("/operations/clients/{$this->client->id}/location/history");

        $response->assertOk()
            ->assertJsonCount(1, 'locations')
            ->assertJsonPath('locations.0.lat', -36.8485)
            ->assertJsonPath('locations.0.lng', 174.7633)
            ->assertJsonPath('locations.0.speed', 14)
            ->assertJsonPath('locations.0.battery', 82);
    }

    public function test_resident_tracking_history_reads_canonical_integration_event_identity(): void
    {
        $device = $this->assignTrackingDevice();

        $this->createIntegrationEvent($device, [
            'canonical_device_id' => $device->id,
            'hardware_id' => null,
            'event_type' => 'location_update',
            'raw_payload' => [
                'lat' => -41.2866,
                'lng' => 174.7756,
                'speed' => 9,
                'battery' => 61,
            ],
        ]);

        $response = $this->actingAs($this->admin)
            ->get("/fleet-assets/resident-tracking/history/{$this->client->id}");

        $response->assertOk();
        $response->assertInertia(function ($page) {
            $locations = $page->toArray()['props']['locations'];

            $this->assertCount(1, $locations);
            $this->assertEquals(-41.2866, $locations[0]['lat']);
            $this->assertEquals(174.7756, $locations[0]['lng']);
            $this->assertEquals(9, $locations[0]['speed']);
            $this->assertEquals(61, $locations[0]['battery']);
            $this->assertEquals('location_update', $locations[0]['event_type']);
        });
    }

    public function test_portal_location_history_reads_canonical_integration_event_identity(): void
    {
        $device = $this->assignTrackingDevice();

        $this->createIntegrationEvent($device, [
            'canonical_device_id' => $device->id,
            'hardware_id' => null,
            'raw_payload' => [
                'latitude' => -43.5321,
                'longitude' => 172.6362,
                'speed' => 5,
                'battery_level' => 47,
            ],
        ]);

        $response = $this->actingAs($this->portalUser)
            ->get("/portal/clients/{$this->client->id}/location/history");

        $response->assertOk()
            ->assertJsonCount(1, 'locations')
            ->assertJsonPath('locations.0.lat', -43.5321)
            ->assertJsonPath('locations.0.lng', 172.6362)
            ->assertJsonPath('locations.0.speed', 5)
            ->assertJsonPath('locations.0.battery', 47);
    }

    public function test_client_location_history_keeps_narrow_fallback_for_unbackfilled_legacy_rows(): void
    {
        $hardware = LocationHardware::create([
            'tenant_id' => 1,
            'site_id' => $this->site->id,
            'provider' => 'legacy_tracker',
            'category' => LocationHardware::CATEGORY_TRACKER,
            'name' => 'Legacy Tracker Hardware',
            'status' => LocationHardware::STATUS_ONLINE,
            'last_seen_at' => now(),
        ]);

        $device = $this->assignTrackingDevice([
            'legacy_location_hardware_id' => $hardware->id,
        ]);

        $this->createIntegrationEvent($device, [
            'canonical_device_id' => null,
            'hardware_id' => $hardware->id,
            'raw_payload' => [
                'lat' => -37.7870,
                'lng' => 175.2793,
                'speed' => 3,
                'battery_pct' => 55,
            ],
        ]);

        $response = $this->actingAs($this->admin)
            ->get("/operations/clients/{$this->client->id}/location/history");

        $response->assertOk()
            ->assertJsonCount(1, 'locations')
            ->assertJsonPath('locations.0.lat', -37.7870)
            ->assertJsonPath('locations.0.lng', 175.2793)
            ->assertJsonPath('locations.0.speed', 3)
            ->assertJsonPath('locations.0.battery', 55);
    }

    private function assignTrackingDevice(array $overrides = []): Device
    {
        $device = Device::factory()->tracking()->create(array_merge([
            'tenant_id' => 1,
            'provider' => 'legacy_tracker',
            'legacy_location_hardware_id' => null,
        ], $overrides));

        DeviceAssignment::create([
            'device_id' => $device->id,
            'assignable_type' => 'client',
            'assignable_id' => $this->client->id,
            'assigned_at' => now(),
        ]);

        return $device;
    }

    private function createIntegrationEvent(Device $device, array $overrides = []): IntegrationEvent
    {
        return IntegrationEvent::create(array_merge([
            'tenant_id' => 1,
            'site_id' => $this->site->id,
            'room_id' => null,
            'hardware_id' => null,
            'canonical_device_id' => $device->id,
            'provider' => 'legacy_tracker',
            'source_app' => 'legacy_tracker',
            'source_event_id' => (string) Str::uuid(),
            'occurred_at' => now(),
            'received_at' => now(),
            'severity' => IntegrationEvent::SEVERITY_INFO,
            'event_type' => 'location_update',
            'tags' => [],
            'normalized_payload' => [
                'summary' => 'Tracker location update',
            ],
            'raw_payload' => [
                'latitude' => -36.8485,
                'longitude' => 174.7633,
            ],
        ], $overrides));
    }
}
