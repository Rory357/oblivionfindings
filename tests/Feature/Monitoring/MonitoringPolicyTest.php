<?php

use App\Domain\Monitoring\Data\ObservationInput;
use App\Domain\Monitoring\Enums\MonitorKind;
use App\Domain\Monitoring\Enums\MonitorState;
use App\Domain\Monitoring\Models\Monitor;
use App\Domain\Monitoring\Models\MonitoringCoverageExpectation;
use App\Domain\Monitoring\Models\MonitoringMaintenanceWindow;
use App\Domain\Monitoring\Models\MonitorObservation;
use App\Domain\Monitoring\Services\CoverageAnalyzer;
use App\Domain\Monitoring\Services\MaintenanceEvaluator;
use App\Domain\Monitoring\Services\MonitoringObservationIngestor;
use App\Domain\Monitoring\Services\MonitoringRollupService;
use App\Domain\Monitoring\Services\MonitorStateMachine;
use App\Domain\SecurityDevices\Enums\AssignmentType;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Domain\SecurityDevices\Models\DeviceEvent;
use App\Models\Site;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    CarbonImmutable::setTestNow('2026-07-23T12:00:00Z');
});

afterEach(function () {
    CarbonImmutable::setTestNow();
});

it('stores explicit policy maintenance dependency and coverage fields', function () {
    expect(Schema::hasColumns('monitoring_profiles', [
        'failure_duration_seconds', 'recovery_duration_seconds', 'rising_threshold',
        'falling_threshold', 'baseline_window_seconds', 'baseline_minimum_samples',
        'baseline_deviation_multiplier', 'maintenance_policy', 'rollup_policy', 'retention_policy_id',
    ]))->toBeTrue()
        ->and(Schema::hasColumns('monitors', [
            'effective_state', 'pending_since_at', 'root_cause_monitor_id',
            'suppression_reason', 'suppressed_at',
        ]))->toBeTrue()
        ->and(Schema::hasTable('monitor_dependencies'))->toBeTrue()
        ->and(Schema::hasTable('monitoring_maintenance_windows'))->toBeTrue()
        ->and(Schema::hasTable('monitoring_coverage_expectations'))->toBeTrue();
});

it('requires consecutive count and duration before failure and recovery transitions', function () {
    [$site, $monitor] = policyMonitor([
        'current_state' => MonitorState::Healthy,
        'effective_state' => MonitorState::Healthy,
    ], [
        'failure_confirmations' => 2,
        'failure_duration_seconds' => 60,
        'recovery_confirmations' => 2,
        'recovery_duration_seconds' => 30,
    ]);
    $ingestor = app(MonitoringObservationIngestor::class);

    $first = $ingestor->ingest($monitor, policyObservation('fail-1', MonitorState::Failed, '12:00:00'), $site->id, $monitor->device_id, null);
    $second = $ingestor->ingest($monitor->fresh(), policyObservation('fail-2', MonitorState::Failed, '12:00:30'), $site->id, $monitor->device_id, null);
    $third = $ingestor->ingest($monitor->fresh(), policyObservation('fail-3', MonitorState::Failed, '12:01:00'), $site->id, $monitor->device_id, null);

    expect($first->stateChanged)->toBeFalse()
        ->and($second->stateChanged)->toBeFalse()
        ->and($third->stateChanged)->toBeTrue()
        ->and($monitor->fresh()->current_state)->toBe(MonitorState::Failed)
        ->and($monitor->fresh()->pending_since_at)->toBeNull();

    $firstRecovery = $ingestor->ingest($monitor->fresh(), policyObservation('up-1', MonitorState::Healthy, '12:02:00'), $site->id, $monitor->device_id, null);
    $secondRecovery = $ingestor->ingest($monitor->fresh(), policyObservation('up-2', MonitorState::Healthy, '12:02:30'), $site->id, $monitor->device_id, null);

    expect($firstRecovery->stateChanged)->toBeFalse()
        ->and($secondRecovery->stateChanged)->toBeTrue()
        ->and($monitor->fresh()->current_state)->toBe(MonitorState::Healthy);
});

it('applies fixed and baseline hysteresis while stale or unknown never improves a failure', function () {
    [$site, $monitor] = policyMonitor([
        'current_state' => MonitorState::Healthy,
        'effective_state' => MonitorState::Healthy,
    ], [
        'failure_confirmations' => 1,
        'recovery_confirmations' => 1,
        'rising_threshold' => 80,
        'falling_threshold' => 70,
    ]);
    $machine = app(MonitorStateMachine::class);

    $rising = $machine->decide($monitor, policyObservation('rise', MonitorState::Healthy, '12:00:00', 90));
    $monitor->forceFill(['current_state' => MonitorState::Failed]);
    $held = $machine->decide($monitor, policyObservation('hold', MonitorState::Healthy, '12:00:01', 75));
    $falling = $machine->decide($monitor, policyObservation('fall', MonitorState::Healthy, '12:00:02', 65));
    $unknown = $machine->decide($monitor, policyObservation('unknown', MonitorState::Unknown, '12:00:03'));

    expect($rising->reportedState)->toBe(MonitorState::Failed)
        ->and($rising->reason)->toBe('rising_threshold_exceeded')
        ->and($held->reportedState)->toBe(MonitorState::Failed)
        ->and($held->stateChanged)->toBeFalse()
        ->and($falling->reportedState)->toBe(MonitorState::Healthy)
        ->and($unknown->reportedState)->toBe(MonitorState::Failed)
        ->and($unknown->reason)->toBe('uncertain_state_cannot_improve_failure');

    $monitor->profile->forceFill([
        'rising_threshold' => null,
        'falling_threshold' => null,
        'baseline_window_seconds' => 3600,
        'baseline_minimum_samples' => 3,
        'baseline_deviation_multiplier' => 2,
    ])->save();
    foreach ([9, 10, 11] as $index => $value) {
        MonitorObservation::factory()->create([
            'monitor_id' => $monitor->id,
            'source_key' => "baseline-{$index}",
            'state' => MonitorState::Healthy,
            'value' => $value,
            'observed_at' => now()->subMinutes(3 - $index),
        ]);
    }
    $monitor->forceFill(['current_state' => MonitorState::Healthy])->save();
    $baseline = $machine->decide($monitor->fresh('profile'), policyObservation('baseline-outlier', MonitorState::Healthy, '12:00:04', 30));

    expect($baseline->reportedState)->toBe(MonitorState::Degraded)
        ->and($baseline->reason)->toBe('baseline_deviation')
        ->and($baseline->evidence['sample_count'])->toBe(3)
        ->and($baseline->evidence['upper_bound'])->toBeLessThan(30);
});

it('suppresses during active one off or recurring maintenance and emits the root failure after maintenance ends', function () {
    [$site, $monitor] = policyMonitor([
        'current_state' => MonitorState::Healthy,
        'effective_state' => MonitorState::Healthy,
        'affects_availability' => true,
    ], [
        'failure_confirmations' => 1,
        'maintenance_policy' => 'suppress_notifications_and_ticketing',
    ]);
    $window = MonitoringMaintenanceWindow::query()->create([
        'site_id' => $site->id,
        'monitor_id' => $monitor->id,
        'name' => 'Network change',
        'starts_at' => now()->subMinute(),
        'ends_at' => now()->addMinute(),
        'recurrence' => null,
        'recurrence_until' => null,
        'policy' => 'suppress_notifications_and_ticketing',
        'status' => 'active',
        'reason' => 'approved_change',
    ]);

    $during = app(MonitoringObservationIngestor::class)->ingest(
        $monitor,
        policyObservation('maint-fail', MonitorState::Failed, '12:00:00'),
        $site->id,
        $monitor->device_id,
        null,
    );

    expect($during->stateChanged)->toBeTrue()
        ->and($monitor->fresh()->current_state)->toBe(MonitorState::Failed)
        ->and($monitor->fresh()->effective_state)->toBe(MonitorState::Suppressed)
        ->and($monitor->fresh()->suppression_reason)->toBe('maintenance')
        ->and(DeviceEvent::query()->where('device_id', $monitor->device_id)->count())->toBe(0)
        ->and(app(MaintenanceEvaluator::class)->activeWindow($monitor->fresh(), now()))->not->toBeNull();

    CarbonImmutable::setTestNow('2026-07-23T12:02:00Z');
    $after = app(MonitoringObservationIngestor::class)->ingest(
        $monitor->fresh(),
        policyObservation('maint-ended-fail', MonitorState::Failed, '12:02:00'),
        $site->id,
        $monitor->device_id,
        null,
    );

    expect($after->stateChanged)->toBeTrue()
        ->and($monitor->fresh()->effective_state)->toBe(MonitorState::Failed)
        ->and($monitor->fresh()->suppression_reason)->toBeNull()
        ->and(DeviceEvent::query()->where('device_id', $monitor->device_id)->where('event_type', 'offline')->count())->toBe(1);

    $window->forceFill([
        'starts_at' => now()->subWeek()->startOfDay()->addHours(12),
        'ends_at' => now()->subWeek()->startOfDay()->addHours(13),
        'recurrence' => 'weekly',
        'recurrence_until' => now()->addWeeks(4),
    ])->save();
    expect(app(MaintenanceEvaluator::class)->activeWindow($monitor->fresh(), now()->startOfDay()->addMinutes(30 + 12 * 60)))
        ->not->toBeNull();
});

it('classifies coverage with evidence and rolls devices Sites and the estate honestly', function () {
    $site = policySite();
    $device = policyDevice($site, ['domain' => 'it_infrastructure', 'category' => 'network']);
    $healthy = Monitor::factory()->create([
        'device_id' => $device->id,
        'kind' => MonitorKind::Icmp,
        'current_state' => MonitorState::Healthy,
        'effective_state' => MonitorState::Healthy,
        'last_observation_at' => now(),
    ]);
    $paused = Monitor::factory()->create([
        'device_id' => $device->id,
        'kind' => MonitorKind::Tcp,
        'is_enabled' => false,
    ]);
    $failedCollection = Monitor::factory()->create([
        'device_id' => $device->id,
        'kind' => MonitorKind::Snmp,
        'current_state' => MonitorState::Failed,
        'effective_state' => MonitorState::Failed,
        'last_observation_at' => now(),
    ]);
    MonitorObservation::factory()->create([
        'monitor_id' => $failedCollection->id,
        'state' => MonitorState::Failed,
        'message' => 'snmp_transport_unavailable',
    ]);
    foreach ([
        ['reachability', MonitorKind::Icmp, 'supported'],
        ['dns_resolution', MonitorKind::Dns, 'supported'],
        ['web_endpoint', MonitorKind::Http, 'unsupported'],
        ['service_port', MonitorKind::Tcp, 'supported'],
        ['snmp_inventory', MonitorKind::Snmp, 'supported'],
    ] as [$capability, $kind, $support]) {
        MonitoringCoverageExpectation::query()->create([
            'site_id' => $site->id,
            'device_domain' => 'it_infrastructure',
            'device_category' => 'network',
            'capability' => $capability,
            'monitor_kind' => $kind,
            'minimum_count' => 1,
            'support_status' => $support,
            'support_evidence' => ['source' => 'device_class_policy'],
            'is_active' => true,
        ]);
    }

    $coverage = app(CoverageAnalyzer::class)->analyze($device)->keyBy('capability');

    expect($coverage['reachability']->status)->toBe('covered')
        ->and($coverage['dns_resolution']->status)->toBe('missing')
        ->and($coverage['web_endpoint']->status)->toBe('unsupported')
        ->and($coverage['service_port']->status)->toBe('paused')
        ->and($coverage['snmp_inventory']->status)->toBe('collection_failed')
        ->and($coverage['snmp_inventory']->evidence['reason_code'])->toBe('snmp_transport_unavailable');

    $rollups = app(MonitoringRollupService::class);
    expect($rollups->device($device, now())['state'])->toBe(MonitorState::Failed)
        ->and($rollups->site($site, now())['state'])->toBe(MonitorState::Failed)
        ->and($rollups->estate(now())['state'])->toBe(MonitorState::Failed);

    $failedCollection->forceFill(['current_state' => MonitorState::NotApplicable, 'effective_state' => MonitorState::NotApplicable])->save();
    $healthy->forceFill([
        'current_state' => MonitorState::Unknown,
        'effective_state' => MonitorState::Unknown,
        'last_observation_at' => now()->subHour(),
    ])->save();

    expect($rollups->device($device->fresh(), now())['state'])->toBe(MonitorState::Stale)
        ->not->toBe(MonitorState::Healthy);
});

/** @return array{Site, Monitor} */
function policyMonitor(array $monitorAttributes = [], array $profileAttributes = []): array
{
    $site = policySite();
    $device = policyDevice($site);
    $monitor = Monitor::factory()->create([
        'device_id' => $device->id,
        ...$monitorAttributes,
    ]);
    $monitor->profile->forceFill($profileAttributes)->save();

    return [$site, $monitor->fresh('profile')];
}

function policySite(): Site
{
    return Site::factory()->create([
        'is_active' => true,
        'archived' => false,
        'archived_at' => null,
    ]);
}

/** @param array<string, mixed> $attributes */
function policyDevice(Site $site, array $attributes = []): Device
{
    $device = Device::factory()->itInfrastructure()->create($attributes);
    DeviceAssignment::query()->create([
        'device_id' => $device->id,
        'assignable_type' => DeviceAssignment::TARGET_SITE,
        'assignable_id' => $site->id,
        'assignment_type' => AssignmentType::Permanent,
        'assigned_at' => now()->subMinute(),
        'released_at' => null,
    ]);

    return $device;
}

function policyObservation(
    string $key,
    MonitorState $state,
    string $time,
    int|float|null $value = null,
): ObservationInput {
    return new ObservationInput(
        sourceKey: $key,
        state: $state,
        observedAt: CarbonImmutable::parse("2026-07-23T{$time}Z"),
        value: $value,
        unit: $value === null ? null : 'percent',
        message: $state === MonitorState::Failed ? 'probe_failed' : 'probe_ok',
    );
}
