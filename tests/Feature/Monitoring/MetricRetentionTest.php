<?php

use App\Domain\Monitoring\Contracts\TimeSeriesStore;
use App\Domain\Monitoring\Data\MetricSample;
use App\Domain\Monitoring\Data\ObservationInput;
use App\Domain\Monitoring\Data\RuntimeEnvelope;
use App\Domain\Monitoring\Data\TimeSeriesPoint;
use App\Domain\Monitoring\Enums\MonitorState;
use App\Domain\Monitoring\Enums\RuntimeMessageType;
use App\Domain\Monitoring\Exceptions\TimeSeriesUnavailable;
use App\Domain\Monitoring\Handlers\EventEnvelopeHandler;
use App\Domain\Monitoring\Jobs\DownsampleMetrics;
use App\Domain\Monitoring\Models\MetricCurrentSummary;
use App\Domain\Monitoring\Models\MetricSeries;
use App\Domain\Monitoring\Models\Monitor;
use App\Domain\Monitoring\Models\MonitoringRetentionPolicy;
use App\Domain\Monitoring\Models\MonitoringRetentionTombstone;
use App\Domain\Monitoring\Services\CapacityProjectionService;
use App\Domain\Monitoring\Services\MetricIngestService;
use App\Domain\Monitoring\Services\MonitoringObservationIngestor;
use App\Domain\Monitoring\Services\RetentionEnforcer;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Infrastructure\Monitoring\InfluxDbTimeSeriesStore;
use App\Models\Site;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-07-23T12:00:00Z');
    $this->store = new MetricRetentionFakeTimeSeriesStore;
    app()->instance(TimeSeriesStore::class, $this->store);
    config()->set('monitoring.storage.capacity.minimum_samples', 6);
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

it('stores samples outside MySQL and retains only canonical pointers and current summaries', function (): void {
    [$site, $device, $monitor] = metricRetentionMonitor();

    app(MetricIngestService::class)->write($monitor, new MetricSample(
        metric: 'interface.in_bytes',
        value: 1024,
        unit: 'bytes',
        dimensions: ['if_index' => '1', 'interface' => 'WAN 1'],
        observedAt: CarbonImmutable::parse('2026-07-23T11:59:00Z'),
        source: 'native_snmp',
    ));

    $series = MetricSeries::query()->sole();
    $summary = MetricCurrentSummary::query()->sole();

    expect($this->store->points)->toHaveCount(1)
        ->and($series->site_id)->toBe($site->id)
        ->and($series->device_id)->toBe($device->id)
        ->and($series->monitor_id)->toBe($monitor->id)
        ->and($series->dimensions)->toBe(['if_index' => '1', 'interface' => 'WAN 1'])
        ->and($series->external_key)->not->toBeEmpty()
        ->and($summary->value)->toBe('1024.000000')
        ->and($summary->storage_state)->toBe('available')
        ->and(Schema::hasTable('monitor_metric_samples'))->toBeFalse();
});

it('deduplicates a timestamp and rejects a unit conflict for the same metric identity', function (): void {
    [, , $monitor] = metricRetentionMonitor();
    $sample = new MetricSample(
        metric: 'interface.utilisation',
        value: 42.5,
        unit: 'percent',
        dimensions: ['if_index' => '7'],
        observedAt: CarbonImmutable::parse('2026-07-23T11:55:00Z'),
        source: 'native_snmp',
    );

    $service = app(MetricIngestService::class);
    $service->write($monitor, $sample);
    $service->write($monitor, $sample);

    expect($this->store->points)->toHaveCount(1)
        ->and(MetricSeries::query()->count())->toBe(1)
        ->and(MetricCurrentSummary::query()->sole()->sample_count)->toBe(1);

    expect(fn () => $service->write($monitor, new MetricSample(
        metric: 'interface.utilisation',
        value: 0.425,
        unit: 'ratio',
        dimensions: ['if_index' => '7'],
        observedAt: CarbonImmutable::parse('2026-07-23T11:56:00Z'),
        source: 'native_snmp',
    )))->toThrow(InvalidArgumentException::class, 'unit');
});

it('marks external write failure as unavailable without advancing healthy evidence', function (): void {
    [, , $monitor] = metricRetentionMonitor();
    $service = app(MetricIngestService::class);
    $service->write($monitor, new MetricSample(
        metric: 'latency',
        value: 10,
        unit: 'milliseconds',
        observedAt: CarbonImmutable::parse('2026-07-23T11:50:00Z'),
    ));

    $this->store->failWrites = true;

    expect(fn () => $service->write($monitor, new MetricSample(
        metric: 'latency',
        value: 999,
        unit: 'milliseconds',
        observedAt: CarbonImmutable::parse('2026-07-23T11:51:00Z'),
    )))->toThrow(TimeSeriesUnavailable::class);

    $summary = MetricCurrentSummary::query()->sole();
    expect($summary->value)->toBe('10.000000')
        ->and($summary->observed_at->toIso8601String())->toBe('2026-07-23T11:50:00+00:00')
        ->and($summary->storage_state)->toBe('unavailable');
});

it('creates hourly and daily percentile rollups without a raw sample table', function (): void {
    [, , $monitor] = metricRetentionMonitor();
    $service = app(MetricIngestService::class);

    foreach ([10, 20, 30, 40, 50, 60] as $minute => $value) {
        $service->write($monitor, new MetricSample(
            metric: 'wan.utilisation',
            value: $value,
            unit: 'percent',
            observedAt: CarbonImmutable::parse("2026-07-22T10:0{$minute}:00Z"),
        ));
    }

    app(DownsampleMetrics::class)->handle($this->store, $service);

    $hourly = MetricSeries::query()->where('retention_tier', 'hourly')->sole();
    $daily = MetricSeries::query()->where('retention_tier', 'daily')->sole();
    $hourlyPoint = collect($this->store->points)->first(
        fn (TimeSeriesPoint $point): bool => $point->externalKey === $hourly->external_key,
    );
    $dailyPoint = collect($this->store->points)->first(
        fn (TimeSeriesPoint $point): bool => $point->externalKey === $daily->external_key,
    );

    expect($hourlyPoint?->statistics)->toMatchArray([
        'p50' => 35.0,
        'p95' => 57.5,
        'min' => 10.0,
        'max' => 60.0,
        'count' => 6,
    ])->and($dailyPoint?->statistics['count'])->toBe(6)
        ->and(Schema::hasTable('monitor_metric_samples'))->toBeFalse();
});

it('applies the most restrictive matching privacy policy and writes value-free tombstones', function (): void {
    [$site, $device, $monitor] = metricRetentionMonitor();
    $service = app(MetricIngestService::class);
    $service->write($monitor, new MetricSample(
        metric: 'occupancy.count',
        value: 123456.789,
        unit: 'count',
        observedAt: CarbonImmutable::parse('2026-07-18T12:00:00Z'),
        dataClass: 'operational',
        privacyClass: 'sensitive',
    ));

    MonitoringRetentionPolicy::query()->updateOrCreate(
        ['scope_kind' => 'application'],
        [
            'name' => 'Application baseline',
            'raw_days' => 30,
            'hourly_days' => 180,
            'daily_days' => 1825,
            'legal_hold' => false,
            'is_active' => true,
        ],
    );
    MonitoringRetentionPolicy::query()->create([
        'name' => 'Site baseline',
        'scope_kind' => 'site',
        'site_id' => $site->id,
        'raw_days' => 14,
        'hourly_days' => 90,
        'daily_days' => 730,
        'is_active' => true,
    ]);
    $privacy = MonitoringRetentionPolicy::query()->create([
        'name' => 'Sensitive operational minimisation',
        'scope_kind' => 'privacy',
        'privacy_class' => 'sensitive',
        'raw_days' => 2,
        'hourly_days' => 30,
        'daily_days' => 365,
        'is_active' => true,
    ]);

    $result = app(RetentionEnforcer::class)->enforce(
        CarbonImmutable::now(),
        actorId: null,
        jobReference: 'retention-test-001',
    );

    $tombstone = MonitoringRetentionTombstone::query()->sole();
    $encoded = json_encode($tombstone->toArray(), JSON_THROW_ON_ERROR);

    expect($result['metric_payloads_deleted'])->toBe(1)
        ->and($this->store->deletions)->toHaveCount(1)
        ->and($tombstone->policy_id)->toBe($privacy->id)
        ->and($tombstone->site_id)->toBe($site->id)
        ->and($tombstone->device_id)->toBe($device->id)
        ->and($tombstone->monitor_id)->toBe($monitor->id)
        ->and($tombstone->data_class)->toBe('operational')
        ->and($tombstone->job_reference)->toBe('retention-test-001')
        ->and($encoded)->not->toContain('123456.789')
        ->and($encoded)->not->toContain('value');
});

it('preserves payloads under legal hold and identifies missing restored pointers', function (): void {
    [, , $monitor] = metricRetentionMonitor();
    app(MetricIngestService::class)->write($monitor, new MetricSample(
        metric: 'device.temperature',
        value: 18.4,
        unit: 'celsius',
        observedAt: CarbonImmutable::parse('2025-01-01T00:00:00Z'),
    ));
    MonitoringRetentionPolicy::query()->create([
        'name' => 'Held device evidence',
        'scope_kind' => 'device',
        'device_id' => $monitor->device_id,
        'raw_days' => 1,
        'hourly_days' => 1,
        'daily_days' => 1,
        'legal_hold' => true,
        'is_active' => true,
    ]);

    $enforcer = app(RetentionEnforcer::class);
    $result = $enforcer->enforce(CarbonImmutable::now(), jobReference: 'retention-test-002');

    expect($result['held_series'])->toBe(1)
        ->and($this->store->deletions)->toBe([])
        ->and(MonitoringRetentionTombstone::query()->count())->toBe(0);

    $this->store->missingAll = true;
    expect($enforcer->validatePointers())->toHaveCount(1)
        ->and(MetricCurrentSummary::query()->sole()->storage_state)->toBe('missing');
});

it('returns an explainable forecast and an honest insufficient-data state', function (): void {
    [, , $monitor] = metricRetentionMonitor();
    $service = app(MetricIngestService::class);

    foreach ([40, 45, 50, 55, 60, 65, 70, 75] as $hour => $value) {
        $service->write($monitor, new MetricSample(
            metric: 'wan.utilisation',
            value: $value,
            unit: 'percent',
            observedAt: CarbonImmutable::parse('2026-07-22T00:00:00Z')->addHours($hour),
        ));
    }

    $series = MetricSeries::query()->where('metric', 'wan.utilisation')->sole();
    $projection = app(CapacityProjectionService::class)->project(
        $series,
        threshold: 90,
        from: CarbonImmutable::parse('2026-07-21T00:00:00Z'),
        to: CarbonImmutable::now(),
    );

    expect($projection->state)->toBe('forecast')
        ->and($projection->sampleCount)->toBe(8)
        ->and($projection->p95)->toBe(75.0)
        ->and($projection->slopePerDay)->toBeGreaterThan(0)
        ->and($projection->confidence)->toBeGreaterThan(0.9)
        ->and($projection->thresholdAt)->not->toBeNull()
        ->and($projection->measuredFrom->toIso8601String())->toBe('2026-07-22T00:00:00+00:00')
        ->and($projection->measuredTo->toIso8601String())->toBe('2026-07-22T07:00:00+00:00');

    config()->set('monitoring.storage.capacity.minimum_samples', 20);
    expect(app(CapacityProjectionService::class)->project(
        $series,
        threshold: 90,
        from: CarbonImmutable::parse('2026-07-21T00:00:00Z'),
        to: CarbonImmutable::now(),
    )->state)->toBe('insufficient_data');
});

it('uses one configured Influx scope, bounded batches, idempotency tags, and redacted failures', function (): void {
    config()->set('monitoring.storage.timeseries', [
        'driver' => 'influxdb',
        'url' => 'https://influx.example.test',
        'token' => 'RAW-INFLUX-TOKEN-SENTINEL',
        'organisation' => 'oblivion-findings',
        'bucket' => 'native-monitoring',
        'maximum_batch_points' => 1,
        'connect_timeout_seconds' => 1,
        'response_timeout_seconds' => 2,
    ]);
    Http::fake([
        'https://influx.example.test/api/v2/write*' => Http::response('', 204),
        'https://influx.example.test/health' => Http::response(['status' => 'pass']),
    ]);
    $store = new InfluxDbTimeSeriesStore;
    $point = new TimeSeriesPoint(
        externalKey: str_repeat('a', 64),
        seriesId: 10,
        siteId: 20,
        deviceId: 30,
        monitorId: 40,
        metric: 'interface.in_bytes',
        value: 100,
        unit: 'bytes',
        dimensions: ['if_index' => '1'],
        tier: 'raw',
        observedAt: CarbonImmutable::parse('2026-07-23T11:00:00Z'),
        idempotencyKey: str_repeat('b', 64),
    );

    $store->writePoints([$point]);

    Http::assertSent(fn ($request): bool => str_contains($request->url(), '/api/v2/write')
        && str_contains($request->url(), 'org=oblivion-findings')
        && str_contains($request->url(), 'bucket=native-monitoring')
        && $request->hasHeader('Authorization', 'Bearer RAW-INFLUX-TOKEN-SENTINEL')
        && str_contains($request->body(), 'idempotency_key="'.str_repeat('b', 64).'"'));
    expect($store->healthy())->toBeTrue();

    expect(fn () => $store->writePoints([$point, $point]))
        ->toThrow(TimeSeriesUnavailable::class, 'batch');

    config()->set('monitoring.storage.timeseries.url', 'https://failing-influx.example.test');
    Http::fake(['https://failing-influx.example.test/*' => Http::response('RAW-INFLUX-TOKEN-SENTINEL', 500)]);
    $failingStore = new InfluxDbTimeSeriesStore;
    try {
        $failingStore->writePoints([$point]);
        test()->fail('Expected the unavailable exception.');
    } catch (TimeSeriesUnavailable $exception) {
        expect($exception->getMessage())->not->toContain('RAW-INFLUX-TOKEN-SENTINEL');
    }
});

it('projects signed flow buckets to external metric series without creating a parallel alert path', function (): void {
    $site = Site::factory()->create(['is_active' => true, 'archived' => false, 'archived_at' => null]);
    $device = Device::factory()->itInfrastructure()->create(['ip_address' => '10.20.30.1']);
    DeviceAssignment::query()->create([
        'device_id' => $device->id,
        'assignable_type' => DeviceAssignment::TARGET_SITE,
        'assignable_id' => $site->id,
        'assignment_type' => 'permanent',
        'assigned_at' => now()->subDay(),
    ]);
    $envelope = RuntimeEnvelope::new(
        RuntimeMessageType::Event,
        'flow:site:'.$site->id,
        1,
        'flow-envelope-001',
        [
            'event_family' => 'flow_metric',
            'site_id' => $site->id,
            'source_address' => '10.20.30.1',
            'protocol_family' => 'ipfix',
            'source_id' => 10,
            'sequence' => 20,
            'buckets' => [[
                'application' => 'https',
                'bucket_start' => '2026-07-23T11:55:00Z',
                'bytes' => 4096,
                'direction' => 'egress',
                'flow_count' => 4,
                'input_interface' => 1,
                'output_interface' => 2,
                'packets' => 32,
                'protocol' => 6,
            ]],
        ],
    );

    app(EventEnvelopeHandler::class)->handle($envelope, $site->id);

    expect($this->store->points)->toHaveCount(3)
        ->and(MetricSeries::query()->pluck('metric')->sort()->values()->all())
        ->toBe(['flow.bytes', 'flow.count', 'flow.packets'])
        ->and(DB::table('device_events')->count())->toBe(0)
        ->and(Schema::hasTable('monitor_metric_samples'))->toBeFalse();
});

it('projects safe numeric monitor observations to external history while retaining canonical current state', function (): void {
    config()->set('monitoring.storage.timeseries.url', 'https://timeseries.example.test');
    [$site, $device, $monitor] = metricRetentionMonitor();

    $result = app(MonitoringObservationIngestor::class)->ingest(
        $monitor,
        new ObservationInput(
            sourceKey: 'metric-projection-observation-001',
            state: MonitorState::Healthy,
            observedAt: CarbonImmutable::parse('2026-07-23T11:58:00Z'),
            value: 1,
            unit: 'online',
            latencyMs: 12,
            metrics: [
                'if_index' => 7,
                'interface_name' => 'WAN 1',
                'in_bps' => 850_000_000,
                'status' => 'up',
            ],
        ),
        $site->id,
        $device->id,
        null,
    );

    expect($result->observation->site_id)->toBe($site->id)
        ->and($this->store->points)->toHaveCount(3)
        ->and(MetricSeries::query()->pluck('metric')->sort()->values()->all())
        ->toBe(['monitor.latency', 'monitor.value', 'observation.in_bps'])
        ->and(MetricSeries::query()->pluck('privacy_class')->unique()->all())->toBe(['standard']);
});

/** @return array{Site, Device, Monitor} */
function metricRetentionMonitor(): array
{
    $site = Site::factory()->create(['is_active' => true, 'archived' => false, 'archived_at' => null]);
    $device = Device::factory()->itInfrastructure()->create();
    DeviceAssignment::query()->create([
        'device_id' => $device->id,
        'assignable_type' => DeviceAssignment::TARGET_SITE,
        'assignable_id' => $site->id,
        'assignment_type' => 'permanent',
        'assigned_at' => now()->subDay(),
    ]);
    $monitor = Monitor::factory()->create(['device_id' => $device->id]);

    return [$site, $device, $monitor];
}

final class MetricRetentionFakeTimeSeriesStore implements TimeSeriesStore
{
    /** @var list<TimeSeriesPoint> */
    public array $points = [];

    /** @var list<array<string, mixed>> */
    public array $deletions = [];

    public bool $failWrites = false;

    public bool $missingAll = false;

    public function writePoints(array $points): void
    {
        if ($this->failWrites) {
            throw new TimeSeriesUnavailable('Time-series storage is unavailable.');
        }

        foreach ($points as $point) {
            $duplicate = collect($this->points)->contains(
                fn (TimeSeriesPoint $stored): bool => $stored->idempotencyKey === $point->idempotencyKey,
            );
            if (! $duplicate) {
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
                && $point->observedAt->betweenIncluded($from, $to))
            ->sortBy(fn (TimeSeriesPoint $point): int => $point->observedAt->getTimestamp())
            ->values()
            ->all();
    }

    public function deleteRange(
        string $externalKey,
        string $tier,
        CarbonImmutable $from,
        CarbonImmutable $to,
    ): void {
        if ($this->failWrites) {
            throw new TimeSeriesUnavailable('Time-series storage is unavailable.');
        }

        $this->deletions[] = compact('externalKey', 'tier', 'from', 'to');
        $this->points = collect($this->points)
            ->reject(fn (TimeSeriesPoint $point): bool => $point->externalKey === $externalKey
                && $point->tier === $tier
                && $point->observedAt->betweenIncluded($from, $to))
            ->values()
            ->all();
    }

    public function exists(
        string $externalKey,
        string $tier,
        ?CarbonImmutable $from = null,
        ?CarbonImmutable $to = null,
    ): bool {
        return ! $this->missingAll && collect($this->points)->contains(
            fn (TimeSeriesPoint $point): bool => $point->externalKey === $externalKey
                && $point->tier === $tier
                && ($from === null || $point->observedAt->greaterThanOrEqualTo($from))
                && ($to === null || $point->observedAt->lessThan($to)),
        );
    }

    public function healthy(): bool
    {
        return ! $this->failWrites;
    }
}
