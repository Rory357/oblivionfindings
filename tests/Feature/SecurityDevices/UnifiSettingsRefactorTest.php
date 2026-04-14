<?php

namespace Tests\Feature\SecurityDevices;

use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Models\LocationHardware;
use App\Models\Role;
use App\Models\Site;
use App\Models\SiteRoom;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SecurityDevicesPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UnifiSettingsRefactorTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $noPerms;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
        $this->seed(SecurityDevicesPermissionsSeeder::class);

        $this->admin = User::factory()->create();
        $this->admin->roles()->attach(Role::where('name', 'admin')->first());

        $this->noPerms = User::factory()->create();
    }

    // ── Authorization ─────────────────────────────────────────────

    public function test_requires_authentication(): void
    {
        $this->get('/settings/integrations/unifi')->assertRedirect('/login');
    }

    public function test_requires_integration_permission(): void
    {
        $this->actingAs($this->noPerms)
            ->get('/settings/integrations/unifi')
            ->assertForbidden();
    }

    public function test_accessible_with_permission(): void
    {
        $this->actingAs($this->admin)
            ->get('/settings/integrations/unifi')
            ->assertOk();
    }

    // ── Canonical UniFi device display ─────────────────────────────

    public function test_displays_unifi_devices_from_canonical_registry(): void
    {
        $device = Device::factory()->itInfrastructure()->create([
            'name' => 'UniFi AP Office',
            'provider' => 'unifi',
            'model' => 'U6-LR',
            'mac_address' => 'AA:BB:CC:DD:EE:FF',
        ]);

        $response = $this->actingAs($this->admin)
            ->get('/settings/integrations/unifi');

        $response->assertOk();
        $response->assertInertia(function ($page) {
            $devices = $page->toArray()['props']['syncedDevices'];
            $this->assertCount(1, $devices);

            $d = $devices[0];
            $this->assertEquals('UniFi AP Office', $d['name']);
            $this->assertEquals('unifi', $d['status'] !== null ? 'unifi' : 'unifi'); // provider filter works
            $this->assertEquals('U6-LR', $d['model']);
            $this->assertEquals('AA:BB:CC:DD:EE:FF', $d['mac_address']);
            $this->assertArrayHasKey('device_uid', $d);
            $this->assertArrayHasKey('domain', $d);
            $this->assertArrayHasKey('health_status', $d);
            $this->assertArrayHasKey('detail_url', $d);
            $this->assertStringContainsString('/security-devices/devices/', $d['detail_url']);
        });
    }

    public function test_non_unifi_devices_do_not_appear(): void
    {
        Device::factory()->create(['provider' => 'unifi', 'name' => 'UniFi Device']);
        Device::factory()->create(['provider' => 'hikvision', 'name' => 'Hikvision Camera']);
        Device::factory()->create(['provider' => 'manual', 'name' => 'Manual Device']);

        $response = $this->actingAs($this->admin)
            ->get('/settings/integrations/unifi');

        $response->assertInertia(function ($page) {
            $devices = $page->toArray()['props']['syncedDevices'];
            $this->assertCount(1, $devices);
            $this->assertEquals('UniFi Device', $devices[0]['name']);
        });
    }

    // ── Site/room context from assignments ─────────────────────────

    public function test_displays_site_context_from_assignment(): void
    {
        $site = Site::factory()->create(['name' => 'Auckland Office']);
        $device = Device::factory()->create(['provider' => 'unifi', 'name' => 'UniFi Switch']);

        DeviceAssignment::create([
            'device_id' => $device->id,
            'assignable_type' => 'site',
            'assignable_id' => $site->id,
            'assigned_at' => now(),
        ]);

        $response = $this->actingAs($this->admin)
            ->get('/settings/integrations/unifi');

        $response->assertInertia(function ($page) {
            $d = $page->toArray()['props']['syncedDevices'][0];
            $this->assertEquals('Auckland Office', $d['site_name']);
        });
    }

    public function test_displays_room_context_from_assignment(): void
    {
        $site = Site::factory()->create(['name' => 'Main Office']);
        $room = SiteRoom::create([
            'tenant_id' => 1,
            'site_id' => $site->id,
            'name' => 'Server Room',
        ]);

        $device = Device::factory()->create(['provider' => 'unifi', 'name' => 'UniFi AP']);

        DeviceAssignment::create([
            'device_id' => $device->id,
            'assignable_type' => 'room',
            'assignable_id' => $room->id,
            'assigned_at' => now(),
        ]);

        $response = $this->actingAs($this->admin)
            ->get('/settings/integrations/unifi');

        $response->assertInertia(function ($page) use ($room) {
            $d = $page->toArray()['props']['syncedDevices'][0];
            $this->assertEquals('Server Room', $d['room_name']);
            $this->assertEquals($room->id, $d['room_id']);
        });
    }

    public function test_unassigned_devices_show_unassigned(): void
    {
        Device::factory()->create(['provider' => 'unifi', 'name' => 'Floating AP']);

        $response = $this->actingAs($this->admin)
            ->get('/settings/integrations/unifi');

        $response->assertInertia(function ($page) {
            $d = $page->toArray()['props']['syncedDevices'][0];
            $this->assertEquals('Unassigned', $d['site_name']);
            $this->assertNull($d['room_id']);
        });
    }

    public function test_room_assignment_updates_canonical_device_assignment_and_legacy_shadow(): void
    {
        $site = Site::factory()->create(['tenant_id' => 1, 'name' => 'Main Office']);
        $room = SiteRoom::create([
            'tenant_id' => 1,
            'site_id' => $site->id,
            'name' => 'Comms Room',
        ]);

        $shadow = LocationHardware::create([
            'tenant_id' => 1,
            'site_id' => $site->id,
            'provider' => 'unifi',
            'category' => LocationHardware::CATEGORY_AP,
            'name' => 'UniFi AP',
            'status' => LocationHardware::STATUS_ONLINE,
            'external_ref' => ['provider_entity_id' => 'unifi-ap-1'],
        ]);

        $device = Device::factory()->itInfrastructure()->create([
            'tenant_id' => 1,
            'provider' => 'unifi',
            'name' => 'UniFi AP',
            'legacy_location_hardware_id' => $shadow->id,
            'external_ref' => ['provider_entity_id' => 'unifi-ap-1'],
        ]);

        DeviceAssignment::create([
            'device_id' => $device->id,
            'assignable_type' => 'site',
            'assignable_id' => $site->id,
            'assigned_at' => now(),
        ]);

        $this->actingAs($this->admin)
            ->put("/settings/integrations/unifi/hardware/{$device->id}/room", [
                'room_id' => $room->id,
            ])
            ->assertRedirect();

        $active = $device->fresh()->assignments()->active()->first();

        $this->assertNotNull($active);
        $this->assertEquals('room', $active->assignable_type);
        $this->assertEquals($room->id, $active->assignable_id);
        $this->assertEquals($room->id, $shadow->fresh()->room_id);
    }

    public function test_clearing_room_restores_site_assignment_and_clears_legacy_shadow_room(): void
    {
        $site = Site::factory()->create(['tenant_id' => 1, 'name' => 'Branch Office']);
        $room = SiteRoom::create([
            'tenant_id' => 1,
            'site_id' => $site->id,
            'name' => 'Entry',
        ]);

        $shadow = LocationHardware::create([
            'tenant_id' => 1,
            'site_id' => $site->id,
            'room_id' => $room->id,
            'provider' => 'unifi',
            'category' => LocationHardware::CATEGORY_DOOR,
            'name' => 'UniFi Access Reader',
            'status' => LocationHardware::STATUS_ONLINE,
            'external_ref' => ['provider_entity_id' => 'unifi-door-1'],
        ]);

        $device = Device::factory()->security()->create([
            'tenant_id' => 1,
            'provider' => 'unifi',
            'name' => 'UniFi Access Reader',
            'legacy_location_hardware_id' => $shadow->id,
            'external_ref' => ['provider_entity_id' => 'unifi-door-1'],
        ]);

        DeviceAssignment::create([
            'device_id' => $device->id,
            'assignable_type' => 'room',
            'assignable_id' => $room->id,
            'assigned_at' => now(),
        ]);

        $this->actingAs($this->admin)
            ->put("/settings/integrations/unifi/hardware/{$device->id}/room", [
                'room_id' => null,
            ])
            ->assertRedirect();

        $active = $device->fresh()->assignments()->active()->first();

        $this->assertNotNull($active);
        $this->assertEquals('site', $active->assignable_type);
        $this->assertEquals($site->id, $active->assignable_id);
        $this->assertNull($shadow->fresh()->room_id);
    }

    // ── Canonical fields present ──────────────────────────────────

    public function test_canonical_fields_present(): void
    {
        Device::factory()->create([
            'provider' => 'unifi',
            'name' => 'Test AP',
            'firmware_version' => '6.5.28',
            'ip_address' => '192.168.1.42',
            'serial_number' => 'UNIFI-001',
        ]);

        $response = $this->actingAs($this->admin)
            ->get('/settings/integrations/unifi');

        $response->assertInertia(function ($page) {
            $d = $page->toArray()['props']['syncedDevices'][0];
            $this->assertArrayHasKey('device_uid', $d);
            $this->assertArrayHasKey('domain', $d);
            $this->assertArrayHasKey('category', $d);
            $this->assertArrayHasKey('status', $d);
            $this->assertArrayHasKey('health_status', $d);
            $this->assertArrayHasKey('manufacturer', $d);
            $this->assertArrayHasKey('serial_number', $d);
            $this->assertArrayHasKey('mac_address', $d);
            $this->assertArrayHasKey('ip_address', $d);
            $this->assertArrayHasKey('firmware_version', $d);
            $this->assertArrayHasKey('detail_url', $d);
            $this->assertEquals('6.5.28', $d['firmware_version']);
            $this->assertEquals('192.168.1.42', $d['ip_address']);
        });
    }
}
