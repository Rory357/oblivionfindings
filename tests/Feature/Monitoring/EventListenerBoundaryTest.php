<?php

use App\Domain\Monitoring\Contracts\TimeSeriesStore;
use App\Domain\Monitoring\Data\TimeSeriesPoint;
use App\Domain\Monitoring\Discovery\Models\DiscoveryScope;
use App\Domain\Monitoring\Models\MonitoringOutbox;
use App\Domain\Monitoring\Protocols\Flow\FlowIntakeService;
use App\Domain\Monitoring\Protocols\Syslog\SyslogIntakeService;
use App\Domain\Monitoring\Services\MonitoringEnvelopeConsumer;
use App\Domain\Monitoring\Services\RuntimeEnvelopeCodec;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Domain\SecurityDevices\Models\DeviceEvent;
use App\Models\Site;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

final class TaskTenBoundaryTimeSeriesStore implements TimeSeriesStore
{
    /** @var list<TimeSeriesPoint> */
    public array $points = [];

    public function writePoints(array $points): void
    {
        foreach ($points as $point) {
            if (! collect($this->points)->contains(
                fn (TimeSeriesPoint $stored): bool => $stored->idempotencyKey === $point->idempotencyKey,
            )) {
                $this->points[] = $point;
            }
        }
    }

    public function range(string $externalKey, string $tier, CarbonImmutable $from, CarbonImmutable $to): array
    {
        return collect($this->points)->filter(
            fn (TimeSeriesPoint $point): bool => $point->externalKey === $externalKey
                && $point->tier === $tier
                && $point->observedAt->greaterThanOrEqualTo($from)
                && $point->observedAt->lessThan($to),
        )->values()->all();
    }

    public function deleteRange(string $externalKey, string $tier, CarbonImmutable $from, CarbonImmutable $to): void {}

    public function exists(
        string $externalKey,
        string $tier,
        ?CarbonImmutable $from = null,
        ?CarbonImmutable $to = null,
    ): bool {
        return collect($this->points)->contains(
            fn (TimeSeriesPoint $point): bool => $point->externalKey === $externalKey
                && $point->tier === $tier
                && ($from === null || $point->observedAt->greaterThanOrEqualTo($from))
                && ($to === null || $point->observedAt->lessThan($to)),
        );
    }

    public function healthy(): bool
    {
        return true;
    }
}

beforeEach(function () {
    CarbonImmutable::setTestNow('2026-07-23T03:34:56Z');
    Queue::fake();
    config()->set('monitoring.delivery.sequence_lock_store', 'array');
    config()->set('monitoring.delivery.allow_local_sequence_lock_for_tests', true);
    config()->set('monitoring.inbound.listener_state_store', 'array');
    config()->set('monitoring.inbound.allow_local_state_store_for_tests', true);
    config()->set('monitoring.signing', [
        'active_key_id' => 'task-ten-key',
        'keys' => [
            'task-ten-key' => base64_encode(str_repeat("\x45", SODIUM_CRYPTO_AUTH_KEYBYTES)),
        ],
    ]);
    app()->instance(TimeSeriesStore::class, new TaskTenBoundaryTimeSeriesStore);
});

afterEach(fn () => CarbonImmutable::setTestNow());

/** @return array{site: Site, device: Device, scope: DiscoveryScope} */
function taskTenTelemetryContext(array $protocols = ['syslog', 'flow']): array
{
    $site = Site::factory()->create([
        'is_active' => true,
        'archived' => false,
        'archived_at' => null,
    ]);
    $device = Device::factory()->itInfrastructure()->create(['ip_address' => '10.44.0.1']);
    DeviceAssignment::query()->create([
        'device_id' => $device->id,
        'assignable_type' => DeviceAssignment::TARGET_SITE,
        'assignable_id' => $site->id,
        'assignment_type' => 'permanent',
        'assigned_at' => now()->subMinute(),
        'released_at' => null,
    ]);
    $scope = DiscoveryScope::factory()->create([
        'site_id' => $site->id,
        'collector_id' => null,
        'name' => 'Central telemetry network',
        'cidrs' => ['10.44.0.0/24'],
        'protocols' => $protocols,
        'status' => 'active',
    ]);

    return compact('site', 'device', 'scope');
}

function taskTenBoundaryIpv4(string $address): int
{
    return unpack('Naddress', inet_pton($address))['address'];
}

function taskTenBoundaryNetFlowV5(int $sequence, int $uptime = 500_000): string
{
    $header = pack('nnNNNNCCn', 5, 1, $uptime, 1_753_247_695, 0, $sequence, 1, 2, 0);
    $record = pack(
        'N3n2N4n2C4n2C2n',
        taskTenBoundaryIpv4('10.44.0.10'),
        taskTenBoundaryIpv4('10.44.0.20'),
        0,
        7,
        8,
        5,
        6400,
        499_000,
        499_500,
        51_514,
        443,
        0,
        0x12,
        6,
        0,
        64_512,
        64_513,
        24,
        24,
        0,
    );

    return $header.$record;
}

it('adds durable bounded exporter sequence state without raw addresses or packets', function () {
    expect(Schema::hasColumns('monitoring_flow_exporter_states', [
        'site_id',
        'exporter_hash',
        'family',
        'source_id',
        'last_sequence',
        'last_uptime_ms',
        'last_record_count',
        'last_datagram_hash',
        'last_exported_at',
        'last_seen_at',
    ]))->toBeTrue()
        ->and(Schema::hasColumn('monitoring_flow_exporter_states', 'exporter_address'))->toBeFalse()
        ->and(Schema::hasColumn('monitoring_flow_exporter_states', 'raw_datagram'))->toBeFalse();
});

it('stages syslog once and creates a canonical DeviceEvent only in the event consumer', function () {
    $context = taskTenTelemetryContext();
    $datagram = '<34>1 2026-07-23T03:34:55Z edge-01 sshd 4321 AUTH42 - Login failed';

    $first = app(SyslogIntakeService::class)->ingest($datagram, '10.44.0.1');
    $duplicate = app(SyslogIntakeService::class)->ingest($datagram, '10.44.0.1');

    expect($duplicate->is($first))->toBeTrue()
        ->and(MonitoringOutbox::query()->count())->toBe(1)
        ->and(DeviceEvent::query()->count())->toBe(0);
    $envelope = app(RuntimeEnvelopeCodec::class)->decode($first->envelope_bytes);
    expect($envelope->payload)->toMatchArray([
        'event_family' => 'syslog',
        'site_id' => $context['site']->id,
        'source_address' => '10.44.0.1',
        'facility' => 4,
        'severity_code' => 2,
        'message' => 'Login failed',
    ])->and($envelope->payload)->not->toHaveKey('raw_datagram');

    app(MonitoringEnvelopeConsumer::class)->consume(
        'event-projector',
        $first->envelope_bytes,
        $context['site']->id,
    );
    app(MonitoringEnvelopeConsumer::class)->consume(
        'event-projector',
        $duplicate->envelope_bytes,
        $context['site']->id,
    );

    $event = DeviceEvent::query()->sole();
    expect($event->device_id)->toBe($context['device']->id)
        ->and($event->event_type)->toBe('signal')
        ->and($event->severity)->toBe('critical')
        ->and($event->source)->toBe('oblivion_syslog')
        ->and($event->payload)->not->toHaveKey('raw_datagram');
});

it('publishes bounded flow metric envelopes and one canonical sequence-gap health event', function () {
    $context = taskTenTelemetryContext();
    $firstPacket = taskTenBoundaryNetFlowV5(1000);
    $gapPacket = taskTenBoundaryNetFlowV5(1003, 501_000);

    $first = app(FlowIntakeService::class)->ingest($firstPacket, '10.44.0.1');
    $duplicate = app(FlowIntakeService::class)->ingest($firstPacket, '10.44.0.1');
    $gap = app(FlowIntakeService::class)->ingest($gapPacket, '10.44.0.1');

    expect($first)->toHaveCount(1)
        ->and($duplicate)->toHaveCount(1)
        ->and($gap)->toHaveCount(2)
        ->and(MonitoringOutbox::query()->count())->toBe(3)
        ->and(DeviceEvent::query()->count())->toBe(0);
    $envelopes = MonitoringOutbox::query()->orderBy('id')->get()
        ->map(fn (MonitoringOutbox $outbox) => app(RuntimeEnvelopeCodec::class)->decode($outbox->envelope_bytes));
    $metrics = $envelopes->filter(fn ($envelope) => $envelope->payload['event_family'] === 'flow_metric')->values();
    $health = $envelopes->first(fn ($envelope) => $envelope->payload['event_family'] === 'flow_health');
    expect($metrics)->toHaveCount(2)
        ->and($metrics[0]->payload['buckets'][0])->toMatchArray([
            'application' => 'https',
            'bytes' => 6400,
            'packets' => 5,
            'flow_count' => 1,
        ])->and($health->payload)->toMatchArray([
            'site_id' => $context['site']->id,
            'source_address' => '10.44.0.1',
            'protocol_family' => 'netflow-v5',
            'reason' => 'sequence_gap',
            'expected_sequence' => 1001,
            'actual_sequence' => 1003,
            'gap_count' => 2,
        ]);

    app(MonitoringEnvelopeConsumer::class)->consume(
        'event-projector',
        $first[0]->envelope_bytes,
        $context['site']->id,
    );
    app(MonitoringEnvelopeConsumer::class)->consume(
        'event-projector',
        $gap[0]->envelope_bytes,
        $context['site']->id,
    );
    $healthOutbox = MonitoringOutbox::query()->get()->first(
        fn (MonitoringOutbox $outbox): bool => app(RuntimeEnvelopeCodec::class)
            ->decode($outbox->envelope_bytes)->payload['event_family'] === 'flow_health',
    );
    app(MonitoringEnvelopeConsumer::class)->consume(
        'event-projector',
        $healthOutbox->envelope_bytes,
        $context['site']->id,
    );

    $event = DeviceEvent::query()->sole();
    expect($event->device_id)->toBe($context['device']->id)
        ->and($event->source)->toBe('oblivion_flow')
        ->and($event->severity)->toBe('warning');
});

it('rejects unknown ambiguous oversized and protocol-mismatched senders without domain writes', function () {
    $context = taskTenTelemetryContext(['syslog']);
    $syslog = '<34>1 2026-07-23T03:34:55Z edge-01 sshd - - - failure';

    expect(fn () => app(SyslogIntakeService::class)->ingest($syslog, '10.99.0.1'))
        ->toThrow(RuntimeException::class, 'does not resolve to one approved Site scope');
    DiscoveryScope::factory()->create([
        'site_id' => $context['site']->id,
        'collector_id' => null,
        'name' => 'Overlapping syslog network',
        'cidrs' => ['10.44.0.0/24'],
        'protocols' => ['syslog'],
        'status' => 'active',
    ]);
    expect(fn () => app(SyslogIntakeService::class)->ingest($syslog, '10.44.0.1'))
        ->toThrow(RuntimeException::class, 'does not resolve to one approved Site scope');
    expect(fn () => app(SyslogIntakeService::class)->ingest(str_repeat('x', 8193), '10.44.0.1'))
        ->toThrow(RuntimeException::class, 'exceeds the configured limit');
    expect(fn () => app(FlowIntakeService::class)->ingest(taskTenBoundaryNetFlowV5(1000), '10.44.0.2'))
        ->toThrow(RuntimeException::class, 'does not resolve to one approved Site scope');

    expect(MonitoringOutbox::query()->count())->toBe(0)
        ->and(DeviceEvent::query()->count())->toBe(0);
});

it('keeps listener commands bounded allowlisted distinct and free of inline domain writes', function () {
    $root = str_replace('\\', '/', dirname(__DIR__, 3));
    $snmp = file_get_contents($root.'/app/Console/Commands/MonitoringListenSnmpTraps.php');
    $syslog = file_get_contents($root.'/app/Console/Commands/MonitoringListenSyslog.php');
    $flow = file_get_contents($root.'/app/Console/Commands/MonitoringListenFlow.php');
    $config = file_get_contents($root.'/config/monitoring.php');

    expect($snmp)->toContain('monitoring:listen-snmp-traps', '65_507 + 1', 'ListenerHeartbeatReporter', 'UdpListenerLiveness')
        ->not->toContain('DeviceEvent::')
        ->and($syslog)->toContain('monitoring:listen-syslog', 'SyslogDecoder::MAX_DATAGRAM_BYTES + 1', 'ListenerHeartbeatReporter', 'UdpListenerLiveness')
        ->not->toContain('DeviceEvent::', '0.0.0.0')
        ->and($flow)->toContain('monitoring:listen-flow', '65_507 + 1', 'ListenerHeartbeatReporter', 'UdpListenerLiveness')
        ->not->toContain('DeviceEvent::', '0.0.0.0')
        ->and($config)->toContain("'bind_allowlist'", "'port' => (int) env('MONITORING_SYSLOG_PORT', 5514)", "'port' => (int) env('MONITORING_FLOW_PORT', 2055)")
        ->and(collect(Artisan::all())->keys())->toContain(
            'monitoring:listen-snmp-traps',
            'monitoring:listen-syslog',
            'monitoring:listen-flow',
        );
});
