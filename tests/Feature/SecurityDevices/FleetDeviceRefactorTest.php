<?php

namespace Tests\Feature\SecurityDevices;

use App\Domain\SecurityDevices\Enums\DeviceStatus;
use App\Domain\SecurityDevices\Enums\LinkType;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Domain\SecurityDevices\Models\DeviceAssetLink;
use App\Models\Asset;
use App\Models\AssetTelemetrySnapshot;
use App\Models\AssetTracker;
use App\Models\Client;
use App\Models\ClientConsent;
use App\Models\ConsentType;
use App\Models\ConsentTypeVersion;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SecurityDevicesPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FleetDeviceRefactorTest extends TestCase
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

    // ── Index: reads from canonical devices ────────────────────────

    public function test_index_returns_canonical_tracking_devices(): void
    {
        Device::factory()->tracking()->create(['name' => 'GPS Tracker 1']);
        Device::factory()->tracking()->create(['name' => 'GPS Tracker 2']);
        Device::factory()->security()->create(['name' => 'Camera']); // not tracking

        $response = $this->actingAs($this->admin)->get('/fleet-assets/devices');

        $response->assertOk();
        $response->assertInertia(function ($page) {
            $devices = $page->toArray()['props']['devices']['data'];
            $this->assertCount(2, $devices); // only tracking domain
            $this->assertEquals('canonical', $devices[0]['source']);
            $this->assertArrayHasKey('device_uid', $devices[0]);
            $this->assertArrayHasKey('detail_url', $devices[0]);
        });
    }

    public function test_index_shows_linked_asset(): void
    {
        $device = Device::factory()->tracking()->create();
        $vehicle = Asset::factory()->vehicle()->create(['name' => 'Van 42']);

        DeviceAssetLink::create([
            'device_id' => $device->id,
            'asset_id' => $vehicle->id,
            'link_type' => LinkType::InstalledIn,
            'linked_at' => now(),
        ]);

        $response = $this->actingAs($this->admin)->get('/fleet-assets/devices');

        $response->assertInertia(function ($page) {
            $d = $page->toArray()['props']['devices']['data'][0];
            $this->assertNotNull($d['asset']);
            $this->assertEquals('Van 42', $d['asset']['name']);
        });
    }

    public function test_index_stats_correct(): void
    {
        Device::factory()->tracking()->create(['status' => DeviceStatus::Active]);
        Device::factory()->tracking()->create(['status' => DeviceStatus::Offline]);

        $unpairedDevice = Device::factory()->tracking()->create(['status' => DeviceStatus::Active]);
        // No asset link for unpaired device.

        $pairedDevice = Device::factory()->tracking()->create(['status' => DeviceStatus::Active]);
        $vehicle = Asset::factory()->vehicle()->create();
        DeviceAssetLink::create([
            'device_id' => $pairedDevice->id,
            'asset_id' => $vehicle->id,
            'link_type' => LinkType::InstalledIn,
            'linked_at' => now(),
        ]);

        $response = $this->actingAs($this->admin)->get('/fleet-assets/devices');

        $response->assertInertia(fn ($page) => $page
            ->where('stats.total', 4)
            ->where('stats.online', 3) // 2 active + 1 unpaired active + 1 paired active... wait, let me count
        );
    }

    // ── Show: reads from canonical device ──────────────────────────

    public function test_show_returns_canonical_device(): void
    {
        $device = Device::factory()->tracking()->create([
            'name' => 'Vehicle Tracker X',
            'imei' => '123456789',
        ]);

        $response = $this->actingAs($this->admin)
            ->get("/fleet-assets/devices/{$device->id}");

        $response->assertOk();
        $response->assertInertia(function ($page) {
            $tracker = $page->toArray()['props']['tracker'];
            $this->assertEquals('Vehicle Tracker X', $tracker['name']);
            $this->assertEquals('123456789', $tracker['imei']);
            $this->assertArrayHasKey('detail_url', $tracker);
            $this->assertArrayHasKey('health_status', $tracker);
        });
    }

    public function test_show_prefers_canonical_snapshot_lineage_without_legacy_bridge(): void
    {
        $asset = Asset::factory()->vehicle()->create();
        $tracker = AssetTracker::create([
            'asset_id' => $asset->id,
            'vendor' => 'quicklink',
            'device_uid' => 'QL-100',
            'status' => 'paired',
            'paired_at' => now(),
        ]);
        $device = Device::factory()->tracking()->create([
            'legacy_asset_tracker_id' => null,
        ]);

        AssetTelemetrySnapshot::create([
            'asset_id' => $asset->id,
            'asset_tracker_id' => $tracker->id,
            'device_id' => $device->id,
            'occurred_at' => now()->subMinute(),
            'received_at' => now(),
            'vendor_payload_hash' => 'hash-show-canonical',
        ]);

        $response = $this->actingAs($this->admin)
            ->get("/fleet-assets/devices/{$device->id}");

        $response->assertOk();
        $response->assertInertia(function ($page) {
            $tracker = $page->toArray()['props']['tracker'];
            $this->assertCount(1, $tracker['telemetry_snapshots']);
        });
    }

    // ── Pair: creates device_asset_link ────────────────────────────

    public function test_pair_creates_device_asset_link(): void
    {
        $device = Device::factory()->tracking()->create();
        $vehicle = Asset::factory()->vehicle()->create();

        $response = $this->actingAs($this->admin)
            ->post('/fleet-assets/devices/pair', [
                'asset_id' => $vehicle->id,
                'device_id' => $device->id,
            ]);

        $response->assertRedirect();

        $this->assertDatabaseHas('device_asset_links', [
            'device_id' => $device->id,
            'asset_id' => $vehicle->id,
            'link_type' => 'installed_in',
        ]);

        $this->assertNull(
            DeviceAssetLink::where('device_id', $device->id)
                ->where('asset_id', $vehicle->id)
                ->value('unlinked_at')
        );
    }

    public function test_pair_rejects_device_already_linked_to_another_asset(): void
    {
        $device = Device::factory()->tracking()->create();
        $vehicleA = Asset::factory()->vehicle()->create();
        $vehicleB = Asset::factory()->vehicle()->create();

        DeviceAssetLink::create([
            'device_id' => $device->id,
            'asset_id' => $vehicleA->id,
            'link_type' => LinkType::InstalledIn,
            'linked_at' => now(),
        ]);

        $response = $this->actingAs($this->admin)
            ->post('/fleet-assets/devices/pair', [
                'asset_id' => $vehicleB->id,
                'device_id' => $device->id,
            ]);

        $response->assertRedirect();
        $response->assertSessionHasErrors(['device_id']);
    }

    // ── Unpair: unlinks device_asset_link ──────────────────────────

    public function test_unpair_sets_unlinked_at(): void
    {
        $device = Device::factory()->tracking()->create();
        $vehicle = Asset::factory()->vehicle()->create();

        DeviceAssetLink::create([
            'device_id' => $device->id,
            'asset_id' => $vehicle->id,
            'link_type' => LinkType::InstalledIn,
            'linked_at' => now(),
        ]);

        $response = $this->actingAs($this->admin)
            ->post("/fleet-assets/devices/{$device->id}/unpair");

        $response->assertRedirect();

        $this->assertEquals(0, DeviceAssetLink::where('device_id', $device->id)->active()->count());
    }

    // ── Inactive links don't appear ───────────────────────────────

    public function test_unlinked_devices_not_shown_as_paired(): void
    {
        $device = Device::factory()->tracking()->create();
        $vehicle = Asset::factory()->vehicle()->create();

        // Historically linked but now unlinked.
        DeviceAssetLink::create([
            'device_id' => $device->id,
            'asset_id' => $vehicle->id,
            'link_type' => LinkType::InstalledIn,
            'linked_at' => now()->subDays(30),
            'unlinked_at' => now()->subDays(5),
        ]);

        $response = $this->actingAs($this->admin)->get('/fleet-assets/devices');

        $response->assertInertia(function ($page) {
            $d = $page->toArray()['props']['devices']['data'][0];
            $this->assertNull($d['asset']); // no active link
        });
    }

    // ── Other vehicles' devices isolated ──────────────────────────

    public function test_asset_show_only_shows_linked_devices(): void
    {
        $vehicleA = Asset::factory()->vehicle()->create();
        $vehicleB = Asset::factory()->vehicle()->create();

        $deviceA = Device::factory()->tracking()->create(['name' => 'Tracker A']);
        $deviceB = Device::factory()->tracking()->create(['name' => 'Tracker B']);

        DeviceAssetLink::create([
            'device_id' => $deviceA->id,
            'asset_id' => $vehicleA->id,
            'link_type' => LinkType::InstalledIn,
            'linked_at' => now(),
        ]);
        DeviceAssetLink::create([
            'device_id' => $deviceB->id,
            'asset_id' => $vehicleB->id,
            'link_type' => LinkType::InstalledIn,
            'linked_at' => now(),
        ]);

        $response = $this->actingAs($this->admin)
            ->get("/fleet-assets/assets/{$vehicleA->id}");

        $response->assertOk();
        $response->assertInertia(function ($page) {
            $trackers = $page->toArray()['props']['asset']['trackers'];
            $this->assertCount(1, $trackers);
            $this->assertEquals('Tracker A', $trackers[0]['name']);
            $this->assertArrayHasKey('detail_url', $trackers[0]);
            $this->assertArrayHasKey('health_status', $trackers[0]);
            $this->assertEquals('installed_in', $trackers[0]['link_type']);
        });
    }

    // ── Canonical fields present ──────────────────────────────────

    public function test_device_data_includes_canonical_fields(): void
    {
        $device = Device::factory()->tracking()->create([
            'provider' => 'queclink',
            'battery_level' => 78,
        ]);

        $response = $this->actingAs($this->admin)->get('/fleet-assets/devices');

        $response->assertInertia(function ($page) {
            $d = $page->toArray()['props']['devices']['data'][0];
            $this->assertArrayHasKey('id', $d);
            $this->assertArrayHasKey('device_uid', $d);
            $this->assertArrayHasKey('name', $d);
            $this->assertArrayHasKey('vendor', $d);
            $this->assertArrayHasKey('imei', $d);
            $this->assertArrayHasKey('status', $d);
            $this->assertArrayHasKey('health_status', $d);
            $this->assertArrayHasKey('last_seen_at', $d);
            $this->assertArrayHasKey('battery_level', $d);
            $this->assertArrayHasKey('detail_url', $d);
            $this->assertEquals('queclink', $d['vendor']);
            $this->assertEquals(78, $d['battery_level']);
        });
    }

    public function test_consent_index_prefers_assignment_consent_and_uses_canonical_device_ids(): void
    {
        $client = Client::factory()->create();
        $asset = Asset::factory()->vehicle()->forClient($client->id)->create(['name' => 'Van Consent']);
        $tracker = AssetTracker::create([
            'asset_id' => $asset->id,
            'vendor' => 'queclink',
            'device_uid' => 'QUE-CONSENT',
            'status' => 'paired',
            'paired_at' => now(),
        ]);
        $device = Device::factory()->tracking()->create([
            'provider' => 'queclink',
            'legacy_asset_tracker_id' => $tracker->id,
        ]);

        $trackerConsent = $this->createFleetTrackingConsent($client, [
            'status' => 'withdrawn',
            'given_by_user_id' => $this->admin->id,
            'given_at' => now()->subDays(7),
            'withdrawn_at' => now()->subDay(),
        ]);
        $tracker->update(['consent_id' => $trackerConsent->id]);

        $assignmentConsent = $this->createFleetTrackingConsent($client, [
            'given_by_user_id' => $this->admin->id,
            'given_at' => now()->subHour(),
            'expires_at' => now()->addDays(30),
        ]);
        DeviceAssignment::create([
            'device_id' => $device->id,
            'assignable_type' => DeviceAssignment::TARGET_CLIENT,
            'assignable_id' => $client->id,
            'assigned_at' => now()->subHour(),
            'consent_id' => $assignmentConsent->id,
        ]);

        $response = $this->actingAs($this->admin)->get('/fleet-assets/devices/consent');

        $response->assertOk();
        $response->assertInertia(function ($page) use ($device) {
            $rows = collect($page->toArray()['props']['devices']);
            $row = $rows->firstWhere('id', $device->id);

            $this->assertNotNull($row);
            $this->assertEquals($device->id, $row['id']);
            $this->assertEquals('consented', $row['consent_status']);
        });
    }

    public function test_grant_consent_uses_canonical_device_route_and_syncs_assignment_and_tracker(): void
    {
        $client = Client::factory()->create();
        $asset = Asset::factory()->vehicle()->forClient($client->id)->create();
        $tracker = AssetTracker::create([
            'asset_id' => $asset->id,
            'vendor' => 'queclink',
            'device_uid' => 'QUE-GRANT',
            'status' => 'paired',
            'paired_at' => now(),
        ]);
        $device = Device::factory()->tracking()->create([
            'provider' => 'queclink',
            'legacy_asset_tracker_id' => $tracker->id,
        ]);
        $assignment = DeviceAssignment::create([
            'device_id' => $device->id,
            'assignable_type' => DeviceAssignment::TARGET_CLIENT,
            'assignable_id' => $client->id,
            'assigned_at' => now(),
        ]);

        $this->actingAs($this->admin)
            ->post("/fleet-assets/devices/{$device->id}/consent/grant", [
                'notes' => 'Granted in PR29 test',
            ])
            ->assertRedirect();

        $consentId = ClientConsent::query()->latest('id')->value('id');

        $this->assertDatabaseHas('device_assignments', [
            'id' => $assignment->id,
            'consent_id' => $consentId,
        ]);
        $this->assertDatabaseHas('asset_trackers', [
            'id' => $tracker->id,
            'consent_id' => $consentId,
        ]);
    }

    public function test_revoke_consent_uses_canonical_device_route_and_revokes_distinct_assignment_and_tracker_consents(): void
    {
        $client = Client::factory()->create();
        $asset = Asset::factory()->vehicle()->forClient($client->id)->create();
        $tracker = AssetTracker::create([
            'asset_id' => $asset->id,
            'vendor' => 'queclink',
            'device_uid' => 'QUE-REVOKE',
            'status' => 'paired',
            'paired_at' => now(),
        ]);
        $device = Device::factory()->tracking()->create([
            'provider' => 'queclink',
            'legacy_asset_tracker_id' => $tracker->id,
        ]);

        $assignmentConsent = $this->createFleetTrackingConsent($client, [
            'given_by_user_id' => $this->admin->id,
            'given_at' => now()->subDay(),
            'expires_at' => now()->addDays(30),
        ]);
        $trackerConsent = $this->createFleetTrackingConsent($client, [
            'given_by_user_id' => $this->admin->id,
            'given_at' => now()->subDays(2),
            'expires_at' => now()->addDays(14),
        ]);

        DeviceAssignment::create([
            'device_id' => $device->id,
            'assignable_type' => DeviceAssignment::TARGET_CLIENT,
            'assignable_id' => $client->id,
            'assigned_at' => now(),
            'consent_id' => $assignmentConsent->id,
        ]);
        $tracker->update(['consent_id' => $trackerConsent->id]);

        $this->actingAs($this->admin)
            ->post("/fleet-assets/devices/{$device->id}/consent/revoke", [
                'reason' => 'Consent withdrawn',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('client_consents', [
            'id' => $assignmentConsent->id,
            'status' => 'withdrawn',
        ]);
        $this->assertDatabaseHas('client_consents', [
            'id' => $trackerConsent->id,
            'status' => 'withdrawn',
        ]);
    }

    private function createFleetTrackingConsent(Client $client, array $overrides = []): ClientConsent
    {
        $consentType = ConsentType::firstOrCreate(
            ['name' => 'Fleet Tracking'],
            [
                'category' => 'operational',
                'description' => 'Consent for vehicle location tracking.',
                'purpose' => 'Enable fleet vehicle GPS tracking.',
                'legal_basis' => 'consent',
                'is_mandatory' => false,
                'requires_capacity_assessment' => false,
                'allows_withdrawal' => true,
                'renewal_required' => false,
                'active' => true,
            ]
        );

        $version = ConsentTypeVersion::firstOrCreate(
            [
                'consent_type_id' => $consentType->id,
                'version' => 1,
            ],
            [
                'description' => 'Fleet tracking v1',
                'purpose' => 'Enable fleet vehicle GPS tracking.',
                'legal_basis' => 'consent',
                'effective_from' => now()->subDay(),
            ]
        );

        return ClientConsent::create(array_merge([
            'client_id' => $client->id,
            'consent_type_id' => $consentType->id,
            'consent_type_version_id' => $version->id,
            'status' => 'given',
            'given_at' => now(),
            'given_by_user_id' => $this->admin->id,
            'given_method' => 'electronic',
            'created_by' => $this->admin->id,
            'updated_by' => $this->admin->id,
        ], $overrides));
    }
}
