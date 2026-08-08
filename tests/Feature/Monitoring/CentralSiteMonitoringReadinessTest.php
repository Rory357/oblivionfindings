<?php

use App\Domain\Monitoring\Discovery\Models\DiscoveryRun;
use App\Domain\Monitoring\Discovery\Models\DiscoveryScope;
use App\Domain\Monitoring\Enums\MonitorState;
use App\Domain\Monitoring\Models\Monitor;
use App\Domain\Monitoring\Models\MonitoringCollector;
use App\Domain\Monitoring\Models\MonitoringProfile;
use App\Domain\Monitoring\Models\MonitoringRuntimeHeartbeat;
use App\Domain\Monitoring\Models\MonitorObservation;
use App\Domain\Monitoring\Services\CentralSiteMonitoringReadinessService;
use App\Domain\Monitoring\Topology\Models\TopologySnapshot;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Models\Site;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Str;
use Symfony\Component\Console\Command\Command;

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

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-07-27T12:00:00Z');
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

it('reports Site-specific direct path proof without exposing network or credential evidence', function () {
    $site = Site::factory()->create(['name' => 'Direct Proof Site']);
    $device = Device::factory()->itInfrastructure()->create(['name' => 'Proof gateway']);
    DeviceAssignment::create([
        'device_id' => $device->id,
        'assignable_type' => DeviceAssignment::TARGET_SITE,
        'assignable_id' => $site->id,
        'assigned_at' => now()->subHour(),
    ]);
    $profile = MonitoringProfile::factory()->create([
        'stale_after_seconds' => 300,
        'is_active' => true,
    ]);
    $monitor = Monitor::factory()->create([
        'device_id' => $device->id,
        'profile_id' => $profile->id,
        'name' => 'Direct WAN HTTPS',
        'target' => 'https://10.44.1.1/private-health',
        'config' => ['credential_reference' => 'vault-secret-reference'],
        'current_state' => MonitorState::Healthy,
        'effective_state' => MonitorState::Healthy,
        'last_observation_at' => now()->subMinute(),
    ])->load('profile');
    TopologySnapshot::factory()->create([
        'site_id' => $site->id,
        'source' => 'native:snmp',
        'captured_at' => now()->subMinutes(2),
        'node_count' => 2,
        'edge_count' => 1,
    ]);
    $scope = DiscoveryScope::factory()->create([
        'site_id' => $site->id,
        'collector_id' => null,
        'cidrs' => ['10.44.0.0/16'],
        'seed_hosts' => ['10.44.1.1'],
        'status' => 'active',
    ]);
    DiscoveryRun::factory()->create([
        'discovery_scope_id' => $scope->id,
        'status' => 'completed',
        'completed_at' => now()->subMinutes(3),
    ]);
    $workers = collect(CentralSiteMonitoringReadinessService::REQUIRED_WORKERS)
        ->mapWithKeys(fn (string $component): array => [$component => ['state' => 'available']])
        ->all();

    $withoutDurableEvidence = app(CentralSiteMonitoringReadinessService::class)->assess(
        collect([$site]),
        collect([$device]),
        collect([$monitor]),
        collect([$device->id => $site->id]),
        $workers,
    )->sole();
    expect($withoutDurableEvidence['state'])->toBe('awaiting_evidence')
        ->and($withoutDurableEvidence['proof_state'])->toBe('not_verified')
        ->and($withoutDurableEvidence['fresh'])->toBe(0)
        ->and($withoutDurableEvidence['durable_direct_evidence'])->toBe(0);

    MonitorObservation::factory()->create([
        'monitor_id' => $monitor->id,
        'source_key' => "provider:{$monitor->id}:not-central-proof",
        'state' => MonitorState::Healthy,
        'observed_at' => now()->subSeconds(50),
    ]);
    expect(app(CentralSiteMonitoringReadinessService::class)->assess(
        collect([$site]),
        collect([$device]),
        collect([$monitor]),
        collect([$device->id => $site->id]),
        $workers,
    )->sole()['proof_state'])->toBe('not_verified');

    MonitorObservation::factory()->create([
        'monitor_id' => $monitor->id,
        'source_key' => "runtime:{$monitor->id}:direct-proof",
        'state' => MonitorState::Healthy,
        'observed_at' => now()->subMinute(),
    ]);
    $report = app(CentralSiteMonitoringReadinessService::class)->assess(
        collect([$site]),
        collect([$device]),
        collect([$monitor]),
        collect([$device->id => $site->id]),
        $workers,
    )->sole();

    expect($report)->toMatchArray([
        'state' => 'ready',
        'proof_state' => 'verified',
        'direct_monitors' => 1,
        'direct_devices' => 1,
        'durable_direct_evidence' => 1,
        'fresh' => 1,
        'stale' => 0,
        'never_observed' => 0,
        'attention' => 0,
    ])->and($report['runtime']['state'])->toBe('available')
        ->and($report['runtime']['available'])->toBe(4)
        ->and($report['runtime']['required'])->toBe(4)
        ->and($report['oldest_evidence_at'])->toBe($report['evidence_at'])
        ->and($report['direct_monitor_fingerprint'])->toMatch('/\A[a-f0-9]{64}\z/')
        ->and($report['topology']['state'])->toBe('current')
        ->and($report['topology']['node_count'])->toBe(2)
        ->and($report['topology']['edge_count'])->toBe(1)
        ->and($report['discovery']['state'])->toBe('current')
        ->and($report['discovery']['scopes'])->toBe(1)
        ->and(json_encode($report, JSON_THROW_ON_ERROR))
        ->not->toContain('10.44.1.1')
        ->not->toContain('vault-secret-reference')
        ->not->toContain('10.44.0.0/16');

    $neverObservedMonitor = Monitor::factory()->create([
        'device_id' => $device->id,
        'profile_id' => $profile->id,
        'current_state' => MonitorState::Unknown,
        'effective_state' => MonitorState::Unknown,
        'last_observation_at' => null,
    ])->load('profile');
    $partialEvidence = app(CentralSiteMonitoringReadinessService::class)->assess(
        collect([$site]),
        collect([$device]),
        collect([$monitor, $neverObservedMonitor]),
        collect([$device->id => $site->id]),
        $workers,
    )->sole();
    expect($partialEvidence)->toMatchArray([
        'state' => 'awaiting_evidence',
        'proof_state' => 'not_verified',
        'direct_monitors' => 2,
        'durable_direct_evidence' => 1,
        'fresh' => 1,
        'stale' => 0,
        'never_observed' => 1,
    ])->and($partialEvidence['direct_monitor_fingerprint'])->not->toBe($report['direct_monitor_fingerprint']);

    TopologySnapshot::factory()->create([
        'site_id' => $site->id,
        'source' => 'native:snmp',
        'captured_at' => now()->subHours(2),
        'node_count' => 2,
        'edge_count' => 1,
    ]);
    $incompleteTopology = app(CentralSiteMonitoringReadinessService::class)->assess(
        collect([$site]),
        collect([$device]),
        collect([$monitor]),
        collect([$device->id => $site->id]),
        $workers,
    )->sole();
    expect($incompleteTopology['state'])->toBe('evidence_incomplete')
        ->and($incompleteTopology['proof_state'])->toBe('not_verified');
    TopologySnapshot::factory()->create([
        'site_id' => $site->id,
        'source' => 'native:snmp',
        'captured_at' => now()->subMinutes(2),
        'node_count' => 2,
        'edge_count' => 1,
    ]);

    DiscoveryRun::factory()->create([
        'discovery_scope_id' => $scope->id,
        'status' => 'completed',
        'completed_at' => now()->subDays(2),
    ]);
    $incompleteDiscovery = app(CentralSiteMonitoringReadinessService::class)->assess(
        collect([$site]),
        collect([$device]),
        collect([$monitor]),
        collect([$device->id => $site->id]),
        $workers,
    )->sole();
    expect($incompleteDiscovery['state'])->toBe('evidence_incomplete')
        ->and($incompleteDiscovery['proof_state'])->toBe('not_verified');
    DiscoveryRun::factory()->create([
        'discovery_scope_id' => $scope->id,
        'status' => 'completed',
        'completed_at' => now()->subMinutes(3),
    ]);

    $monitor->forceFill([
        'current_state' => MonitorState::Failed,
        'effective_state' => MonitorState::Failed,
    ])->save();
    $attention = app(CentralSiteMonitoringReadinessService::class)->assess(
        collect([$site]),
        collect([$device]),
        collect([$monitor->fresh()->load('profile')]),
        collect([$device->id => $site->id]),
        $workers,
    )->sole();
    expect($attention['state'])->toBe('attention')
        ->and($attention['proof_state'])->toBe('verified')
        ->and($attention['attention'])->toBe(1);

    $workers['checks']['state'] = 'stale';
    $runtimeUnavailable = app(CentralSiteMonitoringReadinessService::class)->assess(
        collect([$site]),
        collect([$device]),
        collect([$monitor->fresh()->load('profile')]),
        collect([$device->id => $site->id]),
        $workers,
    )->sole();
    expect($runtimeUnavailable['state'])->toBe('runtime_unavailable')
        ->and($runtimeUnavailable['proof_state'])->toBe('not_verified');
});

it('distinguishes collector-only and unconfigured Sites', function () {
    $collectorOnly = Site::factory()->create(['name' => 'Collector Only Site']);
    $unconfigured = Site::factory()->create(['name' => 'Unconfigured Site']);
    $device = Device::factory()->itInfrastructure()->create();
    $collector = MonitoringCollector::factory()->create([
        'site_id' => $collectorOnly->id,
    ]);
    $monitor = Monitor::factory()->create([
        'device_id' => $device->id,
        'collector_id' => $collector->id,
        'last_observation_at' => now()->subMinute(),
    ])->load('profile');
    $workers = collect(CentralSiteMonitoringReadinessService::REQUIRED_WORKERS)
        ->mapWithKeys(fn (string $component): array => [$component => ['state' => 'available']])
        ->all();

    $reports = app(CentralSiteMonitoringReadinessService::class)->assess(
        collect([$collectorOnly, $unconfigured]),
        collect([$device]),
        collect([$monitor]),
        collect([$device->id => $collectorOnly->id]),
        $workers,
    )->keyBy('state');

    expect($reports)->toHaveKeys(['remote_only', 'not_configured'])
        ->and($reports['remote_only']['proof_state'])->toBe('not_verified')
        ->and($reports['not_configured']['direct_monitors'])->toBe(0);
});

it('provides a failing or passing read-only deployment gate for one Site', function () {
    $site = Site::factory()->create(['name' => 'Release Gate Site']);
    $device = Device::factory()->itInfrastructure()->create();
    DeviceAssignment::create([
        'device_id' => $device->id,
        'assignable_type' => DeviceAssignment::TARGET_SITE,
        'assignable_id' => $site->id,
        'assigned_at' => now()->subHour(),
    ]);
    $monitor = Monitor::factory()->create([
        'device_id' => $device->id,
        'target' => '10.99.0.1',
        'config' => ['password' => 'never-print-command-secret'],
        'current_state' => MonitorState::Healthy,
        'effective_state' => MonitorState::Healthy,
        'last_observation_at' => now()->subMinute(),
    ]);
    MonitorObservation::factory()->create([
        'monitor_id' => $monitor->id,
        'source_key' => "runtime:{$monitor->id}:release-gate",
        'state' => MonitorState::Healthy,
        'observed_at' => now()->subMinute(),
    ]);
    TopologySnapshot::factory()->create([
        'site_id' => $site->id,
        'source' => 'native:snmp',
        'captured_at' => now()->subMinute(),
    ]);
    $scope = DiscoveryScope::factory()->create([
        'site_id' => $site->id,
        'collector_id' => null,
        'status' => 'active',
    ]);
    DiscoveryRun::factory()->create([
        'discovery_scope_id' => $scope->id,
        'status' => 'completed',
        'completed_at' => now()->subMinute(),
    ]);

    $this->artisan('monitoring:central-site-readiness', [
        'site' => $site->id,
        '--json' => true,
    ])->assertFailed();

    foreach (CentralSiteMonitoringReadinessService::REQUIRED_WORKERS as $component) {
        MonitoringRuntimeHeartbeat::create([
            'component' => $component,
            'queue' => "monitoring-{$component}",
            'last_dispatched_token' => (string) Str::uuid(),
            'last_dispatched_at' => now()->subSeconds(20),
            'last_consumed_token' => (string) Str::uuid(),
            'last_consumed_dispatch_at' => now()->subSeconds(20),
            'last_consumed_at' => now()->subSeconds(10),
        ]);
    }

    $result = $this->artisan('monitoring:central-site-readiness', [
        'site' => $site->id,
        '--json' => true,
    ])->expectsOutputToContain('"all_sites_verified":true')
        ->doesntExpectOutputToContain('10.99.0.1')
        ->doesntExpectOutputToContain('never-print-command-secret')
        ->assertSuccessful();

    expect($result)->not->toBeNull();
});

it('rejects a non-positive Site identifier before querying readiness', function () {
    $this->artisan('monitoring:central-site-readiness', [
        'site' => 0,
    ])->expectsOutput('The Site ID must be a positive integer.')
        ->assertExitCode(Command::INVALID);
});
