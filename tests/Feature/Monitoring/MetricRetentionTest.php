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
use App\Domain\Monitoring\Models\MetricRollupCoverage;
use App\Domain\Monitoring\Models\MetricSeries;
use App\Domain\Monitoring\Models\Monitor;
use App\Domain\Monitoring\Models\MonitoringRetentionDeletionIntent;
use App\Domain\Monitoring\Models\MonitoringRetentionPolicy;
use App\Domain\Monitoring\Models\MonitoringRetentionTombstone;
use App\Domain\Monitoring\Services\CapacityProjectionService;
use App\Domain\Monitoring\Services\MetricIngestService;
use App\Domain\Monitoring\Services\MonitoringObservationIngestor;
use App\Domain\Monitoring\Services\ProductionRetentionEndpointAttestation;
use App\Domain\Monitoring\Services\ProductionRetentionEndpointGuard;
use App\Domain\Monitoring\Services\ProductionRetentionEvidenceVerifier;
use App\Domain\Monitoring\Services\RetentionEnforcer;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Infrastructure\Monitoring\InfluxDbTimeSeriesStore;
use App\Models\Site;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
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

it('refuses to label a local fixture runtime as production retention evidence', function (): void {
    $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'retention-command-'.bin2hex(random_bytes(8));
    mkdir($directory, 0700);
    if (DIRECTORY_SEPARATOR !== '\\') {
        chmod($directory, 0700);
    }

    try {
        $this->artisan('monitoring:record-production-retention-evidence', [
            '--output-directory' => $directory,
            '--json' => true,
        ])->expectsOutputToContain('"a05_release_evidence":false')
            ->assertFailed();

        expect(glob($directory.DIRECTORY_SEPARATOR.'*') ?: [])->toBe([])
            ->and(MonitoringRetentionTombstone::query()->count())->toBe(0);
    } finally {
        rmdir($directory);
    }
});

it('rejects reserved production endpoints and endpoint attestations that do not match their signature or live pins', function (): void {
    $guard = new ProductionRetentionEndpointGuard;
    $settings = [
        'driver' => 'influxdb',
        'url' => 'https://localhost:8086',
        'token' => 'configured-secret',
        'organisation' => 'configured-org',
        'bucket' => 'configured-bucket',
    ];

    expect($guard->errors(
        'production',
        false,
        'mysql',
        InfluxDbTimeSeriesStore::class,
        $settings,
        ['host' => 'mysql.invalid', 'database' => 'oblivion'],
    ))->toContain('pinned_mysql_endpoint_required', 'secure_influxdb_url_required');

    $attestation = new ProductionRetentionEndpointAttestation;
    $keyPair = sodium_crypto_sign_seed_keypair(str_repeat("\x63", SODIUM_CRYPTO_SIGN_SEEDBYTES));
    $publicKey = sodium_crypto_sign_publickey($keyPair);
    $secretKey = sodium_crypto_sign_secretkey($keyPair);
    $releaseRevision = str_repeat('d', 40);
    $observed = [
        'mysql_endpoint_sha256' => str_repeat('a', 64),
        'influx_scope_sha256' => str_repeat('b', 64),
        'influx_tls_certificate_sha256' => str_repeat('c', 64),
    ];
    $document = [
        'schema' => 'monitoring-production-retention-endpoint-attestation-v1',
        'run_id' => '018f47a8-674f-7d2c-9f1c-9d5f82f7d128',
        'release_revision' => $releaseRevision,
        'valid_from_utc' => CarbonImmutable::now()->subHour()->toIso8601ZuluString(),
        'valid_until_utc' => CarbonImmutable::now()->addHour()->toIso8601ZuluString(),
        ...$observed,
        'key_reference' => 'ATTEST-'.substr(hash('sha256', $publicKey), 0, 32),
    ];
    $document['signature_base64'] = base64_encode(sodium_crypto_sign_detached(
        "oblivion-a05-production-endpoints-v1\n".$attestation->canonicalJson($document),
        $secretKey,
    ));

    $encoded = json_encode($document, JSON_THROW_ON_ERROR);
    $duplicate = preg_replace(
        '/\A\{/',
        '{"run_id":"'.$document['run_id'].'",',
        $encoded,
        1,
    );
    $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'retention-attestation-'.bin2hex(random_bytes(8)).'.json';
    file_put_contents($path, $duplicate);
    try {
        expect(fn () => $attestation->load(
            $path,
            $observed,
            $releaseRevision,
            CarbonImmutable::now(),
            $publicKey,
        ))->toThrow(RuntimeException::class, 'attestation is invalid');
    } finally {
        @unlink($path);
    }

    $wrongPins = $observed;
    $wrongPins['influx_scope_sha256'] = str_repeat('f', 64);
    expect(fn () => $attestation->verify(
        $document,
        $wrongPins,
        $releaseRevision,
        CarbonImmutable::now(),
        $publicKey,
    ))->toThrow(RuntimeException::class, 'does not match the live endpoints');

    $validFrom = $document['valid_from_utc'];
    $document['valid_from_utc'] = CarbonImmutable::now()->subHour()->format('Y-m-d\TH:i:sP');
    $document['signature_base64'] = base64_encode(sodium_crypto_sign_detached(
        "oblivion-a05-production-endpoints-v1\n".$attestation->canonicalJson(
            Arr::except($document, 'signature_base64'),
        ),
        $secretKey,
    ));
    expect(fn () => $attestation->verify(
        $document,
        $observed,
        $releaseRevision,
        CarbonImmutable::now(),
        $publicKey,
    ))->toThrow(RuntimeException::class, 'outside its approved window');

    $document['valid_from_utc'] = $validFrom;
    $document['signature_base64'] = base64_encode(str_repeat("\x00", SODIUM_CRYPTO_SIGN_BYTES));
    expect(fn () => $attestation->verify(
        $document,
        $observed,
        $releaseRevision,
        CarbonImmutable::now(),
        $publicKey,
    ))->toThrow(RuntimeException::class, 'signature is invalid');
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
        ->and(MetricRollupCoverage::query()->count())->toBe(2)
        ->and(MetricRollupCoverage::query()
            ->where('source_series_id', MetricSeries::query()->where('retention_tier', 'raw')->value('id'))
            ->where('target_tier', 'hourly')
            ->where('target_series_id', $hourly->id)
            ->where('covered_from', '2026-07-22 10:00:00.000000')
            ->where('covered_until', '2026-07-23 12:00:00.000000')
            ->exists())->toBeTrue()
        ->and(Schema::hasTable('monitor_metric_samples'))->toBeFalse();
});

it('keeps rollup coverage unique and rejects invalid tier or interval watermarks', function (): void {
    [, , $monitor] = metricRetentionMonitor();
    $service = app(MetricIngestService::class);
    $service->write($monitor, new MetricSample(
        metric: 'wan.utilisation',
        value: 42,
        unit: 'percent',
        observedAt: CarbonImmutable::parse('2026-07-22T10:05:00Z'),
    ));
    app(DownsampleMetrics::class)->handle($this->store, $service);

    $coverage = MetricRollupCoverage::query()
        ->where('target_tier', 'hourly')
        ->sole();
    $base = [
        'source_series_id' => $coverage->source_series_id,
        'target_series_id' => $coverage->target_series_id,
        'covered_from' => '2026-07-22 10:00:00.000000',
        'covered_until' => '2026-07-23 10:00:00.000000',
        'completed_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ];

    expect(fn () => DB::table('monitoring_metric_rollup_coverages')->insert([
        ...$base,
        'target_tier' => 'hourly',
    ]))->toThrow(QueryException::class)
        ->and(fn () => DB::table('monitoring_metric_rollup_coverages')->insert([
            ...$base,
            'target_tier' => 'minute',
        ]))->toThrow(QueryException::class)
        ->and(fn () => DB::table('monitoring_metric_rollup_coverages')->insert([
            ...$base,
            'target_tier' => 'daily',
            'covered_until' => $base['covered_from'],
        ]))->toThrow(QueryException::class);

    expect(array_keys($coverage->getAttributes()))
        ->not->toContain('value', 'statistics', 'dimensions', 'external_key');
});

it('rewinds a durable watermark before accepting a late source observation', function (): void {
    [, , $monitor] = metricRetentionMonitor();
    $service = app(MetricIngestService::class);
    $service->write($monitor, new MetricSample(
        metric: 'wan.utilisation',
        value: 40,
        unit: 'percent',
        observedAt: CarbonImmutable::parse('2026-07-20T10:05:00Z'),
    ));
    app(DownsampleMetrics::class)->handle($this->store, $service);

    $raw = MetricSeries::query()->where('retention_tier', 'raw')->sole();
    $coverage = MetricRollupCoverage::query()
        ->where('source_series_id', $raw->id)
        ->where('target_tier', 'hourly')
        ->sole();
    expect($coverage->covered_until->toIso8601String())->toBe('2026-07-23T12:00:00+00:00');

    $service->write($monitor, new MetricSample(
        metric: 'wan.utilisation',
        value: 80,
        unit: 'percent',
        observedAt: CarbonImmutable::parse('2026-07-21T09:45:00Z'),
    ));

    $coverage->refresh();
    expect($coverage->covered_from->toIso8601String())->toBe('2026-07-20T10:00:00+00:00')
        ->and($coverage->covered_until->toIso8601String())->toBe('2026-07-21T09:00:00+00:00');
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

    app(DownsampleMetrics::class)->handle($this->store, $service);

    $result = app(RetentionEnforcer::class)->enforce(
        CarbonImmutable::now(),
        actorId: null,
        jobReference: 'retention-test-001',
    );

    $tombstone = MonitoringRetentionTombstone::query()->sole();
    $encoded = json_encode($tombstone->toArray(), JSON_THROW_ON_ERROR);

    expect($result['metric_payloads_deleted'])->toBe(1)
        ->and($result['rollup_coverage_verified_series'])->toBe(1)
        ->and($result['occupied_rollup_buckets_verified'])->toBe(1)
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

it('fails closed without complete downstream coverage and deletes a half-open source interval', function (): void {
    [, , $monitor] = metricRetentionMonitor();
    $service = app(MetricIngestService::class);
    $cutoff = CarbonImmutable::parse('2026-07-21T12:00:00Z');
    $service->write($monitor, new MetricSample(
        metric: 'occupancy.count',
        value: 111111.125,
        unit: 'count',
        observedAt: CarbonImmutable::parse('2026-07-18T12:00:00Z'),
        dataClass: 'operational',
        privacyClass: 'sensitive',
    ));
    $service->write($monitor, new MetricSample(
        metric: 'occupancy.count',
        value: 166666.5,
        unit: 'count',
        observedAt: CarbonImmutable::parse('2026-07-19T13:00:00Z'),
        dataClass: 'operational',
        privacyClass: 'sensitive',
    ));
    $service->write($monitor, new MetricSample(
        metric: 'occupancy.count',
        value: 222222.25,
        unit: 'count',
        observedAt: $cutoff,
        dataClass: 'operational',
        privacyClass: 'sensitive',
    ));
    MonitoringRetentionPolicy::query()->create([
        'name' => 'Half-open retention proof',
        'scope_kind' => 'privacy',
        'privacy_class' => 'sensitive',
        'raw_days' => 2,
        'hourly_days' => 30,
        'daily_days' => 365,
        'is_active' => true,
    ]);

    $blocked = app(RetentionEnforcer::class)->enforce(
        CarbonImmutable::now(),
        jobReference: 'retention-coverage-blocked',
    );

    expect($blocked['metric_payloads_deleted'])->toBe(0)
        ->and($blocked['rollup_coverage_blocked_series'])->toBe(1)
        ->and($this->store->deletions)->toBe([])
        ->and(MonitoringRetentionTombstone::query()->count())->toBe(0);

    app(DownsampleMetrics::class)->handle($this->store, $service);
    $raw = MetricSeries::query()->where('retention_tier', 'raw')->sole();
    $coverage = MetricRollupCoverage::query()
        ->where('source_series_id', $raw->id)
        ->where('target_tier', 'hourly')
        ->sole();
    $coverage->forceFill(['covered_until' => $cutoff->subMicrosecond()])->save();
    $partial = app(RetentionEnforcer::class)->enforce(
        CarbonImmutable::now(),
        jobReference: 'retention-coverage-partial',
    );
    expect($partial['metric_payloads_deleted'])->toBe(0)
        ->and($partial['rollup_coverage_blocked_series'])->toBe(1)
        ->and($this->store->deletions)->toBe([])
        ->and(MonitoringRetentionTombstone::query()->count())->toBe(0);

    $coverage->forceFill(['covered_until' => $cutoff])->save();

    $hourly = MetricSeries::query()->findOrFail($coverage->target_series_id);
    $hourlyPoints = collect($this->store->points)
        ->filter(fn (TimeSeriesPoint $point): bool => $point->externalKey === $hourly->external_key)
        ->sortBy(fn (TimeSeriesPoint $point): int => $point->observedAt->getTimestamp())
        ->values()
        ->all();
    $deletionIntervalHourlyPoints = collect($hourlyPoints)
        ->filter(fn (TimeSeriesPoint $point): bool => $point->observedAt->lessThan($cutoff))
        ->values();
    expect($hourlyPoints)->toHaveCount(3)
        ->and($deletionIntervalHourlyPoints)->toHaveCount(2);
    /** @var TimeSeriesPoint $missingLaterHourly */
    $missingLaterHourly = $deletionIntervalHourlyPoints->last();
    $this->store->points = collect($this->store->points)
        ->reject(fn (TimeSeriesPoint $point): bool => $point->idempotencyKey === $missingLaterHourly->idempotencyKey)
        ->values()
        ->all();

    $missingDownstream = app(RetentionEnforcer::class)->enforce(
        CarbonImmutable::now(),
        jobReference: 'retention-downstream-missing',
    );
    expect($missingDownstream['metric_payloads_deleted'])->toBe(0)
        ->and($missingDownstream['rollup_coverage_blocked_series'])->toBe(1)
        ->and($this->store->deletions)->toBe([])
        ->and(MonitoringRetentionTombstone::query()->count())->toBe(0);

    $this->store->points[] = $missingLaterHourly;

    /** @var TimeSeriesPoint $corruptedSource */
    $corruptedSource = $deletionIntervalHourlyPoints->first();
    $corruptedRollup = new TimeSeriesPoint(
        externalKey: $corruptedSource->externalKey,
        seriesId: $corruptedSource->seriesId,
        siteId: $corruptedSource->siteId,
        deviceId: $corruptedSource->deviceId,
        monitorId: $corruptedSource->monitorId,
        metric: $corruptedSource->metric,
        value: $corruptedSource->value + 1,
        unit: $corruptedSource->unit,
        dimensions: $corruptedSource->dimensions,
        tier: $corruptedSource->tier,
        observedAt: $corruptedSource->observedAt,
        idempotencyKey: $corruptedSource->idempotencyKey,
        statistics: [
            ...$corruptedSource->statistics,
            'p95' => (float) $corruptedSource->statistics['p95'] + 1,
        ],
    );
    $this->store->points = collect($this->store->points)
        ->map(fn (TimeSeriesPoint $point): TimeSeriesPoint => $point->idempotencyKey === $corruptedSource->idempotencyKey
            ? $corruptedRollup
            : $point)
        ->values()
        ->all();

    $corrupted = app(RetentionEnforcer::class)->enforce(
        CarbonImmutable::now(),
        jobReference: 'retention-downstream-corrupted',
    );
    expect($corrupted['metric_payloads_deleted'])->toBe(0)
        ->and($corrupted['rollup_coverage_blocked_series'])->toBe(1)
        ->and($this->store->deletions)->toBe([])
        ->and(MonitoringRetentionDeletionIntent::query()->count())->toBe(0)
        ->and(MonitoringRetentionTombstone::query()->count())->toBe(0);

    $this->store->points = collect($this->store->points)
        ->map(fn (TimeSeriesPoint $point): TimeSeriesPoint => $point->idempotencyKey === $corruptedSource->idempotencyKey
            ? $corruptedSource
            : $point)
        ->values()
        ->all();

    $deleted = app(RetentionEnforcer::class)->enforce(
        CarbonImmutable::now(),
        jobReference: 'retention-coverage-complete',
    );
    $raw->refresh();
    $tombstone = MonitoringRetentionTombstone::query()->sole();
    $remainingRaw = collect($this->store->points)
        ->filter(fn (TimeSeriesPoint $point): bool => $point->externalKey === $raw->external_key)
        ->values();

    expect($deleted['metric_payloads_deleted'])->toBe(1)
        ->and($deleted['rollup_coverage_blocked_series'])->toBe(0)
        ->and($deleted['rollup_coverage_verified_series'])->toBe(1)
        ->and($deleted['occupied_rollup_buckets_verified'])->toBe(2)
        ->and($this->store->deletions)->toHaveCount(1)
        ->and($this->store->deletions[0]['from']->toIso8601String())->toBe('2026-07-18T12:00:00+00:00')
        ->and($this->store->deletions[0]['to']->equalTo($cutoff))->toBeTrue()
        ->and($remainingRaw)->toHaveCount(1)
        ->and($remainingRaw->sole()->observedAt->equalTo($cutoff))->toBeTrue()
        ->and($raw->first_point_at->equalTo($cutoff))->toBeTrue()
        ->and($raw->last_point_at->equalTo($cutoff))->toBeTrue()
        ->and($tombstone->period_start->toIso8601String())->toBe('2026-07-18T12:00:00+00:00')
        ->and($tombstone->period_end->equalTo($cutoff))->toBeTrue()
        ->and(json_encode($tombstone->toArray(), JSON_THROW_ON_ERROR))
        ->not->toContain('111111.125', '166666.5', '222222.25', 'value');
});

it('recovers a durable deletion intent after external deletion succeeds before database finalisation', function (): void {
    [, , $monitor] = metricRetentionMonitor();
    $ingest = app(MetricIngestService::class);
    $cutoff = CarbonImmutable::parse('2026-07-21T12:00:00Z');

    foreach ([
        '2026-07-18T12:00:00Z' => 12,
        $cutoff->toIso8601ZuluString() => 24,
    ] as $observedAt => $value) {
        $ingest->write($monitor, new MetricSample(
            metric: 'occupancy.count',
            value: $value,
            unit: 'count',
            observedAt: CarbonImmutable::parse($observedAt),
            privacyClass: 'sensitive',
        ));
    }
    MonitoringRetentionPolicy::query()->create([
        'name' => 'Durable deletion intent proof',
        'scope_kind' => 'privacy',
        'privacy_class' => 'sensitive',
        'raw_days' => 2,
        'hourly_days' => 30,
        'daily_days' => 365,
        'is_active' => true,
    ]);
    app(DownsampleMetrics::class)->handle($this->store, $ingest);
    $raw = MetricSeries::query()->where('retention_tier', 'raw')->sole();
    $this->store->failNextExistenceCheckAfterDeletion = true;

    $interrupted = app(RetentionEnforcer::class)->enforce(
        CarbonImmutable::now(),
        jobReference: 'retention-intent-recovery',
        includeSnapshots: false,
    );
    $intent = MonitoringRetentionDeletionIntent::query()->sole();
    $failedVerification = app(ProductionRetentionEvidenceVerifier::class)->verify(
        'retention-intent-recovery',
        CarbonImmutable::now()->subMinute(),
        CarbonImmutable::now()->addMinute(),
        ['held' => []],
        $interrupted,
    );

    expect($interrupted['metric_payloads_deleted'])->toBe(0)
        ->and($interrupted['unresolved_deletion_intents'])->toBe(1)
        ->and($intent->state)->toBe('pending')
        ->and($this->store->deletions)->toHaveCount(1)
        ->and($this->store->exists(
            (string) $raw->external_key,
            'raw',
            CarbonImmutable::parse('2026-07-18T12:00:00Z'),
            $cutoff,
        ))->toBeFalse()
        ->and(MonitoringRetentionTombstone::query()->count())->toBe(0)
        ->and($failedVerification['errors'])->toContain('deletion_intent_unresolved');

    $recovered = app(RetentionEnforcer::class)->enforce(
        CarbonImmutable::now(),
        jobReference: 'retention-intent-recovery',
        includeSnapshots: false,
    );
    $intent->refresh();
    $tombstone = MonitoringRetentionTombstone::query()->sole();
    $raw->refresh();

    expect($recovered['reconciled_deletion_intents'])->toBe(1)
        ->and($recovered['unresolved_deletion_intents'])->toBe(0)
        ->and($recovered['metric_payloads_deleted'])->toBe(1)
        ->and($intent->state)->toBe('completed')
        ->and($intent->delete_acknowledged_at)->not->toBeNull()
        ->and($intent->completed_at)->not->toBeNull()
        ->and($tombstone->deletion_intent_id)->toBe($intent->id)
        ->and($tombstone->deletionIntent?->is($intent))->toBeTrue()
        ->and($raw->first_point_at->equalTo($cutoff))->toBeTrue();
});

it('exercises the production retention verifier contract locally without creating release evidence', function (): void {
    [, , $privacyMonitor] = metricRetentionMonitor();
    [, , $heldMonitor] = metricRetentionMonitor();
    $ingest = app(MetricIngestService::class);

    foreach (['2026-07-18T12:00:00Z', '2026-07-22T13:00:00Z'] as $observedAt) {
        $ingest->write($privacyMonitor, new MetricSample(
            metric: 'occupancy.count',
            value: 12,
            unit: 'count',
            observedAt: CarbonImmutable::parse($observedAt),
            privacyClass: 'sensitive',
        ));
        $ingest->write($heldMonitor, new MetricSample(
            metric: 'device.temperature',
            value: 18,
            unit: 'celsius',
            observedAt: CarbonImmutable::parse($observedAt),
        ));
    }

    MonitoringRetentionPolicy::query()->create([
        'name' => 'Sensitive minimisation verifier fixture',
        'scope_kind' => 'privacy',
        'privacy_class' => 'sensitive',
        'raw_days' => 2,
        'hourly_days' => 1,
        'daily_days' => 365,
        'is_active' => true,
    ]);
    MonitoringRetentionPolicy::query()->create([
        'name' => 'Held verifier fixture',
        'scope_kind' => 'device',
        'device_id' => $heldMonitor->device_id,
        'raw_days' => 1,
        'hourly_days' => 1,
        'daily_days' => 1,
        'legal_hold' => true,
        'is_active' => true,
    ]);

    $started = CarbonImmutable::now()->subMinute();
    $verifier = app(ProductionRetentionEvidenceVerifier::class);
    $before = $verifier->captureBefore(CarbonImmutable::now());
    app(DownsampleMetrics::class)->handle($this->store, $ingest);
    $retention = app(RetentionEnforcer::class)->enforce(
        CarbonImmutable::now(),
        jobReference: 'local-retention-verifier-contract',
        includeSnapshots: false,
    );
    $verification = $verifier->verify(
        'local-retention-verifier-contract',
        $started,
        CarbonImmutable::now()->addMinute(),
        $before,
        $retention,
    );

    expect($verification['errors'])->toBe([])
        ->and($verification['execution']['raw_to_hourly_chain_count'])->toBeGreaterThanOrEqual(1)
        ->and($verification['execution']['hourly_to_daily_chain_count'])->toBeGreaterThanOrEqual(1)
        ->and($verification['execution']['privacy_tombstone_count'])->toBe(2)
        ->and($verification['execution']['held_record_count'])->toBe(1)
        ->and($verification['integrity'])->each->toBe(0)
        ->and(MonitoringRetentionTombstone::query()
            ->where('job_reference', 'local-retention-verifier-contract')
            ->count())->toBe(2);
});

it('detects both count loss and payload mutation anywhere inside a due legal-hold range', function (): void {
    [, , $monitor] = metricRetentionMonitor();
    $ingest = app(MetricIngestService::class);
    foreach ([
        '2026-07-18T12:00:00Z' => 18.1,
        '2026-07-19T12:00:00Z' => 18.2,
        '2026-07-22T12:00:00Z' => 18.3,
    ] as $observedAt => $value) {
        $ingest->write($monitor, new MetricSample(
            metric: 'device.temperature',
            value: $value,
            unit: 'celsius',
            observedAt: CarbonImmutable::parse($observedAt),
        ));
    }
    MonitoringRetentionPolicy::query()->create([
        'name' => 'Legal-hold range commitment proof',
        'scope_kind' => 'device',
        'device_id' => $monitor->device_id,
        'raw_days' => 1,
        'hourly_days' => 1,
        'daily_days' => 1,
        'legal_hold' => true,
        'is_active' => true,
    ]);

    $verifier = app(ProductionRetentionEvidenceVerifier::class);
    $before = $verifier->captureBefore(CarbonImmutable::now());
    $originalPoints = $this->store->points;
    /** @var TimeSeriesPoint $middle */
    $middle = collect($originalPoints)
        ->sortBy(fn (TimeSeriesPoint $point): int => $point->observedAt->getTimestamp())
        ->values()
        ->get(1);
    $mutated = new TimeSeriesPoint(
        externalKey: $middle->externalKey,
        seriesId: $middle->seriesId,
        siteId: $middle->siteId,
        deviceId: $middle->deviceId,
        monitorId: $middle->monitorId,
        metric: $middle->metric,
        value: $middle->value + 0.5,
        unit: $middle->unit,
        dimensions: $middle->dimensions,
        tier: $middle->tier,
        observedAt: $middle->observedAt,
        idempotencyKey: $middle->idempotencyKey,
        statistics: $middle->statistics,
    );
    $this->store->points = collect($originalPoints)
        ->map(fn (TimeSeriesPoint $point): TimeSeriesPoint => $point->idempotencyKey === $middle->idempotencyKey
            ? $mutated
            : $point)
        ->values()
        ->all();

    $retention = [
        'metric_payloads_deleted' => 0,
        'rollup_coverage_blocked_series' => 0,
        'rollup_coverage_verified_series' => 0,
        'occupied_rollup_buckets_verified' => 0,
        'reconciled_deletion_intents' => 0,
        'unresolved_deletion_intents' => 0,
    ];
    $digestMismatch = $verifier->verify(
        'legal-hold-digest-mismatch',
        CarbonImmutable::now()->subMinute(),
        CarbonImmutable::now()->addMinute(),
        $before,
        $retention,
    );

    $this->store->points = collect($originalPoints)
        ->reject(fn (TimeSeriesPoint $point): bool => $point->idempotencyKey === $middle->idempotencyKey)
        ->values()
        ->all();
    $countMismatch = $verifier->verify(
        'legal-hold-count-mismatch',
        CarbonImmutable::now()->subMinute(),
        CarbonImmutable::now()->addMinute(),
        $before,
        $retention,
    );

    expect($before['held'])->toHaveCount(1)
        ->and($digestMismatch['integrity']['legal_hold_gap_count'])->toBe(1)
        ->and($digestMismatch['errors'])->toContain('legal_hold_preservation_gap')
        ->and($countMismatch['integrity']['legal_hold_gap_count'])->toBe(1)
        ->and($countMismatch['errors'])->toContain('legal_hold_preservation_gap');
});

it('captures a legal-hold cohort only when it is eligible under the same aligned retention cutoff', function (): void {
    [, , $monitor] = metricRetentionMonitor();
    $ingest = app(MetricIngestService::class);
    $now = CarbonImmutable::parse('2026-07-23T12:15:00Z');
    MonitoringRetentionPolicy::query()->create([
        'name' => 'Aligned legal-hold cohort proof',
        'scope_kind' => 'device',
        'device_id' => $monitor->device_id,
        'raw_days' => 1,
        'hourly_days' => 1,
        'daily_days' => 1,
        'legal_hold' => true,
        'is_active' => true,
    ]);

    $ingest->write($monitor, new MetricSample(
        metric: 'device.temperature',
        value: 18.4,
        unit: 'celsius',
        observedAt: CarbonImmutable::parse('2026-07-22T12:05:00Z'),
    ));
    $verifier = app(ProductionRetentionEvidenceVerifier::class);

    expect(RetentionEnforcer::retentionCutoff('raw', $now, 1))
        ->toEqual(CarbonImmutable::parse('2026-07-22T12:00:00Z'))
        ->and($verifier->captureBefore($now)['held'])->toBe([]);

    $ingest->write($monitor, new MetricSample(
        metric: 'device.temperature',
        value: 18.5,
        unit: 'celsius',
        observedAt: CarbonImmutable::parse('2026-07-22T11:00:00Z'),
    ));

    expect($verifier->captureBefore($now)['held'])->toHaveCount(1);
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

it('rejects a missing exact series boundary while a middle point remains', function (string $missingBoundary): void {
    [, , $monitor] = metricRetentionMonitor();
    $ingest = app(MetricIngestService::class);
    foreach ([
        '2026-07-23T11:57:00Z',
        '2026-07-23T11:58:00Z',
        '2026-07-23T11:59:00Z',
    ] as $observedAt) {
        $ingest->write($monitor, new MetricSample(
            metric: 'device.boundary-proof',
            value: 18.4,
            unit: 'celsius',
            observedAt: CarbonImmutable::parse($observedAt),
        ));
    }

    $series = MetricSeries::query()->where('metric', 'device.boundary-proof')->sole();
    $seriesPoints = collect($this->store->points)
        ->where('externalKey', $series->external_key)
        ->sortBy(fn (TimeSeriesPoint $point): int => $point->observedAt->getTimestamp())
        ->values();
    expect(app(RetentionEnforcer::class)->validatePointers())->toBe([]);
    $missing = $missingBoundary === 'first' ? $seriesPoints->first() : $seriesPoints->last();
    $middle = $seriesPoints->get(1);
    $this->store->points = collect($this->store->points)
        ->reject(fn (TimeSeriesPoint $point): bool => $point->idempotencyKey === $missing->idempotencyKey)
        ->values()
        ->all();

    $verification = app(ProductionRetentionEvidenceVerifier::class)->verify(
        'exact-boundary-pointer-gap',
        CarbonImmutable::now()->subMinute(),
        CarbonImmutable::now()->addMinute(),
        ['held' => []],
        [
            'metric_payloads_deleted' => 0,
            'rollup_coverage_blocked_series' => 0,
            'rollup_coverage_verified_series' => 0,
            'occupied_rollup_buckets_verified' => 0,
            'reconciled_deletion_intents' => 0,
            'unresolved_deletion_intents' => 0,
        ],
    );

    expect($middle)->toBeInstanceOf(TimeSeriesPoint::class)
        ->and(collect($this->store->points)->contains(
            fn (TimeSeriesPoint $point): bool => $point->idempotencyKey === $middle->idempotencyKey,
        ))->toBeTrue()
        ->and(app(RetentionEnforcer::class)->validatePointers())->toBe([$series->id])
        ->and($verification['integrity']['timeseries_reference_gap_count'])->toBe(1)
        ->and($verification['errors'])->toContain('timeseries_reference_gap');
})->with([
    'missing first boundary' => 'first',
    'missing last boundary' => 'last',
]);

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
        'https://influx.example.test/api/v2/delete*' => Http::response('', 204),
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
    $deleteFrom = CarbonImmutable::parse('2026-07-20T00:00:00.123456Z');
    $deleteUntil = CarbonImmutable::parse('2026-07-21T00:00:00.123456Z');
    $store->deleteRange($point->externalKey, 'raw', $deleteFrom, $deleteUntil);

    Http::assertSent(fn ($request): bool => str_contains($request->url(), '/api/v2/write')
        && str_contains($request->url(), 'org=oblivion-findings')
        && str_contains($request->url(), 'bucket=native-monitoring')
        && $request->hasHeader('Authorization', 'Bearer RAW-INFLUX-TOKEN-SENTINEL')
        && str_contains($request->body(), 'idempotency_key="'.str_repeat('b', 64).'"'));
    Http::assertSent(fn ($request): bool => str_contains($request->url(), '/api/v2/delete')
        && data_get($request->data(), 'start') === $deleteFrom->format('Y-m-d\TH:i:s.u\Z')
        && data_get($request->data(), 'stop') === $deleteUntil->format('Y-m-d\TH:i:s.u\Z')
        && str_contains((string) data_get($request->data(), 'predicate'), 'tier="raw"'));
    expect($store->healthy())->toBeTrue();

    expect(fn () => $store->deleteRange($point->externalKey, 'raw', $deleteFrom, $deleteFrom))
        ->toThrow(TimeSeriesUnavailable::class, 'range');

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

    public bool $failNextExistenceCheckAfterDeletion = false;

    private bool $failNextExistenceCheck = false;

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
                // Influx updates fields at one measurement/tag/timestamp key.
                $this->points = collect($this->points)
                    ->reject(fn (TimeSeriesPoint $stored): bool => $stored->externalKey === $point->externalKey
                        && $stored->tier === $point->tier
                        && $stored->observedAt->equalTo($point->observedAt))
                    ->values()
                    ->all();
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
        if ($this->missingAll) {
            return [];
        }

        return collect($this->points)
            ->filter(fn (TimeSeriesPoint $point): bool => $point->externalKey === $externalKey
                && $point->tier === $tier
                && $point->observedAt->greaterThanOrEqualTo($from)
                && $point->observedAt->lessThan($to))
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
                && $point->observedAt->greaterThanOrEqualTo($from)
                && $point->observedAt->lessThan($to))
            ->values()
            ->all();
        if ($this->failNextExistenceCheckAfterDeletion) {
            $this->failNextExistenceCheckAfterDeletion = false;
            $this->failNextExistenceCheck = true;
        }
    }

    public function exists(
        string $externalKey,
        string $tier,
        ?CarbonImmutable $from = null,
        ?CarbonImmutable $to = null,
    ): bool {
        if ($this->failNextExistenceCheck) {
            $this->failNextExistenceCheck = false;

            throw new TimeSeriesUnavailable('Time-series storage failed after deleting the requested range.');
        }

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
