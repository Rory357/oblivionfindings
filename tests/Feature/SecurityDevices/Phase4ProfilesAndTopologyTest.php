<?php

namespace Tests\Feature\SecurityDevices;

use App\Domain\Monitoring\Contracts\CredentialLeaseProvider;
use App\Domain\Monitoring\Data\CredentialLease;
use App\Domain\Monitoring\Discovery\Models\DiscoveryCandidate;
use App\Domain\Monitoring\Discovery\Models\DiscoveryRun;
use App\Domain\Monitoring\Discovery\Models\DiscoveryScope;
use App\Domain\Monitoring\Discovery\Services\DiscoveryRunner;
use App\Domain\Monitoring\Models\MonitoringCollector;
use App\Domain\Monitoring\Services\CollectorConfigurationService;
use App\Domain\Monitoring\Topology\Models\TopologySnapshot;
use App\Domain\SecurityDevices\Credentials\Enums\CredentialReferenceStatus;
use App\Domain\SecurityDevices\Credentials\Enums\CredentialRotationStatus;
use App\Domain\SecurityDevices\Credentials\Enums\CredentialTestStatus;
use App\Domain\SecurityDevices\Credentials\Models\CredentialReference;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Domain\SecurityDevices\Models\DeviceEvent;
use App\Domain\SecurityDevices\Models\DeviceRelationship;
use App\Domain\SecurityDevices\Presenters\DeviceProfilePresenter;
use App\Domain\SecurityDevices\Presenters\EstateOperationsPresenter;
use App\Domain\SecurityDevices\Services\SiteTopologyMapService;
use App\Models\Asset;
use App\Models\AssetTracker;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\ConsentType;
use App\Models\FleetTelemetryEvent;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SecurityDevicesPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpClientRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Support\AuthoritativeConsentFixture;
use Tests\TestCase;

class Phase4ProfilesAndTopologyTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $worker;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
        $this->seed(SecurityDevicesPermissionsSeeder::class);

        $this->admin = User::factory()->create([
            'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'),
            'two_factor_confirmed_at' => now(),
        ]);
        $this->admin->roles()->attach(Role::query()->where('name', 'admin')->firstOrFail());

        $this->worker = User::factory()->create();
        $this->worker->roles()->attach(Role::query()->where('name', 'support_worker')->firstOrFail());
    }

    public function test_site_topology_map_reconciles_gateway_switch_ap_device_tree_and_preserves_relationship_immutability(): void
    {
        $site = Site::factory()->create(['name' => 'Harbour Care', 'is_active' => true, 'archived' => false]);

        $gateway = $this->createAssignedSiteDevice($site, [
            'name' => 'Harbour UX7 Gateway',
            'domain' => 'it_infrastructure',
            'category' => 'network',
            'subcategory' => 'gateway',
            'ip_address' => '172.16.0.1',
            'mac_address' => '70:a7:41:00:00:01',
        ]);
        $switch = $this->createAssignedSiteDevice($site, [
            'name' => 'Harbour Core Switch',
            'domain' => 'it_infrastructure',
            'category' => 'network',
            'subcategory' => 'switch',
            'ip_address' => '172.16.0.2',
            'mac_address' => '24:5a:4c:00:00:02',
        ]);
        $ap = $this->createAssignedSiteDevice($site, [
            'name' => 'Lounge Access Point',
            'domain' => 'it_infrastructure',
            'category' => 'network',
            'subcategory' => 'access_point',
            'ip_address' => '172.16.0.3',
            'mac_address' => '24:5a:4c:00:00:03',
        ]);
        $camera = $this->createAssignedSiteDevice($site, [
            'name' => 'Front Entrance Camera',
            'domain' => 'security',
            'category' => 'cctv',
            'subcategory' => 'ip_camera',
            'ip_address' => '172.16.0.61',
            'mac_address' => '44:19:b6:00:00:61',
        ]);

        $scope = DiscoveryScope::factory()->create([
            'site_id' => $site->id,
            'name' => 'Harbour LAN',
            'cidrs' => ['172.16.0.0/24'],
            'exclusions' => [],
            'status' => 'active',
        ]);
        $run = DiscoveryRun::factory()->create([
            'discovery_scope_id' => $scope->id,
            'status' => 'completed',
        ]);

        // Switch reports LLDP to Gateway on Gi1/0/1 and Bridge FDB to AP on Gi1/0/4
        $switchCandidate = DiscoveryCandidate::factory()->create([
            'discovery_run_id' => $run->id,
            'canonical_device_id' => $switch->id,
            'decision' => 'matched',
            'confidence' => 95,
            'evidence_snapshot' => [
                'addresses' => ['172.16.0.2'],
                'mac_addresses' => ['24:5A:4C:00:00:02'],
                'signals' => [
                    'lldp_neighbors' => [[
                        'local_port' => 'Gi1/0/1',
                        'remote_mac' => '70:A7:41:00:00:01',
                        'remote_port' => 'lan0',
                        'remote_name' => 'Harbour UX7 Gateway',
                    ]],
                    'bridge_fdb' => [[
                        'port' => 'Gi1/0/4',
                        'mac' => '24:5A:4C:00:00:03',
                    ]],
                ],
            ],
        ]);

        // Camera reports UniFi wireless association to Lounge Access Point
        DiscoveryCandidate::factory()->create([
            'discovery_run_id' => $run->id,
            'canonical_device_id' => $camera->id,
            'decision' => 'matched',
            'confidence' => 96,
            'evidence_snapshot' => [
                'addresses' => ['172.16.0.61'],
                'mac_addresses' => ['44:19:B6:00:00:61'],
                'signals' => [
                    'unifi_uplink' => [
                        'uplink_mac' => '24:5A:4C:00:00:03',
                        'uplink_ip' => '172.16.0.3',
                        'uplink_port' => 'Wi-Fi (Harbour-IoT)',
                        'kind' => 'wireless',
                        'ssid' => 'Harbour-IoT',
                    ],
                ],
            ],
        ]);

        $service = app(SiteTopologyMapService::class);
        $snapshot = $service->reconcileSite($site->id, $this->admin->id);

        $this->assertInstanceOf(TopologySnapshot::class, $snapshot);
        $this->assertSame('completed', $snapshot->status);

        // Verify immutable relationship behaviour when AP moves from Gi1/0/4 -> Gi1/0/8
        $initialApRel = DeviceRelationship::query()
            ->active()
            ->where('parent_device_id', $switch->id)
            ->where('child_device_id', $ap->id)
            ->firstOrFail();
        $this->assertSame('Gi1/0/4 (Bridge FDB)', $initialApRel->port);

        $switchCandidate->update([
            'evidence_snapshot' => [
                'addresses' => ['172.16.0.2'],
                'mac_addresses' => ['24:5A:4C:00:00:02'],
                'signals' => [
                    'lldp_neighbors' => [[
                        'local_port' => 'Gi1/0/1',
                        'remote_mac' => '70:A7:41:00:00:01',
                        'remote_port' => 'lan0',
                        'remote_name' => 'Harbour UX7 Gateway',
                    ]],
                    'bridge_fdb' => [[
                        'port' => 'Gi1/0/8',
                        'mac' => '24:5A:4C:00:00:03',
                    ]],
                ],
            ],
        ]);

        $service->reconcileSite($site->id, $this->admin->id);

        $archivedRel = DeviceRelationship::query()->findOrFail($initialApRel->id);
        $this->assertNotNull($archivedRel->unlinked_at);
        $this->assertNotEmpty($archivedRel->unlink_reason);

        $currentApRel = DeviceRelationship::query()
            ->active()
            ->where('parent_device_id', $switch->id)
            ->where('child_device_id', $ap->id)
            ->firstOrFail();
        $this->assertSame('Gi1/0/8 (Bridge FDB)', $currentApRel->port);

        // Verify EstateOperationsPresenter::site() exposes full 4-tier hierarchy
        $sitePayload = app(EstateOperationsPresenter::class)->site($this->admin, $site);
        $tree = $sitePayload['topology']['tree'];

        $this->assertSame([
            'gateways' => 1,
            'switches' => 1,
            'access_points' => 1,
            'devices' => 1,
        ], $tree['tier_counts']);
        $this->assertCount(1, $tree['roots']);
        $this->assertSame($gateway->id, $tree['roots'][0]['id']);
        $this->assertSame('gateway', $tree['roots'][0]['tier']);
        $this->assertSame($switch->id, $tree['roots'][0]['children'][0]['id']);
        $this->assertSame($ap->id, $tree['roots'][0]['children'][0]['children'][0]['id']);
        $this->assertSame($camera->id, $tree['roots'][0]['children'][0]['children'][0]['children'][0]['id']);

        // When AP's bridge FDB uplink disappears on a subsequent scan, its stale auto relationship is unlinked
        $switchCandidate->update([
            'evidence_snapshot' => [
                'addresses' => ['172.16.0.2'],
                'mac_addresses' => ['24:5A:4C:00:00:02'],
                'signals' => [
                    'lldp_neighbors' => [[
                        'local_port' => 'Gi1/0/1',
                        'remote_mac' => '70:A7:41:00:00:01',
                        'remote_port' => 'lan0',
                        'remote_name' => 'Harbour UX7 Gateway',
                    ]],
                ],
            ],
        ]);

        $service->reconcileSite($site->id, $this->admin->id);

        $this->assertNotNull($currentApRel->fresh()->unlinked_at);
        $this->assertFalse(
            DeviceRelationship::query()->active()->where('child_device_id', $ap->id)->exists(),
        );
        $this->assertTrue(
            DeviceRelationship::query()
                ->active()
                ->where('parent_device_id', $gateway->id)
                ->where('child_device_id', $switch->id)
                ->exists(),
        );
    }

    public function test_device_profile_presenter_classifies_all_eight_layouts_and_surfaces_checks_and_history(): void
    {
        $site = Site::factory()->create(['name' => 'Kowhai House']);

        $specs = [
            'network' => ['domain' => 'it_infrastructure', 'category' => 'network', 'subcategory' => 'switch'],
            'camera' => ['domain' => 'security', 'category' => 'cctv', 'subcategory' => 'ip_camera'],
            'alarm_panel' => ['domain' => 'security', 'category' => 'alarm', 'subcategory' => 'alarm_panel'],
            'door' => ['domain' => 'security', 'category' => 'access_control', 'subcategory' => 'door_controller'],
            'sensor' => ['domain' => 'iot_healthcare', 'category' => 'environmental', 'subcategory' => 'temperature'],
            'tracker' => ['domain' => 'tracking', 'category' => 'personal_tracker', 'subcategory' => 'wearable_gps'],
            'computer' => ['domain' => 'it_infrastructure', 'category' => 'endpoint', 'subcategory' => 'workstation'],
            'other' => ['domain' => 'it_infrastructure', 'category' => 'printer', 'subcategory' => 'network_printer'],
        ];

        $presenter = app(DeviceProfilePresenter::class);

        foreach ($specs as $expectedLayout => $attrs) {
            $device = $this->createAssignedSiteDevice($site, array_merge(['name' => "Test {$expectedLayout}"], $attrs));
            $device->load(['monitors.profile', 'monitors.collector', 'assignments', 'events', 'maintenanceRecords', 'documents']);
            $assignment = $device->assignments->first();

            $presented = $presenter->present($this->admin, $device, $assignment);

            $this->assertSame($expectedLayout, $presented['profileLayout']);
            $this->assertSame($expectedLayout, $presented['typeProfile']['kind']);
            $this->assertIsArray($presented['historyTimeline']);

            $sectionsByKey = collect($presented['sections'])->keyBy('key');
            $this->assertSame('Checks', $sectionsByKey->get('monitors')['label'] ?? null);
            $this->assertSame('History', $sectionsByKey->get('history')['label'] ?? null);
        }
    }

    public function test_tracker_profile_layout_enforces_client_location_consent_before_rendering_coordinates(): void
    {
        $site = Site::factory()->create(['name' => 'Totara Lodge', 'is_active' => true, 'archived' => false]);
        $client = Client::factory()->create([
            'site_id' => $site->id,
            'status' => 'active',
            'first_name' => 'Aroha',
            'last_name' => 'Ngata',
        ]);

        $asset = Asset::query()->create([
            'site_id' => $site->id,
            'client_id' => $client->id,
            'name' => 'Aroha Safety Pendant',
            'status' => 'active',
            'risk_level' => 'medium',
            'category' => 'personal_tracker',
        ]);
        $assetTracker = AssetTracker::query()->create([
            'asset_id' => $asset->id,
            'vendor' => 'queclink',
            'device_uid' => '867963069999001',
            'imei' => '867963069999001',
            'status' => 'paired',
            'paired_at' => now(),
        ]);

        $tracker = Device::factory()->tracking()->create([
            'name' => 'Care Tracker 01',
            'category' => 'personal_tracker',
            'subcategory' => 'wearable_gps',
            'provider' => 'queclink',
            'device_uid' => '867963069999001',
            'imei' => '867963069999001',
            'legacy_asset_tracker_id' => $assetTracker->id,
        ]);

        $unconsentedAssignment = DeviceAssignment::query()->create([
            'device_id' => $tracker->id,
            'assignable_type' => DeviceAssignment::TARGET_CLIENT,
            'assignable_id' => $client->id,
            'assignment_type' => 'permanent',
            'assigned_at' => now()->subDay(),
        ]);

        FleetTelemetryEvent::query()->create([
            'asset_id' => $asset->id,
            'asset_tracker_id' => $assetTracker->id,
            'device_id' => $tracker->id,
            'vendor' => 'queclink',
            'occurred_at' => now()->subMinute(),
            'received_at' => now()->subMinute(),
            'latitude' => -36.848461,
            'longitude' => 174.763336,
            'speed_kph' => 0.0,
            'battery_pct' => 92,
            'event_type' => 'location_report',
            'idempotency_key' => hash('sha256', Str::uuid()->toString()),
            'raw_payload' => [],
            'consent_blocked' => false,
        ]);

        $tracker->load(['monitors.profile', 'monitors.collector', 'assignments', 'events', 'maintenanceRecords', 'documents']);
        $presenter = app(DeviceProfilePresenter::class);

        // 1. Without active location consent: coordinates MUST be null
        $withoutConsent = $presenter->present($this->admin, $tracker, $unconsentedAssignment);
        $this->assertSame('consent_required', $withoutConsent['typeProfile']['position']['state']);
        $this->assertNull($withoutConsent['typeProfile']['position']['latitude']);
        $this->assertNull($withoutConsent['typeProfile']['position']['longitude']);
        $this->assertStringNotContainsString('-36.848461', json_encode($withoutConsent['typeProfile']));

        // 2. Grant active location-tracking consent for the resident and link assignment
        $unconsentedAssignment->update([
            'released_at' => now()->subSeconds(30),
        ]);

        $consentType = ConsentType::factory()->create([
            'name' => 'Personal Tracker (Wandering Risk)',
            'category' => 'privacy',
            'purpose' => 'Personal safety location tracking',
            'legal_basis' => 'consent',
            'active' => true,
        ]);
        $consent = AuthoritativeConsentFixture::manualSelf($client, $consentType, $this->admin, [
            'status' => 'given',
            'given_at' => now()->subHour(),
            'expires_at' => now()->addMonth(),
        ]);

        $consentedAssignment = DeviceAssignment::query()->create([
            'device_id' => $tracker->id,
            'assignable_type' => DeviceAssignment::TARGET_CLIENT,
            'assignable_id' => $client->id,
            'assignment_type' => 'permanent',
            'assigned_at' => now()->subSeconds(10),
            'consent_id' => $consent->id,
        ]);

        $tracker->refresh()->load(['monitors.profile', 'monitors.collector', 'assignments', 'events', 'maintenanceRecords', 'documents']);
        $withConsent = $presenter->present($this->admin, $tracker, $consentedAssignment);
        $this->assertSame('available', $withConsent['typeProfile']['position']['state']);
        $this->assertEqualsWithDelta(-36.848461, $withConsent['typeProfile']['position']['latitude'], 0.00001);
        $this->assertEqualsWithDelta(174.763336, $withConsent['typeProfile']['position']['longitude'], 0.00001);
    }

    public function test_alarm_disarm_and_door_timed_unlock_enforce_permission_confirmation_and_audit(): void
    {
        $site = Site::factory()->create(['name' => 'Nikau House']);
        $alarm = $this->createAssignedSiteDevice($site, [
            'name' => 'Nikau Main Alarm Panel',
            'domain' => 'security',
            'category' => 'alarm',
            'subcategory' => 'alarm_panel',
            'provider' => 'unconfigured-alarm-provider',
            'status' => 'active',
            'last_seen_at' => now(),
            'config' => [
                'management' => [
                    'capabilities' => ['alarm.arm', 'alarm.disarm'],
                ],
            ],
        ]);
        DeviceEvent::query()->create([
            'device_id' => $alarm->id,
            'event_type' => 'alarm_armed',
            'severity' => 'info',
            'source' => 'panel',
            'occurred_at' => now()->subMinute(),
            'payload' => ['zone' => '1', 'zone_name' => 'Front Foyer', 'zone_state' => 'normal'],
        ]);

        // Unpermitted support worker cannot request alarm disarm (403)
        $this->actingAs($this->worker)
            ->post("/security-devices/devices/{$alarm->id}/commands", [
                'capability' => 'alarm.disarm',
                'parameters' => ['area' => 'main'],
                'reason' => 'Worker attempting unauthorised disarm.',
                'idempotency_key' => 'cmd_worker_disarm_1',
                'impact_acknowledged' => true,
                'confirmation_text' => 'Nikau Main Alarm Panel',
            ])
            ->assertForbidden();

        // Admin submitting alarm.disarm WITH wrong confirmation_text is rejected by validation
        $this->actingAs($this->admin)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->from("/security-devices/devices/{$alarm->id}")
            ->post("/security-devices/devices/{$alarm->id}/commands", [
                'expected_actor_id' => $this->admin->id,
                'capability' => 'alarm.disarm',
                'parameters' => ['area' => 'main'],
                'reason' => 'Scheduled technician entry to foyer.',
                'idempotency_key' => 'cmd_admin_wrong_name_1',
                'impact_acknowledged' => true,
                'confirmation_text' => 'Wrong Name',
            ])
            ->assertSessionHasErrors('confirmation_text');
    }

    public function test_camera_onvif_snapshot_and_ptz_enforce_permissions_ssrf_and_audit_logging(): void
    {
        $site = Site::factory()->create(['name' => 'Rimu Court', 'is_active' => true, 'archived' => false]);
        DiscoveryScope::factory()->create([
            'site_id' => $site->id,
            'name' => 'Rimu Camera Subnet',
            'cidrs' => ['172.16.0.0/24'],
            'exclusions' => [],
            'status' => 'active',
        ]);

        $camera = $this->createAssignedSiteDevice($site, [
            'name' => 'Rimu Courtyard PTZ',
            'domain' => 'security',
            'category' => 'cctv',
            'subcategory' => 'ptz_camera',
            'ip_address' => '172.16.0.61',
            'status' => 'active',
        ]);

        // 1. Worker without CCTV media permission is forbidden from snapshot & PTZ
        $workerNoMedia = User::factory()->create();
        $workerNoMedia->roles()->attach(Role::query()->where('name', 'support_worker')->firstOrFail());
        $mediaPermId = Permission::query()->where('key', 'securityDevices.cctv.media.view')->value('id');
        if ($mediaPermId) {
            $workerNoMedia->permissionOverrides()->syncWithoutDetaching([$mediaPermId => ['allowed' => false]]);
        }

        $this->actingAs($workerNoMedia)
            ->postJson("/security-devices/devices/{$camera->id}/camera/snapshot")
            ->assertForbidden();

        // 2. Test SSRF host-mismatch rejection when ONVIF GetSnapshotUri points to 169.254.169.254
        $minimalJpeg = "\xFF\xD8\xFF\xE0\x00\x10JFIF\x00\x01\x01\x00\x00\x01\x00\x01\x00\x00\xFF\xD9";
        $mode = 'ssrf';

        Http::fake(function (HttpClientRequest $request) use (&$mode, $minimalJpeg) {
            $url = $request->url();
            $body = $request->body();

            if ($request->method() === 'POST' && str_contains($url, '/onvif/media_service') && str_contains($body, 'GetProfiles')) {
                return Http::response(
                    '<?xml version="1.0"?><s:Envelope xmlns:s="http://www.w3.org/2003/05/soap-envelope" xmlns:trt="http://www.onvif.org/ver10/media/wsdl"><s:Body><trt:GetProfilesResponse><trt:Profiles token="MainStream"/></trt:GetProfilesResponse></s:Body></s:Envelope>',
                    200,
                    ['Content-Type' => 'application/soap+xml'],
                );
            }

            if ($request->method() === 'POST' && str_contains($url, '/onvif/media_service') && str_contains($body, 'GetSnapshotUri')) {
                $targetUri = $mode === 'ssrf'
                    ? 'http://169.254.169.254/latest/meta-data'
                    : 'http://172.16.0.61/onvif-http/snapshot?Profile_1';

                return Http::response(
                    '<?xml version="1.0"?><s:Envelope xmlns:s="http://www.w3.org/2003/05/soap-envelope" xmlns:trt="http://www.onvif.org/ver10/media/wsdl" xmlns:tt="http://www.onvif.org/ver10/schema"><s:Body><trt:GetSnapshotUriResponse><trt:MediaUri><tt:Uri>'.$targetUri.'</tt:Uri></trt:MediaUri></trt:GetSnapshotUriResponse></s:Body></s:Envelope>',
                    200,
                    ['Content-Type' => 'application/soap+xml'],
                );
            }

            if ($request->method() === 'GET' && str_contains($url, '172.16.0.61/onvif-http/snapshot')) {
                return Http::response($minimalJpeg, 200, ['Content-Type' => 'image/jpeg']);
            }

            if ($request->method() === 'POST' && str_contains($url, '/onvif/ptz_service')) {
                return Http::response(
                    '<?xml version="1.0"?><s:Envelope xmlns:s="http://www.w3.org/2003/05/soap-envelope"><s:Body><ContinuousMoveResponse/></s:Body></s:Envelope>',
                    200,
                    ['Content-Type' => 'application/soap+xml'],
                );
            }

            return Http::response('', 404);
        });

        $ssrfResp = $this->actingAs($this->admin)
            ->postJson("/security-devices/devices/{$camera->id}/camera/snapshot");
        $ssrfResp->assertOk()
            ->assertJsonPath('ok', false)
            ->assertJsonPath('reason_code', 'invalid_snapshot_uri');

        Http::assertNotSent(fn (HttpClientRequest $req): bool => str_contains($req->url(), '169.254.169.254'));

        // 3. Valid ONVIF JPEG snapshot + PTZ command succeed and write AuditLogs
        $mode = 'valid';

        $okSnap = $this->actingAs($this->admin)
            ->postJson("/security-devices/devices/{$camera->id}/camera/snapshot");
        $okSnap->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('reason_code', 'captured');
        $this->assertStringStartsWith('data:image/jpeg;base64,', (string) $okSnap->json('data_uri'));

        $okPtz = $this->actingAs($this->admin)
            ->postJson("/security-devices/devices/{$camera->id}/camera/ptz", ['action' => 'pan_left']);
        $okPtz->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('action', 'pan_left');

        $this->assertTrue(AuditLog::query()->where('action', 'camera.onvif.snapshot')->exists());
        $this->assertTrue(AuditLog::query()->where('action', 'camera.onvif.ptz')->exists());

        // 4. Out-of-scope private IP camera (outside Site's approved 172.16.0.0/24 DiscoveryScope) fails closed with zero HTTP calls
        $outOfScopeCamera = $this->createAssignedSiteDevice($site, [
            'name' => 'Unapproved Subnet Camera',
            'domain' => 'security',
            'category' => 'cctv',
            'subcategory' => 'ip_camera',
            'ip_address' => '10.55.0.10',
            'status' => 'active',
        ]);

        $this->actingAs($this->admin)
            ->postJson("/security-devices/devices/{$outOfScopeCamera->id}/camera/snapshot")
            ->assertOk()
            ->assertJsonPath('ok', false)
            ->assertJsonPath('reason_code', 'out_of_site_scope');

        Http::assertNotSent(fn (HttpClientRequest $req): bool => str_contains($req->url(), '10.55.0.10'));
    }

    public function test_collector_configuration_seals_site_unifi_discovery_lease_into_signed_envelope(): void
    {
        $configPair = sodium_crypto_sign_seed_keypair(str_repeat("\x41", SODIUM_CRYPTO_SIGN_SEEDBYTES));
        config()->set(
            'monitoring.collector.signing_secret_key',
            base64_encode(sodium_crypto_sign_secretkey($configPair)),
        );

        app()->instance(CredentialLeaseProvider::class, new class implements CredentialLeaseProvider
        {
            public function acquire(int $siteId, string $reference, array $capabilities): CredentialLease
            {
                return new CredentialLease(
                    'unifi-discovery-fixture-lease',
                    CarbonImmutable::now('UTC')->addMinute(),
                    ['api_key' => 'UNIFI-LOCAL-TOPOLOGY-KEY', 'controller_ip' => '172.16.0.1'],
                );
            }
        });

        $site = Site::factory()->create(['name' => 'Pohutukawa House', 'is_active' => true, 'archived' => false]);
        $collectorPair = sodium_crypto_sign_seed_keypair(str_repeat("\x42", SODIUM_CRYPTO_SIGN_SEEDBYTES));
        $collectorPublic = sodium_crypto_sign_publickey($collectorPair);
        $collector = MonitoringCollector::factory()->create([
            'site_id' => $site->id,
            'collector_uuid' => '4f8d2c79-67c3-4e0e-8873-77a9614b9e11',
            'public_key' => base64_encode($collectorPublic),
            'public_key_fingerprint' => hash('sha256', $collectorPublic),
            'client_certificate_fingerprint' => hash('sha256', 'phase4-unifi-collector-cert'),
            'status' => 'online',
            'last_seen_at' => now(),
        ]);

        CredentialReference::query()->create([
            'reference_key' => 'unifi:pohutukawa-controller',
            'site_id' => $site->id,
            'provider' => 'unifi',
            'purpose' => 'monitoring',
            'capabilities' => ['monitoring.collector.unifi.read'],
            'secret_manager_reference' => 'monitoring/pohutukawa/unifi',
            'secret_manager_reference_hash' => hash('sha256', 'monitoring/pohutukawa/unifi'),
            'status' => CredentialReferenceStatus::Active,
            'rotation_status' => CredentialRotationStatus::Current,
            'test_status' => CredentialTestStatus::Passed,
            'version' => 1,
            'created_by_user_id' => $this->admin->id,
        ]);

        $scope = DiscoveryScope::factory()->create([
            'site_id' => $site->id,
            'collector_id' => $collector->id,
            'name' => 'Pohutukawa LAN',
            'cidrs' => ['172.16.0.0/30'],
            'seed_hosts' => ['172.16.0.1'],
            'protocols' => ['icmp', 'tcp', 'tls'],
            'exclusions' => [],
            'port_bounds' => ['tcp' => [80, 443], 'tls' => [443]],
            'max_targets_per_run' => 16,
            'packets_per_second' => 20,
            'status' => 'active',
        ]);

        Queue::fake();
        $runner = app(DiscoveryRunner::class);
        $queuedRun = $runner->start($scope, 'manual');
        $run = $runner->execute((int) $queuedRun->id);

        $envelopeJson = app(CollectorConfigurationService::class)->signedEnvelope($collector, 0);
        $envelope = json_decode($envelopeJson, true, 512, JSON_THROW_ON_ERROR);
        $payload = json_decode((string) base64_decode((string) $envelope['payload'], true), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(2, $payload['version']);
        $this->assertCount(1, $payload['discovery_runs']);
        $this->assertArrayNotHasKey('unifi_credential_reference', $payload['discovery_runs'][0]);
        $this->assertIsArray($payload['discovery_runs'][0]['unifi_lease'] ?? null);
        $this->assertSame('https', $payload['discovery_runs'][0]['unifi_lease']['protocol']);
        $this->assertSame('172.16.0.0/30', $payload['discovery_runs'][0]['unifi_lease']['target']);
        $this->assertSame(
            'discovery:'.strtolower((string) $run->run_uuid),
            $payload['discovery_runs'][0]['unifi_lease']['device_id'],
        );
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function createAssignedSiteDevice(Site $site, array $attributes): Device
    {
        $device = Device::factory()->create($attributes);
        DeviceAssignment::query()->create([
            'device_id' => $device->id,
            'assignable_type' => DeviceAssignment::TARGET_SITE,
            'assignable_id' => $site->id,
            'assignment_type' => 'permanent',
            'assigned_at' => now()->subDay(),
        ]);

        return $device;
    }
}
