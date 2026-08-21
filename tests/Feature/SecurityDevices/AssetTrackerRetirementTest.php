<?php

namespace Tests\Feature\SecurityDevices;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\SecurityDevices\Enums\DeviceStatus;
use App\Domain\SecurityDevices\Enums\LinkType;
use App\Domain\SecurityDevices\Http\Controllers\Integrations\QueclinkHubController;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssetLink;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Models\Asset;
use App\Models\AssetTracker;
use App\Models\Client;
use App\Models\ClientConsent;
use App\Models\ConsentType;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SecurityDevicesPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\Support\AuthoritativeConsentFixture;
use Tests\TestCase;

/**
 * Verify that all consumer paths previously reading from AssetTracker
 * now read from the canonical Security & Devices registry.
 */
class AssetTrackerRetirementTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
        $this->seed(SecurityDevicesPermissionsSeeder::class);

        $this->admin = User::factory()->create([
            'role' => 'admin',

        ]);
        $this->admin->roles()->attach(Role::where('name', 'admin')->first());

        $this->site = Site::factory()->create([]);
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

        $this->assertDatabaseCount('asset_trackers', 0);
    }

    public function test_legacy_asset_tracker_mutation_routes_are_removed(): void
    {
        $vehicle = Asset::factory()->vehicle()->create();

        $this->assertFalse(Route::has('assets.trackers.pair'));
        $this->assertFalse(Route::has('assets.trackers.unpair'));

        $this->actingAs($this->admin)
            ->post("/assets/{$vehicle->id}/trackers/pair", [
                'vendor' => 'legacy-provider',
                'device_uid' => 'LEGACY-RAW-TRACKER',
                'vendor_metadata' => ['secret' => 'must-not-be-stored'],
            ])
            ->assertNotFound();

        $this->assertDatabaseCount('asset_trackers', 0);
        $this->assertDatabaseCount('devices', 0);
        $this->assertDatabaseCount('device_asset_links', 0);
    }

    public function test_asset_index_counts_only_active_visible_canonical_devices(): void
    {
        $vehicle = Asset::factory()->vehicle()->create(['name' => 'Canonical Count Vehicle']);
        $activeDevices = Device::factory()->tracking()->count(2)->create();
        $releasedDevice = Device::factory()->tracking()->create();

        foreach ($activeDevices as $device) {
            DeviceAssetLink::create([
                'device_id' => $device->id,
                'asset_id' => $vehicle->id,
                'link_type' => LinkType::InstalledIn,
                'linked_at' => now(),
            ]);
        }

        DeviceAssetLink::create([
            'device_id' => $releasedDevice->id,
            'asset_id' => $vehicle->id,
            'link_type' => LinkType::InstalledIn,
            'linked_at' => now()->subDay(),
            'unlinked_at' => now(),
        ]);

        $this->actingAs($this->admin)
            ->get('/fleet-assets/assets?search=Canonical%20Count%20Vehicle')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('assets.data', 1)
                ->where('assets.data.0.id', $vehicle->id)
                ->where('assets.data.0.tracker_count', 2));
    }

    public function test_asset_index_marks_technology_restricted_without_device_permission(): void
    {
        $vehicle = Asset::factory()->vehicle()->create([
            'name' => 'Restricted Technology Vehicle',
            'site_id' => $this->site->id,
        ]);
        $viewer = User::factory()->create(['approved_at' => now()]);
        $viewAssets = Permission::query()->where('key', 'assets.viewAny')->firstOrFail();
        $viewDevices = Permission::query()->where('key', 'securityDevices.devices.view')->firstOrFail();
        $viewer->permissionOverrides()->sync([
            $viewAssets->id => ['allowed' => true],
            $viewDevices->id => ['allowed' => false],
        ]);
        HrEmployeeProfile::factory()->create([
            'user_id' => $viewer->id,
            'primary_site_id' => $this->site->id,
            'secondary_site_ids' => [],
            'is_active' => true,
        ]);

        $this->actingAs($viewer)
            ->get('/fleet-assets/assets?search=Restricted%20Technology%20Vehicle')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('assets.data', 1)
                ->where('assets.data.0.id', $vehicle->id)
                ->where('assets.data.0.tracker_count', null));
    }

    public function test_vehicle_operational_payloads_do_not_project_legacy_trackers(): void
    {
        $vehicle = Asset::factory()->vehicle()->create(['name' => 'Canonical Vehicle Payload']);
        AssetTracker::create([
            'asset_id' => $vehicle->id,
            'vendor' => 'legacy-provider',
            'device_uid' => 'LEGACY-ONLY-PAYLOAD',
            'status' => 'paired',
            'paired_at' => now(),
        ]);

        $this->actingAs($this->admin)
            ->get("/fleet-assets/vehicles/{$vehicle->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page->missing('asset.trackers'));

        $this->actingAs($this->admin)
            ->get('/fleet-assets/vehicles?search=Canonical%20Vehicle%20Payload')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('vehicles.data', 1)
                ->missing('vehicles.data.0.tracker_count'));
    }

    // ── Resident tracking reads from canonical devices ─────────────

    public function test_resident_tracking_reads_from_canonical_devices(): void
    {
        $client = Client::factory()->create([

            'site_id' => $this->site->id,
            'status' => 'active',
        ]);
        $consent = $this->createFleetTrackingConsent($client);
        $device = Device::factory()->tracking()->create([
            'name' => 'Resident Pendant',
        ]);

        DeviceAssignment::create([
            'device_id' => $device->id,
            'assignable_type' => 'client',
            'assignable_id' => $client->id,
            'assigned_at' => now(),
            'consent_id' => $consent->id,
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
        $reflection = new \ReflectionClass(AssetTracker::class);
        $docComment = $reflection->getDocComment();

        $this->assertNotFalse($docComment);
        $this->assertStringContainsString('@deprecated', $docComment);
        $this->assertStringContainsString('historical read-only compatibility projections', $docComment);
    }

    public function test_queclink_pairing_and_release_do_not_reference_the_legacy_tracker_store(): void
    {
        $reflection = new \ReflectionClass(QueclinkHubController::class);
        $source = file_get_contents($reflection->getFileName());

        $this->assertIsString($source);
        $this->assertStringNotContainsString('AssetTracker', $source);
        $this->assertStringNotContainsString('asset_trackers', $source);
    }

    // ── Asset.trackers() relationship is marked deprecated ────────

    public function test_asset_trackers_relationship_has_deprecation(): void
    {
        $reflection = new \ReflectionMethod(Asset::class, 'trackers');
        $docComment = $reflection->getDocComment();

        $this->assertNotFalse($docComment);
        $this->assertStringContainsString('@deprecated', $docComment);
    }

    private function createFleetTrackingConsent(Client $client): ClientConsent
    {
        $type = ConsentType::firstOrCreate(
            ['name' => 'Fleet Tracking'],
            [
                'category' => 'operational',
                'description' => 'Vehicle / tracker GPS consent',
                'purpose' => 'Tracker location collection',
                'legal_basis' => 'consent',
                'active' => true,
            ],
        );

        return AuthoritativeConsentFixture::manualSelf($client, $type, $this->admin, [
            'status' => 'given',
            'given_at' => now(),
            'expires_at' => now()->addMonth(),
        ]);
    }
}
