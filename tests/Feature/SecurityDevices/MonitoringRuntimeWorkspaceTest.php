<?php

namespace Tests\Feature\SecurityDevices;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Monitoring\Discovery\Models\DiscoveryCandidate;
use App\Domain\Monitoring\Discovery\Models\DiscoveryRun;
use App\Domain\Monitoring\Discovery\Models\DiscoveryScope;
use App\Domain\Monitoring\Enums\MonitorState;
use App\Domain\Monitoring\Models\MetricCurrentSummary;
use App\Domain\Monitoring\Models\MetricSeries;
use App\Domain\Monitoring\Models\Monitor;
use App\Domain\Monitoring\Models\MonitorDependency;
use App\Domain\Monitoring\Models\MonitoringCollector;
use App\Domain\Monitoring\Models\MonitoringProfile;
use App\Domain\Monitoring\Models\ProviderCapabilityCursor;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Models\ControlRoom\Signal;
use App\Models\ControlRoom\SignalSource;
use App\Models\ControlRoomAlert;
use App\Models\ItTicket;
use App\Models\ItTicketLink;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SecurityDevicesPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Str;
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

class MonitoringRuntimeWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
        $this->seed(SecurityDevicesPermissionsSeeder::class);
    }

    public function test_runtime_workspaces_reconcile_visible_discovery_dependency_storage_and_provider_state(): void
    {
        [$viewer, $allowedSite, $hiddenSite] = $this->siteScopedViewer([
            'securityDevices.events.view',
            'securityDevices.integrations.view',
            'securityDevices.devices.view',
            'controlRoom.alerts.view',
            'it.view',
        ]);
        [$allowedUpstream, $allowedDownstream] = [
            Device::factory()->itInfrastructure()->create(['name' => 'Allowed WAN gateway']),
            Device::factory()->itInfrastructure()->create(['name' => 'Allowed access switch']),
        ];
        $hiddenDevice = Device::factory()->itInfrastructure()->create(['name' => 'Hidden core switch']);
        $this->assignToSite($allowedUpstream, $allowedSite);
        $this->assignToSite($allowedDownstream, $allowedSite);
        $this->assignToSite($hiddenDevice, $hiddenSite);

        $profile = MonitoringProfile::factory()->create(['name' => 'Runtime availability']);
        $upstreamMonitor = Monitor::factory()->create([
            'device_id' => $allowedUpstream->id,
            'profile_id' => $profile->id,
            'name' => 'WAN path',
            'current_state' => MonitorState::Failed,
            'effective_state' => MonitorState::Failed->value,
        ]);
        $downstreamMonitor = Monitor::factory()->create([
            'device_id' => $allowedDownstream->id,
            'profile_id' => $profile->id,
            'name' => 'Access switch path',
            'current_state' => MonitorState::Failed,
            'effective_state' => 'suppressed',
            'root_cause_monitor_id' => $upstreamMonitor->id,
            'suppression_reason' => 'dependency_failed',
        ]);
        Monitor::factory()->create([
            'device_id' => $hiddenDevice->id,
            'profile_id' => $profile->id,
            'name' => 'Hidden monitor sentinel',
        ]);
        MonitorDependency::create([
            'site_id' => $allowedSite->id,
            'upstream_monitor_id' => $upstreamMonitor->id,
            'downstream_monitor_id' => $downstreamMonitor->id,
            'policy' => MonitorDependency::POLICY_SUPPRESS,
            'source' => 'topology',
            'confidence' => 0.95,
            'is_active' => true,
        ]);

        $series = MetricSeries::create([
            'site_id' => $allowedSite->id,
            'device_id' => $allowedUpstream->id,
            'monitor_id' => $upstreamMonitor->id,
            'metric' => 'interface.utilisation',
            'dimensions' => ['if_index' => '1'],
            'dimensions_hash' => hash('sha256', '{"if_index":"1"}'),
            'unit' => 'percent',
            'source' => 'snmp',
            'data_class' => 'operational',
            'privacy_class' => 'standard',
            'retention_tier' => 'raw',
            'external_key' => 'allowed-runtime-series',
            'first_point_at' => now()->subHour(),
            'last_point_at' => now()->subMinute(),
        ]);
        MetricCurrentSummary::create([
            'series_id' => $series->id,
            'value' => 82.5,
            'statistics' => ['p95' => 86.2],
            'sample_count' => 24,
            'observed_at' => now()->subMinute(),
            'storage_state' => 'available',
            'storage_checked_at' => now()->subMinute(),
        ]);

        $collector = MonitoringCollector::factory()->create([
            'site_id' => $allowedSite->id,
            'name' => 'Allowed remote collector',
            'status' => 'online',
            'runtime_state' => 'healthy',
            'backlog_items' => 2,
            'gap_count' => 1,
            'last_heartbeat_at' => now()->subMinute(),
            'last_seen_at' => now()->subMinute(),
            'config' => ['secret' => 'collector-runtime-secret'],
        ]);
        $scope = DiscoveryScope::factory()->create([
            'site_id' => $allowedSite->id,
            'collector_id' => $collector->id,
            'name' => 'Allowed clinical network',
            'protocols' => ['icmp', 'snmp'],
            'cidrs' => ['10.20.0.0/24'],
            'exclusions' => ['10.20.0.1/32'],
            'status' => 'active',
        ]);
        $run = DiscoveryRun::factory()->create([
            'discovery_scope_id' => $scope->id,
            'status' => 'completed',
            'planned_targets' => 10,
            'found_count' => 2,
            'matched_count' => 1,
            'proposed_count' => 1,
            'unresolved_count' => 0,
            'completed_at' => now()->subMinutes(5),
        ]);
        DiscoveryCandidate::factory()->create([
            'discovery_run_id' => $run->id,
            'canonical_device_id' => $allowedDownstream->id,
            'decision' => 'matched',
            'confidence' => 92,
            'reasons' => ['serial_match'],
            'evidence_snapshot' => ['secret' => 'candidate-evidence-secret'],
        ]);

        ProviderCapabilityCursor::create([
            'site_id' => $allowedSite->id,
            'provider' => 'unifi',
            'capability' => 'device_sync',
            'cursor' => 'provider-secret-cursor',
            'last_started_at' => now()->subMinutes(3),
            'last_completed_at' => now()->subMinutes(2),
            'exception_count' => 1,
        ]);

        $alert = ControlRoomAlert::factory()->create([
            'site_id' => $allowedSite->id,
            'status' => ControlRoomAlert::STATUS_RESOLVED,
        ]);
        $source = SignalSource::create([
            'name' => 'Oblivion native monitoring',
            'slug' => 'security_devices',
            'status' => 'active',
        ]);
        Signal::create([
            'signal_source_id' => $source->id,
            'signal_type_code' => 'device_offline',
            'site_id' => $allowedSite->id,
            'severity_hint' => 'high',
            'normalized_data' => [
                'monitor_correlation_key' => hash(
                    'sha256',
                    "site:{$allowedSite->id}:device:{$allowedUpstream->id}:root:{$upstreamMonitor->id}:condition:availability",
                ),
            ],
            'status' => 'processed',
            'alert_id' => $alert->id,
            'processed_at' => now(),
        ]);
        $ticket = ItTicket::factory()->create([
            'site_id' => $allowedSite->id,
            'is_organisation_wide' => false,
            'is_sensitive' => false,
            'source' => 'system',
            'work_type' => 'incident',
            'status' => 'in_progress',
            'monitoring_recovered_at' => now()->subMinute(),
        ]);
        ItTicketLink::create([
            'ticket_id' => $ticket->id,
            'relationship' => 'source_alert',
            'linkable_type' => $alert->getMorphClass(),
            'linkable_id' => $alert->id,
        ]);

        $monitoring = $this->actingAs($viewer)->get('/security-devices/monitoring');
        $monitoring->assertOk()->assertInertia(function ($page) use ($ticket): void {
            $workspace = $page->toArray()['props']['workspace'];
            $rootMonitor = collect($workspace['monitors'])->firstWhere('name', 'WAN path');

            $this->assertTrue($workspace['dependencies']['canonical_model_available']);
            $this->assertCount(1, $workspace['dependencies']['records']);
            $this->assertSame(1, $workspace['dependencies']['suppressed_symptoms']);
            $this->assertSame('available', $workspace['storage']['time_series']['state']);
            $this->assertSame(1, $workspace['storage']['time_series']['series']);
            $this->assertSame(
                ['events', 'checks', 'discovery', 'provider', 'topology', 'maintenance', 'orchestration', 'commands'],
                array_keys($workspace['runtime']['queues']),
            );
            $this->assertSame('resolved', $rootMonitor['correlation']['control_room']['status']);
            $this->assertSame($ticket->reference, $rootMonitor['correlation']['it_incident']['reference']);
            $this->assertNotNull($rootMonitor['correlation']['it_incident']['monitoring_recovered_at']);
        });

        $discovery = $this->actingAs($viewer)->get('/security-devices/discovery');
        $discovery->assertOk()->assertInertia(function ($page) use ($allowedSite): void {
            $workspace = $page->toArray()['props']['workspace'];

            $this->assertSame(1, $workspace['summary']['scopes']);
            $this->assertSame(1, $workspace['summary']['runs']);
            $this->assertSame(1, $workspace['summary']['candidates']);
            $this->assertSame($allowedSite->id, $workspace['scopes'][0]['site']['id']);
            $this->assertSame(['serial_match'], $workspace['candidates'][0]['reasons']);
        });

        $integrations = $this->actingAs($viewer)->get('/security-devices/integrations');
        $integrations->assertOk()->assertInertia(function ($page): void {
            $provider = collect($page->toArray()['props']['providers'])->firstWhere('slug', 'unifi');

            $this->assertSame('1.5', $provider['runtime']['version']);
            $this->assertContains('device_sync', $provider['runtime']['capabilities']);
            $this->assertContains('event_collection', $provider['runtime']['capabilities']);
            $this->assertContains('topology_collection', $provider['runtime']['capabilities']);
            $this->assertSame(1, $provider['runtime']['cursor_scopes']);
            $this->assertSame(1, $provider['runtime']['exception_count']);
        });

        foreach ([$monitoring, $discovery, $integrations] as $response) {
            $response
                ->assertDontSee('Hidden core switch', false)
                ->assertDontSee('Hidden monitor sentinel', false)
                ->assertDontSee('collector-runtime-secret', false)
                ->assertDontSee('candidate-evidence-secret', false)
                ->assertDontSee('provider-secret-cursor', false);
        }
    }

    public function test_runtime_health_is_authenticated_bounded_and_does_not_leak_global_or_secret_configuration(): void
    {
        [$viewer, $allowedSite] = $this->siteScopedViewer([
            'securityDevices.events.view',
        ]);
        MonitoringCollector::factory()->create([
            'site_id' => $allowedSite->id,
            'runtime_state' => 'healthy',
            'last_heartbeat_at' => now()->subMinute(),
            'config' => ['token' => 'runtime-health-secret'],
        ]);

        $this->getJson('/security-devices/runtime-health')->assertUnauthorized();
        $response = $this->actingAs($viewer)->getJson('/security-devices/runtime-health');

        $response->assertOk()
            ->assertJsonStructure([
                'state',
                'workers',
                'queues' => ['events', 'checks', 'discovery', 'provider', 'topology', 'maintenance', 'orchestration', 'commands'],
                'listeners',
                'external_heartbeat' => [
                    'state',
                    'reason_code',
                    'last_sent_age_seconds',
                    'last_evaluated_age_seconds',
                    'note',
                ],
                'storage' => ['time_series', 'snapshots'],
                'collectors',
                'observed_at',
            ])
            ->assertJsonMissing(['endpoint', 'url', 'bucket', 'token', 'secret', 'exception']);
        $this->assertStringNotContainsString('runtime-health-secret', $response->getContent());
        $configuredUrl = config('monitoring.storage.timeseries.url');
        if (is_string($configuredUrl) && $configuredUrl !== '') {
            $this->assertStringNotContainsString($configuredUrl, $response->getContent());
        }
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

    /** @param list<string> $additionalPermissions @return array{User, Site, Site} */
    private function siteScopedViewer(array $additionalPermissions): array
    {
        $allowedSite = Site::factory()->create(['name' => 'Allowed Site '.Str::random(5)]);
        $hiddenSite = Site::factory()->create(['name' => 'Hidden Site '.Str::random(5)]);
        $viewer = User::factory()->create(['approved_at' => now()]);
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
