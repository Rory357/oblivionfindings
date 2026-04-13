<?php

namespace Tests\Feature\SecurityDevices;

use App\Domain\SecurityDevices\Enums\DeviceStatus;
use App\Domain\SecurityDevices\Enums\LinkType;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssetLink;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Models\Asset;
use App\Models\Client;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SecurityDevicesPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Verify that all consumer paths previously reading from AssetTracker
 * now read from the canonical Security & Devices registry.
 */
class AssetTrackerRetirementTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
        $this->seed(SecurityDevicesPermissionsSeeder::class);

        $this->admin = User::factory()->create(['role' => 'admin']);
        $this->admin->roles()->attach(Role::where('name', 'admin')->first());
    }

    // ── Fleet devices reads from canonical devices ────────────────

    public function test_fleet_devices_reads_from_canonical_devices(): void
    {
        $vehicle = Asset::factory()->vehicle()->create();
        $device = Device::factory()->tracking()->create(['name' => 'Canonical Tracker']);

        DeviceAssetLink::create([
            'device_id' => $device->id,
            'asset_id' => $vehicle->id,
            'link_type' => LinkType::InstalledIn,
            'linked_at' => now(),
        ]);

        $response = $this->actingAs($this->admin)->get('/fleet-assets/devices');

        $response->assertOk();
        $response->assertInertia(function ($page) {
            $devices = $page->toArray()['props']['devices']['data'];
            $this->assertGreaterThanOrEqual(1, count($devices));
            $this->assertEquals('canonical', $devices[0]['source']);
        });
    }

    // ── Fleet pair/unpair uses canonical device links ──────────────

    public function test_fleet_pair_creates_device_asset_link(): void
    {
        $vehicle = Asset::factory()->vehicle()->create();
        $device = Device::factory()->tracking()->create();

        $this->actingAs($this->admin)
            ->post('/fleet-assets/devices/pair', [
                'asset_id' => $vehicle->id,
                'device_id' => $device->id,
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('device_asset_links', [
            'device_id' => $device->id,
            'asset_id' => $vehicle->id,
        ]);
    }

    // ── Resident tracking reads from canonical devices ─────────────

    public function test_resident_tracking_reads_from_canonical_devices(): void
    {
        $client = Client::factory()->create(['status' => 'active']);
        $device = Device::factory()->tracking()->create(['name' => 'Resident Pendant']);

        DeviceAssignment::create([
            'device_id' => $device->id,
            'assignable_type' => 'client',
            'assignable_id' => $client->id,
            'assigned_at' => now(),
        ]);

        $response = $this->actingAs($this->admin)
            ->get('/fleet-assets/resident-tracking');

        $response->assertOk();
        $response->assertInertia(function ($page) {
            $residents = $page->toArray()['props']['residents'];
            $this->assertCount(1, $residents);
            $this->assertArrayHasKey('device_uid', $residents[0]);
        });
    }

    // ── Fleet dashboard uses canonical device counts ───────────────

    public function test_fleet_dashboard_device_count_from_canonical(): void
    {
        Device::factory()->tracking()->count(3)->create(['status' => DeviceStatus::Active]);
        Device::factory()->tracking()->create(['status' => DeviceStatus::Offline]);

        $response = $this->actingAs($this->admin)->get('/fleet-assets');

        $response->assertOk();
        // Dashboard should show device counts from canonical devices, not AssetTracker.
        $response->assertInertia(function ($page) {
            $stats = $page->toArray()['props']['stats'];
            $this->assertEquals(3, $stats['total_devices'] ?? $stats['totalDevices'] ?? 0);
        });
    }

    // ── Asset show uses canonical device links ────────────────────

    public function test_asset_show_trackers_from_canonical_links(): void
    {
        $vehicle = Asset::factory()->vehicle()->create();
        $device = Device::factory()->tracking()->create(['name' => 'Vehicle GPS']);

        DeviceAssetLink::create([
            'device_id' => $device->id,
            'asset_id' => $vehicle->id,
            'link_type' => LinkType::InstalledIn,
            'linked_at' => now(),
        ]);

        $response = $this->actingAs($this->admin)
            ->get("/fleet-assets/assets/{$vehicle->id}");

        $response->assertOk();
        $response->assertInertia(function ($page) {
            $trackers = $page->toArray()['props']['asset']['trackers'];
            $this->assertCount(1, $trackers);
            $this->assertEquals('Vehicle GPS', $trackers[0]['name']);
            $this->assertArrayHasKey('detail_url', $trackers[0]);
        });
    }

    // ── AssetTracker model is marked deprecated ───────────────────

    public function test_asset_tracker_model_has_deprecation_annotation(): void
    {
        $reflection = new \ReflectionClass(\App\Models\AssetTracker::class);
        $docComment = $reflection->getDocComment();

        $this->assertNotFalse($docComment);
        $this->assertStringContainsString('@deprecated', $docComment);
    }

    // ── Asset.trackers() relationship is marked deprecated ────────

    public function test_asset_trackers_relationship_has_deprecation(): void
    {
        $reflection = new \ReflectionMethod(\App\Models\Asset::class, 'trackers');
        $docComment = $reflection->getDocComment();

        $this->assertNotFalse($docComment);
        $this->assertStringContainsString('@deprecated', $docComment);
    }
}
