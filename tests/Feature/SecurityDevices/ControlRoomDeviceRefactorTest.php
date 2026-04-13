<?php

namespace Tests\Feature\SecurityDevices;

use App\Domain\SecurityDevices\Models\Device;
use App\Models\ControlRoom\Device as CrDevice;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SecurityDevicesPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ControlRoomDeviceRefactorTest extends TestCase
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

    public function test_device_index_requires_control_room_permission(): void
    {
        $this->actingAs($this->noPerms)
            ->get('/control-room/devices')
            ->assertForbidden();
    }

    public function test_device_index_accessible_with_permission(): void
    {
        $this->actingAs($this->admin)
            ->get('/control-room/devices')
            ->assertOk();
    }

    // ── Canonical device linkage on list ───────────────────────────

    public function test_device_list_includes_canonical_enrichment_when_linked(): void
    {
        $canonicalDevice = Device::factory()->security()->create([
            'name' => 'Canonical Camera',
        ]);

        $crDevice = CrDevice::create([
            'name' => 'CR Camera',
            'type' => 'camera',
            'status' => 'online',
            'canonical_device_id' => $canonicalDevice->id,
        ]);

        $response = $this->actingAs($this->admin)
            ->get('/control-room/devices');

        $response->assertOk();
        $response->assertInertia(function ($page) use ($canonicalDevice) {
            $devices = $page->toArray()['props']['devices']['data'];
            $this->assertCount(1, $devices);

            $d = $devices[0];
            $this->assertEquals($canonicalDevice->id, $d['canonical_id']);
            $this->assertEquals($canonicalDevice->device_uid, $d['canonical_device_uid']);
            $this->assertEquals('security', $d['canonical_domain']);
            $this->assertNotNull($d['canonical_detail_url']);
            $this->assertStringContainsString('/security-devices/devices/', $d['canonical_detail_url']);
        });
    }

    public function test_device_list_safe_fallback_when_no_canonical_device(): void
    {
        CrDevice::create([
            'name' => 'Standalone CR Device',
            'type' => 'sensor',
            'status' => 'online',
            // No canonical_device_id set.
        ]);

        $response = $this->actingAs($this->admin)
            ->get('/control-room/devices');

        $response->assertOk();
        $response->assertInertia(function ($page) {
            $d = $page->toArray()['props']['devices']['data'][0];
            $this->assertEquals('Standalone CR Device', $d['name']);
            $this->assertNull($d['canonical_id']);
            $this->assertNull($d['canonical_device_uid']);
            $this->assertNull($d['canonical_detail_url']);
        });
    }

    // ── Canonical device linkage on detail ─────────────────────────

    public function test_device_show_includes_canonical_data_when_linked(): void
    {
        $canonicalDevice = Device::factory()->itInfrastructure()->create([
            'name' => 'Canonical Switch',
            'serial_number' => 'SW-001',
            'mac_address' => 'AA:BB:CC:DD:EE:FF',
        ]);

        $crDevice = CrDevice::create([
            'name' => 'CR Switch',
            'type' => 'network',
            'status' => 'online',
            'canonical_device_id' => $canonicalDevice->id,
        ]);

        $response = $this->actingAs($this->admin)
            ->get("/control-room/devices/{$crDevice->id}");

        $response->assertOk();
        $response->assertInertia(function ($page) use ($canonicalDevice) {
            $device = $page->toArray()['props']['device'];
            $canonical = $device['canonical'];

            $this->assertNotNull($canonical);
            $this->assertEquals($canonicalDevice->id, $canonical['id']);
            $this->assertEquals($canonicalDevice->device_uid, $canonical['device_uid']);
            $this->assertEquals('it_infrastructure', $canonical['domain']);
            $this->assertEquals('network', $canonical['category']);
            $this->assertEquals('SW-001', $canonical['serial_number']);
            $this->assertEquals('AA:BB:CC:DD:EE:FF', $canonical['mac_address']);
            $this->assertNotNull($canonical['detail_url']);
        });
    }

    public function test_device_show_safe_fallback_without_canonical(): void
    {
        $crDevice = CrDevice::create([
            'name' => 'Standalone Device',
            'type' => 'sensor',
            'status' => 'online',
        ]);

        $response = $this->actingAs($this->admin)
            ->get("/control-room/devices/{$crDevice->id}");

        $response->assertOk();
        $response->assertInertia(function ($page) {
            $device = $page->toArray()['props']['device'];
            $this->assertEquals('Standalone Device', $device['name']);
            $this->assertNull($device['canonical']);
        });
    }

    // ── Existing CR data preserved ────────────────────────────────

    public function test_device_show_preserves_cr_specific_fields(): void
    {
        $crDevice = CrDevice::create([
            'name' => 'Test Sensor',
            'type' => 'sensor',
            'vendor' => 'CustomVendor',
            'model' => 'CS-100',
            'status' => 'online',
            'battery_level' => 85,
            'latitude' => -36.8485,
            'longitude' => 174.7633,
            'location_description' => 'Front entrance',
        ]);

        $response = $this->actingAs($this->admin)
            ->get("/control-room/devices/{$crDevice->id}");

        $response->assertInertia(function ($page) {
            $device = $page->toArray()['props']['device'];
            $this->assertEquals('Test Sensor', $device['name']);
            $this->assertEquals('CustomVendor', $device['vendor']);
            $this->assertEquals('CS-100', $device['model']);
            $this->assertEquals(85, $device['battery_level']);
            $this->assertNotNull($device['latitude']);
            $this->assertEquals('Front entrance', $device['location_description']);
            $this->assertArrayHasKey('config', $device);
            $this->assertArrayHasKey('signal_source', $device);
        });
    }

    // ── Signal pipeline unaffected ────────────────────────────────

    public function test_device_show_includes_signals_and_alerts(): void
    {
        $crDevice = CrDevice::create([
            'name' => 'Test Device',
            'type' => 'alarm_panel',
            'status' => 'online',
        ]);

        $response = $this->actingAs($this->admin)
            ->get("/control-room/devices/{$crDevice->id}");

        $response->assertInertia(function ($page) {
            $this->assertArrayHasKey('signals', $page->toArray()['props']);
            $this->assertArrayHasKey('alerts', $page->toArray()['props']);
        });
    }

    // ── Route behavior unchanged ──────────────────────────────────

    public function test_device_index_route_unchanged(): void
    {
        $this->actingAs($this->admin)
            ->get('/control-room/devices')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('control-room/devices/index')
                ->has('devices')
                ->has('stats')
                ->has('filters')
                ->has('sites')
                ->has('device_types')
            );
    }
}
