<?php

use App\Domain\Monitoring\Data\ObservationInput;
use App\Domain\Monitoring\Enums\MonitorState;
use App\Domain\Monitoring\Exceptions\RuntimeScopeViolation;
use App\Domain\Monitoring\Exceptions\RuntimeSiteScopeViolation;
use App\Domain\Monitoring\Models\Monitor;
use App\Domain\Monitoring\Models\MonitoringCollector;
use App\Domain\Monitoring\Services\MonitoringObservationIngestor;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Domain\SecurityDevices\Models\DeviceEvent;
use App\Models\Site;
use App\Models\SiteRoom;
use Carbon\CarbonImmutable;

function monitoringObservation(
    string $sourceKey,
    MonitorState $state,
    ?CarbonImmutable $observedAt = null,
): ObservationInput {
    return new ObservationInput(
        sourceKey: $sourceKey,
        state: $state,
        observedAt: $observedAt ?? CarbonImmutable::parse('2026-07-18 04:00:00')->addSeconds(
            abs(crc32($sourceKey)) % 300,
        ),
        latencyMs: $state === MonitorState::Healthy ? 8 : null,
        message: $state->value,
    );
}

function assignMonitoringSite(Monitor $monitor): Site
{
    $site = Site::factory()->create();
    DeviceAssignment::create([
        'device_id' => $monitor->device_id,
        'assignable_type' => DeviceAssignment::TARGET_SITE,
        'assignable_id' => $site->id,
        'assignment_type' => 'permanent',
        'assigned_at' => now(),
    ]);

    return $site;
}

it('waits for the configured failure confirmation count before transitioning', function () {
    $monitor = Monitor::factory()->create([
        'current_state' => MonitorState::Healthy,
        'affects_availability' => true,
    ]);
    $monitor->profile->update(['failure_confirmations' => 3]);
    $site = assignMonitoringSite($monitor);
    $observedAt = CarbonImmutable::parse('2026-07-18 04:00:00');

    $ingestor = app(MonitoringObservationIngestor::class);
    $first = $ingestor->ingest(
        $monitor,
        monitoringObservation('fail-1', MonitorState::Failed, $observedAt),
        $site->id,
        $monitor->device_id,
        null,
    );
    $second = $ingestor->ingest(
        $monitor->fresh(),
        monitoringObservation('fail-2', MonitorState::Failed, $observedAt->addSecond()),
        $site->id,
        $monitor->device_id,
        null,
    );

    expect($first->stateChanged)->toBeFalse()
        ->and($second->stateChanged)->toBeFalse()
        ->and($monitor->fresh()->current_state)->toBe(MonitorState::Healthy)
        ->and($monitor->fresh()->pending_state)->toBe(MonitorState::Failed)
        ->and($monitor->fresh()->pending_count)->toBe(2)
        ->and(DeviceEvent::where('device_id', $monitor->device_id)->where('event_type', 'offline')->count())->toBe(0);

    $result = $ingestor->ingest(
        $monitor->fresh(),
        monitoringObservation('fail-3', MonitorState::Failed, $observedAt->addSeconds(2)),
        $site->id,
        $monitor->device_id,
        null,
    );

    expect($result->stateChanged)->toBeTrue()
        ->and($result->from)->toBe(MonitorState::Healthy)
        ->and($result->to)->toBe(MonitorState::Failed)
        ->and($monitor->fresh()->current_state)->toBe(MonitorState::Failed)
        ->and($monitor->fresh()->pending_state)->toBeNull()
        ->and($monitor->fresh()->pending_count)->toBe(0)
        ->and(DeviceEvent::where('device_id', $monitor->device_id)->where('event_type', 'offline')->count())->toBe(1);
});

it('deduplicates a runtime observation without incrementing confirmation', function () {
    $monitor = Monitor::factory()->create(['current_state' => MonitorState::Healthy]);
    $site = assignMonitoringSite($monitor);
    $input = monitoringObservation('same-key', MonitorState::Failed);
    $ingestor = app(MonitoringObservationIngestor::class);

    $first = $ingestor->ingest($monitor, $input, $site->id, $monitor->device_id, null);
    $second = $ingestor->ingest($monitor->fresh(), $input, $site->id, $monitor->device_id, null);

    expect($first->duplicate)->toBeFalse()
        ->and($second->duplicate)->toBeTrue()
        ->and($second->observation->is($first->observation))->toBeTrue()
        ->and($monitor->observations()->count())->toBe(1)
        ->and($monitor->fresh()->pending_count)->toBe(1);
});

it('persists a late observation without regressing current state or emitting a historical event', function () {
    $monitor = Monitor::factory()->create([
        'current_state' => MonitorState::Healthy,
        'effective_state' => MonitorState::Healthy,
        'affects_availability' => true,
    ]);
    $monitor->profile->update([
        'failure_confirmations' => 1,
        'recovery_confirmations' => 1,
    ]);
    $site = assignMonitoringSite($monitor);
    $ingestor = app(MonitoringObservationIngestor::class);
    $newerAt = CarbonImmutable::parse('2026-07-18 04:02:00');

    $newer = $ingestor->ingest(
        $monitor,
        monitoringObservation('newer-failure', MonitorState::Failed, $newerAt),
        $site->id,
        $monitor->device_id,
        null,
    );
    $before = $monitor->fresh();

    $late = $ingestor->ingest(
        $before,
        monitoringObservation('late-recovery', MonitorState::Healthy, $newerAt->subMinute()),
        $site->id,
        $monitor->device_id,
        null,
    );
    $after = $monitor->fresh();

    expect($newer->stateChanged)->toBeTrue()
        ->and($newer->deviceEvent?->event_type)->toBe('offline')
        ->and($late->duplicate)->toBeFalse()
        ->and($late->stateChanged)->toBeFalse()
        ->and($late->from)->toBe(MonitorState::Failed)
        ->and($late->to)->toBe(MonitorState::Failed)
        ->and($late->deviceEvent)->toBeNull()
        ->and($monitor->observations()->count())->toBe(2)
        ->and($late->observation->observed_at->equalTo($newerAt->subMinute()))->toBeTrue()
        ->and($after->current_state)->toBe(MonitorState::Failed)
        ->and($after->effective_state)->toBe(MonitorState::Failed)
        ->and($after->last_observation_at->equalTo($before->last_observation_at))->toBeTrue()
        ->and($after->last_state_changed_at->equalTo($before->last_state_changed_at))->toBeTrue()
        ->and(DeviceEvent::query()->where('device_id', $monitor->device_id)->count())->toBe(1)
        ->and(DeviceEvent::query()->where('device_id', $monitor->device_id)->where('event_type', 'online')->count())->toBe(0);
});

it('keeps an equal-time conflicting observation out of the pending state projection', function () {
    $monitor = Monitor::factory()->create([
        'current_state' => MonitorState::Healthy,
        'effective_state' => MonitorState::Healthy,
        'affects_availability' => true,
    ]);
    $monitor->profile->update(['failure_confirmations' => 3]);
    $site = assignMonitoringSite($monitor);
    $ingestor = app(MonitoringObservationIngestor::class);
    $observedAt = CarbonImmutable::parse('2026-07-18 04:03:00');

    $pending = $ingestor->ingest(
        $monitor,
        monitoringObservation('equal-time-failure', MonitorState::Failed, $observedAt),
        $site->id,
        $monitor->device_id,
        null,
    );
    $before = $monitor->fresh();

    $conflict = $ingestor->ingest(
        $before,
        monitoringObservation('equal-time-healthy', MonitorState::Healthy, $observedAt->addMilliseconds(500)),
        $site->id,
        $monitor->device_id,
        null,
    );
    $after = $monitor->fresh();

    expect($pending->stateChanged)->toBeFalse()
        ->and($before->pending_state)->toBe(MonitorState::Failed)
        ->and($before->pending_count)->toBe(1)
        ->and($conflict->duplicate)->toBeFalse()
        ->and($conflict->stateChanged)->toBeFalse()
        ->and($conflict->from)->toBe(MonitorState::Healthy)
        ->and($conflict->to)->toBe(MonitorState::Healthy)
        ->and($conflict->deviceEvent)->toBeNull()
        ->and($monitor->observations()->count())->toBe(2)
        ->and($conflict->observation->observed_at->equalTo($observedAt))->toBeTrue()
        ->and($after->pending_state)->toBe($before->pending_state)
        ->and($after->pending_count)->toBe($before->pending_count)
        ->and($after->pending_since_at->equalTo($before->pending_since_at))->toBeTrue()
        ->and($after->last_observation_at->equalTo($before->last_observation_at))->toBeTrue()
        ->and(DeviceEvent::query()->where('device_id', $monitor->device_id)->count())->toBe(0);
});

it('preserves suppression and root cause projection for a late observation', function () {
    $rootCause = Monitor::factory()->create([
        'current_state' => MonitorState::Failed,
        'effective_state' => MonitorState::Failed,
    ]);
    $lastObservationAt = CarbonImmutable::parse('2026-07-18 04:04:00');
    $lastStateChangedAt = $lastObservationAt->subMinute();
    $suppressedAt = $lastObservationAt->subSeconds(30);
    $monitor = Monitor::factory()->create([
        'current_state' => MonitorState::Failed,
        'effective_state' => MonitorState::Suppressed,
        'root_cause_monitor_id' => $rootCause->id,
        'suppression_reason' => 'dependency',
        'suppressed_at' => $suppressedAt,
        'last_observation_at' => $lastObservationAt,
        'last_state_changed_at' => $lastStateChangedAt,
    ]);
    $monitor->profile->update(['recovery_confirmations' => 1]);
    $site = assignMonitoringSite($monitor);

    $late = app(MonitoringObservationIngestor::class)->ingest(
        $monitor,
        monitoringObservation('late-suppressed-recovery', MonitorState::Healthy, $lastObservationAt->subMinutes(2)),
        $site->id,
        $monitor->device_id,
        null,
    );
    $after = $monitor->fresh();

    expect($late->duplicate)->toBeFalse()
        ->and($late->stateChanged)->toBeFalse()
        ->and($late->from)->toBe(MonitorState::Suppressed)
        ->and($late->to)->toBe(MonitorState::Suppressed)
        ->and($late->deviceEvent)->toBeNull()
        ->and($monitor->observations()->count())->toBe(1)
        ->and($after->current_state)->toBe(MonitorState::Failed)
        ->and($after->effective_state)->toBe(MonitorState::Suppressed)
        ->and($after->root_cause_monitor_id)->toBe($rootCause->id)
        ->and($after->suppression_reason)->toBe('dependency')
        ->and($after->suppressed_at->equalTo($suppressedAt))->toBeTrue()
        ->and($after->last_observation_at->equalTo($lastObservationAt))->toBeTrue()
        ->and($after->last_state_changed_at->equalTo($lastStateChangedAt))->toBeTrue()
        ->and(DeviceEvent::query()->where('device_id', $monitor->device_id)->count())->toBe(0);
});

it('keeps a confirmed failure when a later observation is unknown', function () {
    $monitor = Monitor::factory()->create([
        'current_state' => MonitorState::Failed,
        'pending_state' => MonitorState::Healthy,
        'pending_count' => 1,
    ]);
    $site = assignMonitoringSite($monitor);

    $result = app(MonitoringObservationIngestor::class)->ingest(
        $monitor,
        monitoringObservation('unknown-1', MonitorState::Unknown),
        $site->id,
        $monitor->device_id,
        null,
    );

    expect($result->stateChanged)->toBeFalse()
        ->and($monitor->fresh()->current_state)->toBe(MonitorState::Failed)
        ->and($monitor->fresh()->pending_state)->toBeNull()
        ->and($monitor->fresh()->pending_count)->toBe(0)
        ->and(DeviceEvent::where('device_id', $monitor->device_id)->count())->toBe(0);
});

it('emits one online event only after confirmed availability recovery', function () {
    $monitor = Monitor::factory()->create([
        'current_state' => MonitorState::Failed,
        'affects_availability' => true,
    ]);
    $monitor->profile->update(['recovery_confirmations' => 2]);
    $site = assignMonitoringSite($monitor);
    $ingestor = app(MonitoringObservationIngestor::class);
    $observedAt = CarbonImmutable::parse('2026-07-18 04:05:00');

    $first = $ingestor->ingest(
        $monitor,
        monitoringObservation('up-1', MonitorState::Healthy, $observedAt),
        $site->id,
        $monitor->device_id,
        null,
    );

    expect($first->stateChanged)->toBeFalse()
        ->and($monitor->fresh()->current_state)->toBe(MonitorState::Failed)
        ->and(DeviceEvent::where('device_id', $monitor->device_id)->where('event_type', 'online')->count())->toBe(0);

    $result = $ingestor->ingest(
        $monitor->fresh(),
        monitoringObservation('up-2', MonitorState::Healthy, $observedAt->addSecond()),
        $site->id,
        $monitor->device_id,
        null,
    );

    expect($result->stateChanged)->toBeTrue()
        ->and($monitor->fresh()->current_state)->toBe(MonitorState::Healthy)
        ->and(DeviceEvent::where('device_id', $monitor->device_id)->where('event_type', 'online')->count())->toBe(1)
        ->and($result->deviceEvent?->payload['monitor_id'])->toBe($monitor->id)
        ->and($result->deviceEvent?->payload['observation_id'])->toBe($result->observation->id);
});

it('does not emit availability events for a performance-only monitor', function () {
    $monitor = Monitor::factory()->create([
        'current_state' => MonitorState::Healthy,
        'affects_availability' => false,
    ]);
    $monitor->profile->update(['failure_confirmations' => 1]);
    $site = assignMonitoringSite($monitor);

    $result = app(MonitoringObservationIngestor::class)->ingest(
        $monitor,
        monitoringObservation('latency-fail', MonitorState::Failed),
        $site->id,
        $monitor->device_id,
        null,
    );

    expect($result->stateChanged)->toBeTrue()
        ->and($monitor->fresh()->current_state)->toBe(MonitorState::Failed)
        ->and($result->deviceEvent)->toBeNull()
        ->and(DeviceEvent::where('device_id', $monitor->device_id)->count())->toBe(0);
});

it('snapshots canonical device site and collector evidence and rejects a wrong site', function () {
    $monitor = Monitor::factory()->create(['current_state' => MonitorState::Healthy]);
    $site = assignMonitoringSite($monitor);
    $wrongSite = Site::factory()->create();
    $collector = MonitoringCollector::factory()->create(['site_id' => $site->id]);
    $monitor->forceFill(['collector_id' => $collector->id])->save();
    $ingestor = app(MonitoringObservationIngestor::class);

    $result = $ingestor->ingest(
        $monitor->fresh(),
        monitoringObservation('canonical-snapshot', MonitorState::Healthy),
        $site->id,
        $monitor->device_id,
        $collector->collector_uuid,
    );

    expect($result->observation->device_id)->toBe($monitor->device_id)
        ->and($result->observation->site_id)->toBe($site->id)
        ->and($result->observation->collector_id)->toBe($collector->id);

    expect(fn () => $ingestor->ingest(
        $monitor->fresh(),
        monitoringObservation('wrong-site', MonitorState::Healthy),
        $wrongSite->id,
        $monitor->device_id,
        $collector->collector_uuid,
    ))->toThrow(RuntimeSiteScopeViolation::class)
        ->and($monitor->observations()->count())->toBe(1);
});

it('snapshots a canonical site inherited through a room assignment', function () {
    $site = Site::factory()->create();
    $room = SiteRoom::create([
        'site_id' => $site->id,
        'name' => 'Network cabinet',
    ]);
    $monitor = Monitor::factory()->create(['current_state' => MonitorState::Healthy]);
    DeviceAssignment::create([
        'device_id' => $monitor->device_id,
        'assignable_type' => DeviceAssignment::TARGET_ROOM,
        'assignable_id' => $room->id,
        'assignment_type' => 'permanent',
        'assigned_at' => now(),
    ]);

    $result = app(MonitoringObservationIngestor::class)->ingest(
        $monitor,
        monitoringObservation('room-site-snapshot', MonitorState::Healthy),
        $site->id,
        $monitor->device_id,
        null,
    );

    expect($result->observation->site_id)->toBe($site->id)
        ->and($result->observation->device_id)->toBe($monitor->device_id);
});

it('revalidates signed device and collector identity against the locked monitor', function () {
    $monitor = Monitor::factory()->create(['current_state' => MonitorState::Healthy]);
    $site = assignMonitoringSite($monitor);
    $collector = MonitoringCollector::factory()->create(['site_id' => $site->id]);
    $monitor->forceFill(['collector_id' => $collector->id])->save();
    $ingestor = app(MonitoringObservationIngestor::class);

    expect(fn () => $ingestor->ingest(
        $monitor->fresh(),
        monitoringObservation('forged-device-after-lock', MonitorState::Healthy),
        $site->id,
        $monitor->device_id + 999,
        $collector->collector_uuid,
    ))->toThrow(RuntimeScopeViolation::class, 'Observation device does not match its canonical monitor.')
        ->and($monitor->observations()->count())->toBe(0);

    expect(fn () => $ingestor->ingest(
        $monitor->fresh(),
        monitoringObservation('forged-collector-after-lock', MonitorState::Healthy),
        $site->id,
        $monitor->device_id,
        fake()->uuid(),
    ))->toThrow(RuntimeScopeViolation::class, 'Observation collector does not match its canonical monitor.')
        ->and($monitor->observations()->count())->toBe(0);
});
