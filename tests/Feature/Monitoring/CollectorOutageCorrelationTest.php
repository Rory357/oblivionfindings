<?php

use App\Domain\Monitoring\Enums\MonitorState;
use App\Domain\Monitoring\Models\Monitor;
use App\Domain\Monitoring\Services\CollectorHealthService;
use App\Domain\SecurityDevices\Models\DeviceEvent;
use Carbon\CarbonImmutable;
use Tests\Support\Monitoring\CollectorOutageFixture;

beforeEach(function () {
    CollectorOutageFixture::configure();
});

afterEach(function () {
    CarbonImmutable::setTestNow();
});

it('marks collector-backed data stale and creates one root path correlation until contiguous recovery', function () {
    config()->set('monitoring.collector.heartbeat_stale_seconds', 180);
    $record = CollectorOutageFixture::enrolled($this);
    $firstDevice = CollectorOutageFixture::device($record['site']);
    $secondDevice = CollectorOutageFixture::device($record['site']);
    $first = Monitor::factory()->create([
        'device_id' => $firstDevice->id,
        'collector_id' => $record['collector']->id,
        'current_state' => MonitorState::Healthy,
        'effective_state' => MonitorState::Healthy,
    ]);
    $second = Monitor::factory()->create([
        'device_id' => $secondDevice->id,
        'collector_id' => $record['collector']->id,
        'current_state' => MonitorState::Failed,
        'effective_state' => MonitorState::Failed,
    ]);
    $record['collector']->forceFill([
        'status' => 'online',
        'last_seen_at' => now()->subMinutes(4),
        'last_heartbeat_at' => now()->subMinutes(4),
    ])->save();

    $health = app(CollectorHealthService::class);
    $health->evaluate($record['collector']->fresh(), CarbonImmutable::now('UTC'));
    $health->evaluate($record['collector']->fresh(), CarbonImmutable::now('UTC'));

    expect($record['collector']->fresh()->status)->toBe('unavailable')
        ->and($first->fresh()->current_state)->toBe(MonitorState::Healthy)
        ->and($first->fresh()->effective_state)->toBe(MonitorState::Stale)
        ->and($second->fresh()->current_state)->toBe(MonitorState::Failed)
        ->and($second->fresh()->effective_state)->toBe(MonitorState::Stale)
        ->and(DeviceEvent::query()->where('device_id', $record['collector']->collector_device_id)->where('event_type', 'offline')->count())->toBe(1)
        ->and(DeviceEvent::query()->whereIn('device_id', [$firstDevice->id, $secondDevice->id])->count())->toBe(0);

    $health->recordHeartbeat(
        $record['collector']->fresh(),
        CollectorOutageFixture::heartbeat(),
        CarbonImmutable::now('UTC'),
    );

    expect($record['collector']->fresh()->status)->toBe('online')
        ->and($first->fresh()->effective_state)->toBe(MonitorState::Healthy)
        ->and($second->fresh()->effective_state)->toBe(MonitorState::Failed)
        ->and(DeviceEvent::query()->where('device_id', $record['collector']->collector_device_id)->where('event_type', 'online')->count())->toBe(1)
        ->and(DeviceEvent::query()->where('device_id', $record['collector']->collector_device_id)->count())->toBe(2);
});
