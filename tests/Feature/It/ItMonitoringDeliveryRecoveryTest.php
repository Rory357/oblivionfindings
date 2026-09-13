<?php

use App\Domain\It\Services\ItMonitoringDeliveryService;
use App\Domain\Monitoring\Models\MonitoringIncidentEvidenceSnapshot;
use App\Domain\SecurityDevices\Events\DeviceSignalPublished;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Domain\SecurityDevices\Models\DeviceEvent;
use App\Domain\SecurityDevices\Models\DeviceEventSignalOutbox;
use App\Jobs\DispatchDeviceEventSignalOutbox;
use App\Jobs\DispatchDeviceMonitoringTicket;
use App\Listeners\It\CreateOrUpdateMonitoringTicket;
use App\Models\AuditLog;
use App\Models\ControlRoom\Signal;
use App\Models\ControlRoom\SignalSource;
use App\Models\ControlRoomAlert;
use App\Models\ItTicket;
use App\Models\Site;
use App\Models\User;
use App\Services\ControlRoom\SafetySignalDeliveryRecoveryService;
use App\Support\SafeOperationalData;
use Database\Seeders\SecurityDevicesSignalSeeder;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    $this->seed(SecurityDevicesSignalSeeder::class);
    Queue::fake();
    Notification::fake();
    Http::preventStrayRequests();
    config()->set('inertia.ssr.enabled', false);
});

/** @return array{DeviceEventSignalOutbox, DeviceEvent, Device, Site} */
function pendingMonitoringDelivery(string $type = 'offline', string $domain = 'it_infrastructure', bool $publish = true): array
{
    $site = Site::factory()->create(['is_active' => true, 'archived' => false, 'archived_at' => null]);
    $device = Device::factory()->create(['domain' => $domain]);
    DeviceAssignment::query()->create([
        'device_id' => $device->id, 'assignable_type' => DeviceAssignment::TARGET_SITE,
        'assignable_id' => $site->id, 'assignment_type' => 'permanent', 'assigned_at' => now()->subMinute(),
        'assigned_by_user_id' => User::factory()->create()->id,
    ]);
    $event = DeviceEvent::query()->create([
        'device_id' => $device->id, 'event_type' => $type, 'severity' => $type === 'offline' ? 'high' : 'info',
        'source' => 'oblivion_monitoring', 'occurred_at' => now(),
        'payload' => ['message' => 'diagnostic-private-sentinel', 'monitor_correlation_key' => hash('sha256', 'fixture-'.$device->id)],
    ]);
    $outbox = $event->signalOutbox()->sole();
    if ($publish) {
        app()->call([new DispatchDeviceEventSignalOutbox($outbox->id), 'handle']);
    }

    return [$outbox->fresh(), $event, $device, $site];
}

function deliverMonitoringIt(DeviceEventSignalOutbox $outbox): void
{
    app()->call([new DispatchDeviceMonitoringTicket($outbox->id), 'handle']);
}

test('lost queued listener is recovered once independently of the acknowledged Control Room alert', function () {
    [$outbox, $event, $device] = pendingMonitoringDelivery();
    $alert = ControlRoomAlert::query()->sole();
    expect($outbox->status)->toBe('sent')->and($outbox->it_status)->toBe('pending')
        ->and(ItTicket::query()->count())->toBe(0);

    $report = app(SafetySignalDeliveryRecoveryService::class)->recover();
    expect($report['device_it']['queued'])->toBe(1);
    Queue::assertPushed(DispatchDeviceMonitoringTicket::class, fn ($job) => $job->outboxId === $outbox->id);
    deliverMonitoringIt($outbox);
    $ticket = ItTicket::query()->sole();
    expect($outbox->fresh()->it_status)->toBe('applied')
        ->and($outbox->fresh()->it_outcome_code)->toBe('ticket_created')
        ->and($outbox->fresh()->it_attempts)->toBe(1)
        ->and($outbox->fresh()->it_ticket_ids)->toBe([$ticket->id])
        ->and(ControlRoomAlert::query()->sole()->id)->toBe($alert->id);

    $ticket->update(['status' => 'resolved', 'resolved_at' => now()]);
    deliverMonitoringIt($outbox);
    app(CreateOrUpdateMonitoringTicket::class)->handle(new DeviceSignalPublished($device, $event, Signal::query()->sole(), true));
    expect(ItTicket::query()->count())->toBe(1)
        ->and($ticket->fresh()->status)->toBe('resolved')
        ->and($outbox->fresh()->it_attempts)->toBe(1)
        ->and(AuditLog::query()->where('action', 'it.monitoring.delivery_completed')->count())->toBe(1);
    $audit = AuditLog::query()->where('action', 'it.monitoring.delivery_completed')->sole();
    expect(data_get($audit->meta, 'after.status'))->toBe('applied')
        ->and(data_get($audit->meta, 'after.state'))->toBe('ticket_created')
        ->and(data_get($audit->meta, 'after.it_attempts'))->toBe(1)
        ->and($audit->user_id)->toBeNull()
        ->and(json_encode($audit->meta))->not->toContain('diagnostic-private-sentinel');
});

test('IT insertion failure rolls back its ticket and evidence but preserves Control Room delivery for retry', function () {
    [$outbox] = pendingMonitoringDelivery();
    $alert = ControlRoomAlert::query()->sole();
    $fail = true;
    ItTicket::created(function () use (&$fail): void {
        if ($fail) {
            throw new RuntimeException('diagnostic-private-sentinel SQL failure');
        }
    });
    expect(fn () => deliverMonitoringIt($outbox))->toThrow(RuntimeException::class);
    expect($outbox->fresh()->status)->toBe('sent')->and($outbox->fresh()->it_status)->toBe('failed')
        ->and($outbox->fresh()->it_outcome_code)->toBe('processing_failed')
        ->and($outbox->fresh()->it_attempts)->toBe(1)
        ->and(ItTicket::query()->count())->toBe(0)
        ->and(MonitoringIncidentEvidenceSnapshot::query()->count())->toBe(0)
        ->and(ControlRoomAlert::query()->sole()->id)->toBe($alert->id)
        ->and(ControlRoomAlert::query()->sole()->status)->toBe($alert->status);
    $fail = false;
    app(SafetySignalDeliveryRecoveryService::class)->retry('device_it', $outbox->id);
    deliverMonitoringIt($outbox);
    expect($outbox->fresh()->it_status)->toBe('applied')->and($outbox->fresh()->it_attempts)->toBe(2)
        ->and(ItTicket::query()->count())->toBe(1)
        ->and(MonitoringIncidentEvidenceSnapshot::query()->count())->toBe(1)
        ->and(AuditLog::query()->where('action', 'it.monitoring.delivery_retry_requested')->count())->toBe(1);
    $audit = AuditLog::query()->where('action', 'it.monitoring.delivery_retry_requested')->sole();
    expect(data_get($audit->meta, 'before.status'))->toBe('failed')
        ->and(data_get($audit->meta, 'before.state'))->toBe('processing_failed')
        ->and(data_get($audit->meta, 'before.it_attempts'))->toBe(1)
        ->and(data_get($audit->meta, 'after.it_attempt_limit'))->toBe(2)
        ->and(data_get($audit->meta, 'after.state'))->toBe('retry_requested');
});

test('exhausted failures require an explicit durable retry and preserve lifetime attempts during a queue outage', function () {
    [$outbox] = pendingMonitoringDelivery();
    $fail = true;
    ItTicket::created(function () use (&$fail): void {
        if ($fail) {
            throw new RuntimeException('injected insertion failure');
        }
    });
    for ($attempt = 1; $attempt <= 3; $attempt++) {
        expect(fn () => deliverMonitoringIt($outbox))->toThrow(RuntimeException::class);
        expect($outbox->fresh()->it_attempts)->toBe($attempt);
    }
    expect($outbox->fresh()->it_status)->toBe('dead_letter');
    $report = app(SafetySignalDeliveryRecoveryService::class)->recover();
    expect($report['device_it']['queued'])->toBe(0)->and($report['device_it']['failures'])->toBe(1)
        ->and($report['device_it']['failure_rows'][0]['last_error'])->toBe('processing_failed');
    deliverMonitoringIt($outbox);
    expect($outbox->fresh()->it_attempts)->toBe(3);

    Bus::shouldReceive('dispatch')->once()->andThrow(new RuntimeException('injected queue outage'));
    app(ItMonitoringDeliveryService::class)->retry($outbox->id);
    expect($outbox->fresh()->it_status)->toBe('pending')->and($outbox->fresh()->it_attempt_limit)->toBe(4);
    $fail = false;
    deliverMonitoringIt($outbox);
    expect($outbox->fresh()->it_status)->toBe('applied')->and($outbox->fresh()->it_attempts)->toBe(4)
        ->and(ItTicket::query()->count())->toBe(1);
});

test('delayed IT delivery denies changed canonical scope or source evidence', function (string $change) {
    [$outbox, $event, $device, $site] = pendingMonitoringDelivery();
    $originalScope = $outbox->it_scope;
    match ($change) {
        'site' => $device->assignments()->update(['assignable_id' => Site::factory()->create()->id]),
        'inactive_site' => $site->update(['is_active' => false]),
        'domain' => $device->update(['domain' => 'security']),
        'episode' => $event->update(['payload' => ['monitor_correlation_key' => hash('sha256', 'changed')]]),
        'signal' => Signal::query()->sole()->update(['normalized_data' => ['canonical_device_id' => $device->id + 1000]]),
    };
    deliverMonitoringIt($outbox);
    expect($outbox->fresh()->status)->toBe('sent')->and($outbox->fresh()->it_status)->toBe('unroutable')
        ->and($outbox->fresh()->it_outcome_code)->toBe('source_scope_changed')
        ->and($outbox->fresh()->it_scope)->toBe($originalScope)
        ->and(ItTicket::query()->count())->toBe(0)->and(ControlRoomAlert::query()->count())->toBe(1);
    expect(app(ItMonitoringDeliveryService::class)->recover(100)['queued'])->toBe(0);
})->with(['site', 'inactive_site', 'domain', 'episode', 'signal']);

test('inactive source requires repair and explicit retry without discarding the approved scope binding', function () {
    [$outbox] = pendingMonitoringDelivery();
    $source = SignalSource::query()->where('slug', 'security_devices')->sole();
    $source->update(['status' => 'inactive']);
    deliverMonitoringIt($outbox);
    expect($outbox->fresh()->it_status)->toBe('unroutable')->and($outbox->fresh()->it_outcome_code)->toBe('source_unavailable');
    $source->update(['status' => 'active']);
    app(ItMonitoringDeliveryService::class)->retry($outbox->id);
    deliverMonitoringIt($outbox);
    expect($outbox->fresh()->it_status)->toBe('applied')->and(ItTicket::query()->count())->toBe(1);
});

test('unmatched recovery is explicit and unsupported device domains never create technical work', function () {
    [$recovery] = pendingMonitoringDelivery('online');
    deliverMonitoringIt($recovery);
    expect($recovery->fresh()->it_status)->toBe('ignored')->and($recovery->fresh()->it_outcome_code)->toBe('recovery_unmatched');
    [$unsupported] = pendingMonitoringDelivery('offline', 'iot_healthcare');
    deliverMonitoringIt($unsupported);
    expect($unsupported->fresh()->it_status)->toBe('ignored')->and($unsupported->fresh()->it_outcome_code)->toBe('unsupported_event')
        ->and($unsupported->fresh()->it_attempts)->toBe(0)->and(ItTicket::query()->count())->toBe(0);
});

test('legacy acknowledged events remain unverified rather than recreating already settled IT work', function () {
    [$outbox, $event, $device] = pendingMonitoringDelivery();
    $outbox->update(['it_status' => null, 'it_scope' => null, 'it_signal_id' => null]);
    $existing = ItTicket::factory()->create(['source' => 'system', 'status' => 'closed']);
    $report = app(ItMonitoringDeliveryService::class)->recover(100);
    app(CreateOrUpdateMonitoringTicket::class)->handle(new DeviceSignalPublished($device, $event, Signal::query()->sole(), true));
    expect($report['legacy_unverified'])->toBe(1)->and($report['queued'])->toBe(0)
        ->and(ItTicket::query()->sole()->id)->toBe($existing->id)->and($outbox->fresh()->it_status)->toBeNull();
    expect(fn () => app(ItMonitoringDeliveryService::class)->retry($outbox->id))->toThrow(DomainException::class);
});

test('required completion audit failure rolls back the IT result and is recoverable without duplicating the alert', function () {
    [$outbox] = pendingMonitoringDelivery();
    $fail = true;
    AuditLog::creating(function (AuditLog $audit) use (&$fail): void {
        if ($fail && $audit->action === 'it.monitoring.delivery_completed') {
            throw new RuntimeException('injected audit failure');
        }
    });
    expect(fn () => deliverMonitoringIt($outbox))->toThrow(RuntimeException::class);
    expect(ItTicket::query()->count())->toBe(0)->and($outbox->fresh()->it_status)->toBe('failed')
        ->and(ControlRoomAlert::query()->count())->toBe(1);
    $fail = false;
    deliverMonitoringIt($outbox);
    expect(ItTicket::query()->count())->toBe(1)->and($outbox->fresh()->it_status)->toBe('applied')
        ->and(AuditLog::query()->where('action', 'it.monitoring.delivery_completed')->count())->toBe(1);
});

test('IT dispatch intent rolls back with an interrupted source acknowledgement and is prepared on retry', function () {
    [$outbox] = pendingMonitoringDelivery(publish: false);
    expect(fn () => DB::transaction(function () use ($outbox): void {
        app()->call([new DispatchDeviceEventSignalOutbox($outbox->id), 'handle']);
        expect($outbox->fresh()->it_status)->toBe('pending');
        throw new RuntimeException('injected source transaction interruption');
    }))->toThrow(RuntimeException::class);
    expect($outbox->fresh()->status)->toBe('pending')->and($outbox->fresh()->it_status)->toBeNull()
        ->and(ControlRoomAlert::query()->count())->toBe(0)->and(Signal::query()->count())->toBe(0);
    app()->call([new DispatchDeviceEventSignalOutbox($outbox->id), 'handle']);
    deliverMonitoringIt($outbox);
    expect($outbox->fresh()->status)->toBe('sent')->and($outbox->fresh()->it_status)->toBe('applied')
        ->and(ControlRoomAlert::query()->count())->toBe(1)->and(ItTicket::query()->count())->toBe(1);
});

test('delivery audit counters accept bounded integers and reject free text provider material', function () {
    expect(SafeOperationalData::auditValues(['it_attempts' => 3, 'it_attempt_limit' => 4]))
        ->toBe(['it_attempts' => 3, 'it_attempt_limit' => 4]);
    foreach (['diagnostic-private-sentinel', -1, 4294967296, 1.5, true, ['secret' => 'diagnostic-private-sentinel']] as $invalid) {
        expect(SafeOperationalData::auditValues(['it_attempts' => $invalid, 'it_attempt_limit' => $invalid]))->toBe([]);
    }
});
