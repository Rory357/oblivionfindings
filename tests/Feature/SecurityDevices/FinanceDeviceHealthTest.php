<?php

namespace Tests\Feature\SecurityDevices;

use App\Domain\Finance\Models\FinFixedAsset;
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

class FinanceDeviceHealthTest extends TestCase
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

    // ── Device health appears when linked ─────────────────────────

    public function test_fixed_asset_show_includes_device_health_when_linked(): void
    {
        $asset = Asset::factory()->vehicle()->create(['name' => 'Fleet Van 7']);

        $fixedAsset = FinFixedAsset::create([
            'organization_id' => 1,
            'asset_name' => 'Fleet Van 7 (Finance)',
            'category' => 'vehicle',
            'purchase_date' => '2025-01-15',
            'purchase_cost' => 45000,
            'residual_value' => 5000,
            'useful_life_months' => 60,
            'depreciation_method' => 'straight_line',
            'accumulated_depreciation' => 0,
            'status' => 'active',
            'linked_asset_id' => $asset->id,
            'created_by' => $this->admin->id,
        ]);

        $device = Device::factory()->tracking()->create([
            'name' => 'Van 7 GPS',
            'provider' => 'queclink',
            'battery_level' => 92,
        ]);

        DeviceAssetLink::create([
            'device_id' => $device->id,
            'asset_id' => $asset->id,
            'link_type' => LinkType::InstalledIn,
            'linked_at' => now(),
        ]);

        $response = $this->actingAs($this->admin)
            ->get("/finance/fixed-assets/{$fixedAsset->id}");

        $response->assertOk();
        $response->assertInertia(function ($page) {
            $linkedDevices = $page->toArray()['props']['linkedDevices'];
            $this->assertCount(1, $linkedDevices);

            $d = $linkedDevices[0];
            $this->assertEquals('Van 7 GPS', $d['name']);
            $this->assertEquals('queclink', $d['provider']);
            $this->assertEquals(92, $d['battery_level']);
            $this->assertNotEmpty($d['device_uid']);
            $this->assertNotNull($d['detail_url']);
            $this->assertStringContainsString('/security-devices/devices/', $d['detail_url']);
            $this->assertArrayHasKey('health_status', $d);
            $this->assertEquals('installed_in', $d['link_type']);
        });
    }

    // ── Linked asset info appears ─────────────────────────────────

    public function test_fixed_asset_show_includes_linked_asset_info(): void
    {
        $asset = Asset::factory()->create(['name' => 'Server Alpha', 'asset_tag' => 'SRV-001']);

        $fixedAsset = FinFixedAsset::create([
            'organization_id' => 1,
            'asset_name' => 'Server Alpha (Finance)',
            'category' => 'it_equipment',
            'purchase_date' => '2025-06-01',
            'purchase_cost' => 8000,
            'residual_value' => 500,
            'useful_life_months' => 48,
            'depreciation_method' => 'straight_line',
            'accumulated_depreciation' => 0,
            'status' => 'active',
            'linked_asset_id' => $asset->id,
            'created_by' => $this->admin->id,
        ]);

        $response = $this->actingAs($this->admin)
            ->get("/finance/fixed-assets/{$fixedAsset->id}");

        $response->assertInertia(function ($page) {
            $linkedAsset = $page->toArray()['props']['linkedAsset'];
            $this->assertNotNull($linkedAsset);
            $this->assertEquals('Server Alpha', $linkedAsset['name']);
            $this->assertEquals('SRV-001', $linkedAsset['asset_tag']);
        });
    }

    // ── Inactive links do not appear ──────────────────────────────

    public function test_unlinked_devices_not_shown(): void
    {
        $asset = Asset::factory()->create();
        $device = Device::factory()->tracking()->create();

        DeviceAssetLink::create([
            'device_id' => $device->id,
            'asset_id' => $asset->id,
            'link_type' => LinkType::InstalledIn,
            'linked_at' => now()->subDays(30),
            'unlinked_at' => now()->subDays(5),
        ]);

        $fixedAsset = FinFixedAsset::create([
            'organization_id' => 1,
            'asset_name' => 'Test FA',
            'category' => 'equipment',
            'purchase_date' => '2025-01-01',
            'purchase_cost' => 1000,
            'residual_value' => 100,
            'useful_life_months' => 36,
            'depreciation_method' => 'straight_line',
            'accumulated_depreciation' => 0,
            'status' => 'active',
            'linked_asset_id' => $asset->id,
            'created_by' => $this->admin->id,
        ]);

        $response = $this->actingAs($this->admin)
            ->get("/finance/fixed-assets/{$fixedAsset->id}");

        $response->assertInertia(function ($page) {
            $this->assertCount(0, $page->toArray()['props']['linkedDevices']);
        });
    }

    // ── Other assets' devices do not appear ───────────────────────

    public function test_other_assets_devices_not_shown(): void
    {
        $assetA = Asset::factory()->create();
        $assetB = Asset::factory()->create();

        $deviceB = Device::factory()->create(['name' => 'Device B']);
        DeviceAssetLink::create([
            'device_id' => $deviceB->id,
            'asset_id' => $assetB->id,
            'link_type' => LinkType::InstalledIn,
            'linked_at' => now(),
        ]);

        $fixedAsset = FinFixedAsset::create([
            'organization_id' => 1,
            'asset_name' => 'FA for Asset A',
            'category' => 'equipment',
            'purchase_date' => '2025-01-01',
            'purchase_cost' => 5000,
            'residual_value' => 500,
            'useful_life_months' => 36,
            'depreciation_method' => 'straight_line',
            'accumulated_depreciation' => 0,
            'status' => 'active',
            'linked_asset_id' => $assetA->id,
            'created_by' => $this->admin->id,
        ]);

        $response = $this->actingAs($this->admin)
            ->get("/finance/fixed-assets/{$fixedAsset->id}");

        $response->assertInertia(function ($page) {
            $this->assertCount(0, $page->toArray()['props']['linkedDevices']);
        });
    }

    // ── Safe fallback when no linked asset ────────────────────────

    public function test_no_linked_asset_returns_empty(): void
    {
        $fixedAsset = FinFixedAsset::create([
            'organization_id' => 1,
            'asset_name' => 'Standalone FA',
            'category' => 'furniture',
            'purchase_date' => '2025-01-01',
            'purchase_cost' => 2000,
            'residual_value' => 200,
            'useful_life_months' => 60,
            'depreciation_method' => 'straight_line',
            'accumulated_depreciation' => 0,
            'status' => 'active',
            'linked_asset_id' => null,
            'created_by' => $this->admin->id,
        ]);

        $response = $this->actingAs($this->admin)
            ->get("/finance/fixed-assets/{$fixedAsset->id}");

        $response->assertOk();
        $response->assertInertia(function ($page) {
            $this->assertNull($page->toArray()['props']['linkedAsset']);
            $this->assertCount(0, $page->toArray()['props']['linkedDevices']);
        });
    }

    // ── Canonical fields present ──────────────────────────────────

    public function test_device_health_includes_canonical_fields(): void
    {
        $asset = Asset::factory()->create();
        $device = Device::factory()->itInfrastructure()->create([
            'name' => 'Core Switch',
            'serial_number' => 'SW-999',
        ]);

        DeviceAssetLink::create([
            'device_id' => $device->id,
            'asset_id' => $asset->id,
            'link_type' => LinkType::Primary,
            'linked_at' => now(),
        ]);

        $fixedAsset = FinFixedAsset::create([
            'organization_id' => 1,
            'asset_name' => 'Core Switch (Finance)',
            'category' => 'it_equipment',
            'purchase_date' => '2025-03-01',
            'purchase_cost' => 12000,
            'residual_value' => 1000,
            'useful_life_months' => 60,
            'depreciation_method' => 'straight_line',
            'accumulated_depreciation' => 0,
            'status' => 'active',
            'linked_asset_id' => $asset->id,
            'created_by' => $this->admin->id,
        ]);

        $response = $this->actingAs($this->admin)
            ->get("/finance/fixed-assets/{$fixedAsset->id}");

        $response->assertInertia(function ($page) {
            $d = $page->toArray()['props']['linkedDevices'][0];
            $this->assertArrayHasKey('id', $d);
            $this->assertArrayHasKey('device_uid', $d);
            $this->assertArrayHasKey('name', $d);
            $this->assertArrayHasKey('domain', $d);
            $this->assertArrayHasKey('category', $d);
            $this->assertArrayHasKey('status', $d);
            $this->assertArrayHasKey('health_status', $d);
            $this->assertArrayHasKey('provider', $d);
            $this->assertArrayHasKey('last_seen_at', $d);
            $this->assertArrayHasKey('battery_level', $d);
            $this->assertArrayHasKey('link_type', $d);
            $this->assertArrayHasKey('detail_url', $d);
        });
    }
}
