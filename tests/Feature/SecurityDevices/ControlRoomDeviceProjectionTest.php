<?php

namespace Tests\Feature\SecurityDevices;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Models\ControlRoom\Device as CrDevice;
use App\Models\ControlRoom\Signal;
use App\Models\ControlRoomAlert;
use App\Models\Role;
use App\Models\Site;
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

    // ── Signal activity never becomes Device health authority ─────────

    public function test_cr_device_records_signal_activity_without_mutating_legacy_health_fields(): void
    {
        $legacyLastSeen = now()->subDay()->startOfSecond();
        $crDevice = CrDevice::create([
            'name' => 'Signal activity test',
            'type' => 'sensor',
            'status' => 'offline',
            'last_seen_at' => $legacyLastSeen,
            'battery_level' => 7,
        ]);

        $crDevice->recordSignal();
        $crDevice->refresh();

        $this->assertNotNull($crDevice->last_signal_at);
        $this->assertTrue($crDevice->last_seen_at->equalTo($legacyLastSeen));
        $this->assertSame('offline', $crDevice->status);
        $this->assertSame(7, $crDevice->battery_level);
    }

    public function test_cr_device_exposes_no_duplicate_health_lifecycle_api(): void
    {
        foreach ([
            'markOnline',
            'markOffline',
            'updateBattery',
            'isOnline',
            'isStale',
            'hasLowBattery',
            'scopeOnline',
            'scopeOffline',
            'scopeStale',
            'scopeLowBattery',
        ] as $method) {
            $this->assertFalse(
                method_exists(CrDevice::class, $method),
                "Control Room projection must not expose duplicate Device health method {$method}().",
            );
        }
    }

    // ── Controllers use canonical enrichment ──────────────────────

    public function test_device_index_includes_canonical_enrichment(): void
    {
        $canonical = Device::factory()->security()->create();
        $this->assignCanonicalToSite($canonical);
        CrDevice::create([
            'name' => 'Enriched Device',
            'type' => 'camera',
            'status' => 'online',
            'site_id' => $this->site->id,
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
        $this->assignCanonicalToSite($canonical);
        $crDevice = CrDevice::create([
            'name' => 'Show Device',
            'type' => 'network',
            'status' => 'online',
            'site_id' => $this->site->id,
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
