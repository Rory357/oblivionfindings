<?php

use App\Domain\Monitoring\Enums\MonitorKind;
use App\Domain\Monitoring\Enums\MonitorState;
use App\Domain\Monitoring\Models\Monitor;
use App\Domain\Monitoring\Models\MonitorDependency;
use App\Domain\Monitoring\Models\MonitoringCoverageExpectation;
use App\Domain\Monitoring\Models\MonitoringMaintenanceWindow;
use App\Domain\Monitoring\Models\MonitorObservation;
use App\Domain\Monitoring\Models\ProviderCapabilityCursor;
use App\Domain\Monitoring\Services\ProtocolPolicyEvidenceService;
use App\Domain\SecurityDevices\Enums\AssignmentType;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Domain\SecurityDevices\Models\DeviceEvent;
use App\Models\Integration\IntegrationProviderConnection;
use App\Models\Integration\IntegrationSiteConfig;
use App\Models\Site;
use App\Services\Integration\Contracts\ObservationCollectionCapability;

it('fails closed with a complete value-free matrix when live evidence is absent', function () {
    $report = app(ProtocolPolicyEvidenceService::class)->report(60);

    expect($report['all_verified'])->toBeFalse()
        ->and($report['protocols'])->toHaveKeys([
            'icmp', 'tcp', 'dns', 'http', 'https', 'tls', 'snmp_v3',
            'snmp_traps', 'syslog', 'flow', 'ssh_read_only', 'winrm_read_only',
            'provider_unifi', 'provider_milesight',
        ])
        ->and($report['policy'])->toHaveKeys([
            'profiles', 'coverage', 'dependencies', 'maintenance', 'confirmation',
            'hysteresis', 'stale_unknown', 'baselines', 'rollups',
        ]);

    $encoded = json_encode($report, JSON_THROW_ON_ERROR);
    expect($encoded)
        ->not->toContain('target', 'payload', 'site_id', 'device_id', 'credential', 'source_key');

    $this->artisan('monitoring:protocol-policy-evidence', [
        '--window-minutes' => 60,
        '--json' => true,
    ])->expectsOutputToContain('"all_verified":false')
        ->assertFailed();
});

it('rejects an unsafe unbounded evidence window', function () {
    $this->artisan('monitoring:protocol-policy-evidence', [
        '--window-minutes' => 10_081,
        '--json' => true,
    ])->expectsOutput('window-minutes must be an integer from 5 to 10080.')
        ->assertExitCode(2);
});

it('does not mistake legacy or superseded snmp and an unmonitored expected device for complete evidence', function () {
    $site = Site::factory()->create([
        'is_active' => true,
        'archived' => false,
        'archived_at' => null,
    ]);
    $monitored = Device::factory()->itInfrastructure()->create([
        'domain' => 'it_infrastructure',
        'category' => 'network',
    ]);
    $unmonitored = Device::factory()->itInfrastructure()->create([
        'domain' => 'it_infrastructure',
        'category' => 'network',
    ]);
    foreach ([$monitored, $unmonitored] as $device) {
        DeviceAssignment::query()->create([
            'device_id' => $device->id,
            'assignable_type' => DeviceAssignment::TARGET_SITE,
            'assignable_id' => $site->id,
            'assignment_type' => AssignmentType::Permanent,
            'assigned_at' => now()->subMinute(),
        ]);
    }

    $legacySnmp = Monitor::factory()->create([
        'device_id' => $monitored->id,
        'kind' => MonitorKind::Snmp,
        'config' => [],
        'current_state' => MonitorState::Healthy,
        'effective_state' => MonitorState::Healthy,
        'last_observation_at' => now(),
    ]);
    MonitorObservation::factory()->create([
        'monitor_id' => $legacySnmp->id,
        'state' => MonitorState::Healthy,
        'observed_at' => now(),
    ]);
    $unknownV3 = Monitor::factory()->create([
        'device_id' => $monitored->id,
        'kind' => MonitorKind::Snmp,
        'config' => ['version' => 'v3'],
        'current_state' => MonitorState::Unknown,
        'effective_state' => MonitorState::Unknown,
        'last_observation_at' => now(),
    ]);
    MonitorObservation::factory()->create([
        'monitor_id' => $unknownV3->id,
        'state' => MonitorState::Healthy,
        'observed_at' => now()->subMinutes(2),
    ]);
    MonitorObservation::factory()->create([
        'monitor_id' => $unknownV3->id,
        'state' => MonitorState::Unknown,
        'observed_at' => now(),
    ]);
    MonitoringCoverageExpectation::query()->create([
        'site_id' => $site->id,
        'device_domain' => 'it_infrastructure',
        'device_category' => 'network',
        'capability' => 'snmp_inventory',
        'monitor_kind' => MonitorKind::Snmp,
        'minimum_count' => 1,
        'support_status' => 'supported',
        'support_evidence' => ['source' => 'device_class_policy'],
        'is_active' => true,
    ]);

    $report = app(ProtocolPolicyEvidenceService::class)->report(60);

    expect($report['protocols']['snmp_v3'])
        ->toMatchArray([
            'state' => 'not_verified',
            'configured' => 1,
            'fresh' => 0,
        ])
        ->and($report['policy']['coverage']['state'])->toBe('not_verified')
        ->and($report['policy']['coverage']['applicable_devices'])->toBe(2)
        ->and($report['policy']['coverage']['covered'])->toBe(1)
        ->and($report['policy']['coverage']['missing'])->toBe(1)
        ->and($report['policy']['coverage']['gaps'])->toBe(1)
        ->and($report['execution_cursor'])->toMatch('/\A[a-f0-9]{64}\z/');

    $unchanged = app(ProtocolPolicyEvidenceService::class)->report(60);
    expect($unchanged['execution_cursor'])->toBe($report['execution_cursor']);

    MonitorObservation::factory()->create([
        'monitor_id' => $legacySnmp->id,
        'state' => MonitorState::Healthy,
        'observed_at' => now(),
    ]);
    $advanced = app(ProtocolPolicyEvidenceService::class)->report(60);
    expect($advanced['execution_cursor'])->not->toBe($report['execution_cursor']);
});

it('requires fresh provider monitor evidence at every mapped site', function () {
    $sites = collect(range(1, 2))->map(fn (int $index): Site => Site::factory()->create([
        'name' => "Provider evidence site {$index}",
        'is_active' => true,
        'archived' => false,
        'archived_at' => null,
    ]));
    IntegrationProviderConnection::query()->create([
        'provider' => 'unifi',
        'secret_encrypted' => 'encrypted-provider-evidence-secret',
        'secret_last4' => 'cret',
        'status' => IntegrationProviderConnection::STATUS_CONNECTED,
        'requires_credential_replacement' => false,
    ]);
    foreach ($sites as $index => $site) {
        IntegrationSiteConfig::query()->create([
            'site_id' => $site->id,
            'provider' => 'unifi',
            'status' => IntegrationSiteConfig::STATUS_HYBRID,
            'mapped_external_site_id' => "provider-site-{$index}",
            'mapped_external_site_name' => "Provider Site {$index}",
            'is_active' => true,
        ]);
        ProviderCapabilityCursor::query()->create([
            'site_id' => $site->id,
            'provider' => 'unifi',
            'capability' => ObservationCollectionCapability::class,
            'cursor' => "provider-cursor-{$index}",
            'last_started_at' => now()->subMinutes(2),
            'last_completed_at' => now()->subMinute(),
        ]);
    }

    $createFreshMonitor = function (Site $site): void {
        $device = Device::factory()->itInfrastructure()->create(['provider' => 'unifi']);
        DeviceAssignment::query()->create([
            'device_id' => $device->id,
            'assignable_type' => DeviceAssignment::TARGET_SITE,
            'assignable_id' => $site->id,
            'assignment_type' => AssignmentType::Permanent,
            'assigned_at' => now()->subMinutes(5),
        ]);
        $monitor = Monitor::factory()->create([
            'device_id' => $device->id,
            'kind' => MonitorKind::Provider,
            'config' => ['provider' => 'unifi', 'collection' => 'device_status'],
            'current_state' => MonitorState::Healthy,
            'effective_state' => MonitorState::Healthy,
            'last_observation_at' => now(),
        ]);
        MonitorObservation::factory()->create([
            'monitor_id' => $monitor->id,
            'state' => MonitorState::Healthy,
            'observed_at' => now(),
        ]);
    };

    $createFreshMonitor($sites->first());
    $partial = app(ProtocolPolicyEvidenceService::class)->report(60)['protocols']['provider_unifi'];

    expect($partial)->toMatchArray([
        'state' => 'not_verified',
        'mapped_sites' => 2,
        'completed_sites' => 2,
        'configured' => 1,
        'fresh' => 1,
        'fresh_sites' => 1,
    ]);

    $createFreshMonitor($sites->last());
    $complete = app(ProtocolPolicyEvidenceService::class)->report(60)['protocols']['provider_unifi'];

    expect($complete)->toMatchArray([
        'state' => 'verified',
        'mapped_sites' => 2,
        'completed_sites' => 2,
        'configured' => 2,
        'fresh' => 2,
        'fresh_sites' => 2,
    ]);
});

it('requires current suppression and recovery evidence to correlate to the exercised monitor', function () {
    $site = Site::factory()->create([
        'is_active' => true,
        'archived' => false,
        'archived_at' => null,
    ]);
    $monitors = collect(range(1, 3))->map(function () use ($site): Monitor {
        $device = Device::factory()->itInfrastructure()->create();
        DeviceAssignment::query()->create([
            'device_id' => $device->id,
            'assignable_type' => DeviceAssignment::TARGET_SITE,
            'assignable_id' => $site->id,
            'assignment_type' => AssignmentType::Permanent,
            'assigned_at' => now()->subHour(),
        ]);

        return Monitor::factory()->create([
            'device_id' => $device->id,
            'current_state' => MonitorState::Failed,
            'effective_state' => MonitorState::Failed,
        ]);
    });
    [$upstream, $downstream, $maintenanceTarget] = $monitors;

    MonitorDependency::query()->create([
        'site_id' => $site->id,
        'upstream_monitor_id' => $upstream->id,
        'downstream_monitor_id' => $downstream->id,
        'policy' => MonitorDependency::POLICY_SUPPRESS,
        'source' => 'manual',
        'confidence' => 1,
        'is_active' => true,
    ]);
    $upstream->forceFill([
        'effective_state' => MonitorState::Suppressed,
        'suppression_reason' => 'dependency',
    ])->save();

    MonitoringMaintenanceWindow::query()->create([
        'site_id' => $site->id,
        'monitor_id' => $maintenanceTarget->id,
        'name' => 'Controlled evidence drill',
        'starts_at' => now()->subMinutes(30),
        'ends_at' => now()->addMinutes(30),
        'policy' => 'suppress_notifications_and_ticketing',
        'status' => 'active',
        'reason' => 'release_acceptance',
    ]);
    $downstream->forceFill([
        'effective_state' => MonitorState::Suppressed,
        'suppression_reason' => 'maintenance',
    ])->save();

    foreach ([
        ['online', now()->subMinutes(20)],
        ['offline', now()->subMinutes(10)],
    ] as [$eventType, $occurredAt]) {
        DeviceEvent::query()->create([
            'device_id' => $upstream->device_id,
            'event_type' => $eventType,
            'severity' => 'info',
            'payload' => ['monitor_id' => $upstream->id],
            'source' => 'oblivion_monitoring',
            'occurred_at' => $occurredAt,
        ]);
    }

    $policy = app(ProtocolPolicyEvidenceService::class)->report(60)['policy'];

    expect($policy['dependencies'])
        ->toMatchArray(['state' => 'not_verified', 'observed_suppressions' => 0])
        ->and($policy['maintenance'])
        ->toMatchArray(['state' => 'not_verified', 'observed_suppressions' => 0])
        ->and($policy['confirmation'])
        ->toMatchArray([
            'state' => 'not_verified',
            'confirmed_failures' => 1,
            'confirmed_recoveries' => 0,
        ]);

    $downstream->forceFill([
        'effective_state' => MonitorState::Suppressed,
        'suppression_reason' => 'dependency',
        'suppressed_at' => now()->subHours(2),
    ])->save();
    $maintenanceTarget->forceFill([
        'effective_state' => MonitorState::Suppressed,
        'suppression_reason' => 'maintenance',
        'suppressed_at' => now()->subHours(2),
    ])->save();

    $stalePolicy = app(ProtocolPolicyEvidenceService::class)->report(60)['policy'];

    expect($stalePolicy['dependencies'])
        ->toMatchArray(['state' => 'not_verified', 'observed_suppressions' => 0])
        ->and($stalePolicy['maintenance'])
        ->toMatchArray(['state' => 'not_verified', 'observed_suppressions' => 0]);

    $downstream->forceFill(['suppressed_at' => now()->subMinute()])->save();
    $maintenanceTarget->forceFill(['suppressed_at' => now()->subMinute()])->save();

    $currentPolicy = app(ProtocolPolicyEvidenceService::class)->report(60)['policy'];

    expect($currentPolicy['dependencies'])
        ->toMatchArray(['state' => 'verified', 'observed_suppressions' => 1])
        ->and($currentPolicy['maintenance'])
        ->toMatchArray(['state' => 'verified', 'observed_suppressions' => 1]);
});
