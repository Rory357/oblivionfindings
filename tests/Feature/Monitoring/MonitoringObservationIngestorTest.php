<?php

use App\Domain\Monitoring\Data\ObservationInput;
use App\Domain\Monitoring\Enums\MonitorState;
use App\Domain\Monitoring\Models\Monitor;
use App\Domain\Monitoring\Services\MonitoringObservationIngestor;
use App\Domain\SecurityDevices\Models\DeviceEvent;
use Carbon\CarbonImmutable;

function monitoringObservation(string $sourceKey, MonitorState $state): ObservationInput
{
    return new ObservationInput(
        sourceKey: $sourceKey,
        state: $state,
        observedAt: CarbonImmutable::parse('2026-07-18 04:00:00')->addSeconds(
            abs(crc32($sourceKey)) % 300,
        ),
        latencyMs: $state === MonitorState::Healthy ? 8 : null,
        message: $state->value,
    );
}

it('waits for the configured failure confirmation count before transitioning', function () {
    $monitor = Monitor::factory()->create([
        'current_state' => MonitorState::Healthy,
        'affects_availability' => true,
    ]);
    $monitor->profile->update(['failure_confirmations' => 3]);

    $ingestor = app(MonitoringObservationIngestor::class);
    $first = $ingestor->ingest($monitor, monitoringObservation('fail-1', MonitorState::Failed));
    $second = $ingestor->ingest($monitor->fresh(), monitoringObservation('fail-2', MonitorState::Failed));

    expect($first->stateChanged)->toBeFalse()
        ->and($second->stateChanged)->toBeFalse()
        ->and($monitor->fresh()->current_state)->toBe(MonitorState::Healthy)
        ->and($monitor->fresh()->pending_state)->toBe(MonitorState::Failed)
        ->and($monitor->fresh()->pending_count)->toBe(2)
        ->and(DeviceEvent::where('device_id', $monitor->device_id)->where('event_type', 'offline')->count())->toBe(0);

    $result = $ingestor->ingest($monitor->fresh(), monitoringObservation('fail-3', MonitorState::Failed));

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
    $input = monitoringObservation('same-key', MonitorState::Failed);
    $ingestor = app(MonitoringObservationIngestor::class);

    $first = $ingestor->ingest($monitor, $input);
    $second = $ingestor->ingest($monitor->fresh(), $input);

    expect($first->duplicate)->toBeFalse()
        ->and($second->duplicate)->toBeTrue()
        ->and($second->observation->is($first->observation))->toBeTrue()
        ->and($monitor->observations()->count())->toBe(1)
        ->and($monitor->fresh()->pending_count)->toBe(1);
});

it('moves to unknown immediately without treating missing evidence as healthy', function () {
    $monitor = Monitor::factory()->create([
        'current_state' => MonitorState::Failed,
        'pending_state' => MonitorState::Healthy,
        'pending_count' => 1,
    ]);

    $result = app(MonitoringObservationIngestor::class)->ingest(
        $monitor,
        monitoringObservation('unknown-1', MonitorState::Unknown),
    );

    expect($result->stateChanged)->toBeTrue()
        ->and($monitor->fresh()->current_state)->toBe(MonitorState::Unknown)
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
    $ingestor = app(MonitoringObservationIngestor::class);

    $first = $ingestor->ingest($monitor, monitoringObservation('up-1', MonitorState::Healthy));

    expect($first->stateChanged)->toBeFalse()
        ->and($monitor->fresh()->current_state)->toBe(MonitorState::Failed)
        ->and(DeviceEvent::where('device_id', $monitor->device_id)->where('event_type', 'online')->count())->toBe(0);

    $result = $ingestor->ingest($monitor->fresh(), monitoringObservation('up-2', MonitorState::Healthy));

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

    $result = app(MonitoringObservationIngestor::class)->ingest(
        $monitor,
        monitoringObservation('latency-fail', MonitorState::Failed),
    );

    expect($result->stateChanged)->toBeTrue()
        ->and($monitor->fresh()->current_state)->toBe(MonitorState::Failed)
        ->and($result->deviceEvent)->toBeNull()
        ->and(DeviceEvent::where('device_id', $monitor->device_id)->count())->toBe(0);
});
