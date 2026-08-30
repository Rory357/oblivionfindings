<?php

use App\Domain\Monitoring\Contracts\ApprovedProbeScopeProvider;
use App\Domain\Monitoring\Contracts\TcpTransport;
use App\Domain\Monitoring\Contracts\TimeSeriesStore;
use App\Domain\Monitoring\Data\AuthorizedProbeTarget;
use App\Domain\Monitoring\Data\ProbeScope;
use App\Domain\Monitoring\Data\TcpTransportResult;
use App\Domain\Monitoring\Data\TimeSeriesPoint;
use App\Domain\Monitoring\Enums\MonitorKind;
use App\Domain\Monitoring\Enums\MonitorState;
use App\Domain\Monitoring\Exceptions\RuntimeScopeViolation;
use App\Domain\Monitoring\Exceptions\TimeSeriesUnavailable;
use App\Domain\Monitoring\Jobs\RunMonitorCheck;
use App\Domain\Monitoring\Models\MetricPointReceipt;
use App\Domain\Monitoring\Models\Monitor;
use App\Domain\Monitoring\Models\MonitoringCollector;
use App\Domain\Monitoring\Models\MonitoringProfile;
use App\Domain\Monitoring\Models\MonitorObservation;
use App\Domain\Monitoring\Services\EgressPolicy;
use App\Domain\Monitoring\Services\MetricIngestService;
use App\Domain\Monitoring\Services\MonitorCheckRunner;
use App\Domain\Monitoring\Services\MonitoringObservationIngestor;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Domain\SecurityDevices\Models\DeviceEvent;
use App\Models\Site;
use Carbon\CarbonImmutable;

final class TaskFiveRunnerScopeProvider implements ApprovedProbeScopeProvider
{
    public int $calls = 0;

    public function forDeviceAtSite(int $siteId, int $deviceId): ProbeScope
    {
        $this->calls++;

        return new ProbeScope(
            siteId: $siteId,
            deviceId: $deviceId,
            approvedCidrs: ['10.44.0.0/16'],
            allowedPorts: [53, 80, 443],
            connectTimeoutSeconds: 2,
            responseTimeoutSeconds: 5,
            maxResponseBytes: 4096,
        );
    }
}

final class TaskFiveRunnerTcpTransport implements TcpTransport
{
    public int $calls = 0;

    public function __construct(public TcpTransportResult $result) {}

    public function probe(AuthorizedProbeTarget $target): TcpTransportResult
    {
        $this->calls++;

        return $this->result;
    }
}

final class TaskFiveRunnerMetricStore implements TimeSeriesStore
{
    /** @var list<TimeSeriesPoint> */
    public array $points = [];

    public int $writeAttempts = 0;

    private bool $failNextWrite = true;

    public function writePoints(array $points): void
    {
        foreach ($points as $point) {
            $this->writeAttempts++;
            if ($this->failNextWrite) {
                $this->failNextWrite = false;

                throw new TimeSeriesUnavailable('Time-series storage is unavailable.');
            }

            if (! collect($this->points)->contains(
                fn (TimeSeriesPoint $stored): bool => $stored->idempotencyKey === $point->idempotencyKey,
            )) {
                $this->points[] = $point;
            }
        }
    }

    public function range(
        string $externalKey,
        string $tier,
        CarbonImmutable $from,
        CarbonImmutable $to,
    ): array {
        return collect($this->points)
            ->filter(fn (TimeSeriesPoint $point): bool => $point->externalKey === $externalKey
                && $point->tier === $tier
                && $point->observedAt->greaterThanOrEqualTo($from)
                && $point->observedAt->lessThan($to))
            ->values()
            ->all();
    }

    public function deleteRange(
        string $externalKey,
        string $tier,
        CarbonImmutable $from,
        CarbonImmutable $to,
    ): void {
        $this->points = collect($this->points)
            ->reject(fn (TimeSeriesPoint $point): bool => $point->externalKey === $externalKey
                && $point->tier === $tier
                && $point->observedAt->greaterThanOrEqualTo($from)
                && $point->observedAt->lessThan($to))
            ->values()
            ->all();
    }

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

/** @return array{site: Site, device: Device, profile: MonitoringProfile, monitor: Monitor, scope: TaskFiveRunnerScopeProvider, transport: TaskFiveRunnerTcpTransport} */
function taskFiveRunnableMonitor(TcpTransportResult $result): array
{
    $site = Site::factory()->create([
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
        'failure_confirmations' => 1,
        'recovery_confirmations' => 1,
        'is_active' => true,
    ]);
    $monitor = Monitor::factory()->create([
        'device_id' => $device->id,
        'profile_id' => $profile->id,
        'collector_id' => null,
        'kind' => MonitorKind::Tcp,
        'target' => '10.44.1.8',
        'config' => ['host' => '10.44.1.8', 'port' => 443],
        'current_state' => MonitorState::Unknown,
        'is_enabled' => true,
    ]);
    $scope = new TaskFiveRunnerScopeProvider;
    $transport = new TaskFiveRunnerTcpTransport($result);
    app()->instance(ApprovedProbeScopeProvider::class, $scope);
    app()->instance(TcpTransport::class, $transport);
    app()->forgetInstance(EgressPolicy::class);

    return compact('site', 'device', 'profile', 'monitor', 'scope', 'transport');
}

it('runs on monitoring-checks and publishes one idempotent canonical observation', function () {
    $record = taskFiveRunnableMonitor(new TcpTransportResult(true, 9, 'connected'));
    $job = new RunMonitorCheck($record['monitor']->id, 'scheduled:2026-07-23T00:00:00Z');

    expect($job->connection)->toBe('redis')
        ->and($job->queue)->toBe('monitoring-checks')
        ->and($job->tries)->toBe(3)
        ->and($job->timeout)->toBe(90)
        ->and($job->uniqueId())->toBe($record['monitor']->id.':scheduled:2026-07-23T00:00:00Z');

    $job->handle(app(MonitorCheckRunner::class));
    $job->handle(app(MonitorCheckRunner::class));

    $sourceKey = "runtime:{$record['monitor']->id}:scheduled:2026-07-23T00:00:00Z";
    $observation = MonitorObservation::query()->where('source_key', $sourceKey)->sole();
    expect($observation->state)->toBe(MonitorState::Healthy)
        ->and($observation->value)->toBe('9.000000')
        ->and($observation->unit)->toBe('ms')
        ->and($observation->latency_ms)->toBe(9)
        ->and($observation->message)->toBe('connected')
        ->and($observation->metrics)->toMatchArray([
            'reason_code' => 'connected',
            'protocol_kind' => 'tcp',
            'port' => 443,
        ])
        ->and($record['transport']->calls)->toBe(1)
        ->and($record['scope']->calls)->toBe(1);
});

it('retries incomplete canonical metrics without probing the direct target again', function () {
    config()->set('monitoring.storage.timeseries.url', 'https://timeseries.example.test');
    $record = taskFiveRunnableMonitor(new TcpTransportResult(true, 9, 'connected'));
    $store = new TaskFiveRunnerMetricStore;
    app()->instance(TimeSeriesStore::class, $store);
    app()->forgetInstance(MetricIngestService::class);
    app()->forgetInstance(MonitoringObservationIngestor::class);
    app()->forgetInstance(MonitorCheckRunner::class);
    $job = new RunMonitorCheck($record['monitor']->id, 'scheduled:metric-recovery-proof');

    expect(fn () => $job->handle(app(MonitorCheckRunner::class)))
        ->toThrow(TimeSeriesUnavailable::class);

    $observation = MonitorObservation::query()
        ->where('monitor_id', $record['monitor']->id)
        ->sole();
    expect($observation->metrics_projected_at)->toBeNull()
        ->and($record['transport']->calls)->toBe(1)
        ->and($record['scope']->calls)->toBe(1)
        ->and($store->writeAttempts)->toBe(1)
        ->and($store->points)->toBe([]);

    $job->handle(app(MonitorCheckRunner::class));

    expect($record['transport']->calls)->toBe(1)
        ->and($record['scope']->calls)->toBe(1)
        ->and(MonitorObservation::query()->where('monitor_id', $record['monitor']->id)->count())->toBe(1)
        ->and($observation->fresh()->metrics_projected_at)->not->toBeNull()
        ->and($store->writeAttempts)->toBe(4)
        ->and($store->points)->toHaveCount(3)
        ->and(collect($store->points)->pluck('metric')->sort()->values()->all())
        ->toBe(['monitor.latency', 'monitor.value', 'observation.port'])
        ->and(MetricPointReceipt::query()->count())->toBe(3);
});

it('publishes a direct failure through the existing DeviceEvent lifecycle', function () {
    $record = taskFiveRunnableMonitor(new TcpTransportResult(false, null, 'connection_refused'));

    (new RunMonitorCheck($record['monitor']->id, 'manual:failure-proof'))
        ->handle(app(MonitorCheckRunner::class));

    $monitor = $record['monitor']->fresh();
    $observation = $monitor->observations()->sole();
    $event = DeviceEvent::query()
        ->where('device_id', $record['device']->id)
        ->where('source', 'oblivion_monitoring')
        ->sole();
    expect($monitor->current_state)->toBe(MonitorState::Failed)
        ->and($observation->state)->toBe(MonitorState::Failed)
        ->and($observation->message)->toBe('connection_refused')
        ->and($observation->metrics)->not->toHaveKeys(['body', 'authorization', 'cookie'])
        ->and($event->event_type)->toBe('offline')
        ->and($event->payload['observation_id'] ?? null)->toBe($observation->id);
});

it('fails before transport for disabled inactive collected or unsupported monitors', function (Closure $mutate) {
    $record = taskFiveRunnableMonitor(new TcpTransportResult(true, 9, 'connected'));
    $mutate($record);

    expect(fn () => app(MonitorCheckRunner::class)->run($record['monitor']->id, 'scheduled:rejected'))
        ->toThrow(RuntimeScopeViolation::class)
        ->and($record['transport']->calls)->toBe(0)
        ->and(MonitorObservation::query()->where('monitor_id', $record['monitor']->id)->count())->toBe(0);
})->with([
    'disabled monitor' => fn (array $record) => $record['monitor']->update(['is_enabled' => false]),
    'inactive profile' => fn (array $record) => $record['profile']->update(['is_active' => false]),
    'collector-backed monitor' => function (array $record): void {
        $collector = MonitoringCollector::factory()->create([
            'site_id' => $record['site']->id,
            'status' => 'online',
        ]);
        $record['monitor']->update(['collector_id' => $collector->id]);
    },
    'unsupported kind' => fn (array $record) => $record['monitor']->update(['kind' => MonitorKind::Provider]),
]);

it('rejects malformed schedule keys without dispatching or persisting work', function (string $scheduleKey) {
    $record = taskFiveRunnableMonitor(new TcpTransportResult(true, 9, 'connected'));

    expect(fn () => new RunMonitorCheck($record['monitor']->id, $scheduleKey))
        ->toThrow(InvalidArgumentException::class)
        ->and($record['transport']->calls)->toBe(0)
        ->and(MonitorObservation::query()->where('monitor_id', $record['monitor']->id)->count())->toBe(0);
})->with([
    'empty' => '',
    'space' => 'manual:contains a space',
    'slash' => 'manual:../../secret',
    'too long' => str_repeat('a', 191),
]);
