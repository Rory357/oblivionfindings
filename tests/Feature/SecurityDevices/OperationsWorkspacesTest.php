<?php

namespace Tests\Feature\SecurityDevices;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Monitoring\Discovery\Models\DiscoveryCandidate;
use App\Domain\Monitoring\Discovery\Models\DiscoveryResult;
use App\Domain\Monitoring\Discovery\Models\DiscoveryRun;
use App\Domain\Monitoring\Discovery\Models\DiscoveryScope;
use App\Domain\Monitoring\Enums\MonitorKind;
use App\Domain\Monitoring\Enums\MonitorState;
use App\Domain\Monitoring\Models\Monitor;
use App\Domain\Monitoring\Models\MonitoringCollector;
use App\Domain\Monitoring\Models\MonitoringProfile;
use App\Domain\Monitoring\Models\MonitorObservation;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Domain\SecurityDevices\Models\DeviceMaintenanceRecord;
use App\Domain\SecurityDevices\Presenters\MonitoringOperationsPresenter;
use App\Models\Asset;
use App\Models\Client;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SecurityDevicesPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use RuntimeException;
use Tests\TestCase;

if (getenv('MONITORING_USE_PREBUILT_TEST_DATABASE') === '1') {
    $databasePath = getenv('DB_DATABASE');
    if (getenv('APP_ENV') !== 'testing'
        || getenv('DB_CONNECTION') !== 'sqlite'
        || ! is_string($databasePath)
        || $databasePath === ''
        || $databasePath === ':memory:'
        || ! is_file($databasePath)) {
        throw new RuntimeException(
            'MONITORING_USE_PREBUILT_TEST_DATABASE requires APP_ENV=testing, DB_CONNECTION=sqlite, and an existing file-backed database.',
        );
    }

    RefreshDatabaseState::$migrated = true;
}

class OperationsWorkspacesTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
        $this->seed(SecurityDevicesPermissionsSeeder::class);

        $this->admin = User::factory()->create([

            'approved_at' => now(),
        ]);
        $this->admin->roles()->attach(Role::query()->where('name', 'admin')->firstOrFail());
    }

    public function test_native_monitoring_groups_collection_path_failure_and_redacts_probe_secrets(): void
    {
        $site = Site::factory()->create(['name' => 'Remote Care Site']);
        $directDevice = Device::factory()->itInfrastructure()->create([
            'name' => 'Main WAN gateway',
        ]);
        $remoteDevice = Device::factory()->itInfrastructure()->create([
            'name' => 'Remote switch',
        ]);
        $unrelatedDevice = Device::factory()->itInfrastructure()->create([
            'name' => 'Unrelated router',
        ]);
        $this->assignToSite($directDevice, $site);
        $this->assignToSite($remoteDevice, $site);

        $profile = MonitoringProfile::factory()->create([
            'name' => 'Availability',
            'stale_after_seconds' => 300,
        ]);
        $collector = MonitoringCollector::factory()->create([
            'site_id' => $site->id,
            'name' => 'Remote site collector',
            'status' => 'offline',
            'last_seen_at' => now()->subMinutes(12),
            'config' => ['token' => 'collector-secret-token'],
        ]);

        $direct = Monitor::factory()->create([
            'device_id' => $directDevice->id,
            'profile_id' => $profile->id,
            'collector_id' => null,
            'name' => 'Direct ICMP',
            'target' => '10.0.0.1?password=direct-secret',
            'config' => ['community' => 'private-community'],
            'current_state' => MonitorState::Healthy,
            'last_observation_at' => now()->subMinute(),
        ]);
        $remote = Monitor::factory()->create([
            'device_id' => $remoteDevice->id,
            'profile_id' => $profile->id,
            'collector_id' => $collector->id,
            'name' => 'Remote SNMP',
            'kind' => MonitorKind::Snmp,
            'target' => 'snmp://private-target',
            'config' => ['auth' => 'monitor-secret'],
            'current_state' => MonitorState::Failed,
            'last_observation_at' => now()->subMinutes(11),
        ]);
        MonitorObservation::factory()->create([
            'monitor_id' => $direct->id,
            'source_key' => 'direct-observation',
            'state' => MonitorState::Healthy,
            'value' => 12.5,
            'unit' => 'ms',
            'latency_ms' => 12,
            'message' => 'probe-password=observation-secret',
            'metrics' => ['community' => 'metric-secret'],
            'observed_at' => now()->subMinute(),
            'ingested_at' => now()->subMinute(),
        ]);
        $fallbackMonitor = Monitor::factory()->create([
            'device_id' => $unrelatedDevice->id,
            'name' => 'Unrelated monitor',
            'current_state' => MonitorState::Failed,
        ]);

        $response = $this->actingAs($this->admin)->get('/security-devices/monitoring');

        $response->assertOk()->assertInertia(function ($page) use ($direct, $fallbackMonitor, $remote): void {
            $page->component('security-devices/monitoring');
            $workspace = $page->toArray()['props']['workspace'];

            $this->assertSame(3, $workspace['summary']['total_monitors']);
            $this->assertSame(2, $workspace['summary']['direct_monitors']);
            $this->assertSame(1, $workspace['summary']['remote_monitors']);
            $this->assertSame(1, $workspace['summary']['collection_paths_unavailable']);
            $this->assertSame(2, $workspace['summary']['active_findings']);
            $this->assertSame(
                collect([$direct->id, $remote->id, $fallbackMonitor->id])->sort()->values()->all(),
                collect($workspace['monitors'])->pluck('id')->sort()->values()->all(),
            );
            $this->assertSame('direct', collect($workspace['monitors'])->firstWhere('id', $direct->id)['collection']['mode']);
            $this->assertSame('collection_unavailable', collect($workspace['monitors'])->firstWhere('id', $remote->id)['effective_state']);
            $this->assertSame('failed', collect($workspace['monitors'])->firstWhere('id', $remote->id)['reported_state']);
            $this->assertCount(1, $workspace['findings']['monitors']);
            $this->assertCount(1, $workspace['findings']['collection_paths']);
            $this->assertSame(1, $workspace['findings']['collection_paths'][0]['affected_devices']);
            $this->assertSame('evidence_backed', $workspace['coverage']['unsupported_state']);
            $this->assertTrue($workspace['dependencies']['canonical_model_available']);
            $this->assertSame([], collect($workspace['dependencies']['records'])->all());
        });
        $response
            ->assertDontSee('collector-secret-token', false)
            ->assertDontSee('direct-secret', false)
            ->assertDontSee('private-community', false)
            ->assertDontSee('monitor-secret', false)
            ->assertDontSee('observation-secret', false)
            ->assertDontSee('metric-secret', false);
    }

    public function test_monitoring_is_limited_to_the_viewers_visible_sites(): void
    {
        [$viewer, $allowedSite, $hiddenSite] = $this->siteScopedViewer([
            'securityDevices.events.view',
        ]);
        $allowedDevice = Device::factory()->create(['name' => 'Allowed device']);
        $hiddenDevice = Device::factory()->create(['name' => 'Hidden device']);
        $this->assignToSite($allowedDevice, $allowedSite);
        $this->assignToSite($hiddenDevice, $hiddenSite);
        $profile = MonitoringProfile::factory()->create([]);
        foreach ([$allowedDevice, $hiddenDevice] as $device) {
            Monitor::factory()->create([
                'device_id' => $device->id,
                'profile_id' => $profile->id,
                'name' => $device->name.' monitor',
            ]);
        }

        $response = $this->actingAs($viewer)->get('/security-devices/monitoring');

        $response->assertOk()->assertInertia(function ($page): void {
            $monitors = $page->toArray()['props']['workspace']['monitors'];
            $this->assertSame(['Allowed device'], collect($monitors)->pluck('device.name')->all());
        });
        $response->assertDontSee('Hidden device', false);
    }

    public function test_central_site_readiness_omits_inaccessible_sites_devices_and_counts(): void
    {
        [$viewer, $allowedSite, $hiddenSite] = $this->siteScopedViewer([
            'securityDevices.events.view',
        ]);
        $profile = MonitoringProfile::factory()->create(['stale_after_seconds' => 300]);
        foreach ([
            [$allowedSite, 'Allowed central gateway'],
            [$hiddenSite, 'Hidden central gateway sentinel'],
        ] as [$site, $name]) {
            $device = Device::factory()->itInfrastructure()->create(['name' => $name]);
            $this->assignToSite($device, $site);
            Monitor::factory()->create([
                'device_id' => $device->id,
                'profile_id' => $profile->id,
                'name' => $name.' direct check',
                'current_state' => MonitorState::Healthy,
                'effective_state' => MonitorState::Healthy,
                'last_observation_at' => now()->subMinute(),
            ]);
        }

        $workspace = app(MonitoringOperationsPresenter::class)->present($viewer, ['tab' => 'collection']);
        $encoded = json_encode($workspace, JSON_THROW_ON_ERROR);

        $this->assertSame(
            [$allowedSite->id],
            collect($workspace['collection']['direct_sites'])->pluck('site.id')->all(),
        );
        $this->assertSame(['Allowed central gateway'], collect($workspace['monitors'])->pluck('device.name')->all());
        $this->assertStringNotContainsString('Hidden central gateway sentinel', $encoded);
        $this->assertStringNotContainsString($hiddenSite->name, $encoded);
    }

    public function test_monitoring_resolves_client_staff_and_vehicle_devices_into_canonical_site_readiness(): void
    {
        $site = Site::factory()->create(['name' => 'Integrated Care Site']);
        $client = Client::withoutEvents(fn () => Client::forceCreate([
            'first_name' => 'Integrated',
            'last_name' => 'Client',
            'site_id' => $site->id,
            'status' => 'active',
        ]));
        $staff = User::factory()->create(['approved_at' => now()]);
        HrEmployeeProfile::factory()->create([
            'user_id' => $staff->id,
            'primary_site_id' => $site->id,
            'secondary_site_ids' => [],
            'is_active' => true,
        ]);
        $vehicle = Asset::factory()->vehicle()->create([
            'site_id' => $site->id,
            'home_site_id' => $site->id,
            'status' => 'active',
        ]);
        $devices = collect([
            [
                Device::factory()->create([
                    'name' => 'Client healthcare sensor',
                    'domain' => 'iot_healthcare',
                    'category' => 'medical_device',
                ]),
                DeviceAssignment::TARGET_CLIENT,
                $client->id,
            ],
            [
                Device::factory()->itInfrastructure()->create(['name' => 'Staff field laptop']),
                DeviceAssignment::TARGET_STAFF,
                $staff->id,
            ],
            [
                Device::factory()->itInfrastructure()->create(['name' => 'Fleet vehicle gateway']),
                DeviceAssignment::TARGET_VEHICLE,
                $vehicle->id,
            ],
        ]);
        $profile = MonitoringProfile::factory()->create(['stale_after_seconds' => 300]);
        foreach ($devices as [$device, $targetType, $targetId]) {
            DeviceAssignment::create([
                'device_id' => $device->id,
                'assignable_type' => $targetType,
                'assignable_id' => $targetId,
                'assigned_at' => now()->subHour(),
            ]);
            Monitor::factory()->create([
                'device_id' => $device->id,
                'profile_id' => $profile->id,
                'name' => $device->name.' direct check',
                'current_state' => MonitorState::Healthy,
                'effective_state' => MonitorState::Healthy,
                'last_observation_at' => now()->subMinute(),
            ]);
        }

        $workspace = app(MonitoringOperationsPresenter::class)->present($this->admin, ['tab' => 'collection']);
        $siteReadiness = collect($workspace['collection']['direct_sites'])->firstWhere('site.id', $site->id);

        $this->assertNotNull($siteReadiness);
        $this->assertSame(3, $siteReadiness['devices']);
        $this->assertSame(3, $siteReadiness['direct_devices']);
        $this->assertSame(3, $siteReadiness['direct_monitors']);
        $this->assertSame(
            [$site->id],
            collect($workspace['monitors'])->pluck('site.id')->unique()->values()->all(),
        );
    }

    public function test_maintenance_workspace_classifies_work_and_supports_reconcilable_filters(): void
    {
        $site = Site::factory()->create(['name' => 'Clinical Site']);
        $device = Device::factory()->create([
            'name' => 'Infusion pump',
            'domain' => 'iot_healthcare',
            'category' => 'medical_device',
        ]);
        $this->assignToSite($device, $site);

        $records = [
            ['description' => 'Overdue repair', 'type' => 'repair', 'status' => 'scheduled', 'scheduled_for' => now()->subDay()],
            ['description' => 'Calibration due', 'type' => 'calibration', 'status' => 'scheduled', 'scheduled_for' => now()->addDays(3)],
            ['description' => 'Firmware rollout', 'type' => 'firmware_update', 'status' => 'in_progress', 'scheduled_for' => now()],
            ['description' => 'Configuration baseline', 'type' => 'configuration_change', 'status' => 'scheduled', 'scheduled_for' => now()->addDays(30)],
            ['description' => 'Completed inspection', 'type' => 'inspection', 'status' => 'completed', 'scheduled_for' => now()->subDays(3), 'completed_at' => now()->subDay()],
        ];
        foreach ($records as $record) {
            DeviceMaintenanceRecord::create(['device_id' => $device->id] + $record);
        }

        $response = $this->actingAs($this->admin)->get("/security-devices/maintenance?site_id={$site->id}&type=calibration&tab=calibration");

        $response->assertOk()->assertInertia(function ($page) use ($site): void {
            $page->component('security-devices/maintenance');
            $workspace = $page->toArray()['props']['workspace'];

            $this->assertSame('calibration', $workspace['active_tab']);
            $this->assertSame(1, $workspace['summary']['overdue']);
            $this->assertSame(1, $workspace['summary']['due_soon']);
            $this->assertSame(1, $workspace['summary']['planned']);
            $this->assertSame(1, $workspace['summary']['in_progress']);
            $this->assertSame(1, $workspace['summary']['completed']);
            $this->assertSame(1, $workspace['summary']['calibration']);
            $this->assertSame(2, $workspace['summary']['firmware_configuration']);
            $this->assertSame(['Calibration due'], collect($workspace['records'])->pluck('description')->all());
            $this->assertSame($site->id, $workspace['filters']['site_id']);
            $this->assertSame('calibration', $workspace['filters']['type']);
            $this->assertArrayNotHasKey('notes', $workspace['records'][0]);
        });
    }

    public function test_maintenance_mutations_enforce_device_visibility_and_accept_configuration_work(): void
    {
        [$viewer, $allowedSite, $hiddenSite] = $this->siteScopedViewer([
            'securityDevices.maintenance.view',
            'securityDevices.maintenance.manage',
        ]);
        $allowedDevice = Device::factory()->create([]);
        $hiddenDevice = Device::factory()->create([]);
        $this->assignToSite($allowedDevice, $allowedSite);
        $this->assignToSite($hiddenDevice, $hiddenSite);

        $payload = [
            'type' => 'configuration_change',
            'description' => 'Apply approved configuration baseline',
            'scheduled_for' => now()->addDay()->toDateString(),
        ];

        $this->actingAs($viewer)
            ->post("/security-devices/devices/{$allowedDevice->id}/maintenance", $payload)
            ->assertRedirect();
        $this->assertDatabaseHas('device_maintenance_records', [
            'device_id' => $allowedDevice->id,
            'type' => 'configuration_change',
        ]);

        $this->actingAs($viewer)
            ->post("/security-devices/devices/{$hiddenDevice->id}/maintenance", $payload)
            ->assertNotFound();
    }

    public function test_discovery_distinguishes_direct_coverage_from_remote_paths_without_fake_candidates(): void
    {
        $site = Site::factory()->create(['name' => 'Remote Site']);
        $directDevice = Device::factory()->create(['name' => 'Direct gateway']);
        $remoteDevice = Device::factory()->create(['name' => 'Remote access point']);
        $this->assignToSite($remoteDevice, $site);
        $profile = MonitoringProfile::factory()->create([]);
        $collector = MonitoringCollector::factory()->create([
            'site_id' => $site->id,
            'name' => 'Remote collector',
            'status' => 'offline',
            'last_seen_at' => now()->subMinutes(20),
            'config' => ['api_key' => 'discovery-secret'],
        ]);
        Monitor::factory()->create([
            'device_id' => $directDevice->id,
            'profile_id' => $profile->id,
            'collector_id' => null,
        ]);
        Monitor::factory()->create([
            'device_id' => $remoteDevice->id,
            'profile_id' => $profile->id,
            'collector_id' => $collector->id,
        ]);

        $response = $this->actingAs($this->admin)->get('/security-devices/discovery');

        $response->assertOk()->assertInertia(function ($page): void {
            $workspace = $page->toArray()['props']['workspace'];

            $this->assertSame(1, $workspace['summary']['direct_monitors']);
            $this->assertSame(1, $workspace['summary']['remote_monitors']);
            $this->assertSame(1, $workspace['summary']['collection_paths_unavailable']);
            $this->assertSame(1, $workspace['direct_coverage']['monitors']);
            $this->assertSame('Collector-free monitor configuration', $workspace['direct_coverage']['path_label']);
            $this->assertStringContainsString(
                'durable central-runtime proof remain in Monitoring',
                $workspace['direct_coverage']['description'],
            );
            $this->assertSame(1, $workspace['collectors'][0]['affected_devices']);
            $this->assertSame('unavailable', $workspace['collectors'][0]['freshness_state']);
            $this->assertSame('not_assessed', $workspace['limitations']['unsupported_state']);
            $this->assertArrayNotHasKey('not_configured_monitors', $workspace['limitations']);
            $this->assertSame([], collect($workspace['candidates'])->all());
            $this->assertSame([], collect($workspace['runs'])->all());
        });
        $response->assertDontSee('discovery-secret', false);
    }

    public function test_discovery_explains_remote_handoff_returned_progress_and_review_work(): void
    {
        $site = Site::factory()->create(['name' => 'Remote Clinical Site']);
        $collector = MonitoringCollector::factory()->create([
            'site_id' => $site->id,
            'name' => 'Remote Site collector',
            'status' => 'online',
            'last_seen_at' => now()->subMinute(),
            'last_heartbeat_at' => now()->subMinute(),
        ]);
        $scope = DiscoveryScope::factory()->create([
            'site_id' => $site->id,
            'collector_id' => $collector->id,
            'name' => 'Remote clinical network',
            'cidrs' => ['10.44.0.0/24'],
            'protocols' => ['icmp', 'tcp'],
            'status' => 'active',
        ]);
        $run = DiscoveryRun::factory()->create([
            'discovery_scope_id' => $scope->id,
            'status' => 'running',
            'planned_targets' => 2,
            'scope_snapshot' => $scope->snapshot(),
        ]);
        DiscoveryResult::query()->create([
            'discovery_run_id' => $run->id,
            'target_reference_hash' => str_repeat('a', 64),
            'target_source' => 'cidr',
            'outcome' => 'found',
            'evidence_hash' => str_repeat('b', 64),
            'observed_at' => now()->subMinute(),
        ]);
        DiscoveryResult::query()->create([
            'discovery_run_id' => $run->id,
            'target_reference_hash' => str_repeat('c', 64),
            'target_source' => 'cidr',
            'outcome' => 'pending',
        ]);
        DiscoveryCandidate::factory()->create([
            'discovery_run_id' => $run->id,
            'canonical_device_id' => null,
            'decision' => 'review',
            'confidence' => 25,
            'reasons' => ['hostname_is_mutable'],
        ]);

        $this->actingAs($this->admin)->get('/security-devices/discovery?tab=runs')
            ->assertOk()
            ->assertInertia(function ($page): void {
                $workspace = $page->toArray()['props']['workspace'];
                $run = $workspace['runs'][0];

                $this->assertSame('remote_collector', $run['collection_mode']);
                $this->assertSame('Remote Site collector', $run['collector']['name']);
                $this->assertSame('available', $run['collector']['state']);
                $this->assertSame(1, $run['returned']);
                $this->assertSame(1, $run['pending']);
                $this->assertSame(1, $workspace['summary']['candidates_requiring_review']);
            });
    }

    public function test_revoked_collector_is_one_unavailable_path_in_monitoring_and_discovery(): void
    {
        $site = Site::factory()->create(['name' => 'Revoked Collector Site']);
        $device = Device::factory()->itInfrastructure()->create(['name' => 'Remote gateway']);
        $this->assignToSite($device, $site);
        $collector = MonitoringCollector::factory()->create([
            'site_id' => $site->id,
            'name' => 'Revoked remote collector',
            'status' => 'online',
            'last_seen_at' => now()->subMinute(),
            'last_heartbeat_at' => now()->subMinute(),
            'revoked_at' => now(),
        ]);
        Monitor::factory()->create([
            'device_id' => $device->id,
            'collector_id' => $collector->id,
            'current_state' => MonitorState::Healthy,
            'effective_state' => MonitorState::Healthy,
        ]);

        $this->actingAs($this->admin)
            ->get('/security-devices/monitoring')
            ->assertOk()
            ->assertInertia(function ($page): void {
                $workspace = $page->toArray()['props']['workspace'];

                $this->assertSame(1, $workspace['summary']['collection_paths_unavailable']);
                $this->assertSame('revoked', $workspace['monitors'][0]['collection']['state']);
                $this->assertSame('collection_unavailable', $workspace['monitors'][0]['effective_state']);
                $this->assertCount(1, $workspace['findings']['collection_paths']);
                $this->assertSame('revoked', $workspace['findings']['collection_paths'][0]['state']);
                $this->assertSame(1, $workspace['findings']['collection_paths'][0]['affected_devices']);
            });

        $this->actingAs($this->admin)
            ->get('/security-devices/discovery')
            ->assertOk()
            ->assertInertia(function ($page): void {
                $workspace = $page->toArray()['props']['workspace'];

                $this->assertSame(1, $workspace['summary']['collection_paths_unavailable']);
                $this->assertSame(1, $workspace['summary']['affected_devices']);
                $this->assertSame('revoked', $workspace['collectors'][0]['freshness_state']);
            });
    }

    public function test_discovery_reports_true_totals_and_prioritises_actionable_candidates(): void
    {
        $site = Site::factory()->create(['name' => 'Large Discovery Site']);
        $scope = DiscoveryScope::factory()->create([
            'site_id' => $site->id,
            'collector_id' => null,
            'name' => 'Large central scope',
        ]);
        $runs = DiscoveryRun::factory()->count(101)->create([
            'discovery_scope_id' => $scope->id,
        ]);
        $actionable = DiscoveryCandidate::factory()->create([
            'discovery_run_id' => $runs->first()->id,
            'decision' => 'review',
        ]);
        DiscoveryCandidate::factory()->count(200)->create([
            'discovery_run_id' => $runs->last()->id,
            'decision' => 'matched',
        ]);

        $this->actingAs($this->admin)
            ->get('/security-devices/discovery?tab=candidates')
            ->assertOk()
            ->assertInertia(function ($page) use ($actionable): void {
                $workspace = $page->toArray()['props']['workspace'];

                $this->assertSame(101, $workspace['summary']['runs']);
                $this->assertSame(100, $workspace['summary']['runs_shown']);
                $this->assertTrue($workspace['summary']['runs_truncated']);
                $this->assertSame(201, $workspace['summary']['candidates']);
                $this->assertSame(200, $workspace['summary']['candidates_shown']);
                $this->assertTrue($workspace['summary']['candidates_truncated']);
                $this->assertSame(1, $workspace['summary']['candidates_requiring_review']);
                $this->assertContains(
                    $actionable->id,
                    collect($workspace['candidates'])->pluck('id')->all(),
                );
            });
    }

    private function assignToSite(Device $device, Site $site): void
    {
        DeviceAssignment::create([
            'device_id' => $device->id,
            'assignable_type' => DeviceAssignment::TARGET_SITE,
            'assignable_id' => $site->id,
            'assigned_at' => now(),
        ]);
    }

    /** @param list<string> $additionalPermissions */
    private function siteScopedViewer(array $additionalPermissions): array
    {
        $allowedSite = Site::factory()->create(['name' => 'Allowed Site']);
        $hiddenSite = Site::factory()->create(['name' => 'Hidden Site']);
        $viewer = User::factory()->create([

            'approved_at' => now(),
        ]);
        $viewer->roles()->attach(Role::query()->where('name', 'support_worker')->firstOrFail());
        $permissionIds = Permission::query()
            ->whereIn('key', $additionalPermissions)
            ->pluck('id')
            ->mapWithKeys(fn (int $id): array => [$id => ['allowed' => true]])
            ->all();
        $viewer->permissionOverrides()->syncWithoutDetaching($permissionIds);
        HrEmployeeProfile::factory()->create([
            'user_id' => $viewer->id,
            'primary_site_id' => $allowedSite->id,
            'secondary_site_ids' => [],
        ]);

        return [$viewer, $allowedSite, $hiddenSite];
    }
}
