<?php

use App\Domain\Monitoring\Contracts\SnapshotStore;
use App\Domain\Monitoring\Exceptions\SnapshotStoreUnavailable;
use App\Domain\Monitoring\Jobs\PullProviderCapability;
use App\Domain\Monitoring\Models\ConfigurationSnapshot;
use App\Domain\Monitoring\Models\MonitoringOutbox;
use App\Domain\Monitoring\Models\ProviderCapabilityCursor;
use App\Domain\Monitoring\Models\ProviderCapabilityException;
use App\Domain\Monitoring\Services\ConfigurationSnapshotService;
use App\Domain\Monitoring\Services\MonitoringOutboxPublisher;
use App\Domain\Monitoring\Services\RuntimeEnvelopeCodec;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Models\Integration\IntegrationProviderConnection;
use App\Models\Integration\IntegrationSiteConfig;
use App\Models\Site;
use App\Services\Integration\Contracts\EventCollectionCapability;
use App\Services\Integration\Contracts\ObservationCollectionCapability;
use App\Services\Integration\Contracts\SnapshotCollectionCapability;
use App\Services\Integration\Data\IntegrationCapabilityManifest;
use App\Services\Integration\Data\ProviderEventPage;
use App\Services\Integration\Data\ProviderObservationPage;
use App\Services\Integration\Data\ProviderSnapshotPage;
use App\Services\Integration\IntegrationAdapterInterface;
use App\Services\Integration\IntegrationAdapterRegistry;
use App\Services\Integration\SyncResult;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    Queue::fake();
    CarbonImmutable::setTestNow('2026-07-23T08:00:00Z');
    config()->set('monitoring.signing', [
        'active_key_id' => 'provider-test-key',
        'keys' => [
            'provider-test-key' => base64_encode(str_repeat("\x37", SODIUM_CRYPTO_AUTH_KEYBYTES)),
        ],
    ]);
    config()->set('monitoring.delivery.sequence_lock_store', 'array');
    config()->set('monitoring.delivery.allow_local_sequence_lock_for_tests', true);
});

afterEach(function () {
    CarbonImmutable::setTestNow();
});

it('publishes signed observations and stops the cursor at the last Site-safe item', function () {
    $site = providerCapabilitySite();
    $page = new ProviderObservationPage(
        items: [
            providerObservation($site->id, 'cursor-001', 'source-001'),
            providerObservation($site->id + 999, 'cursor-002', 'source-002'),
        ],
        nextCursor: 'cursor-003',
    );
    $adapter = registerObservationFixture($page);

    $job = new PullProviderCapability('fixture', $site->id);
    $job->handle(app(IntegrationAdapterRegistry::class), app(MonitoringOutboxPublisher::class));

    $cursor = ProviderCapabilityCursor::query()->sole();
    $outbox = MonitoringOutbox::query()->sole();
    $envelope = app(RuntimeEnvelopeCodec::class)->decode($outbox->envelope_bytes);

    expect($job->queue)->toBe('monitoring-provider')
        ->and($adapter->requestedCursor)->toBeNull()
        ->and($adapter->requestedLimit)->toBe(25)
        ->and($cursor->site_id)->toBe($site->id)
        ->and($cursor->cursor)->toBe('cursor-001')
        ->and($cursor->exception_count)->toBe(1)
        ->and($envelope->payload['site_id'])->toBe($site->id)
        ->and($envelope->payload)->not->toHaveKey('cursor')
        ->and($envelope->source)->toBe("provider:fixture:site:{$site->id}:observations")
        ->and(ProviderCapabilityException::query()->sole()->code)->toBe('site_scope_mismatch');
});

it('advances only a partial page safe cursor and records bounded provider exceptions', function () {
    $site = providerCapabilitySite();
    $page = new ProviderObservationPage(
        items: [providerObservation($site->id, 'cursor-010', 'source-010')],
        nextCursor: 'cursor-999',
        partial: true,
        retryAfterSeconds: 120,
        exceptions: [['code' => 'item_invalid', 'item_reference' => 'hash-010']],
    );
    registerObservationFixture($page);

    (new PullProviderCapability('fixture', $site->id))
        ->handle(app(IntegrationAdapterRegistry::class), app(MonitoringOutboxPublisher::class));

    $cursor = ProviderCapabilityCursor::query()->sole();

    expect($cursor->cursor)->toBe('cursor-010')
        ->and($cursor->retry_not_before?->toIso8601String())->toBe('2026-07-23T08:02:00+00:00')
        ->and($cursor->exception_count)->toBe(1)
        ->and(ProviderCapabilityException::query()->sole()->toArray())
        ->toMatchArray([
            'site_id' => $site->id,
            'provider' => 'fixture',
            'code' => 'item_invalid',
            'item_reference' => 'hash-010',
        ]);
});

it('publishes typed provider events through the signed event runtime and advances the provider cursor', function () {
    $site = providerCapabilitySite('event-fixture');
    $page = new ProviderEventPage(
        items: [[
            'site_id' => $site->id,
            'provider' => 'event-fixture',
            'source_app' => 'access',
            'source_event_id' => 'door-event-001',
            'occurred_at' => '2026-07-23T07:59:00Z',
            'severity' => 'warn',
            'event_type' => 'door_access_denied',
            'normalized_payload' => [
                'summary' => 'Access denied at Front door',
                'door_name' => 'Front door',
            ],
            'body_hash' => hash('sha256', 'door-event-001'),
        ]],
        nextCursor: '2026-07-23T08:00:00Z',
    );
    $adapter = registerEventFixture($page);

    $job = new PullProviderCapability(
        'event-fixture',
        $site->id,
        EventCollectionCapability::class,
    );
    $job->handle(app(IntegrationAdapterRegistry::class), app(MonitoringOutboxPublisher::class));

    $cursor = ProviderCapabilityCursor::query()->sole();
    $outbox = MonitoringOutbox::query()->sole();
    $envelope = app(RuntimeEnvelopeCodec::class)->decode($outbox->envelope_bytes);

    expect($adapter->requestedCursor)->toBeNull()
        ->and($adapter->requestedLimit)->toBe(25)
        ->and($cursor->cursor)->toBe('2026-07-23T08:00:00Z')
        ->and($outbox->stream)->toBe('monitoring-events')
        ->and($envelope->type->value)->toBe('event')
        ->and($envelope->source)->toBe("provider:event-fixture:site:{$site->id}:events")
        ->and($envelope->payload)->toMatchArray([
            'event_family' => 'provider_event',
            'site_id' => $site->id,
            'provider' => 'event-fixture',
            'source_event_id' => 'door-event-001',
            'event_type' => 'door_access_denied',
        ]);
});

it('rejects an entire provider event page when any event crosses the requested Site scope', function () {
    $site = providerCapabilitySite('event-fixture');
    registerEventFixture(new ProviderEventPage(
        items: [[
            'site_id' => $site->id + 999,
            'provider' => 'event-fixture',
            'source_app' => 'access',
            'source_event_id' => 'cross-site-event-001',
            'occurred_at' => '2026-07-23T07:59:00Z',
            'severity' => 'critical',
            'event_type' => 'door_forced_open',
            'normalized_payload' => ['summary' => 'Cross-Site event must not be staged'],
            'body_hash' => hash('sha256', 'cross-site-event-001'),
        ]],
        nextCursor: '2026-07-23T08:00:00Z',
    ));

    (new PullProviderCapability(
        'event-fixture',
        $site->id,
        EventCollectionCapability::class,
    ))->handle(app(IntegrationAdapterRegistry::class), app(MonitoringOutboxPublisher::class));

    expect(MonitoringOutbox::query()->count())->toBe(0)
        ->and(ProviderCapabilityCursor::query()->sole()->cursor)->toBeNull()
        ->and(ProviderCapabilityCursor::query()->sole()->exception_count)->toBe(1)
        ->and(ProviderCapabilityException::query()->sole()->code)->toBe('site_scope_mismatch');
});

it('does not create runtime state when a provider capability is absent', function () {
    $site = Site::factory()->create();

    (new PullProviderCapability('unifi', $site->id))
        ->handle(app(IntegrationAdapterRegistry::class), app(MonitoringOutboxPublisher::class));

    expect(ProviderCapabilityCursor::query()->count())->toBe(0)
        ->and(ProviderCapabilityException::query()->count())->toBe(0)
        ->and(MonitoringOutbox::query()->count())->toBe(0);
});

it('discards an in-flight provider result when the connection is disabled before persistence', function () {
    $site = providerCapabilitySite();
    $adapter = registerObservationFixture(
        new ProviderObservationPage(
            items: [providerObservation($site->id, 'discarded-cursor', 'discarded-source')],
            nextCursor: 'discarded-cursor',
        ),
        function (IntegrationProviderConnection $connection): void {
            $connection->update([
                'status' => IntegrationProviderConnection::STATUS_DISABLED,
                'requires_credential_replacement' => true,
            ]);
        },
    );

    (new PullProviderCapability('fixture', $site->id))
        ->handle(app(IntegrationAdapterRegistry::class), app(MonitoringOutboxPublisher::class));

    expect($adapter->requestedLimit)->toBe(25)
        ->and(ProviderCapabilityCursor::query()->sole()->cursor)->toBeNull()
        ->and(MonitoringOutbox::query()->count())->toBe(0);
});

it('collects provider snapshots through the declared capability and advances only persisted evidence', function () {
    $site = providerCapabilitySite('snapshot-fixture');
    $device = Device::factory()->itInfrastructure()->create();
    DeviceAssignment::query()->create([
        'device_id' => $device->id,
        'assignable_type' => DeviceAssignment::TARGET_SITE,
        'assignable_id' => $site->id,
        'assignment_type' => 'permanent',
        'assigned_at' => now()->subDay(),
    ]);
    $page = new ProviderSnapshotPage(items: [[
        'cursor' => 'snapshot-cursor-001',
        'site_id' => $site->id,
        'device_id' => $device->id,
        'captured_at' => '2026-07-23T07:59:00Z',
        'payload' => [
            'firmware_version' => '9.0.1',
            'configuration' => ['hostname' => 'snapshot-edge'],
        ],
    ]]);
    $adapter = new ProviderSnapshotFixtureAdapter($page);
    app()->instance(ProviderSnapshotFixtureAdapter::class, $adapter);
    app(IntegrationAdapterRegistry::class)->register(
        'snapshot-fixture',
        ProviderSnapshotFixtureAdapter::class,
        new IntegrationCapabilityManifest(
            provider: 'snapshot-fixture',
            version: '1.0',
            capabilities: [SnapshotCollectionCapability::class],
            requiredPermissions: ['securityDevices.integrations.view'],
            sensitivityLabels: ['configuration_snapshots'],
            pageLimit: 10,
            minimumIntervalSeconds: 60,
            backfillLimit: 20,
        ),
    );
    $store = new ProviderSnapshotFixtureStore;
    app()->instance(SnapshotStore::class, $store);

    (new PullProviderCapability(
        'snapshot-fixture',
        $site->id,
        SnapshotCollectionCapability::class,
    ))->handle(
        app(IntegrationAdapterRegistry::class),
        app(MonitoringOutboxPublisher::class),
        app(ConfigurationSnapshotService::class),
    );

    $snapshot = ConfigurationSnapshot::query()->sole();
    expect($adapter->requestedLimit)->toBe(10)
        ->and(ProviderCapabilityCursor::query()->sole()->cursor)->toBe('snapshot-cursor-001')
        ->and($snapshot->device_id)->toBe($device->id)
        ->and($snapshot->source)->toBe('snapshot-fixture')
        ->and($store->objects[$snapshot->storage_path])->toContain('snapshot-edge')
        ->and(MonitoringOutbox::query()->count())->toBe(0);
});

function providerCapabilitySite(string $provider = 'fixture'): Site
{
    $site = Site::factory()->create();
    IntegrationProviderConnection::query()->create([
        'provider' => $provider,
        'secret_encrypted' => Crypt::encryptString('fixture-key'),
        'secret_last4' => '-key',
        'status' => IntegrationProviderConnection::STATUS_CONNECTED,
    ]);
    IntegrationSiteConfig::query()->create([
        'site_id' => $site->id,
        'provider' => $provider,
        'status' => IntegrationSiteConfig::STATUS_HYBRID,
        'mapped_external_site_id' => 'fixture-site',
        'is_active' => true,
    ]);

    return $site;
}

function registerObservationFixture(
    ProviderObservationPage $page,
    ?Closure $beforeReturn = null,
): ProviderCapabilityFixtureAdapter {
    $adapter = new ProviderCapabilityFixtureAdapter($page, $beforeReturn);
    app()->instance(ProviderCapabilityFixtureAdapter::class, $adapter);
    app(IntegrationAdapterRegistry::class)->register(
        'fixture',
        ProviderCapabilityFixtureAdapter::class,
        new IntegrationCapabilityManifest(
            provider: 'fixture',
            version: '1.0',
            capabilities: [ObservationCollectionCapability::class],
            requiredPermissions: ['securityDevices.integrations.view'],
            sensitivityLabels: ['operational_observations'],
            pageLimit: 25,
            minimumIntervalSeconds: 60,
            backfillLimit: 100,
        ),
    );

    return $adapter;
}

function registerEventFixture(ProviderEventPage $page): ProviderEventFixtureAdapter
{
    $adapter = new ProviderEventFixtureAdapter($page);
    app()->instance(ProviderEventFixtureAdapter::class, $adapter);
    app(IntegrationAdapterRegistry::class)->register(
        'event-fixture',
        ProviderEventFixtureAdapter::class,
        new IntegrationCapabilityManifest(
            provider: 'event-fixture',
            version: '1.0',
            capabilities: [EventCollectionCapability::class],
            requiredPermissions: ['securityDevices.integrations.view'],
            sensitivityLabels: ['event_metadata'],
            pageLimit: 25,
            minimumIntervalSeconds: 60,
            backfillLimit: 100,
        ),
    );

    return $adapter;
}

/** @return array<string, mixed> */
function providerObservation(int $siteId, string $cursor, string $sourceKey): array
{
    return [
        'cursor' => $cursor,
        'monitor_id' => 41,
        'device_id' => 81,
        'site_id' => $siteId,
        'source_key' => $sourceKey,
        'state' => 'healthy',
        'observed_at' => '2026-07-23T08:00:00Z',
        'value' => 1,
        'unit' => 'online',
        'latency_ms' => 12,
        'message' => 'provider_online',
        'metrics' => ['provider' => 'fixture', 'status' => 'online'],
    ];
}

final class ProviderCapabilityFixtureAdapter implements IntegrationAdapterInterface, ObservationCollectionCapability
{
    public ?string $requestedCursor = null;

    public ?int $requestedLimit = null;

    public function __construct(
        private readonly ProviderObservationPage $page,
        private readonly ?Closure $beforeReturn = null,
    ) {}

    public function provider(): string
    {
        return 'fixture';
    }

    public function capabilities(): array
    {
        return [ObservationCollectionCapability::class];
    }

    public function testConnection(IntegrationProviderConnection $connection): bool
    {
        return true;
    }

    public function discoverSites(IntegrationProviderConnection $connection): array
    {
        return [];
    }

    public function syncDevices(IntegrationSiteConfig $siteConfig, IntegrationProviderConnection $providerConnection): SyncResult
    {
        return new SyncResult;
    }

    public function pullHealth(IntegrationSiteConfig $siteConfig, IntegrationProviderConnection $providerConnection): array
    {
        return [];
    }

    public function pullEvents(IntegrationSiteConfig $siteConfig, IntegrationProviderConnection $providerConnection, ?DateTimeInterface $since = null): array
    {
        return [];
    }

    public function collectObservations(
        IntegrationSiteConfig $siteConfig,
        IntegrationProviderConnection $providerConnection,
        ?string $cursor,
        int $limit,
    ): ProviderObservationPage {
        $this->requestedCursor = $cursor;
        $this->requestedLimit = $limit;
        if ($this->beforeReturn) {
            ($this->beforeReturn)($providerConnection);
        }

        return $this->page;
    }
}

final class ProviderEventFixtureAdapter implements EventCollectionCapability, IntegrationAdapterInterface
{
    public ?string $requestedCursor = null;

    public ?int $requestedLimit = null;

    public function __construct(private readonly ProviderEventPage $page) {}

    public function provider(): string
    {
        return 'event-fixture';
    }

    public function capabilities(): array
    {
        return [EventCollectionCapability::class];
    }

    public function testConnection(IntegrationProviderConnection $connection): bool
    {
        return true;
    }

    public function discoverSites(IntegrationProviderConnection $connection): array
    {
        return [];
    }

    public function syncDevices(IntegrationSiteConfig $siteConfig, IntegrationProviderConnection $providerConnection): SyncResult
    {
        return new SyncResult;
    }

    public function pullHealth(IntegrationSiteConfig $siteConfig, IntegrationProviderConnection $providerConnection): array
    {
        return [];
    }

    public function pullEvents(IntegrationSiteConfig $siteConfig, IntegrationProviderConnection $providerConnection, ?DateTimeInterface $since = null): array
    {
        return [];
    }

    public function collectEvents(
        IntegrationSiteConfig $siteConfig,
        IntegrationProviderConnection $providerConnection,
        ?string $cursor,
        int $limit,
    ): ProviderEventPage {
        $this->requestedCursor = $cursor;
        $this->requestedLimit = $limit;

        return $this->page;
    }
}

final class ProviderSnapshotFixtureAdapter implements IntegrationAdapterInterface, SnapshotCollectionCapability
{
    public ?string $requestedCursor = null;

    public ?int $requestedLimit = null;

    public function __construct(private readonly ProviderSnapshotPage $page) {}

    public function provider(): string
    {
        return 'snapshot-fixture';
    }

    public function capabilities(): array
    {
        return [SnapshotCollectionCapability::class];
    }

    public function testConnection(IntegrationProviderConnection $connection): bool
    {
        return true;
    }

    public function discoverSites(IntegrationProviderConnection $connection): array
    {
        return [];
    }

    public function syncDevices(IntegrationSiteConfig $siteConfig, IntegrationProviderConnection $providerConnection): SyncResult
    {
        return new SyncResult;
    }

    public function pullHealth(IntegrationSiteConfig $siteConfig, IntegrationProviderConnection $providerConnection): array
    {
        return [];
    }

    public function pullEvents(IntegrationSiteConfig $siteConfig, IntegrationProviderConnection $providerConnection, ?DateTimeInterface $since = null): array
    {
        return [];
    }

    public function collectSnapshots(
        IntegrationSiteConfig $siteConfig,
        IntegrationProviderConnection $providerConnection,
        ?string $cursor,
        int $limit,
    ): ProviderSnapshotPage {
        $this->requestedCursor = $cursor;
        $this->requestedLimit = $limit;

        return $this->page;
    }
}

final class ProviderSnapshotFixtureStore implements SnapshotStore
{
    /** @var array<string, string> */
    public array $objects = [];

    public function put(string $path, string $contents): void
    {
        $this->objects[$path] = $contents;
    }

    public function read(string $path): string
    {
        return $this->objects[$path] ?? throw new SnapshotStoreUnavailable('Snapshot storage is unavailable.');
    }

    public function delete(string $path): void
    {
        unset($this->objects[$path]);
    }

    public function exists(string $path): bool
    {
        return isset($this->objects[$path]);
    }
}
