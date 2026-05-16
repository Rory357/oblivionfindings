<?php

namespace Tests\Feature\SecurityDevices;

use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Models\LocationHardware;
use App\Models\Role;
use App\Models\Site;
use App\Models\SiteRoom;
use App\Models\SiteTypePlanPin;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SecurityDevicesPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class SiteHardwareRefactorTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $noPerms;
    private Site $siteA;
    private Site $siteB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
        $this->seed(SecurityDevicesPermissionsSeeder::class);

        $this->admin = User::factory()->create();
        $this->admin->roles()->attach(Role::where('name', 'admin')->first());

        $this->noPerms = User::factory()->create();

        $this->siteA = Site::factory()->create(['name' => 'Site Alpha']);
        $this->siteB = Site::factory()->create(['name' => 'Site Beta']);
    }

    // ── Authorization ─────────────────────────────────────────────

    public function test_requires_authentication(): void
    {
        $this->get("/sites/{$this->siteA->id}/hardware")->assertRedirect('/login');
    }

    // ── Data source is canonical devices ──────────────────────────

    public function test_returns_devices_assigned_to_site(): void
    {
        $device = Device::factory()->create(['name' => 'Camera Alpha']);
        DeviceAssignment::create([
            'device_id' => $device->id,
            'assignable_type' => 'site',
            'assignable_id' => $this->siteA->id,
            'assigned_at' => now(),
        ]);

        $response = $this->actingAs($this->admin)
            ->get("/sites/{$this->siteA->id}/hardware");

        $response->assertOk();
        $response->assertInertia(function ($page) {
            $devices = $page->toArray()['props']['devices'];
            $this->assertCount(1, $devices);
            $this->assertEquals('Camera Alpha', $devices[0]['name']);
            $this->assertArrayHasKey('device_uid', $devices[0]);
            $this->assertArrayHasKey('domain', $devices[0]);
            $this->assertArrayHasKey('health_status', $devices[0]);
            $this->assertArrayNotHasKey('legacy_location_hardware_id', $devices[0]);
        });
    }

    public function test_returns_devices_assigned_to_rooms_within_site(): void
    {
        $room = SiteRoom::create([
            'tenant_id' => 1,
            'site_id' => $this->siteA->id,
            'name' => 'Server Room',
        ]);

        $device = Device::factory()->itInfrastructure()->create(['name' => 'Core Switch']);
        DeviceAssignment::create([
            'device_id' => $device->id,
            'assignable_type' => 'room',
            'assignable_id' => $room->id,
            'assigned_at' => now(),
        ]);

        $response = $this->actingAs($this->admin)
            ->get("/sites/{$this->siteA->id}/hardware");

        $response->assertInertia(function ($page) use ($room) {
            $devices = $page->toArray()['props']['devices'];
            $this->assertCount(1, $devices);
            $this->assertEquals('Core Switch', $devices[0]['name']);
            $this->assertEquals('room', $devices[0]['assignment_type']);
            $this->assertEquals($room->id, $devices[0]['assignment_id']);
        });
    }

    public function test_does_not_return_devices_from_other_sites(): void
    {
        // Device at Site A.
        $deviceA = Device::factory()->create(['name' => 'Device at A']);
        DeviceAssignment::create([
            'device_id' => $deviceA->id,
            'assignable_type' => 'site',
            'assignable_id' => $this->siteA->id,
            'assigned_at' => now(),
        ]);

        // Device at Site B.
        $deviceB = Device::factory()->create(['name' => 'Device at B']);
        DeviceAssignment::create([
            'device_id' => $deviceB->id,
            'assignable_type' => 'site',
            'assignable_id' => $this->siteB->id,
            'assigned_at' => now(),
        ]);

        $response = $this->actingAs($this->admin)
            ->get("/sites/{$this->siteA->id}/hardware");

        $response->assertInertia(function ($page) {
            $devices = $page->toArray()['props']['devices'];
            $this->assertCount(1, $devices);
            $this->assertEquals('Device at A', $devices[0]['name']);
        });
    }

    public function test_does_not_return_released_assignments(): void
    {
        $device = Device::factory()->create(['name' => 'Former Device']);
        DeviceAssignment::create([
            'device_id' => $device->id,
            'assignable_type' => 'site',
            'assignable_id' => $this->siteA->id,
            'assigned_at' => now()->subDays(30),
            'released_at' => now()->subDays(5), // released
        ]);

        $response = $this->actingAs($this->admin)
            ->get("/sites/{$this->siteA->id}/hardware");

        $response->assertInertia(function ($page) {
            $devices = $page->toArray()['props']['devices'];
            $this->assertCount(0, $devices);
        });
    }

    public function test_unassigned_devices_do_not_appear(): void
    {
        // Device with no assignment.
        Device::factory()->create(['name' => 'Floating Device']);

        $response = $this->actingAs($this->admin)
            ->get("/sites/{$this->siteA->id}/hardware");

        $response->assertInertia(function ($page) {
            $this->assertCount(0, $page->toArray()['props']['devices']);
        });
    }

    // ── Device data shape ─────────────────────────────────────────

    public function test_device_data_includes_canonical_fields(): void
    {
        $device = Device::factory()->security()->create([
            'name' => 'Dome Camera',
            'manufacturer' => 'Hikvision',
            'model' => 'DS-2CD2143G2',
            'serial_number' => 'HIK-001',
            'mac_address' => 'AA:BB:CC:DD:EE:FF',
            'ip_address' => '192.168.1.100',
            'firmware_version' => '5.7.1',
            'battery_level' => null,
        ]);
        DeviceAssignment::create([
            'device_id' => $device->id,
            'assignable_type' => 'site',
            'assignable_id' => $this->siteA->id,
            'assigned_at' => now(),
        ]);

        $response = $this->actingAs($this->admin)
            ->get("/sites/{$this->siteA->id}/hardware");

        $response->assertInertia(function ($page) {
            $d = $page->toArray()['props']['devices'][0];
            $this->assertEquals('Dome Camera', $d['name']);
            $this->assertNotEmpty($d['device_uid']);
            $this->assertEquals('security', $d['domain']);
            $this->assertEquals('cctv', $d['category']);
            $this->assertEquals('Hikvision', $d['manufacturer']);
            $this->assertEquals('DS-2CD2143G2', $d['model']);
            $this->assertEquals('HIK-001', $d['serial_number']);
            $this->assertEquals('AA:BB:CC:DD:EE:FF', $d['mac_address']);
            $this->assertEquals('192.168.1.100', $d['ip_address']);
            $this->assertEquals('5.7.1', $d['firmware_version']);
            $this->assertArrayHasKey('status', $d);
            $this->assertArrayHasKey('health_status', $d);
        });
    }

    // ── Integration/rooms data still present ──────────────────────

    public function test_rooms_and_unifi_data_still_passed_without_legacy_site_hardware_props(): void
    {
        SiteRoom::create([
            'tenant_id' => 1,
            'site_id' => $this->siteA->id,
            'name' => 'Reception',
        ]);

        $response = $this->actingAs($this->admin)
            ->get("/sites/{$this->siteA->id}/hardware");

        $response->assertInertia(function ($page) {
            $props = $page->toArray()['props'];
            $this->assertNotEmpty($props['rooms']);
            $this->assertArrayHasKey('unifi', $props);
            $this->assertArrayHasKey('can', $props);
            $this->assertArrayNotHasKey('hardware', $props);
            $this->assertArrayNotHasKey('assets', $props);
            $this->assertArrayNotHasKey('categories', $props);
        });
    }

    public function test_legacy_site_hardware_crud_routes_are_removed_but_room_bridge_routes_remain(): void
    {
        $this->assertFalse(Route::has('sites.hardware.store'));
        $this->assertFalse(Route::has('sites.hardware.update'));
        $this->assertFalse(Route::has('sites.hardware.destroy'));
        $this->assertFalse(Route::has('sites.hardware.linkAsset'));
        $this->assertFalse(Route::has('sites.hardware.refreshStatus'));

        $this->assertTrue(Route::has('sites.hardware.assignRoom'));
        $this->assertTrue(Route::has('sites.hardware.manageRooms'));
    }

    public function test_assign_room_route_updates_canonical_assignment_for_unifi_devices(): void
    {
        $room = SiteRoom::create([
            'tenant_id' => 1,
            'site_id' => $this->siteA->id,
            'name' => 'Network Closet',
        ]);

        $shadow = LocationHardware::create([
            'tenant_id' => 1,
            'site_id' => $this->siteA->id,
            'provider' => 'unifi',
            'category' => LocationHardware::CATEGORY_SWITCH,
            'name' => 'Core Switch',
            'status' => LocationHardware::STATUS_ONLINE,
            'external_ref' => ['provider_entity_id' => 'switch-1'],
        ]);

        $device = Device::factory()->itInfrastructure()->create([
            'tenant_id' => 1,
            'provider' => 'unifi',
            'name' => 'Core Switch',
            'legacy_location_hardware_id' => $shadow->id,
            'external_ref' => ['provider_entity_id' => 'switch-1'],
        ]);

        DeviceAssignment::create([
            'device_id' => $device->id,
            'assignable_type' => 'site',
            'assignable_id' => $this->siteA->id,
            'assigned_at' => now(),
        ]);

        $this->actingAs($this->admin)
            ->post("/sites/{$this->siteA->id}/hardware/{$device->id}/assign-room", [
                'room_id' => $room->id,
            ])
            ->assertRedirect();

        $active = $device->fresh()->assignments()->active()->first();

        $this->assertNotNull($active);
        $this->assertEquals('room', $active->assignable_type);
        $this->assertEquals($room->id, $active->assignable_id);

        // The canonical DeviceAssignment is authoritative; legacy
        // LocationHardware.room_id is intentionally not synced — see
        // UnifiOperationalBridgeService::syncRoomAssignment.
    }

    public function test_release_marks_device_plan_pin_stale(): void
    {
        $device = Device::factory()->security()->create(['name' => 'Front Camera']);
        DeviceAssignment::create([
            'device_id' => $device->id,
            'assignable_type' => 'site',
            'assignable_id' => $this->siteA->id,
            'assigned_at' => now()->subHour(),
        ]);
        $pin = $this->createDevicePlanPin($device, [
            'meta' => ['device_id' => $device->id, 'stale' => false],
        ]);

        $this->actingAs($this->admin)
            ->post("/security-devices/devices/{$device->id}/release")
            ->assertRedirect();

        $pin->refresh();
        $this->assertTrue($pin->meta['stale'] ?? false);
        $this->assertArrayHasKey('released_at', $pin->meta);
        $this->assertSame('assignment_released', $pin->meta['stale_reason'] ?? null);
    }

    public function test_room_move_marks_device_plan_pin_stale(): void
    {
        $roomA = SiteRoom::create([
            'tenant_id' => 1,
            'site_id' => $this->siteA->id,
            'name' => 'Hallway',
        ]);
        $roomB = SiteRoom::create([
            'tenant_id' => 1,
            'site_id' => $this->siteA->id,
            'name' => 'Network Closet',
        ]);

        $device = Device::factory()->itInfrastructure()->create([
            'tenant_id' => 1,
            'provider' => 'unifi',
            'name' => 'Access Point',
        ]);
        DeviceAssignment::create([
            'device_id' => $device->id,
            'assignable_type' => 'room',
            'assignable_id' => $roomA->id,
            'assigned_at' => now()->subHour(),
        ]);
        $pin = $this->createDevicePlanPin($device, [
            'meta' => ['device_id' => $device->id, 'stale' => false],
        ]);

        $this->actingAs($this->admin)
            ->post("/sites/{$this->siteA->id}/hardware/{$device->id}/assign-room", [
                'room_id' => $roomB->id,
            ])
            ->assertRedirect();

        $pin->refresh();
        $this->assertTrue($pin->meta['stale'] ?? false);
        $this->assertArrayHasKey('replaced_at', $pin->meta);
        $this->assertSame('assignment_replaced', $pin->meta['stale_reason'] ?? null);
    }

    public function test_pin_device_writes_plan_pin_for_current_plan(): void
    {
        $planId = DB::table('site_type_plans')->insertGetId([
            'tenant_id' => $this->siteA->tenant_id,
            'site_id' => $this->siteA->id,
            'site_type' => $this->siteA->type,
            'status' => 'published',
            'version' => 1,
            'layout' => json_encode([
                'schema_version' => 1,
                'canvas' => ['width' => 1000, 'height' => 700, 'unit' => 'rel'],
                'rooms' => [],
                'walls' => [],
                'doors' => [],
                'windows' => [],
                'labels' => [],
            ]),
            'published_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $device = Device::factory()->security()->create(['name' => 'Front Camera']);
        DeviceAssignment::create([
            'device_id' => $device->id,
            'assignable_type' => 'site',
            'assignable_id' => $this->siteA->id,
            'assigned_at' => now(),
        ]);

        $this->actingAs($this->admin)
            ->postJson("/sites/{$this->siteA->id}/hardware/{$device->id}/pin", [
                'x' => 0.42,
                'y' => 0.33,
                'label' => 'Front door camera',
            ])
            ->assertOk()
            ->assertJsonPath('pin.kind', 'device')
            ->assertJsonPath('pin.device_id', $device->id);

        $this->assertDatabaseHas('site_type_plan_pins', [
            'tenant_id' => $this->siteA->tenant_id,
            'site_type_plan_id' => $planId,
            'kind' => 'device',
            'device_id' => $device->id,
            'label' => 'Front door camera',
        ]);
    }

    public function test_unpin_device_removes_plan_pin(): void
    {
        $planId = DB::table('site_type_plans')->insertGetId([
            'tenant_id' => $this->siteA->tenant_id,
            'site_id' => $this->siteA->id,
            'site_type' => $this->siteA->type,
            'status' => 'published',
            'version' => 1,
            'layout' => json_encode(['schema_version' => 1, 'canvas' => ['width' => 1000, 'height' => 700]]),
            'published_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $device = Device::factory()->security()->create();

        DB::table('site_type_plan_pins')->insert([
            'tenant_id' => $this->siteA->tenant_id,
            'site_type_plan_id' => $planId,
            'kind' => 'device',
            'device_id' => $device->id,
            'label' => 'Front camera',
            'x' => 0.5,
            'y' => 0.5,
            'rotation_deg' => 0,
            'sort_order' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($this->admin)
            ->deleteJson("/sites/{$this->siteA->id}/hardware/{$device->id}/pin")
            ->assertOk();

        $this->assertDatabaseMissing('site_type_plan_pins', [
            'site_type_plan_id' => $planId,
            'kind' => 'device',
            'device_id' => $device->id,
        ]);
    }

    private function createDevicePlanPin(Device $device, array $overrides = []): SiteTypePlanPin
    {
        $planId = DB::table('site_type_plans')->insertGetId([
            'tenant_id' => $this->siteA->tenant_id,
            'site_id' => $this->siteA->id,
            'site_type' => $this->siteA->type,
            'status' => 'published',
            'version' => 1,
            'layout' => json_encode(['schema_version' => 1, 'canvas' => ['width' => 1000, 'height' => 700]]),
            'published_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return SiteTypePlanPin::create(array_merge([
            'tenant_id' => $this->siteA->tenant_id,
            'site_type_plan_id' => $planId,
            'kind' => SiteTypePlanPin::KIND_DEVICE,
            'device_id' => $device->id,
            'label' => $device->name,
            'meta' => ['stale' => false],
            'x' => 0.5,
            'y' => 0.5,
            'rotation_deg' => 0,
            'sort_order' => 0,
        ], $overrides));
    }
}
