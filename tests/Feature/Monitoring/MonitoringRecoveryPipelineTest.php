<?php

use App\Domain\Monitoring\Models\MonitoringIncidentEvidenceSnapshot;
use App\Domain\Monitoring\Services\MonitoringIssueEpisode;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Domain\SecurityDevices\Models\DeviceEvent;
use App\Jobs\DispatchDeviceEventSignalOutbox;
use App\Jobs\DispatchDeviceMonitoringTicket;
use App\Models\ControlRoom\Device as ControlRoomDevice;
use App\Models\ControlRoom\Signal;
use App\Models\ControlRoom\SignalSource;
use App\Models\ControlRoomAlert;
use App\Models\ItTicket;
use App\Models\Site;
use App\Models\User;
use App\Services\ControlRoom\SignalProcessingService;
use Database\Seeders\SecurityDevicesSignalSeeder;
use Illuminate\Support\Facades\Queue;

/** @return array{Device, ControlRoomDevice} */
function monitoringRecoveryDevice(): array
{
    $site = Site::factory()->create();
    $device = Device::factory()->itInfrastructure()->create();
    $actor = User::factory()->create();

    DeviceAssignment::query()->create([
        'device_id' => $device->id,
        'assignable_type' => DeviceAssignment::TARGET_SITE,
        'assignable_id' => $site->id,
        'assignment_type' => 'permanent',
        'assigned_at' => now(),
        'assigned_by_user_id' => $actor->id,
    ]);

    $projection = ControlRoomDevice::query()->create([
        'name' => $device->name,
        'canonical_device_id' => $device->id,
        'site_id' => $site->id,
        'type' => ControlRoomDevice::TYPE_NETWORK,
    ]);

    return [$device, $projection];
}

beforeEach(function () {
    $this->seed(SecurityDevicesSignalSeeder::class);
});

it('records exact legacy recovery for verification without closing the alert or ticket', function () {
    [$device, $projection] = monitoringRecoveryDevice();

    $failureEvent = DeviceEvent::create([
        'device_id' => $device->id,
        'event_type' => 'offline',
        'severity' => 'high',
        'source' => 'oblivion_monitoring',
        'occurred_at' => now()->subMinutes(2),
    ]);
    $offline = ControlRoomAlert::where('device_id', $projection->id)->latest('id')->firstOrFail();

    $recoveryEvent = DeviceEvent::create([
        'device_id' => $device->id,
        'event_type' => 'online',
        'severity' => 'info',
        'source' => 'oblivion_monitoring',
        'occurred_at' => now()->subMinute(),
        'payload' => ['legacy_monitoring_recovery' => true],
    ]);

    expect($offline->fresh()->status)->toBe(ControlRoomAlert::STATUS_OPEN)
        ->and(data_get($offline->fresh()->context, 'monitoring_recoveries.legacy:'.$failureEvent->id.'.verification_required'))->toBeTrue()
        ->and($offline->fresh()->resolved_at)->toBeNull()
        ->and(ItTicket::sole()->status)->toBe('open')
        ->and(ItTicket::sole()->monitoring_recovered_at)->not->toBeNull()
        ->and(ControlRoomAlert::where('device_id', $projection->id)->count())->toBe(1)
        ->and(Signal::where('signal_type_code', 'device_online')->firstOrFail()->status)->toBe('processed')
        ->and($recoveryEvent->fresh()->processed_at)->not->toBeNull();
    expect(Signal::where('signal_type_code', 'device_online')->sole()->occurred_at->equalTo($recoveryEvent->occurred_at))->toBeTrue();
});

it('keeps explicit occurrence time regardless of legacy receipt field order', function () {
    $occurred = now()->subMinutes(10)->startOfSecond();
    $received = now()->startOfSecond();
    foreach ([['occurred_at' => $occurred, 'received_at' => $received], ['received_at' => $received, 'occurred_at' => $occurred]] as $values) {
        expect((new Signal($values))->occurred_at->equalTo($occurred))->toBeTrue();
    }
    expect((new Signal(['received_at' => $received]))->occurred_at->equalTo($received))->toBeTrue();
});

it('creates one direct verification ticket when legacy recovery is delivered before its fault', function () {
    Queue::fake();
    [$device, $projection] = monitoringRecoveryDevice();
    $payload = ['monitor_correlation_key' => hash('sha256', 'isolated reversed delivery'), 'site_id' => (int) $projection->site_id];
    $failure = DeviceEvent::create([
        'device_id' => $device->id, 'event_type' => 'offline', 'severity' => 'high',
        'source' => 'oblivion_monitoring', 'occurred_at' => now()->subMinutes(2), 'payload' => $payload,
    ]);
    $recovery = DeviceEvent::create([
        'device_id' => $device->id, 'event_type' => 'online', 'severity' => 'info',
        'source' => 'oblivion_monitoring', 'occurred_at' => now()->subMinute(), 'payload' => $payload,
    ]);
    foreach ([$recovery, $failure, $recovery, $failure] as $event) {
        $outbox = $event->signalOutbox()->sole();
        app()->call([new DispatchDeviceEventSignalOutbox($outbox->id), 'handle']);
        app()->call([new DispatchDeviceMonitoringTicket($outbox->id), 'handle']);
    }
    $ticket = ItTicket::sole();
    $snapshot = MonitoringIncidentEvidenceSnapshot::sole();
    expect(ControlRoomAlert::query()->count())->toBe(0)
        ->and($ticket->status)->toBe('open')
        ->and($ticket->status_reason)->toBe('monitoring_recovered')
        ->and($ticket->monitoring_recovered_at->equalTo($recovery->occurred_at))->toBeTrue()
        ->and($ticket->events()->where('type', 'monitoring_recovered')->count())->toBe(1)
        ->and($ticket->links()->where('relationship', 'source_alert')->count())->toBe(0)
        ->and($snapshot->control_room_alert_id)->toBeNull()
        ->and($snapshot->evidence_version)->toBe(2)
        ->and($snapshot->hasValidChecksum())->toBeTrue()
        ->and(data_get($failure->signalOutbox()->sole()->it_scope, 'work_routing.reason'))->toBe('recovered_before_delivery');

    $ticket->update(['status' => 'resolved', 'resolved_at' => now()]);
    app()->call([new DispatchDeviceMonitoringTicket($failure->signalOutbox()->sole()->id), 'handle']);
    expect(ItTicket::query()->count())->toBe(1)->and($ticket->fresh()->status)->toBe('resolved');
});

it('rejects altered legacy recovery evidence before delayed direct IT delivery', function () {
    Queue::fake();
    [$device, $projection] = monitoringRecoveryDevice();
    $payload = ['monitor_correlation_key' => hash('sha256', 'isolated changed recovery'), 'site_id' => (int) $projection->site_id];
    $failure = DeviceEvent::create([
        'device_id' => $device->id, 'event_type' => 'offline', 'severity' => 'high',
        'source' => 'oblivion_monitoring', 'occurred_at' => now()->subMinutes(3), 'payload' => $payload,
    ]);
    $recovery = DeviceEvent::create([
        'device_id' => $device->id, 'event_type' => 'online', 'severity' => 'info',
        'source' => 'oblivion_monitoring', 'occurred_at' => now()->subMinutes(2), 'payload' => $payload,
    ]);
    $outbox = $failure->signalOutbox()->sole();
    app()->call([new DispatchDeviceEventSignalOutbox($outbox->id), 'handle']);
    $recovery->update(['occurred_at' => now()->subMinute()]);
    app()->call([new DispatchDeviceMonitoringTicket($outbox->id), 'handle']);
    expect($outbox->fresh()->it_status)->toBe('unroutable')
        ->and(ItTicket::query()->count())->toBe(0)
        ->and(ControlRoomAlert::query()->count())->toBe(0);
});

it('does not apply a delayed legacy recovery to a later fault on the same correlated ticket', function () {
    [$device, $projection] = monitoringRecoveryDevice();
    $key = hash('sha256', 'isolated legacy collector');
    $start = now()->startOfSecond()->subMinutes(3);
    $first = DeviceEvent::create([
        'device_id' => $device->id, 'event_type' => 'offline', 'severity' => 'high',
        'source' => 'oblivion_monitoring', 'occurred_at' => $start,
        'payload' => ['monitor_correlation_key' => $key],
    ]);
    $second = DeviceEvent::create([
        'device_id' => $device->id, 'event_type' => 'offline', 'severity' => 'high',
        'source' => 'oblivion_monitoring', 'occurred_at' => $start->copy()->addMinutes(2),
        'payload' => ['monitor_correlation_key' => $key],
    ]);
    $recovery = DeviceEvent::create([
        'device_id' => $device->id, 'event_type' => 'online', 'severity' => 'info',
        'source' => 'oblivion_monitoring', 'occurred_at' => $start->copy()->addMinute(),
        'payload' => ['monitor_correlation_key' => $key],
    ]);

    expect(MonitoringIssueEpisode::legacyFailureForRecovery($recovery, (int) $projection->site_id)?->id)->toBe($first->id)
        ->and(ItTicket::sole()->monitoring_recovered_at)->toBeNull()
        ->and(ItTicket::sole()->status_reason)->toBe('monitoring_outage')
        ->and(ControlRoomAlert::sole()->status)->toBe(ControlRoomAlert::STATUS_OPEN)
        ->and(data_get(ControlRoomAlert::sole()->context, 'monitoring_recoveries.legacy:'.$second->id))->toBeNull();

    $current = DeviceEvent::create([
        'device_id' => $device->id, 'event_type' => 'online', 'severity' => 'info',
        'source' => 'oblivion_monitoring', 'occurred_at' => $start->copy()->addMinutes(3),
        'payload' => ['monitor_correlation_key' => $key],
    ]);
    expect(ItTicket::sole()->monitoring_recovered_at->equalTo($current->occurred_at))->toBeTrue()
        ->and(ControlRoomAlert::sole()->status)->toBe(ControlRoomAlert::STATUS_OPEN);
});

it('rejects legacy recovery when its preceding source event is already online or from another site', function () {
    [$device, $projection] = monitoringRecoveryDevice();
    $at = now()->startOfSecond()->subMinutes(3);
    $failure = DeviceEvent::create([
        'device_id' => $device->id, 'event_type' => 'offline', 'severity' => 'high',
        'source' => 'oblivion_monitoring', 'occurred_at' => $at,
        'payload' => ['site_id' => (int) $projection->site_id],
    ]);
    $recovery = DeviceEvent::withoutEvents(fn () => DeviceEvent::create([
        'device_id' => $device->id, 'event_type' => 'online', 'severity' => 'info',
        'source' => 'oblivion_monitoring', 'occurred_at' => $at->copy()->addMinute(),
        'payload' => ['legacy_monitoring_recovery' => true],
    ]));
    $duplicate = DeviceEvent::withoutEvents(fn () => DeviceEvent::create([
        'device_id' => $device->id, 'event_type' => 'online', 'severity' => 'info',
        'source' => 'oblivion_monitoring', 'occurred_at' => $at->copy()->addMinutes(2),
        'payload' => ['legacy_monitoring_recovery' => true],
    ]));

    expect(MonitoringIssueEpisode::legacyFailureForRecovery($recovery, (int) $projection->site_id)?->id)->toBe($failure->id)
        ->and(MonitoringIssueEpisode::legacyFailureForRecovery($duplicate, (int) $projection->site_id))->toBeNull()
        ->and(MonitoringIssueEpisode::legacyFailureForRecovery($recovery, (int) $projection->site_id + 1000))->toBeNull();
});

it('processes an identity-less recovery without resolving unrelated alerts', function () {
    [$device, $projection] = monitoringRecoveryDevice();

    DeviceEvent::create([
        'device_id' => $device->id,
        'event_type' => 'offline',
        'severity' => 'high',
        'source' => 'oblivion_monitoring',
        'occurred_at' => now()->subMinute(),
    ]);
    $offline = ControlRoomAlert::where('device_id', $projection->id)->firstOrFail();

    $source = SignalSource::where('slug', 'security_devices')->firstOrFail();
    $signal = app(SignalProcessingService::class)->ingest([
        'signal_source_id' => $source->id,
        'signal_type_code' => 'device_online',
        'severity_hint' => 'info',
        'occurred_at' => now(),
        'normalized_data' => [],
    ]);

    $resolved = app(SignalProcessingService::class)->processDeviceRecovery($signal);

    expect($resolved)->toBe(0)
        ->and($offline->fresh()->status)->toBe(ControlRoomAlert::STATUS_OPEN)
        ->and($signal->fresh()->status)->toBe('processed');
});

it('rejects non-recovery and foreign-source signals from the device recovery path', function (string $code) {
    $source = SignalSource::where('slug', 'security_devices')->firstOrFail();
    $signal = app(SignalProcessingService::class)->ingest([
        'signal_source_id' => $source->id,
        'signal_type_code' => $code,
        'severity_hint' => 'high',
        'occurred_at' => now(),
    ]);

    expect(fn () => app(SignalProcessingService::class)->processDeviceRecovery($signal))
        ->toThrow(InvalidArgumentException::class, 'Only native device or monitor recovery signals');
})->with(['device_offline', 'device_monitor_failed', 'fleet_device_online']);
