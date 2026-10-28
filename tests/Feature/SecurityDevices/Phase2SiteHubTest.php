<?php

namespace Tests\Feature\SecurityDevices;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Monitoring\Contracts\CollectorCertificateIssuer;
use App\Domain\Monitoring\Data\CollectorCertificateBundle;
use App\Domain\Monitoring\Discovery\Models\DiscoveryScope;
use App\Domain\Monitoring\Discovery\Services\DiscoveryRunner;
use App\Domain\Monitoring\Enums\MonitorKind;
use App\Domain\Monitoring\Models\CollectorEnrollment;
use App\Domain\Monitoring\Models\Monitor;
use App\Domain\Monitoring\Models\MonitoringCollector;
use App\Domain\Monitoring\Models\MonitoringProfile;
use App\Domain\Monitoring\Services\CollectorConfigurationService;
use App\Domain\Monitoring\Services\CollectorHealthService;
use App\Domain\Monitoring\Services\CollectorIngestService;
use App\Domain\Monitoring\Services\CollectorRecoverableEnrollment;
use App\Domain\SecurityDevices\Endpoints\Services\EndpointReleaseCatalogue;
use App\Domain\SecurityDevices\Enums\AssignmentType;
use App\Domain\SecurityDevices\Enums\DeviceDomain;
use App\Domain\SecurityDevices\Enums\DeviceStatus;
use App\Domain\SecurityDevices\Enums\HealthStatus;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Domain\SecurityDevices\Presenters\DeviceProfilePresenter;
use App\Domain\SecurityDevices\Presenters\DiscoveryOperationsPresenter;
use App\Domain\SecurityDevices\Presenters\EstateOperationsPresenter;
use App\Domain\SecurityDevices\Presenters\MonitoringOperationsPresenter;
use App\Domain\SecurityDevices\Presenters\SiteProfileTechnologyProjectionPresenter;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Support\SecurityPolicy;
use Carbon\CarbonImmutable;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SecurityDevicesPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

final class Phase2SiteHubTest extends TestCase
{
    use RefreshDatabase;

    private string $sourceDir = '';

    private string $releaseDir = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
        $this->seed(SecurityDevicesPermissionsSeeder::class);

        $suffix = bin2hex(random_bytes(8));
        $this->sourceDir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'phase2-hub-source-'.$suffix;
        $this->releaseDir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'phase2-hub-releases-'.$suffix;
        mkdir($this->sourceDir, 0700, true);

        $signingSecret = sodium_crypto_sign_secretkey(
            sodium_crypto_sign_seed_keypair(str_repeat('H', 32)),
        );

        config()->set([
            'endpoint_management.server_url' => 'https://oblivionfindings.test',
            'endpoint_management.organisation_label' => 'Oblivion Findings',
            'monitoring.collector.signing_secret_key' => base64_encode($signingSecret),
            'monitoring.collector.installation.server_url' => 'https://oblivionfindings.test',
            'monitoring.collector.installation.tls_public_key_pin' => 'sha256//'.base64_encode(str_repeat('P', 32)),
        ]);

        app()->instance(CollectorCertificateIssuer::class, new class implements CollectorCertificateIssuer
        {
            public function issue(string $collectorUuid): CollectorCertificateBundle
            {
                return new CollectorCertificateBundle(
                    certificatePem: "-----BEGIN CERTIFICATE-----\nhub-{$collectorUuid}\n-----END CERTIFICATE-----\n",
                    privateKeyPem: "-----BEGIN PRIVATE KEY-----\nhub-private\n-----END PRIVATE KEY-----\n",
                    fingerprint: hash('sha256', 'hub-cert-'.$collectorUuid),
                    expiresAt: CarbonImmutable::now('UTC')->addYear(),
                );
            }
        });
    }

    protected function tearDown(): void
    {
        foreach ([$this->sourceDir, $this->releaseDir] as $dir) {
            if ($dir !== '' && is_dir($dir)) {
                File::deleteDirectory($dir);
            }
        }

        SecurityPolicy::flushCache();
        parent::tearDown();
    }

    public function test_publish_endpoint_catalogue_command_verifies_signed_multi_platform_hub_packages_and_serves_downloads(): void
    {
        $signingPair = sodium_crypto_sign_keypair();
        $secretKey = sodium_crypto_sign_secretkey($signingPair);
        $publicKeyB64 = base64_encode(sodium_crypto_sign_publickey($signingPair));

        $files = [
            'windows-amd64' => [
                'platform' => 'windows',
                'architecture' => 'amd64',
                'roles' => ['computer', 'site'],
                'filename' => 'obli-setup-windows-amd64.exe',
                'bytes' => 'windows-amd64-installer-bytes',
            ],
            'windows-arm64' => [
                'platform' => 'windows',
                'architecture' => 'arm64',
                'roles' => ['computer', 'site'],
                'filename' => 'obli-setup-windows-arm64.exe',
                'bytes' => 'windows-arm64-installer-bytes',
            ],
            'linux-amd64' => [
                'platform' => 'linux',
                'architecture' => 'amd64',
                'roles' => ['site'],
                'filename' => 'oblivion-site-hub-linux-amd64.tar.gz',
                'bytes' => 'linux-amd64-hub-archive-bytes',
            ],
            'linux-arm64' => [
                'platform' => 'linux',
                'architecture' => 'arm64',
                'roles' => ['site'],
                'filename' => 'oblivion-site-hub-linux-arm64.tar.gz',
                'bytes' => 'linux-arm64-hub-archive-bytes',
            ],
        ];

        $artifacts = [];
        foreach ($files as $id => $spec) {
            file_put_contents($this->sourceDir.DIRECTORY_SEPARATOR.$spec['filename'], $spec['bytes']);
            $artifacts[] = [
                'id' => $id,
                'platform' => $spec['platform'],
                'architecture' => $spec['architecture'],
                'roles' => $spec['roles'],
                'version' => '0.2.0',
                'release_revision' => 2,
                'filename' => $spec['filename'],
                'size' => strlen($spec['bytes']),
                'sha256' => hash('sha256', $spec['bytes']),
            ];
        }

        $now = CarbonImmutable::now('UTC');
        $cataloguePayload = json_encode([
            'schema' => 1,
            'product' => 'oblivion-downloads',
            'revision' => 5,
            'issued_at' => $now->format('Y-m-d\TH:i:s\Z'),
            'expires_at' => $now->addDays(30)->format('Y-m-d\TH:i:s\Z'),
            'artifacts' => $artifacts,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        $signature = sodium_crypto_sign_detached(
            EndpointReleaseCatalogue::DOMAIN.$cataloguePayload,
            $secretKey,
        );
        file_put_contents(
            $this->sourceDir.DIRECTORY_SEPARATOR.'downloads.json',
            json_encode([
                'payload' => base64_encode($cataloguePayload),
                'signature' => base64_encode($signature),
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
        );

        config()->set([
            'endpoint_management.release_directory' => $this->releaseDir,
            'endpoint_management.release_public_key' => $publicKeyB64,
            'endpoint_management.minimum_catalogue_revision' => 5,
        ]);

        $exitCode = Artisan::call('endpoint:publish-catalogue', [
            'source' => $this->sourceDir,
        ]);

        $this->assertSame(0, $exitCode);
        $this->assertFileExists($this->releaseDir.DIRECTORY_SEPARATOR.'downloads.json');

        $catalogue = EndpointReleaseCatalogue::configured()->catalogue();
        $this->assertCount(4, $catalogue['artifacts']);

        // Grant commands.admin as well so the combined Windows (computer + site) installer is also visible.
        [$operator, $homeSite] = $this->operatorWithSites(
            ['Harbour House'],
            [],
            ['securityDevices.commands.admin'],
        );

        $options = $this->actingAs($operator)
            ->getJson('/security-devices/discovery/collectors/hub-wizard/options?platform=linux')
            ->assertOk();

        $this->assertTrue($options->json('catalogue_available'));
        $this->assertCount(2, $options->json('downloads_by_platform.windows'));
        $this->assertCount(2, $options->json('downloads_by_platform.linux'));
        $this->assertSame([$homeSite->id], collect($options->json('accessible_sites'))->pluck('id')->all());

        $linuxDownloadUrl = (string) $options->json('downloads_by_platform.linux.0.download_url');
        $this->assertSame('Oblivion Site Hub Service', $options->json('downloads_by_platform.linux.0.title'));
        $this->assertSame(strlen($files['linux-amd64']['bytes']), $options->json('downloads_by_platform.linux.0.size_bytes'));
        $this->actingAs($operator)
            ->get('https://localhost'.$linuxDownloadUrl)
            ->assertOk()
            ->assertStreamedContent($files['linux-amd64']['bytes']);
    }

    public function test_add_hub_wizard_issues_setup_code_enrols_collector_and_reports_connected_heartbeat(): void
    {
        [$operator, $homeSite, $sdwanSite, $hiddenSite] = $this->operatorWithSites(
            ['Kauri House', 'Totara Lodge'],
            ['Hidden Private House'],
        );

        $this->actingAs($operator)
            ->postJson('/security-devices/discovery/collectors/hub-wizard/issue', [
                'site_id' => $homeSite->id,
                'name' => 'Kauri NUC Hub',
                'platform' => 'linux',
                'covered_site_ids' => [$hiddenSite->id],
            ])
            ->assertNotFound();

        $issued = $this->actingAs($operator)
            ->postJson('/security-devices/discovery/collectors/hub-wizard/issue', [
                'site_id' => $homeSite->id,
                'name' => 'Kauri NUC Hub',
                'platform' => 'linux',
                'covered_site_ids' => [$sdwanSite->id],
            ])
            ->assertCreated();

        $enrollmentId = (int) $issued->json('enrollment_id');
        $token = (string) $issued->json('enrolment_token');
        $setupCode = (string) $issued->json('setup_code');
        $collectorUuid = (string) $issued->json('collector_uuid');

        $this->assertStringStartsWith('ofc_enrol_', $token);
        $this->assertStringStartsWith('ofh1_', $setupCode);
        $this->assertNotSame('', $collectorUuid);

        $padded = strtr(substr($setupCode, 5), '-_', '+/');
        $padding = strlen($padded) % 4;
        if ($padding !== 0) {
            $padded .= str_repeat('=', 4 - $padding);
        }
        $decodedSetup = json_decode(
            (string) base64_decode($padded, true),
            true,
            16,
            JSON_THROW_ON_ERROR,
        );
        $this->assertSame($token, $decodedSetup['enrolment_token']);
        $this->assertSame($homeSite->id, $decodedSetup['site_id']);
        $this->assertSame($collectorUuid, $decodedSetup['collector_uuid']);

        $this->actingAs($operator)
            ->getJson("/security-devices/discovery/collectors/enrolments/{$enrollmentId}/status")
            ->assertOk()
            ->assertJsonPath('state', 'waiting')
            ->assertJsonPath('collector', null);

        $collectorKeyPair = sodium_crypto_sign_seed_keypair(str_repeat('K', 32));
        $collectorPublic = sodium_crypto_sign_publickey($collectorKeyPair);

        $this->withToken($token)
            ->postJson('https://localhost/api/monitoring/collectors/enrol/recoverable', [
                'collector_id' => $collectorUuid,
                'collector_public_key' => base64_encode($collectorPublic),
                'proof' => base64_encode(sodium_crypto_sign_detached(
                    CollectorRecoverableEnrollment::proofMessage($token, $collectorUuid, $collectorPublic),
                    sodium_crypto_sign_secretkey($collectorKeyPair),
                )),
            ])
            ->assertCreated();

        $collector = MonitoringCollector::query()->where('collector_uuid', $collectorUuid)->firstOrFail();
        $this->assertSame('Kauri NUC Hub', $collector->name);
        $this->assertSame($homeSite->id, (int) $collector->site_id);
        $this->assertSame([$sdwanSite->id], $collector->coveredSiteIds());
        $this->assertSame(
            [$homeSite->id, $sdwanSite->id],
            $collector->authorisedSiteIds(),
        );

        $this->actingAs($operator)
            ->getJson("/security-devices/discovery/collectors/enrolments/{$enrollmentId}/status")
            ->assertOk()
            ->assertJsonPath('state', 'enrolled')
            ->assertJsonPath('collector.name', 'Kauri NUC Hub');

        app(CollectorHealthService::class)->recordHeartbeat($collector, [
            'reported_at' => CarbonImmutable::now('UTC')->format(DATE_ATOM),
            'state' => 'writable',
            'spool_items' => 0,
            'spool_bytes' => 0,
            'oldest_spool_item_at' => null,
            'corrupted_frames' => 0,
            'acknowledged_source_sequence' => 0,
            'highest_seen_source_sequence' => 0,
            'runtime' => [
                'version' => '0.2.0',
                'checks_executed' => 0,
            ],
        ]);

        $this->actingAs($operator)
            ->getJson("/security-devices/discovery/collectors/enrolments/{$enrollmentId}/status")
            ->assertOk()
            ->assertJsonPath('state', 'connected')
            ->assertJsonPath('collector.software_version', '0.2.0')
            ->assertJsonPath('collector.covered_site_ids', [$sdwanSite->id]);

        $enrollment = CollectorEnrollment::query()->findOrFail($enrollmentId);
        $this->assertNotNull($enrollment->consumed_at);
    }

    public function test_hub_monitors_and_scans_cover_sdwan_sites_and_surface_runs_from_and_health_everywhere(): void
    {
        Queue::fake();

        [$operator, $homeSite, $sdwanSite, $hiddenSite] = $this->operatorWithSites(
            ['Rimu Hub Site', 'Matai SD-WAN Site'],
            ['Restricted Site'],
        );

        $hubKeyPair = sodium_crypto_sign_seed_keypair(str_repeat('R', 32));
        $hub = MonitoringCollector::factory()->create([
            'site_id' => $homeSite->id,
            'name' => 'Rimu Rack Hub',
            'status' => 'online',
            'public_key' => base64_encode(sodium_crypto_sign_publickey($hubKeyPair)),
            'public_key_fingerprint' => hash('sha256', sodium_crypto_sign_publickey($hubKeyPair)),
            'client_certificate_fingerprint' => hash('sha256', 'rimu-cert'),
            'last_heartbeat_at' => now()->subSeconds(20),
            'last_seen_at' => now()->subSeconds(20),
            'runtime_state' => 'writable',
            'runtime_status' => [
                'version' => '0.2.0',
                'checks_executed' => 4,
            ],
            'config' => [
                'covered_site_ids' => [$sdwanSite->id],
            ],
        ]);

        // Operator can rename hub and adjust SD-WAN covered Sites, while hidden Sites return 404.
        $this->actingAs($operator)
            ->patchJson("/security-devices/discovery/collectors/{$hub->id}", [
                'name' => 'Rimu Main Hub',
                'covered_site_ids' => [$sdwanSite->id, $hiddenSite->id],
            ])
            ->assertNotFound();

        $this->actingAs($operator)
            ->patchJson("/security-devices/discovery/collectors/{$hub->id}", [
                'name' => 'Rimu Main Hub',
                'covered_site_ids' => [$sdwanSite->id],
            ])
            ->assertOk()
            ->assertJsonPath('collector.name', 'Rimu Main Hub')
            ->assertJsonPath('collector.covered_site_ids', [$sdwanSite->id]);

        $hub->refresh();

        $homeDevice = $this->deviceAtSite($homeSite, 'Rimu Gateway', '172.16.10.1');
        $sdwanDevice = $this->deviceAtSite($sdwanSite, 'Matai NVR', '172.16.20.2');
        $profile = MonitoringProfile::factory()->create(['is_active' => true]);

        // Create a direct scope on Home Site and a hub-assigned scope on the SD-WAN covered Site.
        $directScopeResponse = $this->actingAs($operator)
            ->postJson('/security-devices/discovery/scopes', [
                'site_id' => $homeSite->id,
                'name' => 'Rimu Direct Subnet',
                'cidrs' => ['172.16.10.0/24'],
                'protocols' => ['icmp', 'http'],
                'port_bounds' => ['http' => [80, 443]],
                'max_targets_per_run' => 256,
                'packets_per_second' => 10,
            ])
            ->assertCreated();
        $directScope = DiscoveryScope::query()->findOrFail((int) $directScopeResponse->json('scope.id'));

        $hubScopeResponse = $this->actingAs($operator)
            ->postJson('/security-devices/discovery/scopes', [
                'site_id' => $sdwanSite->id,
                'collector_id' => $hub->id,
                'name' => 'Matai SD-WAN Subnet',
                'cidrs' => ['172.16.20.0/29'],
                'protocols' => ['icmp', 'http'],
                'port_bounds' => ['http' => [80, 443]],
                'max_targets_per_run' => 64,
                'packets_per_second' => 10,
            ])
            ->assertCreated();
        $hubScope = DiscoveryScope::query()->findOrFail((int) $hubScopeResponse->json('scope.id'));
        $this->assertSame($hub->id, (int) $hubScope->collector_id);

        // Create a server-direct monitor on Home Site and a hub-assigned monitor on SD-WAN Site.
        $directMonitorResponse = $this->actingAs($operator)
            ->postJson('/security-devices/monitoring/native-monitors', [
                'device_id' => $homeDevice->id,
                'profile_id' => $profile->id,
                'kind' => MonitorKind::Icmp->value,
                'name' => 'Rimu Gateway Ping',
                'target' => '172.16.10.1',
            ])
            ->assertCreated();
        $directMonitor = Monitor::query()->findOrFail((int) $directMonitorResponse->json('monitor.id'));
        $this->assertNull($directMonitor->collector_id);

        $hubMonitorResponse = $this->actingAs($operator)
            ->postJson('/security-devices/monitoring/native-monitors', [
                'device_id' => $sdwanDevice->id,
                'profile_id' => $profile->id,
                'collector_id' => $hub->id,
                'kind' => MonitorKind::Http->value,
                'name' => 'Matai NVR HTTPS',
                'target' => 'https://172.16.20.2/health',
            ])
            ->assertCreated();
        $hubMonitor = Monitor::query()->findOrFail((int) $hubMonitorResponse->json('monitor.id'));
        $this->assertSame($hub->id, (int) $hubMonitor->collector_id);

        // Trigger and seed a discovery run on the SD-WAN hub scope so targets are queued for the hub.
        $runner = app(DiscoveryRunner::class);
        $hubRun = $runner->start($hubScope, 'manual:test');
        $hubRun = $runner->execute((int) $hubRun->id);
        $this->assertSame('running', $hubRun->status);
        $this->assertSame($hub->id, (int) ($hubRun->scope_snapshot['collector_id'] ?? 0));

        // Signed collector configuration leases SD-WAN Site bounds, monitor, and discovery targets to the hub!
        $envelopeJson = app(CollectorConfigurationService::class)->signedEnvelope($hub, 0);
        $envelope = json_decode($envelopeJson, true, 16, JSON_THROW_ON_ERROR);
        $config = json_decode((string) base64_decode((string) $envelope['payload'], true), true, 32, JSON_THROW_ON_ERROR);

        $this->assertSame($homeSite->id, $config['site_id']);
        $this->assertSame([$sdwanSite->id], $config['scope']['covered_site_ids'] ?? []);
        $this->assertSame([(string) $hubMonitor->id], collect($config['checks'])->pluck('id')->all());
        $this->assertNotEmpty($config['discovery_runs']);

        // Hub ingests an observation for the SD-WAN Site device monitor cleanly!
        $nowIso = CarbonImmutable::now('UTC')->format(DATE_ATOM);
        $ingestAck = app(CollectorIngestService::class)->ingest($hub, [[
            'id' => 'obs-sdwan-1',
            'source_sequence' => 1,
            'created_at' => $nowIso,
            'payload' => [
                'item_type' => 'observation',
                'check_id' => (string) $hubMonitor->id,
                'device_id' => (string) $sdwanDevice->id,
                'protocol' => 'https',
                'target' => '172.16.20.2',
                'observed_at' => $nowIso,
                'duration_ms' => 14,
                'state' => 'healthy',
                'reason_code' => 'http_status_ok',
                'metrics' => ['status_code' => 200],
            ],
        ]]);
        $this->assertSame(['obs-sdwan-1'], $ingestAck['acknowledged_ids']);

        // Verify "Runs from: server" / "Runs from: hub Rimu Main Hub" and Hub health across all presenters!
        $discoveryWorkspace = app(DiscoveryOperationsPresenter::class)->present($operator, 'scopes');
        $scopesById = collect($discoveryWorkspace['scopes'])->keyBy('id');
        $this->assertSame('Runs from: server', $scopesById[$directScope->id]['runs_from']);
        $this->assertSame('Runs from: hub Rimu Main Hub', $scopesById[$hubScope->id]['runs_from']);

        $runsById = collect($discoveryWorkspace['runs'])->keyBy('id');
        $this->assertSame('Runs from: hub Rimu Main Hub', $runsById[$hubRun->id]['runs_from']);

        $collectorRow = collect($discoveryWorkspace['collectors'])->firstWhere('id', $hub->id);
        $this->assertNotNull($collectorRow);
        $this->assertSame('0.2.0', $collectorRow['software_version']);
        $this->assertSame(1, $collectorRow['monitor_load']);
        $this->assertSame(1, $collectorRow['scope_load']);
        $this->assertSame([$sdwanSite->id], $collectorRow['covered_site_ids']);

        $monitoringWorkspace = app(MonitoringOperationsPresenter::class)->present($operator, []);
        $monitorsById = collect($monitoringWorkspace['monitors'])->keyBy('id');
        $this->assertSame('Runs from: server', $monitorsById[$directMonitor->id]['runs_from']);
        $this->assertSame('Runs from: hub Rimu Main Hub', $monitorsById[$hubMonitor->id]['runs_from']);

        $freshHomeDevice = $homeDevice->fresh(['monitors.profile', 'monitors.collector', 'assignments']);
        $homeDeviceProfile = app(DeviceProfilePresenter::class)->present(
            $operator,
            $freshHomeDevice,
            $freshHomeDevice->assignments->first(fn ($a) => $a->released_at === null),
        );
        $this->assertSame('Runs from: server', $homeDeviceProfile['monitors'][0]['runsFrom']);

        $freshSdwanDevice = $sdwanDevice->fresh(['monitors.profile', 'monitors.collector', 'assignments']);
        $sdwanDeviceProfile = app(DeviceProfilePresenter::class)->present(
            $operator,
            $freshSdwanDevice,
            $freshSdwanDevice->assignments->first(fn ($a) => $a->released_at === null),
        );
        $this->assertSame('Runs from: hub Rimu Main Hub', $sdwanDeviceProfile['monitors'][0]['runsFrom']);

        $sdwanSiteTech = app(EstateOperationsPresenter::class)->site($operator, $sdwanSite);
        $this->assertSame('Runs from: hub Rimu Main Hub', $sdwanSiteTech['monitoring']['monitors'][0]['runs_from']);
        $this->assertCount(1, $sdwanSiteTech['collectors']);
        $this->assertFalse($sdwanSiteTech['collectors'][0]['is_home_site']);
        $this->assertSame('0.2.0', $sdwanSiteTech['collectors'][0]['software_version']);

        // Force a failed state on hubMonitor to verify SiteProfileTechnologyProjectionPresenter issues list too.
        $hubMonitor->forceFill([
            'current_state' => 'failed',
            'effective_state' => 'failed',
        ])->save();
        $projection = app(SiteProfileTechnologyProjectionPresenter::class)->present($operator, $sdwanSite);
        $this->assertSame('Runs from: hub Rimu Main Hub', $projection['monitoring']['issues'][0]['runs_from']);
    }

    /**
     * @param  list<string>  $allowedNames
     * @param  list<string>  $hiddenNames
     * @param  list<string>  $extraPermissionKeys
     * @return array<int, mixed>
     */
    private function operatorWithSites(array $allowedNames, array $hiddenNames = [], array $extraPermissionKeys = []): array
    {
        $allowedSites = array_map(
            fn (string $name): Site => Site::factory()->create([
                'name' => $name,
                'is_active' => true,
                'archived' => false,
                'archived_at' => null,
            ]),
            $allowedNames,
        );

        $hiddenSites = array_map(
            fn (string $name): Site => Site::factory()->create([
                'name' => $name,
                'is_active' => true,
                'archived' => false,
                'archived_at' => null,
            ]),
            $hiddenNames,
        );

        $user = User::factory()->create([
            'approved_at' => now(),
            'email_verified_at' => now(),
        ]);
        $user->roles()->attach(Role::query()->where('name', 'support_worker')->firstOrFail());

        $permissionKeys = [
            'securityDevices.viewAny',
            'securityDevices.devices.view',
            'securityDevices.devices.create',
            'securityDevices.devices.update',
            'securityDevices.events.view',
            'securityDevices.monitoring.manage',
            'securityDevices.integrations.view',
            'securityDevices.integrations.manage',
            'securityDevices.maintenance.view',
            ...$extraPermissionKeys,
        ];
        $overrides = Permission::query()
            ->whereIn('key', $permissionKeys)
            ->pluck('id')
            ->mapWithKeys(fn ($id): array => [$id => ['allowed' => true]])
            ->all();
        $user->permissionOverrides()->syncWithoutDetaching($overrides);

        $primarySite = $allowedSites[0];
        $secondaryIds = array_map(
            fn (Site $site): int => (int) $site->id,
            array_slice($allowedSites, 1),
        );

        HrEmployeeProfile::factory()->create([
            'user_id' => $user->id,
            'primary_site_id' => $primarySite->id,
            'secondary_site_ids' => $secondaryIds,
            'is_active' => true,
        ]);

        return [$user->fresh(), ...$allowedSites, ...$hiddenSites];
    }

    private function deviceAtSite(Site $site, string $name, string $ip): Device
    {
        $device = Device::factory()->create([
            'name' => $name,
            'domain' => DeviceDomain::ItInfrastructure->value,
            'category' => 'network',
            'subcategory' => 'switch',
            'ip_address' => $ip,
            'status' => DeviceStatus::Active,
            'health_status' => HealthStatus::Healthy,
        ]);

        DeviceAssignment::query()->create([
            'device_id' => $device->id,
            'assignable_type' => DeviceAssignment::TARGET_SITE,
            'assignable_id' => $site->id,
            'assignment_type' => AssignmentType::Permanent,
            'assigned_at' => now(),
        ]);

        return $device;
    }
}
