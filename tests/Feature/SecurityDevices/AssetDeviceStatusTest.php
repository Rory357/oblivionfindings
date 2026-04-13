<?php

namespace Tests\Feature\SecurityDevices;

use App\Domain\SecurityDevices\Enums\LinkType;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssetLink;
use App\Models\Asset;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SecurityDevicesPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AssetDeviceStatusTest extends TestCase
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

    // ── Linked devices appear on asset detail ─────────────────────

    public function test_asset_show_includes_linked_devices(): void
    {
        $asset = Asset::factory()->vehicle()->create();
        $tracker = Device::factory()->tracking()->create([
            'name' => 'Vehicle GPS',
            'provider' => 'queclink',
            'battery_level' => 85,
        ]);

        DeviceAssetLink::create([
            'device_id' => $tracker->id,
            'asset_id' => $asset->id,
            'link_type' => LinkType::InstalledIn,
            'linked_at' => now(),
        ]);

        $response = $this->actingAs($this->admin)
            ->get("/fleet-assets/assets/{$asset->id}");

        $response->assertOk();
        $response->assertInertia(function ($page) {
            $trackers = $page->toArray()['props']['asset']['trackers'];
            $this->assertCount(1, $trackers);

            $t = $trackers[0];
            $this->assertEquals('Vehicle GPS', $t['name']);
            $this->assertNotEmpty($t['device_uid']);
            $this->assertEquals('queclink', $t['vendor']);
            $this->assertEquals(85, $t['battery_level']);
            $this->assertEquals('installed_in', $t['link_type']);
            $this->assertNotNull($t['linked_at']);
            $this->assertArrayHasKey('health_status', $t);
            $this->assertArrayHasKey('detail_url', $t);
            $this->assertStringContainsString('/security-devices/devices/', $t['detail_url']);
        });
    }

    // ── Inactive links do not appear ──────────────────────────────

    public function test_unlinked_devices_not_shown(): void
    {
        $asset = Asset::factory()->vehicle()->create();
        $tracker = Device::factory()->tracking()->create();

        DeviceAssetLink::create([
            'device_id' => $tracker->id,
            'asset_id' => $asset->id,
            'link_type' => LinkType::InstalledIn,
            'linked_at' => now()->subDays(30),
            'unlinked_at' => now()->subDays(5), // unlinked
        ]);

        $response = $this->actingAs($this->admin)
            ->get("/fleet-assets/assets/{$asset->id}");

        $response->assertInertia(function ($page) {
            $trackers = $page->toArray()['props']['asset']['trackers'];
            $this->assertCount(0, $trackers);
        });
    }

    // ── Other assets' devices do not appear ───────────────────────

    public function test_other_assets_devices_not_shown(): void
    {
        $assetA = Asset::factory()->vehicle()->create();
        $assetB = Asset::factory()->vehicle()->create();

        $deviceA = Device::factory()->tracking()->create(['name' => 'Tracker A']);
        $deviceB = Device::factory()->tracking()->create(['name' => 'Tracker B']);

        DeviceAssetLink::create([
            'device_id' => $deviceA->id,
            'asset_id' => $assetA->id,
            'link_type' => LinkType::InstalledIn,
            'linked_at' => now(),
        ]);
        DeviceAssetLink::create([
            'device_id' => $deviceB->id,
            'asset_id' => $assetB->id,
            'link_type' => LinkType::InstalledIn,
            'linked_at' => now(),
        ]);

        $response = $this->actingAs($this->admin)
            ->get("/fleet-assets/assets/{$assetA->id}");

        $response->assertInertia(function ($page) {
            $trackers = $page->toArray()['props']['asset']['trackers'];
            $this->assertCount(1, $trackers);
            $this->assertEquals('Tracker A', $trackers[0]['name']);
        });
    }

    // ── Multiple devices per asset ────────────────────────────────

    public function test_multiple_linked_devices_shown(): void
    {
        $asset = Asset::factory()->vehicle()->create();

        $tracker = Device::factory()->tracking()->create(['name' => 'GPS Tracker']);
        $dashcam = Device::factory()->security()->create(['name' => 'Dashcam']);

        DeviceAssetLink::create([
            'device_id' => $tracker->id,
            'asset_id' => $asset->id,
            'link_type' => LinkType::InstalledIn,
            'linked_at' => now(),
        ]);
        DeviceAssetLink::create([
            'device_id' => $dashcam->id,
            'asset_id' => $asset->id,
            'link_type' => LinkType::InstalledIn,
            'linked_at' => now(),
        ]);

        $response = $this->actingAs($this->admin)
            ->get("/fleet-assets/assets/{$asset->id}");

        $response->assertInertia(function ($page) {
            $trackers = $page->toArray()['props']['asset']['trackers'];
            $this->assertCount(2, $trackers);
        });
    }

    // ── Empty state: no linked devices ────────────────────────────

    public function test_no_linked_devices_returns_empty(): void
    {
        $asset = Asset::factory()->vehicle()->create();

        $response = $this->actingAs($this->admin)
            ->get("/fleet-assets/assets/{$asset->id}");

        $response->assertInertia(function ($page) {
            $trackers = $page->toArray()['props']['asset']['trackers'];
            $this->assertCount(0, $trackers);
        });
    }

    // ── Canonical fields present ──────────────────────────────────

    public function test_canonical_device_fields_present(): void
    {
        $asset = Asset::factory()->vehicle()->create();
        $device = Device::factory()->tracking()->create([
            'serial_number' => 'SN-123',
            'imei' => '999888777666',
        ]);

        DeviceAssetLink::create([
            'device_id' => $device->id,
            'asset_id' => $asset->id,
            'link_type' => LinkType::Primary,
            'linked_at' => now(),
        ]);

        $response = $this->actingAs($this->admin)
            ->get("/fleet-assets/assets/{$asset->id}");

        $response->assertInertia(function ($page) {
            $t = $page->toArray()['props']['asset']['trackers'][0];
            $this->assertArrayHasKey('id', $t);
            $this->assertArrayHasKey('device_uid', $t);
            $this->assertArrayHasKey('name', $t);
            $this->assertArrayHasKey('vendor', $t);
            $this->assertArrayHasKey('status', $t);
            $this->assertArrayHasKey('health_status', $t);
            $this->assertArrayHasKey('last_seen_at', $t);
            $this->assertArrayHasKey('battery_level', $t);
            $this->assertArrayHasKey('link_type', $t);
            $this->assertArrayHasKey('linked_at', $t);
            $this->assertArrayHasKey('detail_url', $t);
            $this->assertEquals('primary', $t['link_type']);
        });
    }
}
