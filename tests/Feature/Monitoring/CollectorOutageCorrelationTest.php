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

it('keeps one root path correlation until the full affected roster returns canonical observations', function () {
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
    $rosterIds = [$first->id, $second->id];
    sort($rosterIds, SORT_NUMERIC);
    $offline = DeviceEvent::query()
        ->where('device_id', $record['collector']->collector_device_id)
        ->where('event_type', 'offline')
        ->sole();

    expect($record['collector']->fresh()->status)->toBe('unavailable')
        ->and($first->fresh()->current_state)->toBe(MonitorState::Healthy)
        ->and($first->fresh()->effective_state)->toBe(MonitorState::Stale)
        ->and($second->fresh()->current_state)->toBe(MonitorState::Failed)
        ->and($second->fresh()->effective_state)->toBe(MonitorState::Stale)
        ->and(DeviceEvent::query()->where('device_id', $record['collector']->collector_device_id)->where('event_type', 'offline')->count())->toBe(1)
        ->and(DeviceEvent::query()->whereIn('device_id', [$firstDevice->id, $secondDevice->id])->count())->toBe(0)
        ->and($offline->payload['affected_monitor_ids'])->toBe($rosterIds)
        ->and($offline->payload['affected_monitor_roster_sha256'])->toBe(CollectorOutageFixture::rosterFingerprint($rosterIds))
        ->and($offline->payload['affected_monitor_count'])->toBe(2)
        ->and($offline->payload['affected_device_count'])->toBe(2);

    $health->recordHeartbeat(
        $record['collector']->fresh(),
        [
            ...CollectorOutageFixture::heartbeat(),
            'state' => 'buffer_full',
            'spool_items' => 2,
            'spool_bytes' => 1024,
            'oldest_spool_item_at' => CarbonImmutable::now('UTC')->subMinute()->format(DATE_ATOM),
            'corrupted_frames' => 1,
            'highest_seen_source_sequence' => 2,
        ],
        CarbonImmutable::now('UTC'),
    );

    expect($record['collector']->fresh()->status)->toBe('unavailable')
        ->and($record['collector']->fresh()->last_recovered_at)->toBeNull()
        ->and($first->fresh()->effective_state)->toBe(MonitorState::Stale)
        ->and($second->fresh()->effective_state)->toBe(MonitorState::Stale)
        ->and(DeviceEvent::query()->where('device_id', $record['collector']->collector_device_id)->where('event_type', 'online')->count())->toBe(0);

    $record['collector']->checkpoint()->update([
        'acknowledged_source_sequence' => 4,
        'highest_seen_source_sequence' => 4,
    ]);
    $record['collector']->forceFill([
        'acknowledged_source_sequence' => 4,
        'highest_seen_source_sequence' => 4,
        'gap_count' => 0,
    ])->save();

    $health->recordHeartbeat(
        $record['collector']->fresh(),
        CollectorOutageFixture::heartbeat(3, 3),
        CarbonImmutable::now('UTC'),
    );

    expect($record['collector']->fresh()->status)->toBe('unavailable')
        ->and($record['collector']->fresh()->last_recovered_at)->toBeNull()
        ->and($first->fresh()->effective_state)->toBe(MonitorState::Stale)
        ->and($second->fresh()->effective_state)->toBe(MonitorState::Stale)
        ->and(DeviceEvent::query()->where('device_id', $record['collector']->collector_device_id)->where('event_type', 'online')->count())->toBe(0)
        ->and(DeviceEvent::query()->where('device_id', $record['collector']->collector_device_id)->where('event_type', 'offline')->count())->toBe(1);

    $health->recordHeartbeat(
        $record['collector']->fresh(),
        CollectorOutageFixture::heartbeat(4, 4),
        CarbonImmutable::now('UTC'),
    );

    expect($record['collector']->fresh()->status)->toBe('unavailable')
        ->and($record['collector']->fresh()->last_recovered_at)->toBeNull()
        ->and($first->fresh()->effective_state)->toBe(MonitorState::Stale)
        ->and($second->fresh()->effective_state)->toBe(MonitorState::Stale)
        ->and(DeviceEvent::query()->where('device_id', $record['collector']->collector_device_id)->where('event_type', 'online')->count())->toBe(0);

    CollectorOutageFixture::canonicalObservation(
        $first,
        $record['collector'],
        5,
        CarbonImmutable::now('UTC')->addSecond(),
    );
    $record['collector']->checkpoint()->update([
        'acknowledged_source_sequence' => 5,
        'highest_seen_source_sequence' => 5,
    ]);
    $record['collector']->forceFill([
        'acknowledged_source_sequence' => 5,
        'highest_seen_source_sequence' => 5,
    ])->save();
    $health->recordHeartbeat(
        $record['collector']->fresh(),
        CollectorOutageFixture::heartbeat(5, 5),
        CarbonImmutable::now('UTC')->addSecond(),
    );

    expect($record['collector']->fresh()->status)->toBe('unavailable')
        ->and($first->fresh()->effective_state)->toBe(MonitorState::Stale)
        ->and($second->fresh()->effective_state)->toBe(MonitorState::Stale)
        ->and(DeviceEvent::query()->where('device_id', $record['collector']->collector_device_id)->where('event_type', 'online')->count())->toBe(0);

    CollectorOutageFixture::canonicalObservation(
        $second,
        $record['collector'],
        6,
        CarbonImmutable::now('UTC')->addSeconds(2),
    );
    $record['collector']->checkpoint()->update([
        'acknowledged_source_sequence' => 6,
        'highest_seen_source_sequence' => 6,
    ]);
    $record['collector']->forceFill([
        'acknowledged_source_sequence' => 6,
        'highest_seen_source_sequence' => 6,
    ])->save();
    $health->recordHeartbeat(
        $record['collector']->fresh(),
        CollectorOutageFixture::heartbeat(6, 6),
        CarbonImmutable::now('UTC')->addSeconds(2),
    );
    $online = DeviceEvent::query()
        ->where('device_id', $record['collector']->collector_device_id)
        ->where('event_type', 'online')
        ->sole();

    expect($record['collector']->fresh()->status)->toBe('online')
        ->and($record['collector']->fresh()->last_recovered_at)->not->toBeNull()
        ->and($first->fresh()->effective_state)->toBe(MonitorState::Healthy)
        ->and($second->fresh()->effective_state)->toBe(MonitorState::Failed)
        ->and(DeviceEvent::query()->where('device_id', $record['collector']->collector_device_id)->where('event_type', 'online')->count())->toBe(1)
        ->and(DeviceEvent::query()->where('device_id', $record['collector']->collector_device_id)->count())->toBe(2)
        ->and($online->payload['affected_monitor_ids'])->toBe($offline->payload['affected_monitor_ids'])
        ->and($online->payload['affected_monitor_roster_sha256'])->toBe($offline->payload['affected_monitor_roster_sha256'])
        ->and($online->payload['affected_monitor_count'])->toBe($offline->payload['affected_monitor_count'])
        ->and($online->payload['affected_device_count'])->toBe($offline->payload['affected_device_count']);
});

it('opens one root path correlation when the collector heartbeat checkpoint lags central state', function () {
    $record = CollectorOutageFixture::enrolled($this);
    $device = CollectorOutageFixture::device($record['site']);
    $monitor = Monitor::factory()->create([
        'device_id' => $device->id,
        'collector_id' => $record['collector']->id,
        'current_state' => MonitorState::Healthy,
        'effective_state' => MonitorState::Healthy,
    ]);
    $record['collector']->checkpoint()->update([
        'acknowledged_source_sequence' => 4,
        'highest_seen_source_sequence' => 4,
    ]);
    $record['collector']->forceFill([
        'acknowledged_source_sequence' => 4,
        'highest_seen_source_sequence' => 4,
        'gap_count' => 0,
    ])->save();

    $health = app(CollectorHealthService::class);
    $health->recordHeartbeat(
        $record['collector']->fresh(),
        CollectorOutageFixture::heartbeat(3, 3),
        CarbonImmutable::now('UTC'),
    );
    $health->recordHeartbeat(
        $record['collector']->fresh(),
        CollectorOutageFixture::heartbeat(3, 3),
        CarbonImmutable::now('UTC'),
    );

    expect($record['collector']->fresh()->status)->toBe('unavailable')
        ->and($monitor->fresh()->effective_state)->toBe(MonitorState::Stale)
        ->and($monitor->fresh()->suppression_reason)->toBe('collector_path')
        ->and(DeviceEvent::query()->where('device_id', $record['collector']->collector_device_id)->where('event_type', 'offline')->count())->toBe(1)
        ->and(DeviceEvent::query()->where('device_id', $device->id)->count())->toBe(0);

    $health->recordHeartbeat(
        $record['collector']->fresh(),
        CollectorOutageFixture::heartbeat(4, 4),
        CarbonImmutable::now('UTC'),
    );

    expect($record['collector']->fresh()->status)->toBe('unavailable')
        ->and($monitor->fresh()->effective_state)->toBe(MonitorState::Stale)
        ->and(DeviceEvent::query()->where('device_id', $record['collector']->collector_device_id)->where('event_type', 'online')->count())->toBe(0);

    CollectorOutageFixture::canonicalObservation(
        $monitor,
        $record['collector'],
        5,
        CarbonImmutable::now('UTC'),
    );
    $record['collector']->checkpoint()->update([
        'acknowledged_source_sequence' => 5,
        'highest_seen_source_sequence' => 5,
    ]);
    $record['collector']->forceFill([
        'acknowledged_source_sequence' => 5,
        'highest_seen_source_sequence' => 5,
    ])->save();
    $health->recordHeartbeat(
        $record['collector']->fresh(),
        CollectorOutageFixture::heartbeat(5, 5),
        CarbonImmutable::now('UTC'),
    );

    expect($record['collector']->fresh()->status)->toBe('unavailable')
        ->and($monitor->fresh()->effective_state)->toBe(MonitorState::Stale)
        ->and(DeviceEvent::query()->where('device_id', $record['collector']->collector_device_id)->where('event_type', 'online')->count())->toBe(0);

    CollectorOutageFixture::canonicalObservation(
        $monitor,
        $record['collector'],
        6,
        CarbonImmutable::now('UTC')->addSecond(),
    );
    $record['collector']->checkpoint()->update([
        'acknowledged_source_sequence' => 6,
        'highest_seen_source_sequence' => 6,
    ]);
    $record['collector']->forceFill([
        'acknowledged_source_sequence' => 6,
        'highest_seen_source_sequence' => 6,
    ])->save();
    $health->recordHeartbeat(
        $record['collector']->fresh(),
        CollectorOutageFixture::heartbeat(6, 6),
        CarbonImmutable::now('UTC')->addSecond(),
    );

    expect($record['collector']->fresh()->status)->toBe('online')
        ->and($monitor->fresh()->effective_state)->toBe(MonitorState::Healthy)
        ->and(DeviceEvent::query()->where('device_id', $record['collector']->collector_device_id)->where('event_type', 'online')->count())->toBe(1)
        ->and(DeviceEvent::query()->where('device_id', $record['collector']->collector_device_id)->count())->toBe(2);
});

it('fails collector recovery closed when the immutable affected roster is :drift', function (string $drift) {
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
    CollectorOutageFixture::canonicalObservation(
        $first,
        $record['collector'],
        1,
        CarbonImmutable::now('UTC')->addSecond(),
    );
    CollectorOutageFixture::canonicalObservation(
        $second,
        $record['collector'],
        2,
        CarbonImmutable::now('UTC')->addSecond(),
    );

    if ($drift === 'disabled') {
        $second->forceFill(['is_enabled' => false])->save();
    } elseif ($drift === 'added') {
        $added = Monitor::factory()->create([
            'device_id' => CollectorOutageFixture::device($record['site'])->id,
            'collector_id' => $record['collector']->id,
            'current_state' => MonitorState::Healthy,
            'effective_state' => MonitorState::Healthy,
        ]);
        CollectorOutageFixture::canonicalObservation(
            $added,
            $record['collector'],
            3,
            CarbonImmutable::now('UTC')->addSecond(),
        );
    } else {
        $second->forceFill(['collector_id' => null])->save();
    }

    $health->recordHeartbeat(
        $record['collector']->fresh(),
        CollectorOutageFixture::heartbeat(),
        CarbonImmutable::now('UTC')->addSecond(),
    );
    $offline = DeviceEvent::query()
        ->where('device_id', $record['collector']->collector_device_id)
        ->where('event_type', 'offline')
        ->sole();

    expect($record['collector']->fresh()->status)->toBe('unavailable')
        ->and($record['collector']->fresh()->last_recovered_at)->toBeNull()
        ->and($first->fresh()->effective_state)->toBe(MonitorState::Stale)
        ->and($second->fresh()->effective_state)->toBe(MonitorState::Stale)
        ->and(DeviceEvent::query()->where('device_id', $record['collector']->collector_device_id)->where('event_type', 'online')->count())->toBe(0)
        ->and(DeviceEvent::query()->where('device_id', $record['collector']->collector_device_id)->where('event_type', 'offline')->count())->toBe(1)
        ->and($offline->payload['affected_monitor_count'])->toBe(2)
        ->and($offline->payload['affected_device_count'])->toBe(2);
})->with([
    'disabled member' => ['disabled'],
    'added enabled member' => ['added'],
    'moved member' => ['moved'],
]);

it('recovers an explicitly pinned empty outage roster without manufacturing affected counts', function () {
    config()->set('monitoring.collector.heartbeat_stale_seconds', 180);
    $record = CollectorOutageFixture::enrolled($this);
    $record['collector']->forceFill([
        'status' => 'online',
        'last_seen_at' => now()->subMinutes(4),
        'last_heartbeat_at' => now()->subMinutes(4),
    ])->save();

    $health = app(CollectorHealthService::class);
    $health->evaluate($record['collector']->fresh(), CarbonImmutable::now('UTC'));
    $offline = DeviceEvent::query()
        ->where('device_id', $record['collector']->collector_device_id)
        ->where('event_type', 'offline')
        ->sole();

    expect($offline->payload['affected_monitor_ids'])->toBe([])
        ->and($offline->payload['affected_monitor_roster_sha256'])->toBe(CollectorOutageFixture::rosterFingerprint([]))
        ->and($offline->payload['affected_monitor_count'])->toBe(0)
        ->and($offline->payload['affected_device_count'])->toBe(0);

    $health->recordHeartbeat(
        $record['collector']->fresh(),
        CollectorOutageFixture::heartbeat(),
        CarbonImmutable::now('UTC')->addSecond(),
    );
    $online = DeviceEvent::query()
        ->where('device_id', $record['collector']->collector_device_id)
        ->where('event_type', 'online')
        ->sole();

    expect($record['collector']->fresh()->status)->toBe('online')
        ->and($online->payload['affected_monitor_ids'])->toBe([])
        ->and($online->payload['affected_monitor_roster_sha256'])->toBe($offline->payload['affected_monitor_roster_sha256'])
        ->and($online->payload['affected_monitor_count'])->toBe(0)
        ->and($online->payload['affected_device_count'])->toBe(0);
});
