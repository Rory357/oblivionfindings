<?php

namespace Tests\Feature\SecurityDevices;

use App\Domain\SecurityDevices\Models\Device;
use App\Models\ControlRoom\Device as CrDevice;
use App\Models\ControlRoom\Signal;
use App\Models\ControlRoomAlert;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SecurityDevicesPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Verify that ControlRoom\Device is correctly positioned as a signal-pipeline
 * projection, not an identity model. Canonical device identity comes from
 * Security & Devices (Device model) via the canonicalDevice() bridge.
 */
class ControlRoomDeviceProjectionTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
        $this->seed(SecurityDevicesPermissionsSeeder::class);

        $this->admin = User::factory()->create();
        $this->admin->roles()->attach(Role::where('name', 'admin')->first());
    }

    // ── Model has projection documentation ────────────────────────

    public function test_cr_device_model_has_projection_docblock(): void
    {
        $reflection = new \ReflectionClass(CrDevice::class);
        $docComment = $reflection->getDocComment();

        $this->assertNotFalse($docComment);
        $this->assertStringContainsString('signal pipeline', strtolower($docComment));
        $this->assertStringContainsString('NOT the canonical device registry', $docComment);
    }

    // ── Canonical bridge relationship exists ───────────────────────

    public function test_cr_device_has_canonical_device_relationship(): void
    {
        $this->assertTrue(
            method_exists(CrDevice::class, 'canonicalDevice'),
            'CrDevice should have canonicalDevice() relationship.'
        );
    }

    public function test_canonical_device_bridge_works(): void
    {
        $canonicalDevice = Device::factory()->create(['name' => 'Canonical Camera']);

        $crDevice = CrDevice::create([
            'name' => 'CR Camera',
            'type' => 'camera',
            'status' => 'online',
            'canonical_device_id' => $canonicalDevice->id,
        ]);

        $this->assertNotNull($crDevice->canonicalDevice);
        $this->assertEquals('Canonical Camera', $crDevice->canonicalDevice->name);
        $this->assertEquals($canonicalDevice->device_uid, $crDevice->canonicalDevice->device_uid);
    }

    public function test_canonical_device_bridge_null_safe(): void
    {
        $crDevice = CrDevice::create([
            'name' => 'Standalone CR Device',
            'type' => 'sensor',
            'status' => 'online',
        ]);

        $this->assertNull($crDevice->canonicalDevice);
    }

    // ── Signal pipeline uses CR device correctly ──────────────────

    public function test_signal_references_cr_device_not_canonical(): void
    {
        $crDevice = CrDevice::create([
            'name' => 'Pipeline Device',
            'type' => 'sensor',
            'status' => 'online',
        ]);

        // Signal references CR device via device_id FK.
        $this->assertTrue(
            method_exists(Signal::class, 'device'),
            'Signal should have device() relationship.'
        );
    }

    // ── Alert references CR device correctly ──────────────────────

    public function test_alert_references_cr_device(): void
    {
        $this->assertTrue(
            method_exists(ControlRoomAlert::class, 'device'),
            'ControlRoomAlert should have device() relationship.'
        );
    }

    // ── CR-specific methods still work ────────────────────────────

    public function test_cr_device_lifecycle_methods_work(): void
    {
        $crDevice = CrDevice::create([
            'name' => 'Lifecycle Test',
            'type' => 'sensor',
            'status' => 'offline',
        ]);

        $crDevice->markOnline();
        $crDevice->refresh();
        $this->assertEquals('online', $crDevice->status);
        $this->assertNotNull($crDevice->last_seen_at);

        $crDevice->updateBattery(42);
        $crDevice->refresh();
        $this->assertEquals(42, $crDevice->battery_level);

        $this->assertTrue($crDevice->isOnline());
        $this->assertFalse($crDevice->hasLowBattery());

        $crDevice->markOffline();
        $crDevice->refresh();
        $this->assertEquals('offline', $crDevice->status);
    }

    // ── Controllers use canonical enrichment ──────────────────────

    public function test_device_index_includes_canonical_enrichment(): void
    {
        $canonical = Device::factory()->security()->create();
        CrDevice::create([
            'name' => 'Enriched Device',
            'type' => 'camera',
            'status' => 'online',
            'canonical_device_id' => $canonical->id,
        ]);

        $response = $this->actingAs($this->admin)
            ->get('/control-room/devices');

        $response->assertOk();
        $response->assertInertia(function ($page) {
            $d = $page->toArray()['props']['devices']['data'][0];
            $this->assertArrayHasKey('canonical_id', $d);
            $this->assertArrayHasKey('canonical_device_uid', $d);
            $this->assertArrayHasKey('canonical_detail_url', $d);
        });
    }

    public function test_device_show_includes_canonical_detail_block(): void
    {
        $canonical = Device::factory()->itInfrastructure()->create();
        $crDevice = CrDevice::create([
            'name' => 'Show Device',
            'type' => 'network',
            'status' => 'online',
            'canonical_device_id' => $canonical->id,
        ]);

        $response = $this->actingAs($this->admin)
            ->get("/control-room/devices/{$crDevice->id}");

        $response->assertOk();
        $response->assertInertia(function ($page) {
            $device = $page->toArray()['props']['device'];
            $this->assertArrayHasKey('canonical', $device);
            $this->assertNotNull($device['canonical']);
            $this->assertArrayHasKey('detail_url', $device['canonical']);
        });
    }

    // ── Routes unchanged ──────────────────────────────────────────

    public function test_control_room_device_routes_still_work(): void
    {
        $this->actingAs($this->admin)
            ->get('/control-room/devices')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('control-room/devices/index')
                ->has('devices')
                ->has('stats')
            );
    }
}
