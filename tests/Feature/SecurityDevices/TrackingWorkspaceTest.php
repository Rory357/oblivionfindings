<?php

namespace Tests\Feature\SecurityDevices;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssetLink;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Models\Asset;
use App\Models\AssetGeofence;
use App\Models\AssetTracker;
use App\Models\Client;
use App\Models\ClientConsent;
use App\Models\ConsentType;
use App\Models\FleetTelemetryEvent;
use App\Models\LoneWorkerSession;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SecurityDevicesPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\AuthoritativeConsentFixture;
use Tests\TestCase;

class TrackingWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
        $this->seed(SecurityDevicesPermissionsSeeder::class);

        $this->admin = $this->viewerWithRole('admin');
    }

    public function test_overview_separates_personal_fleet_and_asset_tracking_without_duplicate_ownership(): void
    {
        $site = $this->site('Kauri House');
        $client = Client::factory()->create([

            'site_id' => $site->id,
            'preferred_name' => 'Mere',
        ]);
        $consent = $this->trackingConsent($client);
        $worker = User::factory()->create(['approved_at' => now()]);
        $vehicle = Asset::factory()->vehicle()->forSite($site)->create(['name' => 'Community van']);
        $asset = Asset::factory()->forSite($site)->create([
            'name' => 'Portable hoist',
            'category' => 'Medical Equipment',
        ]);

        $clientTracker = $this->trackingDevice('Mere pendant', ['category' => 'personal_tracker']);
        $staffTracker = $this->trackingDevice('Lone-worker pendant', ['category' => 'personal_tracker']);
        $fleetTracker = $this->trackingDevice('Van tracker', ['category' => 'vehicle_tracker']);
        $assetTracker = $this->trackingDevice('Hoist tag', ['category' => 'asset_tracker']);
        $unassignedTag = $this->trackingDevice('Spare tag', ['category' => 'asset_tracker']);
        $unrelated = $this->trackingDevice('Unrelated tracker', []);

        $this->assign($clientTracker, DeviceAssignment::TARGET_CLIENT, $client->id, $consent->id);
        $this->assign($staffTracker, DeviceAssignment::TARGET_STAFF, $worker->id);
        $this->assign($fleetTracker, DeviceAssignment::TARGET_VEHICLE, $vehicle->id);
        $this->link($fleetTracker, $vehicle, 'installed_in');
        $this->link($assetTracker, $asset);

        $this->actingAs($this->admin)
            ->get('/security-devices/tracking')
            ->assertOk()
            ->assertInertia(function ($page) use ($unrelated): void {
                $tracking = $page->toArray()['props']['trackingWorkspace'];

                $this->assertSame([
                    'total' => 5,
                    'personal_safety' => 2,
                    'fleet' => 1,
                    'assets' => 2,
                ], $tracking['overview']['inventory']);
                $this->assertSame(2, $tracking['overview']['attention']['unassigned']);
                $this->assertSame(5, $tracking['activeTab']['inventoryTotal']);
                $this->assertContains(
                    $unrelated->id,
                    collect($tracking['activeTab']['devices'])->pluck('id')->all(),
                );
                $this->assertSame(
                    ['personal-safety', 'fleet', 'assets'],
                    collect($tracking['overview']['groups'])->pluck('key')->all(),
                );
            });
    }

    public function test_inventory_reports_when_the_workspace_safety_limit_truncates_results(): void
    {
        Device::factory()
            ->count(101)
            ->tracking()
            ->create([]);

        $this->actingAs($this->admin)
            ->get('/security-devices/tracking')
            ->assertOk()
            ->assertInertia(function ($page): void {
                $activeTab = $page->toArray()['props']['trackingWorkspace']['activeTab'];

                $this->assertSame(100, $activeTab['inventoryShown']);
                $this->assertTrue($activeTab['inventoryTruncated']);
                $this->assertFalse($page->toArray()['props']['trackingWorkspace']['overview']['countsComplete']);
            });
    }

    public function test_attention_handoff_filters_the_bounded_tracking_worklist(): void
    {
        $offline = Device::factory()->offline()->create([
            'domain' => 'tracking',
            'category' => 'asset_tracker',
            'name' => 'Offline tracker',
        ]);
        Device::factory()->create([
            'domain' => 'tracking',
            'category' => 'asset_tracker',
            'name' => 'Healthy tracker',
        ]);

        $this->actingAs($this->admin)
            ->get('/security-devices/tracking?tab=overview&attention=offline')
            ->assertOk()
            ->assertInertia(function ($page) use ($offline): void {
                $active = $page->toArray()['props']['trackingWorkspace']['activeTab'];

                $this->assertSame([$offline->id], collect($active['devices'])->pluck('id')->all());
                $this->assertSame('offline', $active['attentionFilter']['key']);
                $this->assertSame('Offline tracking devices', $active['attentionFilter']['label']);
            });
    }

    public function test_client_tracking_requires_client_policy_active_consent_and_telemetry_permission(): void
    {
        $site = $this->site('Miro House');
        $client = Client::factory()->create([

            'site_id' => $site->id,
            'preferred_name' => 'Ani',
            'first_name' => 'Anahera',
            'last_name' => 'Private-Surname-Sentinel',
            'nhi_number' => 'TRACK-NHI-SENTINEL',
        ]);
        $consent = $this->trackingConsent($client, [
            'expires_at' => now()->addMonth(),
        ]);
        $device = $this->trackingDevice('Ani safety pendant', [
            'category' => 'personal_tracker',
            'latitude' => -36.8485,
            'longitude' => 174.7633,
            'last_seen_at' => now()->subMinute(),
            'meta' => ['raw_person_location' => 'RAW-PERSON-LOCATION-SENTINEL'],
        ]);
        $this->assign($device, DeviceAssignment::TARGET_CLIENT, $client->id, $consent->id);

        $viewer = $this->viewerWithRole('provider_manager');
        $this->assignViewerToSite($viewer, $site);
        $this->actingAs($viewer)
            ->get('/security-devices/tracking?tab=personal-safety')
            ->assertOk()
            ->assertInertia(function ($page) use ($client, $device): void {
                $props = $page->toArray()['props'];
                $activeTab = $props['trackingWorkspace']['activeTab'];
                $row = $activeTab['devices'][0];

                $this->assertSame($device->id, $row['id']);
                $this->assertSame([
                    'id' => $client->id,
                    'displayName' => 'Ani',
                    'href' => "/operations/clients/{$client->id}",
                ], $row['person']);
                $this->assertSame('client', $row['personalSafety']['personType']);
                $this->assertSame('active', $row['privacy']['state']);
                $this->assertSame('active_client_tracking_consent', $row['privacy']['basis']);
                $this->assertTrue($row['privacy']['locationAllowed']);
                $this->assertSame(-36.8485, $row['location']['latitude']);
                $this->assertSame(174.7633, $row['location']['longitude']);
                $this->assertSame("/operations/clients/{$client->id}?tab=location", $row['canonicalHref']);
                $this->assertSame('active', $activeTab['markers'][0]['status']);

                $payload = json_encode($props, JSON_THROW_ON_ERROR);
                foreach (['Private-Surname-Sentinel', 'TRACK-NHI-SENTINEL', 'RAW-PERSON-LOCATION-SENTINEL'] as $forbidden) {
                    $this->assertStringNotContainsString($forbidden, $payload);
                }
            });

        $this->actingAs($viewer)
            ->get("/security-devices/devices/{$device->id}")
            ->assertOk()
            ->assertInertia(function ($page): void {
                $detail = $page->toArray()['props']['device'];

                foreach (['external_ref', 'config', 'meta', 'latitude', 'longitude', 'location_description'] as $field) {
                    $this->assertArrayNotHasKey($field, $detail);
                }
                $this->assertStringNotContainsString(
                    'RAW-PERSON-LOCATION-SENTINEL',
                    json_encode($page->toArray()['props'], JSON_THROW_ON_ERROR),
                );
            });

        $consent->update(['status' => 'withdrawn', 'withdrawn_at' => now()]);

        $this->actingAs($viewer)
            ->get('/security-devices/tracking?tab=personal-safety')
            ->assertOk()
            ->assertInertia(function ($page): void {
                $row = $page->toArray()['props']['trackingWorkspace']['activeTab']['devices'][0];

                $this->assertSame('withdrawn', $row['privacy']['state']);
                $this->assertSame('none', $row['privacy']['basis']);
                $this->assertFalse($row['privacy']['locationAllowed']);
                $this->assertNull($row['location']);
                $this->assertNull($row['canonicalHref']);
                $this->assertNull($row['historyHref']);
            });

        $consent->update(['status' => 'given', 'withdrawn_at' => null]);
        $consent->consentType()->update([
            'name' => 'Photography and media consent',
            'purpose' => 'Authorise approved photographs only',
        ]);

        $this->actingAs($viewer)
            ->get('/security-devices/tracking?tab=personal-safety')
            ->assertOk()
            ->assertInertia(function ($page): void {
                $row = $page->toArray()['props']['trackingWorkspace']['activeTab']['devices'][0];

                $this->assertSame('missing', $row['privacy']['state']);
                $this->assertSame('none', $row['privacy']['basis']);
                $this->assertFalse($row['privacy']['locationAllowed']);
                $this->assertNull($row['location']);
                $this->assertNull($row['canonicalHref']);
            });
    }

    public function test_assignment_created_after_withdrawal_preserves_withdrawn_privacy_without_relabelling_other_inactive_consent(): void
    {
        $site = $this->site('Rimu House');
        $withdrawnClient = Client::factory()->create([
            'site_id' => $site->id,
            'preferred_name' => 'Hana',
        ]);
        $inactiveClient = Client::factory()->create([
            'site_id' => $site->id,
            'preferred_name' => 'Matiu',
        ]);
        $withdrawnConsent = $this->trackingConsent($withdrawnClient, [
            'status' => 'withdrawn',
            'withdrawn_at' => now()->subHour(),
        ]);
        $expiredConsent = $this->trackingConsent($inactiveClient, [
            'expires_at' => now()->subMinute(),
        ]);
        $withdrawnDevice = $this->trackingDevice('Hana safety pendant', [
            'category' => 'personal_tracker',
        ]);
        $inactiveDevice = $this->trackingDevice('Matiu safety pendant', [
            'category' => 'personal_tracker',
        ]);

        $this->assign(
            $withdrawnDevice,
            DeviceAssignment::TARGET_CLIENT,
            $withdrawnClient->id,
            $withdrawnConsent->id,
        );
        $this->assign(
            $inactiveDevice,
            DeviceAssignment::TARGET_CLIENT,
            $inactiveClient->id,
            $expiredConsent->id,
        );

        $withdrawnAssignment = DeviceAssignment::query()
            ->where('device_id', $withdrawnDevice->id)
            ->sole();
        $inactiveAssignment = DeviceAssignment::query()
            ->where('device_id', $inactiveDevice->id)
            ->sole();

        $this->assertSame('consent_withdrawn', $withdrawnAssignment->collection_stop_reason);
        $this->assertSame('consent_not_active', $inactiveAssignment->collection_stop_reason);

        $this->actingAs($this->admin)
            ->get('/security-devices/tracking?tab=personal-safety')
            ->assertOk()
            ->assertInertia(function ($page) use ($inactiveDevice, $withdrawnDevice): void {
                $rows = collect($page->toArray()['props']['trackingWorkspace']['activeTab']['devices']);
                $withdrawn = $rows->firstWhere('id', $withdrawnDevice->id);
                $inactive = $rows->firstWhere('id', $inactiveDevice->id);

                $this->assertSame('withdrawn', $withdrawn['privacy']['state']);
                $this->assertSame('Tracking consent was withdrawn.', $withdrawn['privacy']['reason']);
                $this->assertNull($withdrawn['canonicalHref']);
                $this->assertNull($withdrawn['historyHref']);
                $this->assertSame('inactive', $inactive['privacy']['state']);
                $this->assertSame('No active tracking consent is available.', $inactive['privacy']['reason']);
                $this->assertNull($inactive['canonicalHref']);
                $this->assertNull($inactive['historyHref']);
            });
    }

    public function test_staff_tracker_location_requires_an_authorised_live_lone_worker_purpose(): void
    {
        $site = $this->site('Totara House');
        $worker = User::factory()->create([

            'approved_at' => now(),
            'name' => 'Aroha Worker',
        ]);
        $device = $this->trackingDevice('Aroha lone-worker tracker', [
            'category' => 'personal_tracker',
            'subcategory' => 'lone_worker',
            'latitude' => -41.2866,
            'longitude' => 174.7756,
        ]);
        $this->assign($device, DeviceAssignment::TARGET_STAFF, $worker->id);
        $session = LoneWorkerSession::create([
            'user_id' => $worker->id,
            'site_id' => $site->id,
            'started_at' => now()->subHour(),
            'expected_end_at' => now()->addHour(),
            'activity_description' => 'Remote support visit',
            'check_in_interval_minutes' => 30,
            'status' => 'active',
            'created_by' => $this->admin->id,
            'updated_by' => $this->admin->id,
        ]);
        $viewer = $this->viewerWithPermissions([
            'securityDevices.viewAny',
            'securityDevices.devices.view',
            'securityDevices.devices.viewAllSites',
            'hazards.view',
            'healthSafety.viewAllSites',
            'staff.viewAny',
            'hr.employees.viewAny',
            'assets.telemetry.view',
        ]);
        $this->assignViewerToSite($worker, $site);
        $this->assignViewerToSite($viewer, $site);

        $technicalViewer = $this->viewerWithPermissions([
            'securityDevices.viewAny',
            'securityDevices.devices.view',
            'assets.telemetry.view',
        ]);
        $this->actingAs($technicalViewer)
            ->get('/security-devices/tracking?tab=personal-safety')
            ->assertOk()
            ->assertInertia(function ($page): void {
                $props = $page->toArray()['props'];

                $this->assertSame('restricted', $props['workspace']['activeTabState']);
                $this->assertSame(0, $props['trackingWorkspace']['activeTab']['inventoryTotal']);
                $this->assertSame([], $props['trackingWorkspace']['activeTab']['devices']);
            });

        $this->actingAs($viewer)
            ->get('/security-devices/tracking?tab=personal-safety')
            ->assertOk()
            ->assertInertia(function ($page) use ($session, $worker): void {
                $row = $page->toArray()['props']['trackingWorkspace']['activeTab']['devices'][0];
                $profileId = $worker->hrEmployeeProfile()->value('id');

                $this->assertSame($worker->id, $row['person']['id']);
                $this->assertSame("/hr/people/{$profileId}", $row['person']['href']);
                $this->assertSame('staff', $row['personalSafety']['personType']);
                $this->assertSame('active_lone_worker_session', $row['privacy']['basis']);
                $this->assertSame('active', $row['privacy']['state']);
                $this->assertTrue($row['privacy']['locationAllowed']);
                $this->assertSame("/health-safety/lone-workers?session={$session->id}", $row['canonicalHref']);
                $this->assertNotNull($row['location']);
            });

        $session->update(['status' => 'completed', 'ended_at' => now()]);

        $this->actingAs($viewer)
            ->get('/security-devices/tracking?tab=personal-safety')
            ->assertOk()
            ->assertInertia(function ($page): void {
                $row = $page->toArray()['props']['trackingWorkspace']['activeTab']['devices'][0];

                $this->assertSame('inactive', $row['privacy']['state']);
                $this->assertSame('none', $row['privacy']['basis']);
                $this->assertFalse($row['privacy']['locationAllowed']);
                $this->assertNull($row['location']);
            });
    }

    public function test_security_all_sites_does_not_expand_hr_staff_profile_links_beyond_hr_site_scope(): void
    {
        $localSite = $this->site('Local support office');
        $remoteSite = $this->site('Remote security site');
        $worker = User::factory()->create([
            'approved_at' => now(),
            'name' => 'Remote Security Worker',
        ]);
        $this->assignViewerToSite($worker, $remoteSite);

        $device = $this->trackingDevice('Remote worker tracker', [
            'category' => 'personal_tracker',
            'subcategory' => 'lone_worker',
        ]);
        $this->assign($device, DeviceAssignment::TARGET_STAFF, $worker->id);

        $viewer = $this->viewerWithPermissions([
            'securityDevices.viewAny',
            'securityDevices.devices.view',
            'securityDevices.devices.viewAllSites',
            'hazards.view',
            'staff.viewAny',
            'hr.employees.viewAny',
            'assets.telemetry.view',
        ]);
        $this->assignViewerToSite($viewer, $localSite);

        $this->actingAs($viewer)
            ->get('/security-devices/tracking?tab=personal-safety')
            ->assertOk()
            ->assertInertia(function ($page) use ($device, $worker): void {
                $row = collect($page->toArray()['props']['trackingWorkspace']['activeTab']['devices'])
                    ->firstWhere('id', $device->id);

                $this->assertNotNull($row);
                $this->assertSame($worker->id, $row['person']['id']);
                $this->assertSame('Remote Security Worker', $row['person']['displayName']);
                $this->assertNull($row['person']['href']);
            });

        $this->actingAs($viewer)
            ->get("/security-devices/devices/{$device->id}")
            ->assertOk()
            ->assertInertia(function ($page): void {
                $location = $page->toArray()['props']['profile']['header']['location'];

                $this->assertSame('staff', $location['type']);
                $this->assertSame('Remote Security Worker', $location['name']);
                $this->assertNull($location['href']);
            });
    }

    public function test_fleet_and_asset_tabs_project_canonical_records_and_destination_permissions(): void
    {
        $site = $this->site('Rimu Base');
        $vehicle = Asset::factory()->vehicle()->forSite($site)->create([
            'name' => 'Rimu community van',
            'registration_number' => 'ABC123',
        ]);
        $asset = Asset::factory()->forSite($site)->create([
            'name' => 'Emergency generator',
            'asset_tag' => 'GEN-42',
            'category' => 'Safety Equipment',
        ]);
        $vehicleDevice = $this->trackingDevice('Van telematics', ['category' => 'vehicle_tracker']);
        $assetDevice = $this->trackingDevice('Generator tag', ['category' => 'asset_tracker']);
        $this->assign($vehicleDevice, DeviceAssignment::TARGET_VEHICLE, $vehicle->id);
        $this->link($vehicleDevice, $vehicle, 'installed_in');
        $this->link($assetDevice, $asset);

        $viewer = $this->viewerWithRole('provider_manager');
        $this->assignViewerToSite($viewer, $site);
        $this->actingAs($viewer)
            ->get('/security-devices/tracking?tab=fleet')
            ->assertOk()
            ->assertInertia(function ($page) use ($vehicle, $vehicleDevice): void {
                $tracking = $page->toArray()['props']['trackingWorkspace'];
                $this->assertSame('available', $page->toArray()['props']['workspace']['activeTabState']);
                $this->assertSame(1, $tracking['activeTab']['inventoryTotal']);
                $row = $tracking['activeTab']['devices'][0];
                $this->assertSame($vehicleDevice->id, $row['id']);
                $this->assertSame($vehicle->id, $row['asset']['id']);
                $this->assertSame('ABC123', $row['asset']['reference']);
                $this->assertSame("/fleet-assets/vehicles/{$vehicle->id}", $row['canonicalHref']);
            });

        $this->actingAs($viewer)
            ->get('/security-devices/tracking?tab=assets')
            ->assertOk()
            ->assertInertia(function ($page) use ($asset, $assetDevice): void {
                $row = $page->toArray()['props']['trackingWorkspace']['activeTab']['devices'][0];
                $this->assertSame($assetDevice->id, $row['id']);
                $this->assertSame($asset->id, $row['asset']['id']);
                $this->assertSame('GEN-42', $row['asset']['reference']);
                $this->assertSame("/fleet-assets/assets/{$asset->id}", $row['canonicalHref']);
            });

        $itViewer = $this->viewerWithRole('it_manager');
        $this->actingAs($itViewer)
            ->get('/security-devices/tracking?tab=fleet')
            ->assertOk()
            ->assertInertia(function ($page): void {
                $props = $page->toArray()['props'];
                $this->assertSame('restricted', $props['workspace']['activeTabState']);
                $this->assertSame(0, $props['trackingWorkspace']['activeTab']['inventoryTotal']);
                $this->assertSame([], $props['trackingWorkspace']['activeTab']['devices']);
                $this->assertSame(0, $props['trackingWorkspace']['overview']['inventory']['total']);
            });
    }

    public function test_geofences_and_history_apply_source_permissions_consent_retention_and_redaction(): void
    {
        $site = $this->site('Pohutukawa Base');
        $vehicle = Asset::factory()->vehicle()->forSite($site)->create(['name' => 'Pohutukawa van']);
        $client = Client::factory()->create([

            'site_id' => $site->id,
            'preferred_name' => 'Ria',
        ]);
        $clientAsset = Asset::factory()->forSite($site)->forClient($client->id)->create([
            'name' => 'Ria safety pendant asset',
            'category' => 'personal_tracker',
        ]);
        $consent = $this->trackingConsent($client);
        $vehicleDevice = $this->trackingDevice('Van tracker', ['category' => 'vehicle_tracker']);
        $clientDevice = $this->trackingDevice('Ria tracker', ['category' => 'personal_tracker']);
        $this->link($vehicleDevice, $vehicle, 'installed_in');
        $this->link($clientDevice, $clientAsset);
        $this->assign($clientDevice, DeviceAssignment::TARGET_CLIENT, $client->id, $consent->id);

        $vehicleGeofence = AssetGeofence::create([
            'asset_id' => $vehicle->id,
            'site_id' => $site->id,
            'name' => 'Fleet depot',
            'type' => 'circle',
            'scope' => 'vehicle',
            'shape' => ['lat' => -36.85, 'lng' => 174.76, 'radius_m' => 200],
            'breach_type' => 'both',
            'is_active' => true,
        ]);
        $residentGeofence = AssetGeofence::create([
            'asset_id' => $clientAsset->id,
            'site_id' => $site->id,
            'name' => 'Ria safety zone',
            'type' => 'circle',
            'scope' => 'resident',
            'shape' => ['lat' => -36.86, 'lng' => 174.77, 'radius_m' => 100],
            'breach_type' => 'both',
            'is_active' => true,
        ]);

        $vehicleTracker = $this->legacyTracker($vehicle, 'VEH-42');
        $clientTracker = $this->legacyTracker($clientAsset, 'CLI-42', $consent->id);
        $this->event($vehicle, $vehicleTracker, $vehicleDevice, 'vehicle-current', now()->subMinute());
        $this->event($clientAsset, $clientTracker, $clientDevice, 'client-current', now()->subMinutes(2));
        $this->event($vehicle, $vehicleTracker, $vehicleDevice, 'outside-retention', now()->subDays(400));
        $this->event($vehicle, $vehicleTracker, $vehicleDevice, 'blocked-current', now()->subMinutes(3), true);

        $this->actingAs($this->admin)
            ->get('/security-devices/tracking?tab=geofences')
            ->assertOk()
            ->assertInertia(function ($page) use ($residentGeofence, $vehicleGeofence): void {
                $rows = collect($page->toArray()['props']['trackingWorkspace']['activeTab']['geofences']);
                $this->assertEqualsCanonicalizing(
                    [$vehicleGeofence->id, $residentGeofence->id],
                    $rows->pluck('id')->all(),
                );
                $this->assertSame('/fleet-assets/geofences', $rows->firstWhere('id', $vehicleGeofence->id)['canonicalHref']);
                $this->assertSame('active_client_tracking_consent', $rows->firstWhere('id', $residentGeofence->id)['privacy']['basis']);
            });

        $this->actingAs($this->admin)
            ->get('/security-devices/tracking?tab=history')
            ->assertOk()
            ->assertInertia(function ($page): void {
                $tracking = $page->toArray()['props']['trackingWorkspace'];
                $events = collect($tracking['activeTab']['history']);
                $this->assertEqualsCanonicalizing(['vehicle-current', 'client-current'], $events->pluck('eventType')->all());
                $this->assertSame(
                    ['historical'],
                    collect($tracking['activeTab']['markers'])->pluck('status')->unique()->values()->all(),
                );
                $this->assertSame((int) config('fleet.retention.telemetry_days', 365), $tracking['activeTab']['retentionDays']);
                $payload = json_encode($tracking, JSON_THROW_ON_ERROR);
                $this->assertStringNotContainsString('RAW-HISTORY-PAYLOAD-SENTINEL', $payload);
            });

        $consent->update(['status' => 'withdrawn', 'withdrawn_at' => now()]);

        $this->actingAs($this->admin)
            ->get('/security-devices/tracking?tab=geofences')
            ->assertOk()
            ->assertInertia(function ($page) use ($residentGeofence, $vehicleGeofence): void {
                $ids = collect($page->toArray()['props']['trackingWorkspace']['activeTab']['geofences'])->pluck('id')->all();
                $this->assertContains($vehicleGeofence->id, $ids);
                $this->assertNotContains($residentGeofence->id, $ids);
            });
        $this->actingAs($this->admin)
            ->get('/security-devices/tracking?tab=history')
            ->assertOk()
            ->assertInertia(function ($page): void {
                $types = collect($page->toArray()['props']['trackingWorkspace']['activeTab']['history'])->pluck('eventType')->all();
                $this->assertSame(['vehicle-current'], $types);
            });
    }

    private function viewerWithRole(string $role): User
    {
        $viewer = User::factory()->create(['approved_at' => now()]);
        $viewer->roles()->attach(Role::query()->where('name', $role)->firstOrFail());

        return $viewer;
    }

    /** @param array<int, string> $permissions */
    private function viewerWithPermissions(array $permissions): User
    {
        $viewer = User::factory()->create(['approved_at' => now()]);
        $role = Role::create([
            'name' => 'tracking_workspace_'.str()->uuid(),
            'label' => 'Tracking workspace test',
            'level' => 50,
            'type' => 'custom',
        ]);
        foreach ($permissions as $key) {
            Permission::firstOrCreate(
                ['key' => $key],
                ['description' => $key, 'group' => 'test', 'module' => 'Test'],
            );
        }
        $role->permissions()->sync(Permission::whereIn('key', $permissions)->pluck('id'));
        $viewer->roles()->attach($role->id);

        return $viewer;
    }

    private function site(string $name): Site
    {
        return Site::factory()->create(['name' => $name, 'is_active' => true]);
    }

    private function assignViewerToSite(User $viewer, Site $site): void
    {
        HrEmployeeProfile::factory()->create([
            'user_id' => $viewer->id,
            'primary_site_id' => $site->id,
            'secondary_site_ids' => [],
            'is_active' => true,
        ]);
    }

    private function trackingDevice(string $name, array $attributes = []): Device
    {
        return Device::factory()->tracking()->create([
            'name' => $name,
            ...$attributes,
        ]);
    }

    private function trackingConsent(Client $client, array $attributes = []): ClientConsent
    {
        $type = ConsentType::factory()->create([
            'name' => 'Asset Location Tracking (Safety)',
            'category' => 'privacy',
            'purpose' => 'Personal safety location tracking',
            'legal_basis' => 'consent',
            'active' => true,
        ]);

        return AuthoritativeConsentFixture::manualSelf($client, $type, $this->admin, [
            'status' => 'given',
            'given_at' => now()->subHour(),
            'expires_at' => now()->addMonth(),
            ...$attributes,
        ]);
    }

    private function assign(Device $device, string $type, int $id, ?int $consentId = null): void
    {
        DeviceAssignment::create([
            'device_id' => $device->id,
            'assignable_type' => $type,
            'assignable_id' => $id,
            'assignment_type' => 'permanent',
            'assigned_at' => now(),
            'consent_id' => $consentId,
        ]);
    }

    private function link(Device $device, Asset $asset, string $linkType = 'primary'): void
    {
        DeviceAssetLink::create([
            'device_id' => $device->id,
            'asset_id' => $asset->id,
            'link_type' => $linkType,
            'linked_at' => now(),
        ]);
    }

    private function legacyTracker(Asset $asset, string $uid, ?int $consentId = null): AssetTracker
    {
        return AssetTracker::create([
            'asset_id' => $asset->id,
            'vendor' => 'queclink',
            'device_uid' => $uid,
            'status' => 'paired',
            'paired_at' => now(),
            'consent_id' => $consentId,
        ]);
    }

    private function event(
        Asset $asset,
        AssetTracker $tracker,
        Device $device,
        string $eventType,
        $occurredAt,
        bool $blocked = false,
    ): void {
        FleetTelemetryEvent::create([
            'asset_id' => $asset->id,
            'asset_tracker_id' => $tracker->id,
            'device_id' => $device->id,
            'vendor' => 'queclink',
            'occurred_at' => $occurredAt,
            'received_at' => $occurredAt,
            'latitude' => $blocked ? null : -36.8485,
            'longitude' => $blocked ? null : 174.7633,
            'battery_pct' => 80,
            'event_type' => $eventType,
            'idempotency_key' => hash('sha256', $eventType),
            'raw_payload' => ['private' => 'RAW-HISTORY-PAYLOAD-SENTINEL'],
            'consent_blocked' => $blocked,
        ]);
    }
}
