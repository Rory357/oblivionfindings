<?php

namespace Tests\Feature\SecurityDevices;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Models\ControlRoom\Device as CrDevice;
use App\Models\Role;
use App\Models\Site;
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

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
        $this->seed(SecurityDevicesPermissionsSeeder::class);

        $this->admin = User::factory()->create();
        $this->admin->roles()->attach(Role::where('name', 'admin')->first());
        $this->site = Site::factory()->create();
        HrEmployeeProfile::factory()->create([
            'user_id' => $this->admin->id,
            'primary_site_id' => $this->site->id,
            'secondary_site_ids' => [],
        ]);

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
        $this->assignCanonicalToSite($canonicalDevice);

        $crDevice = CrDevice::create([
            'name' => 'CR Camera',
            'type' => 'camera',
            'status' => 'online',
            'site_id' => $this->site->id,
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
            'site_id' => $this->site->id,
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

    public function test_device_list_exposes_signal_activity_without_treating_projection_health_as_canonical(): void
    {
        $canonicalDevice = Device::factory()->itInfrastructure()->create([
            'name' => 'Canonical core switch',
            'status' => 'active',
            'health_status' => 'healthy',
            'battery_level' => 88,
            'last_seen_at' => now(),
        ]);
        $this->assignCanonicalToSite($canonicalDevice);

        CrDevice::query()->create([
            'name' => 'Stale projection name',
            'type' => CrDevice::TYPE_NETWORK,
            'status' => 'offline',
            'site_id' => $this->site->id,
            'battery_level' => 5,
            'last_seen_at' => now()->subYear(),
            'last_signal_at' => now(),
            'canonical_device_id' => $canonicalDevice->id,
        ]);

        $this->actingAs($this->admin)
            ->get('/control-room/devices')
            ->assertOk()
            ->assertInertia(function ($page): void {
                $props = $page->toArray()['props'];
                $device = $props['devices']['data'][0];

                $this->assertArrayNotHasKey('status', $device);
                $this->assertArrayNotHasKey('is_stale', $device);
                $this->assertArrayNotHasKey('last_seen_at', $device);
                $this->assertArrayNotHasKey('battery_level', $device);
                $this->assertSame('recent', $device['signal_activity']['state']);
                $this->assertSame('active', $device['canonical_status']);
                $this->assertSame(88, $device['canonical_battery_level']);
                $this->assertSame(5, $device['reported_battery_level']);
                $this->assertSame(1, $props['stats']['signal_sources']);
                $this->assertSame(1, $props['stats']['active_24h']);
                $this->assertSame(1, $props['stats']['canonical_linked']);
                $this->assertSame(0, $props['stats']['reconciliation_needed']);
                $this->assertArrayNotHasKey('online', $props['stats']);
                $this->assertArrayNotHasKey('offline', $props['stats']);
                $this->assertArrayNotHasKey('low_battery', $props['stats']);
                $this->assertTrue($props['can']['view_canonical_devices']);
                $this->assertSame('/security-devices/devices', $props['canonicalIndexUrl']);
            });
    }

    public function test_signal_activity_and_reconciliation_filters_return_the_matching_projection_rows(): void
    {
        $canonicalDevice = Device::factory()->itInfrastructure()->create();
        $this->assignCanonicalToSite($canonicalDevice);

        $recentLinked = CrDevice::query()->create([
            'name' => 'Recent linked source',
            'type' => CrDevice::TYPE_NETWORK,
            'site_id' => $this->site->id,
            'last_signal_at' => now(),
            'canonical_device_id' => $canonicalDevice->id,
        ]);
        $quietUnlinked = CrDevice::query()->create([
            'name' => 'Quiet unlinked source',
            'type' => CrDevice::TYPE_SENSOR,
            'site_id' => $this->site->id,
            'last_signal_at' => now()->subDays(2),
        ]);
        $neverUnlinked = CrDevice::query()->create([
            'name' => 'Never received source',
            'type' => CrDevice::TYPE_CAMERA,
            'site_id' => $this->site->id,
            'last_signal_at' => null,
        ]);

        $this->actingAs($this->admin)
            ->get('/control-room/devices?activity=recent&linkage=linked')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('devices.data', 1)
                ->where('devices.data.0.id', $recentLinked->id)
                ->where('filters.activity', 'recent')
                ->where('filters.linkage', 'linked')
            );

        $this->actingAs($this->admin)
            ->get('/control-room/devices?activity=quiet&linkage=unlinked')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('devices.data', 1)
                ->where('devices.data.0.id', $quietUnlinked->id)
            );

        $this->actingAs($this->admin)
            ->get('/control-room/devices?activity=never&linkage=unlinked')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('devices.data', 1)
                ->where('devices.data.0.id', $neverUnlinked->id)
            );
    }

    // ── Canonical device linkage on detail ─────────────────────────

    public function test_device_show_includes_canonical_data_when_linked(): void
    {
        $canonicalDevice = Device::factory()->itInfrastructure()->create([
            'name' => 'Canonical Switch',
            'serial_number' => 'SW-001',
            'mac_address' => 'AA:BB:CC:DD:EE:FF',
        ]);
        $this->assignCanonicalToSite($canonicalDevice);

        $crDevice = CrDevice::create([
            'name' => 'CR Switch',
            'type' => 'network',
            'status' => 'online',
            'site_id' => $this->site->id,
            'canonical_device_id' => $canonicalDevice->id,
        ]);

        $response = $this->actingAs($this->admin)
            ->get("/control-room/devices/{$crDevice->id}");

        $response->assertOk();
        $response->assertInertia(function ($page) use ($canonicalDevice) {
            $device = $page->toArray()['props']['device'];
            $canonical = $device['canonical'];

            $this->assertNotNull($canonical);
            $this->assertEquals('Canonical Switch', $device['name']);
            $this->assertEquals($canonicalDevice->device_uid, $device['device_uid']);
            $this->assertEquals($canonicalDevice->id, $canonical['id']);
            $this->assertEquals('it_infrastructure', $canonical['domain']);
            $this->assertEquals('network', $canonical['category']);
            $this->assertNotNull($canonical['detail_url']);
            $this->assertArrayNotHasKey('serial_number', $canonical);
            $this->assertArrayNotHasKey('mac_address', $canonical);

            $encoded = json_encode($page->toArray()['props'], JSON_THROW_ON_ERROR);
            $this->assertStringNotContainsString('SW-001', $encoded);
            $this->assertStringNotContainsString('AA:BB:CC:DD:EE:FF', $encoded);
        });
    }

    public function test_device_show_safe_fallback_without_canonical(): void
    {
        $crDevice = CrDevice::create([
            'name' => 'Standalone Device',
            'type' => 'sensor',
            'status' => 'online',
            'site_id' => $this->site->id,
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
            'site_id' => $this->site->id,
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
            $this->assertEquals(85, $device['reported_battery_level']);
            $this->assertNotNull($device['latitude']);
            $this->assertEquals('Front entrance', $device['location_description']);
            $this->assertArrayNotHasKey('config', $device);
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
            'site_id' => $this->site->id,
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

    private function assignCanonicalToSite(Device $device): void
    {
        DeviceAssignment::query()->create([
            'device_id' => $device->id,
            'assignable_type' => DeviceAssignment::TARGET_SITE,
            'assignable_id' => $this->site->id,
            'assignment_type' => 'permanent',
            'assigned_at' => now(),
            'assigned_by_user_id' => $this->admin->id,
        ]);
    }
}
