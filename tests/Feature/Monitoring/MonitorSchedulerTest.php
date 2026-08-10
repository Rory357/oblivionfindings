<?php

use App\Domain\Monitoring\Enums\MonitorKind;
use App\Domain\Monitoring\Enums\RuntimeMessageType;
use App\Domain\Monitoring\Jobs\PublishMonitoringOutbox;
use App\Domain\Monitoring\Jobs\RunMonitorCheck;
use App\Domain\Monitoring\Jobs\ScheduleDueMonitors;
use App\Domain\Monitoring\Jobs\ScheduleProviderCapabilities;
use App\Domain\Monitoring\Models\Monitor;
use App\Domain\Monitoring\Models\MonitoringCollector;
use App\Domain\Monitoring\Models\MonitoringOutbox;
use App\Domain\Monitoring\Models\MonitoringProfile;
use App\Domain\Monitoring\Services\MonitorScheduler;
use App\Domain\Monitoring\Services\RuntimeEnvelopeCodec;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Models\Site;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    config()->set('monitoring.delivery.sequence_lock_store', 'array');
    config()->set('monitoring.delivery.allow_local_sequence_lock_for_tests', true);
    config()->set('monitoring.signing', [
        'active_key_id' => 'scheduler-test-key',
        'keys' => [
            'scheduler-test-key' => base64_encode(str_repeat("\x35", SODIUM_CRYPTO_AUTH_KEYBYTES)),
        ],
    ]);
});

/**
 * @param  array<string, mixed>  $profileAttributes
 * @param  array<string, mixed>  $monitorAttributes
 * @return array{site: Site, device: Device, profile: MonitoringProfile, monitor: Monitor}
 */
function schedulerMonitor(
    array $profileAttributes = [],
    array $monitorAttributes = [],
    ?Site $site = null,
): array {
    $site ??= Site::factory()->create([
        'is_active' => true,
        'archived' => false,
        'archived_at' => null,
    ]);
    $device = Device::factory()->itInfrastructure()->create();
    DeviceAssignment::query()->create([
        'device_id' => $device->id,
        'assignable_type' => DeviceAssignment::TARGET_SITE,
        'assignable_id' => $site->id,
        'assignment_type' => 'permanent',
        'assigned_at' => now()->subMinute(),
        'released_at' => null,
    ]);
    $profile = MonitoringProfile::factory()->create([
        'interval_seconds' => 60,
        'is_active' => true,
        ...$profileAttributes,
    ]);
    $monitor = Monitor::factory()->create([
        'device_id' => $device->id,
        'profile_id' => $profile->id,
        'collector_id' => null,
        'kind' => MonitorKind::Icmp,
        'target' => '10.44.1.8',
        'config' => [],
        'is_enabled' => true,
        'last_observation_at' => null,
        ...$monitorAttributes,
    ]);

    return compact('site', 'device', 'profile', 'monitor');
}

it('dispatches each due direct monitor once to the isolated checks queue', function () {
    Queue::fake();
    $now = CarbonImmutable::parse('2026-07-23T12:07:00Z');
    $record = schedulerMonitor(
        profileAttributes: ['interval_seconds' => 300],
        monitorAttributes: ['last_observation_at' => $now->subMinutes(10)],
    );

    $first = app(MonitorScheduler::class)->dispatchDue($now);
    $second = app(MonitorScheduler::class)->dispatchDue($now);

    $expectedScheduleKey = (string) (intdiv($now->timestamp, 300) * 300);
    Queue::assertPushed(RunMonitorCheck::class, 1);
    Queue::assertPushed(RunMonitorCheck::class, function (RunMonitorCheck $job) use ($record, $expectedScheduleKey): bool {
        return $job->monitorId === $record['monitor']->id
            && $job->scheduleKey === $expectedScheduleKey
            && $job->connection === 'redis'
            && $job->queue === 'monitoring-checks';
    });
    expect($first->lockAcquired)->toBeTrue()
        ->and($first->directDispatched)->toBe(1)
        ->and($second->lockAcquired)->toBeFalse()
        ->and($second->directDispatched)->toBe(0);
});

it('omits disabled inactive and not-yet-due monitors', function () {
    Queue::fake();
    $now = CarbonImmutable::parse('2026-07-23T12:07:00Z');
    schedulerMonitor(monitorAttributes: ['is_enabled' => false]);
    schedulerMonitor(profileAttributes: ['is_active' => false]);
    schedulerMonitor(
        profileAttributes: ['interval_seconds' => 300],
        monitorAttributes: ['last_observation_at' => CarbonImmutable::parse('2026-07-23T12:05:00Z')],
    );
    $due = schedulerMonitor(
        profileAttributes: ['interval_seconds' => 300],
        monitorAttributes: ['last_observation_at' => CarbonImmutable::parse('2026-07-23T12:04:59Z')],
    );
    $invalidKind = schedulerMonitor();
    DB::table('monitors')->where('id', $invalidKind['monitor']->id)->update(['kind' => 'shell']);

    $result = app(MonitorScheduler::class)->dispatchDue($now);

    Queue::assertPushed(RunMonitorCheck::class, 1);
    Queue::assertPushed(RunMonitorCheck::class, fn (RunMonitorCheck $job): bool => $job->monitorId === $due['monitor']->id);
    expect($result->directDispatched)->toBe(1)
        ->and($result->omitted)->toBe(1);
});

it('fails closed for devices without one active canonical Site', function () {
    Queue::fake();
    $now = CarbonImmutable::parse('2026-07-23T12:07:00Z');
    $valid = schedulerMonitor();

    $archivedSite = Site::factory()->create([
        'is_active' => true,
        'archived' => true,
        'archived_at' => $now->subDay(),
    ]);
    schedulerMonitor(site: $archivedSite);

    $unassignedDevice = Device::factory()->itInfrastructure()->create();
    $profile = MonitoringProfile::factory()->create();
    Monitor::factory()->create([
        'device_id' => $unassignedDevice->id,
        'profile_id' => $profile->id,
        'last_observation_at' => null,
    ]);

    $result = app(MonitorScheduler::class)->dispatchDue($now);

    Queue::assertPushed(RunMonitorCheck::class, 1);
    Queue::assertPushed(RunMonitorCheck::class, fn (RunMonitorCheck $job): bool => $job->monitorId === $valid['monitor']->id);
    expect($result->omitted)->toBe(2);
});

it('publishes collector checks as deterministic Site-scoped configuration instead of central probes', function () {
    Queue::fake();
    $now = CarbonImmutable::parse('2026-07-23T12:07:00Z');
    $record = schedulerMonitor(
        profileAttributes: ['interval_seconds' => 300],
        monitorAttributes: [
            'kind' => MonitorKind::Snmp,
            'target' => '10.44.1.9',
            'config' => ['version' => 'v3', 'credential_reference' => 'vault:snmp/site-device'],
        ],
    );
    $collector = MonitoringCollector::factory()->create([
        'site_id' => $record['site']->id,
        'status' => 'online',
    ]);
    $record['monitor']->update(['collector_id' => $collector->id]);

    $result = app(MonitorScheduler::class)->dispatchDue($now);

    Queue::assertNotPushed(RunMonitorCheck::class);
    Queue::assertPushed(PublishMonitoringOutbox::class, fn (PublishMonitoringOutbox $job): bool => $job->queue === 'monitoring');
    $outbox = MonitoringOutbox::query()->sole();
    $envelope = app(RuntimeEnvelopeCodec::class)->decode($outbox->envelope_bytes);
    $expectedScheduleKey = (string) (intdiv($now->timestamp, 300) * 300);
    expect($envelope->type)->toBe(RuntimeMessageType::Configuration)
        ->and($outbox->stream)->toBe('collector-configuration')
        ->and($envelope->source)->toBe("central:collector:{$collector->collector_uuid}")
        ->and($envelope->idempotencyKey)->toBe("monitor:{$record['monitor']->id}:schedule:{$expectedScheduleKey}")
        ->and($envelope->payload)->toMatchArray([
            'contract_version' => 1,
            'action' => 'run_monitor_check',
            'schedule_key' => $expectedScheduleKey,
            'site_id' => $record['site']->id,
            'collector_id' => $collector->id,
            'collector_uuid' => $collector->collector_uuid,
            'monitor' => [
                'id' => $record['monitor']->id,
                'device_id' => $record['device']->id,
                'kind' => MonitorKind::Snmp->value,
                'target' => '10.44.1.9',
                'config' => ['version' => 'v3', 'credential_reference' => 'vault:snmp/site-device'],
                'interval_seconds' => 300,
            ],
        ])
        ->and(array_keys($envelope->payload))->toBe([
            'action',
            'collector_id',
            'collector_uuid',
            'contract_version',
            'monitor',
            'schedule_key',
            'site_id',
        ])
        ->and(array_keys($envelope->payload['monitor']))->toBe([
            'config',
            'device_id',
            'id',
            'interval_seconds',
            'kind',
            'target',
        ])
        ->and($result->collectorConfigurations)->toBe(1)
        ->and($result->directDispatched)->toBe(0);
});

it('omits collectors assigned outside the device canonical Site and raw collector secrets', function () {
    Queue::fake();
    $now = CarbonImmutable::parse('2026-07-23T12:07:00Z');
    $mismatch = schedulerMonitor(monitorAttributes: ['kind' => MonitorKind::Snmp]);
    $otherSite = Site::factory()->create(['is_active' => true, 'archived' => false]);
    $otherCollector = MonitoringCollector::factory()->create([
        'site_id' => $otherSite->id,
        'status' => 'online',
    ]);
    $mismatch['monitor']->update(['collector_id' => $otherCollector->id]);

    $unsafe = schedulerMonitor(monitorAttributes: [
        'kind' => MonitorKind::Snmp,
        'config' => ['community' => 'private-community'],
    ]);
    $unsafeCollector = MonitoringCollector::factory()->create([
        'site_id' => $unsafe['site']->id,
        'status' => 'online',
    ]);
    $unsafe['monitor']->update(['collector_id' => $unsafeCollector->id]);

    $result = app(MonitorScheduler::class)->dispatchDue($now);

    Queue::assertNothingPushed();
    expect(MonitoringOutbox::query()->count())->toBe(0)
        ->and($result->omitted)->toBe(2);
});

it('honours the shared scheduler lock before scanning monitors', function () {
    Queue::fake();
    $now = CarbonImmutable::parse('2026-07-23T12:07:00Z');
    schedulerMonitor();
    $scheduleKey = intdiv($now->timestamp, 60) * 60;
    $heldLock = Cache::store('array')->lock("monitoring:schedule:{$scheduleKey}", 120);
    expect($heldLock->get())->toBeTrue();

    $result = app(MonitorScheduler::class)->dispatchDue($now);

    Queue::assertNothingPushed();
    expect($result->lockAcquired)->toBeFalse()
        ->and($result->scanned)->toBe(0);
    $heldLock->release();
});

it('requires a shared Redis scheduler lock outside the explicit test override', function () {
    Queue::fake();
    config()->set('monitoring.delivery.allow_local_sequence_lock_for_tests', false);

    expect(fn () => app(MonitorScheduler::class)->dispatchDue(CarbonImmutable::now('UTC')))
        ->toThrow(RuntimeException::class, 'requires a shared Redis lock store');
    Queue::assertNothingPushed();
});

it('scans ten thousand due monitors in bounded five-hundred-row chunks', function () {
    Queue::fake();
    $now = CarbonImmutable::parse('2026-07-23T12:07:00Z');
    $record = schedulerMonitor();
    $base = $record['monitor']->getAttributes();
    unset($base['id']);
    $record['monitor']->delete();
    $timestamp = now();
    $base = [
        ...$base,
        'device_id' => $record['device']->id,
        'profile_id' => $record['profile']->id,
        'collector_id' => null,
        'kind' => MonitorKind::Icmp->value,
        'name' => 'Bulk ICMP availability',
        'target' => '10.44.1.8',
        'config' => json_encode([], JSON_THROW_ON_ERROR),
        'current_state' => 'unknown',
        'pending_state' => null,
        'pending_count' => 0,
        'affects_availability' => true,
        'is_enabled' => true,
        'last_observation_at' => null,
        'last_state_changed_at' => null,
        'suppressed_until' => null,
        'created_at' => $timestamp,
        'updated_at' => $timestamp,
    ];
    foreach (range(1, 10) as $batch) {
        DB::table('monitors')->insert(array_fill(0, 1000, $base));
    }

    $result = app(MonitorScheduler::class)->dispatchDue($now);

    Queue::assertPushed(RunMonitorCheck::class, 10000);
    expect($result->scanned)->toBe(10000)
        ->and($result->chunks)->toBe(20)
        ->and($result->directDispatched)->toBe(10000);
});

it('registers the scheduler job every minute with distributed overlap guards', function () {
    $event = collect(app(Schedule::class)->events())
        ->first(fn ($candidate): bool => ($candidate->description ?? null) === ScheduleDueMonitors::class);

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('* * * * *')
        ->and($event->onOneServer)->toBeTrue()
        ->and($event->withoutOverlapping)->toBeTrue();

    $job = new ScheduleDueMonitors;
    expect($job->connection)->toBe('redis')
        ->and($job->queue)->toBe('monitoring')
        ->and($job->tries)->toBe(3);
});

it('does not schedule legacy Control Room projection offline detection', function () {
    $scheduledJobs = collect(app(Schedule::class)->events())
        ->pluck('description')
        ->filter()
        ->values();

    expect($scheduledJobs)->not->toContain('App\\Jobs\\DetectCrDeviceOfflineJob');
});

it('registers provider capability orchestration every minute with distributed overlap guards', function () {
    $event = collect(app(Schedule::class)->events())
        ->first(fn ($candidate): bool => ($candidate->description ?? null) === ScheduleProviderCapabilities::class);

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('* * * * *')
        ->and($event->onOneServer)->toBeTrue()
        ->and($event->withoutOverlapping)->toBeTrue();

    $job = new ScheduleProviderCapabilities;
    expect($job->connection)->toBe('redis')
        ->and($job->queue)->toBe('monitoring')
        ->and($job->tries)->toBe(3);
});
