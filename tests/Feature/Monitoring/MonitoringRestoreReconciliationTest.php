<?php

use App\Domain\Monitoring\Contracts\RestoreDependencyProbe;
use App\Domain\Monitoring\Contracts\SnapshotStore;
use App\Domain\Monitoring\Contracts\TimeSeriesStore;
use App\Domain\Monitoring\Models\MetricSeries;
use App\Domain\Monitoring\Models\MonitoringCollector;
use App\Domain\Monitoring\Models\MonitoringInbox;
use App\Domain\Monitoring\Models\MonitoringOutbox;
use App\Domain\Monitoring\Models\ProviderCapabilityCursor;
use App\Domain\Monitoring\Services\MonitoringRestoreReconciliationService;
use App\Domain\SecurityDevices\Credentials\Models\CredentialLeaseGrant;
use App\Domain\SecurityDevices\Credentials\Models\CredentialReference;
use App\Domain\SecurityDevices\Credentials\Services\CredentialReferenceRules;
use App\Domain\SecurityDevices\Models\Device;
use App\Models\Integration\IntegrationSiteConfig;
use App\Models\Site;
use App\Services\Integration\Contracts\EventCollectionCapability;
use App\Services\Integration\IntegrationAdapterRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-08-03T12:00:00Z');
    app()->instance(RestoreDependencyProbe::class, new RestoreDependencyProbeFixture);
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

it('reports a clean empty restored runtime without exposing payloads or endpoints', function (): void {
    $report = app(MonitoringRestoreReconciliationService::class)->report();

    expect($report)->toMatchArray([
        'outbox_gap' => 0,
        'inbox_checkpoint_gap' => 0,
        'orphan_series' => 0,
        'timeseries_pointer_gap' => 0,
        'snapshot_hash_mismatch' => 0,
        'topology_pointer_gap' => 0,
        'collector_sequence_regression' => 0,
        'stale_unpublished_delivery' => 0,
        'published_projection_gap' => 0,
        'provider_cursor_scope_gap' => 0,
        'provider_cursor_stall' => 0,
        'credential_reference_recovery_gap' => 0,
        'credential_lease_recovery_gap' => 0,
        'redis_unavailable' => 0,
        'timeseries_unavailable' => 0,
        'snapshot_store_unavailable' => 0,
        'secret_manager_unavailable' => 0,
    ]);

    $encoded = json_encode($report, JSON_THROW_ON_ERROR);
    expect($encoded)->not->toContain('password', 'token', 'dsn', 'endpoint', 'envelope_bytes');

    expect(Artisan::call('monitoring:reconcile-restore', ['--json' => true]))->toBe(0);
    expect(json_decode(trim(Artisan::output()), true, 512, JSON_THROW_ON_ERROR))
        ->toMatchArray([
            'redis_unavailable' => 0,
            'timeseries_unavailable' => 0,
            'snapshot_store_unavailable' => 0,
            'secret_manager_unavailable' => 0,
        ]);
});

it('fails a healthy restore when a retained MySQL series pointer is missing from InfluxDB', function (): void {
    $site = Site::factory()->create();
    $device = Device::factory()->create();
    $externalKey = str_repeat('a', 64);
    $firstPointAt = CarbonImmutable::parse('2026-08-01T10:00:00.123456Z');
    $lastPointAt = CarbonImmutable::parse('2026-08-02T11:00:00.654321Z');
    $series = MetricSeries::query()->create([
        'site_id' => $site->id,
        'device_id' => $device->id,
        'monitor_id' => null,
        'metric' => 'wan.utilisation',
        'dimensions' => ['interface' => 'wan'],
        'dimensions_hash' => hash('sha256', '{"interface":"wan"}'),
        'unit' => 'percent',
        'source' => 'native_snmp',
        'data_class' => 'operational',
        'privacy_class' => 'standard',
        'retention_tier' => 'raw',
        'external_key' => $externalKey,
        'first_point_at' => $firstPointAt,
        'last_point_at' => $lastPointAt,
    ]);
    $series->refresh();
    $storedFirstPointAt = CarbonImmutable::instance($series->first_point_at)->utc();
    $storedLastPointAt = CarbonImmutable::instance($series->last_point_at)->utc();

    $existsCall = null;
    $timeSeries = Mockery::mock(TimeSeriesStore::class);
    $timeSeries->shouldReceive('exists')
        ->once()
        ->withAnyArgs()
        ->andReturnUsing(function (...$arguments) use (&$existsCall): bool {
            $existsCall = $arguments;

            return false;
        });
    $reconciliation = new MonitoringRestoreReconciliationService(
        app(SnapshotStore::class),
        $timeSeries,
        app(IntegrationAdapterRegistry::class),
        new RestoreDependencyProbeFixture,
        app(CredentialReferenceRules::class),
    );

    $report = $reconciliation->report();
    $failures = collect($report)
        ->except('checked_at')
        ->sum(fn (mixed $value): int => (int) $value);

    expect($report)->toMatchArray([
        'orphan_series' => 0,
        'timeseries_pointer_gap' => 1,
        'timeseries_unavailable' => 0,
    ])->and($failures)->toBe(1)
        ->and($existsCall)->not->toBeNull()
        ->and($existsCall[0])->toBe($externalKey)
        ->and($existsCall[1])->toBe('raw')
        ->and($existsCall[2])->toBeInstanceOf(CarbonImmutable::class)
        ->and($existsCall[2]->equalTo($storedFirstPointAt))->toBeTrue()
        ->and($existsCall[3])->toBeInstanceOf(CarbonImmutable::class)
        ->and($existsCall[3]->equalTo($storedLastPointAt->addMicrosecond()))->toBeTrue()
        ->and(json_encode($report, JSON_THROW_ON_ERROR))
        ->not->toContain($externalKey, 'wan.utilisation', 'native_snmp');
});

it('reports only counts for restored credential reference and lease recovery gaps', function (): void {
    $site = Site::factory()->create();
    $rules = app(CredentialReferenceRules::class);
    $capabilities = ['command:network.device.reboot'];
    $externalReference = 'secret/data/restore/core-switch';
    $reference = CredentialReference::query()->create([
        'reference_key' => 'vault:restore/core-switch',
        'site_id' => $site->id,
        'provider' => 'unifi',
        'purpose' => 'device_management',
        'capabilities' => $capabilities,
        'secret_manager_reference' => $externalReference,
        'secret_manager_reference_hash' => $rules->fingerprint($externalReference),
        'status' => 'active',
        'rotation_status' => 'current',
        'test_status' => 'passed',
        'version' => 2,
    ]);
    $leaseId = 'restore-lease-secret-identifier';
    CredentialLeaseGrant::query()->create([
        'credential_reference_id' => $reference->id,
        'reference_version' => 2,
        'site_id' => $site->id,
        'lease_id' => $leaseId,
        'lease_fingerprint' => $rules->fingerprint($leaseId),
        'capabilities' => $capabilities,
        'status' => CredentialLeaseGrant::STATUS_ISSUED,
        'issued_at' => now()->subMinutes(2),
        'expires_at' => now()->subMinute(),
    ]);

    $report = app(MonitoringRestoreReconciliationService::class)->report();
    expect($report)->toMatchArray([
        'credential_reference_recovery_gap' => 0,
        'credential_lease_recovery_gap' => 1,
    ]);

    DB::table('security_device_credential_references')
        ->where('id', $reference->id)
        ->update(['secret_manager_reference_hash' => str_repeat('0', 64)]);
    $report = app(MonitoringRestoreReconciliationService::class)->report();
    expect($report['credential_reference_recovery_gap'])->toBe(1)
        ->and(json_encode($report, JSON_THROW_ON_ERROR))
        ->not->toContain($externalReference, $leaseId, 'reference_key', 'lease_id');
});

it('detects delivery gaps and collector sequence regressions with value free counts', function (): void {
    foreach ([1, 3] as $sequence) {
        MonitoringOutbox::query()->create([
            'message_id' => (string) Str::uuid(),
            'stream' => 'monitoring-events',
            'source' => 'restore-fixture-source',
            'sequence' => $sequence,
            'idempotency_key' => "restore-fixture-{$sequence}",
            'envelope_bytes' => '{}',
            'available_at' => now(),
        ]);
    }
    MonitoringCollector::factory()->create([
        'acknowledged_source_sequence' => 9,
        'highest_seen_source_sequence' => 8,
        'configuration_sequence' => 4,
    ]);

    $report = app(MonitoringRestoreReconciliationService::class)->report();

    expect($report['outbox_gap'])->toBe(1)
        ->and($report['collector_sequence_regression'])->toBe(1)
        ->and(array_keys($report))->not->toContain('sources', 'collectors', 'payloads');
});

it('detects stalled delivery and provider cursor continuity after restore without exposing identities', function (): void {
    MonitoringOutbox::query()->create([
        'message_id' => (string) Str::uuid(),
        'stream' => 'monitoring-events',
        'source' => 'provider:unifi:site:restore:events',
        'sequence' => 1,
        'idempotency_key' => 'restore-unpublished',
        'envelope_bytes' => '{}',
        'available_at' => now()->subMinutes(20),
    ]);
    MonitoringOutbox::query()->create([
        'message_id' => (string) Str::uuid(),
        'stream' => 'monitoring-events',
        'source' => 'provider:unifi:site:restore:events-published',
        'sequence' => 1,
        'idempotency_key' => 'restore-published',
        'envelope_bytes' => '{}',
        'available_at' => now()->subMinutes(20),
        'published_at' => now()->subMinutes(19),
    ]);

    $site = Site::factory()->create();
    IntegrationSiteConfig::query()->create([
        'site_id' => $site->id,
        'provider' => 'unifi',
        'status' => IntegrationSiteConfig::STATUS_HYBRID,
        'mapped_external_site_id' => 'restore-site',
        'is_active' => true,
    ]);
    ProviderCapabilityCursor::query()->create([
        'site_id' => $site->id,
        'provider' => 'unifi',
        'capability' => EventCollectionCapability::class,
        'last_started_at' => now()->subMinutes(20),
    ]);
    ProviderCapabilityCursor::query()->create([
        'site_id' => $site->id,
        'provider' => 'unifi',
        'capability' => 'App\\Services\\Integration\\Contracts\\RemovedProviderCapability',
        'last_started_at' => now()->subMinutes(20),
        'last_completed_at' => now()->subMinutes(19),
    ]);

    $report = app(MonitoringRestoreReconciliationService::class)->report();

    expect($report)->toMatchArray([
        'stale_unpublished_delivery' => 1,
        'published_projection_gap' => 1,
        'provider_cursor_scope_gap' => 1,
        'provider_cursor_stall' => 1,
    ]);
    expect(array_keys($report))->not->toContain('message_ids', 'sources', 'providers', 'sites', 'capabilities', 'cursors');
});

it('allows recent delivery completed projection and a current declared provider cursor', function (): void {
    MonitoringOutbox::query()->create([
        'message_id' => (string) Str::uuid(),
        'stream' => 'monitoring-events',
        'source' => 'provider:unifi:site:recent:events',
        'sequence' => 1,
        'idempotency_key' => 'recent-unpublished',
        'envelope_bytes' => '{}',
        'available_at' => now()->subMinutes(5),
    ]);

    $messageId = (string) Str::uuid();
    $source = 'provider:unifi:site:completed:events';
    MonitoringOutbox::query()->create([
        'message_id' => $messageId,
        'stream' => 'monitoring-events',
        'source' => $source,
        'sequence' => 1,
        'idempotency_key' => 'completed-published',
        'envelope_bytes' => '{}',
        'available_at' => now()->subMinutes(20),
        'published_at' => now()->subMinutes(19),
    ]);
    MonitoringInbox::query()->create([
        'message_id' => $messageId,
        'consumer' => 'event-projector',
        'source' => $source,
        'sequence' => 1,
        'idempotency_key' => 'completed-published',
        'payload_hash' => hash('sha256', '{}'),
        'envelope_bytes' => '{}',
        'processed_at' => now()->subMinutes(18),
    ]);

    $site = Site::factory()->create();
    IntegrationSiteConfig::query()->create([
        'site_id' => $site->id,
        'provider' => 'unifi',
        'status' => IntegrationSiteConfig::STATUS_HYBRID,
        'mapped_external_site_id' => 'current-site',
        'is_active' => true,
    ]);
    ProviderCapabilityCursor::query()->create([
        'site_id' => $site->id,
        'provider' => 'unifi',
        'capability' => EventCollectionCapability::class,
        'last_started_at' => now()->subMinutes(20),
        'last_completed_at' => now()->subMinutes(19),
    ]);

    $report = app(MonitoringRestoreReconciliationService::class)->report();

    expect($report)->toMatchArray([
        'stale_unpublished_delivery' => 0,
        'published_projection_gap' => 0,
        'provider_cursor_scope_gap' => 0,
        'provider_cursor_stall' => 0,
    ]);
});

it('fails closed when any restored external dependency is unavailable', function (): void {
    app()->instance(RestoreDependencyProbe::class, new RestoreDependencyProbeFixture(
        redis: false,
        timeseries: false,
        snapshots: false,
        secretManager: false,
    ));

    $report = app(MonitoringRestoreReconciliationService::class)->report();

    expect($report)->toMatchArray([
        'redis_unavailable' => 1,
        'timeseries_unavailable' => 1,
        'snapshot_store_unavailable' => 1,
        'secret_manager_unavailable' => 1,
    ]);
    expect(json_encode($report, JSON_THROW_ON_ERROR))
        ->not->toContain('redis_url', 'timeseries_url', 'snapshot_disk', 'endpoint', 'dsn', 'token');

    expect(Artisan::call('monitoring:reconcile-restore', ['--json' => true]))->toBe(1);
    expect(json_encode(
        json_decode(trim(Artisan::output()), true, 512, JSON_THROW_ON_ERROR),
        JSON_THROW_ON_ERROR,
    ))->not->toContain('redis_url', 'timeseries_url', 'snapshot_disk', 'endpoint', 'dsn', 'token');
});

final readonly class RestoreDependencyProbeFixture implements RestoreDependencyProbe
{
    public function __construct(
        private bool $redis = true,
        private bool $timeseries = true,
        private bool $snapshots = true,
        private bool $secretManager = true,
    ) {}

    public function health(): array
    {
        return [
            'redis' => $this->redis,
            'timeseries' => $this->timeseries,
            'snapshots' => $this->snapshots,
            'secret_manager' => $this->secretManager,
        ];
    }
}
