<?php

namespace Tests\Feature\SecurityDevices;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\SecurityDevices\Enums\DeviceStatus;
use App\Domain\SecurityDevices\Enums\LinkType;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssetLink;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Models\Asset;
use App\Models\AssetTelemetrySnapshot;
use App\Models\AssetTracker;
use App\Models\Client;
use App\Models\ClientConsent;
use App\Models\ConsentType;
use App\Models\ConsentTypeVersion;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
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

        $response->assertInertia(function ($page) use ($vehicle) {
            $d = $page->toArray()['props']['devices']['data'][0];
            $this->assertNotNull($d['asset']);
            $this->assertEquals('Van 42', $d['asset']['name']);
            $this->assertSame("/fleet-assets/vehicles/{$vehicle->id}", $d['asset']['href']);
        });
    }

    public function test_asset_links_are_emitted_only_for_destination_routes_the_viewer_can_open(): void
    {
        $visibleSite = Site::factory()->create(['name' => 'Visible Fleet Site']);
        $hiddenSite = Site::factory()->create(['name' => 'Hidden Fleet Site']);
        $vehicle = Asset::factory()->vehicle()->forSite($visibleSite)->create(['name' => 'Destination van']);
        $equipment = Asset::factory()->forSite($visibleSite)->create([
            'name' => 'Destination equipment',
            'category' => 'IT Equipment',
        ]);
        $hiddenVehicle = Asset::factory()->vehicle()->forSite($hiddenSite)->create(['name' => 'Hidden destination van']);
        $vehicleDevice = Device::factory()->tracking()->create(['name' => 'Vehicle destination tracker']);
        $equipmentDevice = Device::factory()->tracking()->create(['name' => 'Equipment destination tracker']);
        $hiddenDevice = Device::factory()->tracking()->create(['name' => 'Hidden destination tracker']);

        foreach ([[$vehicleDevice, $vehicle], [$equipmentDevice, $equipment], [$hiddenDevice, $hiddenVehicle]] as [$device, $asset]) {
            DeviceAssetLink::create([
                'device_id' => $device->id,
                'asset_id' => $asset->id,
                'link_type' => LinkType::InstalledIn,
                'linked_at' => now(),
            ]);
        }

        $fleetViewer = $this->viewerWithPermissions(['fleet.viewAny'], $visibleSite);
        $this->actingAs($fleetViewer)
            ->get('/fleet-assets/devices')
            ->assertOk()
            ->assertInertia(function ($page) use ($equipmentDevice, $hiddenDevice, $vehicle, $vehicleDevice): void {
                $rows = collect($page->toArray()['props']['devices']['data'])->keyBy('id');

                $this->assertSame(
                    "/fleet-assets/vehicles/{$vehicle->id}",
                    $rows->get($vehicleDevice->id)['asset']['href'],
                );
                $this->assertFalse($rows->has($equipmentDevice->id));
                $this->assertFalse($rows->has($hiddenDevice->id));
            });

        $assetViewer = $this->viewerWithPermissions(['assets.trackers.manage', 'assets.viewAny'], $visibleSite);
        $this->actingAs($assetViewer)
            ->get('/fleet-assets/devices')
            ->assertOk()
            ->assertInertia(function ($page) use ($equipment, $equipmentDevice, $hiddenDevice, $vehicle, $vehicleDevice): void {
                $rows = collect($page->toArray()['props']['devices']['data'])->keyBy('id');

                $this->assertSame(
                    "/fleet-assets/assets/{$vehicle->id}",
                    $rows->get($vehicleDevice->id)['asset']['href'],
                );
                $this->assertSame(
                    "/fleet-assets/assets/{$equipment->id}",
                    $rows->get($equipmentDevice->id)['asset']['href'],
                );
                $this->assertFalse($rows->has($hiddenDevice->id));
            });
    }

    public function test_fleet_device_surfaces_conceal_hidden_site_devices_assets_and_consent_rows(): void
    {
        $visibleSite = Site::factory()->create(['name' => 'Fleet Visible Site']);
        $hiddenSite = Site::factory()->create(['name' => 'Fleet Hidden Site']);
        $visibleAsset = Asset::factory()->vehicle()->forSite($visibleSite)->create(['name' => 'Scoped vehicle visible']);
        $hiddenAsset = Asset::factory()->vehicle()->forSite($hiddenSite)->create(['name' => 'Scoped vehicle hidden']);
        $visibleLinked = Device::factory()->tracking()->create(['name' => 'Scoped tracker visible']);
        $hiddenLinked = Device::factory()->tracking()->create(['name' => 'Scoped tracker hidden']);
        foreach ([[$visibleLinked, $visibleAsset], [$hiddenLinked, $hiddenAsset]] as [$device, $asset]) {
            DeviceAssetLink::query()->create([
                'device_id' => $device->id,
                'asset_id' => $asset->id,
                'link_type' => LinkType::InstalledIn,
                'linked_at' => now(),
            ]);
        }

        $visibleCandidate = Device::factory()->tracking()->create(['name' => 'Pair candidate visible']);
        $hiddenCandidate = Device::factory()->tracking()->create(['name' => 'Pair candidate hidden']);
        foreach ([[$visibleCandidate, $visibleSite], [$hiddenCandidate, $hiddenSite]] as [$device, $site]) {
            DeviceAssignment::query()->create([
                'device_id' => $device->id,
                'assignable_type' => DeviceAssignment::TARGET_SITE,
                'assignable_id' => $site->id,
                'assigned_at' => now(),
            ]);
        }

        $viewer = $this->viewerWithPermissions([
            'fleet.viewAny',
            'assets.trackers.manage',
            'assets.viewAny',
        ], $visibleSite);

        $this->actingAs($viewer)
            ->get('/fleet-assets/devices?device='.$visibleLinked->id)
            ->assertOk()
            ->assertInertia(function ($page) use ($hiddenAsset, $hiddenCandidate, $hiddenLinked, $visibleAsset, $visibleCandidate, $visibleLinked): void {
                $props = $page->toArray()['props'];
                $deviceIds = collect($props['devices']['data'])->pluck('id');
                $consentIds = collect($props['consent_devices'])->pluck('id');
                $pairingDeviceIds = collect($props['pairing_options']['devices'])->pluck('id');
                $pairingAssetIds = collect($props['pairing_options']['assets'])->pluck('id');

                $this->assertTrue($deviceIds->contains($visibleLinked->id));
                $this->assertTrue($deviceIds->contains($visibleCandidate->id));
                $this->assertFalse($deviceIds->contains($hiddenLinked->id));
                $this->assertFalse($deviceIds->contains($hiddenCandidate->id));
                $this->assertSame($visibleLinked->id, $props['device_detail']['id']);
                $this->assertSame([$visibleLinked->id], $consentIds->all());
                $this->assertTrue($pairingDeviceIds->contains($visibleCandidate->id));
                $this->assertFalse($pairingDeviceIds->contains($hiddenCandidate->id));
                $this->assertTrue($pairingAssetIds->contains($visibleAsset->id));
                $this->assertFalse($pairingAssetIds->contains($hiddenAsset->id));
            });

        $this->actingAs($viewer)
            ->get('/fleet-assets/devices?device='.$hiddenLinked->id)
            ->assertNotFound();
        $this->actingAs($viewer)
            ->get('/fleet-assets/devices/'.$hiddenLinked->id)
            ->assertNotFound();

        $csv = $this->actingAs($viewer)
            ->get('/fleet-assets/devices?export=csv')
            ->assertOk()
            ->streamedContent();
        $this->assertStringContainsString((string) $visibleLinked->device_uid, $csv);
        $this->assertStringNotContainsString((string) $hiddenLinked->device_uid, $csv);

        $this->actingAs($viewer)
            ->getJson('/fleet-assets/devices/options/search?type=devices&q='.urlencode((string) $visibleCandidate->device_uid))
            ->assertOk()
            ->assertJsonPath('results.0.id', $visibleCandidate->id)
            ->assertJsonCount(1, 'results');
        $this->actingAs($viewer)
            ->getJson('/fleet-assets/devices/options/search?type=assets&q=Scoped%20vehicle')
            ->assertOk()
            ->assertJsonPath('results.0.id', $visibleAsset->id)
            ->assertJsonCount(1, 'results');

        $this->actingAs($viewer)
            ->post('/fleet-assets/devices/pair', ['device_id' => $hiddenCandidate->id, 'asset_id' => $visibleAsset->id])
            ->assertNotFound();
        $this->actingAs($viewer)
            ->post('/fleet-assets/devices/pair', ['device_id' => $visibleCandidate->id, 'asset_id' => $hiddenAsset->id])
            ->assertNotFound();
        $this->actingAs($viewer)
            ->post('/fleet-assets/devices/'.$hiddenLinked->id.'/unpair')
            ->assertNotFound();
        $this->actingAs($viewer)
            ->post('/fleet-assets/devices/'.$hiddenLinked->id.'/consent/grant')
            ->assertNotFound();
        $this->actingAs($viewer)
            ->post('/fleet-assets/devices/'.$hiddenLinked->id.'/consent/revoke', ['reason' => 'Hidden Site test'])
            ->assertNotFound();
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
            ->where('stats.online', 3)
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

        $response->assertRedirect("/fleet-assets/devices?device={$device->id}");

        $this->actingAs($this->admin)
            ->get("/fleet-assets/devices?device={$device->id}")
            ->assertOk()
            ->assertInertia(function ($page) {
                $tracker = $page->toArray()['props']['device_detail'];
                $this->assertEquals('Vehicle Tracker X', $tracker['name']);
                $this->assertEquals('123456789', $tracker['imei']);
                $this->assertArrayHasKey('detail_url', $tracker);
                $this->assertArrayHasKey('health_status', $tracker);
                $this->assertArrayHasKey('telemetry_snapshots', $tracker);
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

        $response->assertRedirect("/fleet-assets/devices?device={$device->id}");

        $this->actingAs($this->admin)
            ->get("/fleet-assets/devices?device={$device->id}")
            ->assertOk()
            ->assertInertia(function ($page) {
                $tracker = $page->toArray()['props']['device_detail'];
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
        $site = Site::factory()->create();
        $client = Client::factory()->create(['site_id' => $site->id, 'status' => 'active']);
        $assetClient = Client::factory()->create(['site_id' => $site->id, 'status' => 'active']);
        $asset = Asset::factory()->vehicle()->forClient($assetClient->id)->create(['name' => 'Van Consent']);
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

        $trackerConsent = $this->createFleetTrackingConsent($assetClient, [
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

        $response->assertRedirect('/fleet-assets/devices?tab=consent');

        $this->actingAs($this->admin)
            ->get('/fleet-assets/devices?tab=consent')
            ->assertOk()
            ->assertInertia(function ($page) use ($client, $device) {
                $props = $page->toArray()['props'];
                $this->assertSame('consent', $props['tab']);
                $row = collect($props['consent_devices'])->firstWhere('id', $device->id);

                $this->assertNotNull($row);
                $this->assertEquals($device->id, $row['id']);
                $this->assertEquals('consented', $row['consent_status']);
                $this->assertSame(trim($client->first_name.' '.$client->last_name), $row['client_name']);
            });
    }

    public function test_grant_consent_updates_only_the_canonical_assignment_and_preserves_tracker_history(): void
    {
        $site = Site::factory()->create();
        $client = Client::factory()->create(['site_id' => $site->id, 'status' => 'active']);
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
        $legacyConsent = $this->createFleetTrackingConsent($client, [
            'given_by_user_id' => $this->admin->id,
            'given_at' => now()->subDays(10),
            'expires_at' => now()->addDays(10),
        ]);
        $tracker->update(['consent_id' => $legacyConsent->id]);

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
            'consent_id' => $legacyConsent->id,
        ]);
        $this->assertDatabaseHas('client_consents', [
            'id' => $legacyConsent->id,
            'status' => 'given',
            'superseded_by_consent_id' => null,
        ]);
    }

    public function test_revoke_consent_withdraws_only_the_canonical_assignment_consent(): void
    {
        $site = Site::factory()->create();
        $client = Client::factory()->create(['site_id' => $site->id, 'status' => 'active']);
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
            'status' => 'given',
        ]);
        $this->assertDatabaseHas('asset_trackers', [
            'id' => $tracker->id,
            'consent_id' => $trackerConsent->id,
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

    /** @param array<int, string> $permissions */
    private function viewerWithPermissions(array $permissions, ?Site $site = null): User
    {
        $viewer = User::factory()->create(['approved_at' => now()]);
        if ($site) {
            HrEmployeeProfile::factory()->create([
                'user_id' => $viewer->id,
                'primary_site_id' => $site->id,
                'secondary_site_ids' => [],
                'is_active' => true,
            ]);
        }
        foreach ($permissions as $key) {
            Permission::firstOrCreate(
                ['key' => $key],
                ['description' => $key, 'group' => 'test', 'module' => 'Test'],
            );
        }
        $overrides = Permission::query()
            ->whereIn('key', $permissions)
            ->pluck('id')
            ->mapWithKeys(fn (int $id): array => [$id => ['allowed' => true]])
            ->all();
        $viewer->permissionOverrides()->sync($overrides);

        return $viewer;
    }
}
