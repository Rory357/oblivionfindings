<?php

namespace Tests\Feature\Integrations;

use App\Domain\Monitoring\Contracts\TimeSeriesStore;
use App\Domain\Monitoring\Data\TimeSeriesPoint;
use App\Domain\Monitoring\Enums\MonitorState;
use App\Domain\Monitoring\Handlers\ObservationEnvelopeHandler;
use App\Domain\Monitoring\Jobs\PullProviderCapability;
use App\Domain\Monitoring\Models\MetricSeries;
use App\Domain\Monitoring\Models\Monitor;
use App\Domain\Monitoring\Models\MonitoringOutbox;
use App\Domain\Monitoring\Models\ProviderCapabilityCursor;
use App\Domain\Monitoring\Models\ProviderCapabilityException;
use App\Domain\Monitoring\Services\MonitoringOutboxPublisher;
use App\Domain\Monitoring\Services\RuntimeEnvelopeCodec;
use App\Models\Integration\IntegrationProviderConnection;
use App\Models\Integration\IntegrationSiteConfig;
use App\Models\Site;
use App\Services\Integration\Adapters\UnifiAdapter;
use App\Services\Integration\Contracts\ObservationCollectionCapability;
use App\Services\Integration\Data\ProviderObservationPage;
use App\Services\Integration\IntegrationAdapterRegistry;
use App\Services\Integration\IntegrationDiscoveryException;
use App\Support\SafeOperationalData;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class UnifiObservationCapabilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow('2026-08-03T03:00:00Z');
        config()->set('monitoring.signing', [
            'active_key_id' => 'unifi-observation-test',
            'keys' => [
                'unifi-observation-test' => base64_encode(str_repeat("\x42", SODIUM_CRYPTO_AUTH_KEYBYTES)),
            ],
        ]);
        config()->set('monitoring.delivery.sequence_lock_store', 'array');
        config()->set('monitoring.delivery.allow_local_sequence_lock_for_tests', true);
        config()->set('monitoring.delivery.queue_connection', 'sync');
        Queue::fake();
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_sync_enrols_a_network_monitor_and_publishes_bounded_current_statistics(): void
    {
        $site = Site::factory()->create();
        $siteConfig = $this->siteConfig($site);
        $connection = $this->connection();
        $device = $this->syncDevice('network-ap-sensitive-id', 'ONLINE');
        unset($device['productLine']);
        $statistics = $this->statistics(
            heartbeat: now()->subSeconds(30)->toIso8601String(),
            cpu: 42.5,
            memory: 61.25,
        );
        $this->fakeOfficialApis([$device], [$this->overview($device)], [
            'network-ap-sensitive-id' => Http::response($statistics),
        ]);

        $sync = app(UnifiAdapter::class)->syncDevices($siteConfig, $connection);
        $this->assertTrue($sync->isSuccess());

        $registry = app(IntegrationAdapterRegistry::class);
        $this->assertTrue($registry->hasCapability('unifi', ObservationCollectionCapability::class));
        $this->assertContains('operational_observations', $registry->manifest('unifi')->sensitivityLabels);

        $monitor = Monitor::query()->with('profile')->sole();
        $this->assertSame('unifi', $monitor->config['provider'] ?? null);
        $this->assertSame('device_status', $monitor->config['collection'] ?? null);
        $this->assertStringNotContainsString('network-ap-sensitive-id', $monitor->target);
        $this->assertSame(60, $monitor->profile->interval_seconds);
        $this->assertSame(180, $monitor->profile->stale_after_seconds);

        $page = app(UnifiAdapter::class)->collectObservations($siteConfig, $connection, null, 200);

        $this->assertFalse($page->partial);
        $this->assertSame('unifi-health-v1:0', $page->nextCursor);
        $this->assertCount(1, $page->items);
        $this->assertSame([
            'monitor_id' => $monitor->id,
            'device_id' => $monitor->device_id,
            'site_id' => $site->id,
            'state' => MonitorState::Healthy->value,
            'observed_at' => '2026-08-03T03:00:00+00:00',
            'value' => 1,
            'unit' => 'online',
            'latency_ms' => null,
            'message' => 'provider_online',
            'metrics' => [
                'provider' => 'unifi',
                'connectivity' => 'online',
                'statistics_available' => true,
                'freshness_age_seconds' => 30,
                'uptime_seconds' => 86400,
                'cpu_utilization_percent' => 42.5,
                'memory_utilization_percent' => 61.25,
                'load_average_1m' => 0.7,
                'load_average_5m' => 0.5,
                'load_average_15m' => 0.4,
                'uplink_tx_bps' => 125000,
                'uplink_rx_bps' => 250000,
            ],
        ], array_diff_key($page->items[0], ['cursor' => true, 'source_key' => true]));
        $serialized = json_encode($page, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('network-ap-sensitive-id', $serialized);
        $this->assertStringNotContainsString('official-unifi-secret', $serialized);

        CarbonImmutable::setTestNow('2026-08-03T03:01:00Z');
        $retry = app(UnifiAdapter::class)->collectObservations($siteConfig, $connection, null, 200);
        $this->assertSame($page->items[0]['source_key'], $retry->items[0]['source_key']);

        (new PullProviderCapability(
            'unifi',
            $site->id,
            ObservationCollectionCapability::class,
        ))->handle(app(IntegrationAdapterRegistry::class), app(MonitoringOutboxPublisher::class));

        $cursor = ProviderCapabilityCursor::query()->sole();
        $outbox = MonitoringOutbox::query()->sole();
        $envelope = app(RuntimeEnvelopeCodec::class)->decode($outbox->envelope_bytes);
        $this->assertSame('unifi-health-v1:0', $cursor->cursor);
        $this->assertSame('monitoring-checks', $outbox->stream);
        $this->assertSame($site->id, $envelope->payload['site_id']);
        $this->assertSame($monitor->id, $envelope->payload['monitor_id']);
        $this->assertArrayNotHasKey('cursor', $envelope->payload);

        Http::assertSent(function (Request $request): bool {
            $query = [];
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            return str_ends_with((string) parse_url($request->url(), PHP_URL_PATH), '/sites/site-ext-1/devices')
                && $query === ['offset' => '0', 'limit' => '25'];
        });
    }

    public function test_offline_wins_over_stale_and_online_old_heartbeat_is_stale_across_pages(): void
    {
        $site = Site::factory()->create();
        $siteConfig = $this->siteConfig($site);
        $connection = $this->connection();
        $offline = $this->syncDevice('a-offline', 'OFFLINE');
        $stale = $this->syncDevice('b-stale', 'ONLINE');
        $this->fakeOfficialApis([$offline, $stale], [
            $this->overview($offline),
            $this->overview($stale),
        ], [
            'a-offline' => Http::response($this->statistics(now()->subHour()->toIso8601String())),
            'b-stale' => Http::response($this->statistics(now()->subHour()->toIso8601String())),
        ]);
        $this->assertTrue(app(UnifiAdapter::class)->syncDevices($siteConfig, $connection)->isSuccess());

        $first = app(UnifiAdapter::class)->collectObservations($siteConfig, $connection, null, 1);
        $second = app(UnifiAdapter::class)->collectObservations(
            $siteConfig,
            $connection,
            $first->nextCursor,
            1,
        );

        $this->assertSame(MonitorState::Failed->value, $first->items[0]['state']);
        $this->assertSame(0, $first->items[0]['value']);
        $this->assertSame('provider_offline', $first->items[0]['message']);
        $this->assertSame('unifi-health-v1:1', $first->nextCursor);
        $this->assertSame(MonitorState::Stale->value, $second->items[0]['state']);
        $this->assertNull($second->items[0]['value']);
        $this->assertSame('provider_stale', $second->items[0]['message']);
        $this->assertSame('unifi-health-v1:0', $second->nextCursor);
    }

    public function test_transitional_state_remains_unknown_when_statistics_are_unavailable(): void
    {
        $site = Site::factory()->create();
        $siteConfig = $this->siteConfig($site);
        $connection = $this->connection();
        $device = $this->syncDevice('updating-device', 'UPDATING');
        $this->fakeOfficialApis([$device], [$this->overview($device)], [
            'updating-device' => Http::response([], 404),
        ]);
        $this->assertTrue(app(UnifiAdapter::class)->syncDevices($siteConfig, $connection)->isSuccess());

        $page = app(UnifiAdapter::class)->collectObservations($siteConfig, $connection, null, 25);

        $this->assertFalse($page->partial);
        $this->assertSame(MonitorState::Unknown->value, $page->items[0]['state']);
        $this->assertSame('provider_state_transitional', $page->items[0]['message']);
        $this->assertFalse($page->items[0]['metrics']['statistics_available']);
    }

    public function test_partial_page_stops_before_an_unresolved_identity_without_requesting_its_statistics(): void
    {
        $site = Site::factory()->create();
        $siteConfig = $this->siteConfig($site);
        $connection = $this->connection();
        $known = $this->syncDevice('z-known', 'ONLINE');
        $unresolved = $this->syncDevice('a-unresolved', 'ONLINE');
        $this->fakeOfficialApis([$known], [
            $this->overview($unresolved),
            $this->overview($known),
        ], [
            'z-known' => Http::response($this->statistics(now()->toIso8601String())),
        ]);
        $this->assertTrue(app(UnifiAdapter::class)->syncDevices($siteConfig, $connection)->isSuccess());

        $page = app(UnifiAdapter::class)->collectObservations($siteConfig, $connection, null, 25);

        $this->assertTrue($page->partial);
        $this->assertSame([], $page->items);
        $this->assertSame('unifi-health-v1:0', $page->nextCursor);
        $this->assertSame('identity_unresolved', $page->exceptions[0]['code']);
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/statistics/latest'));
    }

    public function test_statistics_rate_limit_preserves_the_last_safe_cursor_and_defers(): void
    {
        $site = Site::factory()->create();
        $siteConfig = $this->siteConfig($site);
        $connection = $this->connection();
        $first = $this->syncDevice('a-first', 'ONLINE');
        $second = $this->syncDevice('b-limited', 'ONLINE');
        $this->fakeOfficialApis([$first, $second], [
            $this->overview($first),
            $this->overview($second),
        ], [
            'a-first' => Http::response($this->statistics(now()->toIso8601String())),
            'b-limited' => Http::response(
                ['message' => 'RAW-PROVIDER-RATE-LIMIT'],
                429,
                ['Retry-After' => '90'],
            ),
        ]);
        $this->assertTrue(app(UnifiAdapter::class)->syncDevices($siteConfig, $connection)->isSuccess());

        $page = app(UnifiAdapter::class)->collectObservations($siteConfig, $connection, null, 25);

        $this->assertTrue($page->partial);
        $this->assertCount(1, $page->items);
        $this->assertSame('unifi-health-v1:1', $page->nextCursor);
        $this->assertSame(90, $page->retryAfterSeconds);
        $this->assertSame('provider_rate_limited', $page->exceptions[0]['code']);
        $this->assertStringNotContainsString('RAW-', json_encode($page, JSON_THROW_ON_ERROR));

        (new PullProviderCapability(
            'unifi',
            $site->id,
            ObservationCollectionCapability::class,
        ))->handle(app(IntegrationAdapterRegistry::class), app(MonitoringOutboxPublisher::class));

        $this->assertSame('unifi-health-v1:1', ProviderCapabilityCursor::query()->sole()->cursor);
        $this->assertSame(1, MonitoringOutbox::query()->count());
        $this->assertSame('provider_rate_limited', ProviderCapabilityException::query()->sole()->code);
    }

    public function test_malformed_statistics_fail_closed_without_exposing_the_provider_payload(): void
    {
        $site = Site::factory()->create();
        $siteConfig = $this->siteConfig($site);
        $connection = $this->connection();
        $device = $this->syncDevice('malformed-device', 'ONLINE');
        $this->fakeOfficialApis([$device], [$this->overview($device)], [
            'malformed-device' => Http::response([
                ...$this->statistics(now()->toIso8601String()),
                'cpuUtilizationPct' => 101,
                'message' => 'RAW-MALFORMED-PROVIDER-PAYLOAD',
            ]),
        ]);
        $this->assertTrue(app(UnifiAdapter::class)->syncDevices($siteConfig, $connection)->isSuccess());

        try {
            app(UnifiAdapter::class)->collectObservations($siteConfig, $connection, null, 25);
            $this->fail('Malformed UniFi statistics were accepted.');
        } catch (IntegrationDiscoveryException $exception) {
            $this->assertSame(SafeOperationalData::failureSummary(), $exception->getMessage());
            $this->assertStringNotContainsString('RAW-', $exception->getMessage());
        }
    }

    public function test_invalid_cursor_and_limit_fail_before_network_io(): void
    {
        Http::preventStrayRequests();
        $site = Site::factory()->create();
        $siteConfig = $this->siteConfig($site);
        $connection = $this->connection();

        foreach ([
            ['provider-device-id:unsafe', 25],
            [null, 0],
            [null, 201],
        ] as [$cursor, $limit]) {
            try {
                app(UnifiAdapter::class)->collectObservations($siteConfig, $connection, $cursor, $limit);
                $this->fail('Invalid UniFi observation input was accepted.');
            } catch (\InvalidArgumentException $exception) {
                $this->assertSame('UniFi observation request is invalid.', $exception->getMessage());
            }
        }

        Http::assertNothingSent();
    }

    public function test_gateway_enrols_one_site_wan_monitor_and_publishes_official_isp_metrics(): void
    {
        $site = Site::factory()->create();
        $siteConfig = $this->siteConfig($site);
        $connection = $this->connection();
        $gateway = $this->syncGateway('gateway-sensitive-provider-id', 'ONLINE');
        $this->fakeOfficialApis(
            [$gateway],
            [$this->overview($gateway)],
            ['gateway-sensitive-provider-id' => Http::response($this->statistics(now()->toIso8601String()))],
            Http::response($this->ispMetrics([
                'avgLatency' => 25,
                'download_kbps' => 750000,
                'downtime' => 0,
                'ispAsn' => '64500',
                'ispName' => 'Example Fibre',
                'maxLatency' => 60,
                'packetLoss' => 0.5,
                'upload_kbps' => 150000,
                'uptime' => 99.9,
            ], now()->subMinutes(5)->toIso8601String())),
        );
        $this->assertTrue(app(UnifiAdapter::class)->syncDevices($siteConfig, $connection)->isSuccess());

        $monitors = Monitor::query()->with('profile')->orderBy('id')->get();
        $this->assertCount(2, $monitors);
        $wanMonitor = $monitors->first(
            fn (Monitor $monitor): bool => ($monitor->config['collection'] ?? null) === 'isp_metrics',
        );
        $this->assertNotNull($wanMonitor);
        $this->assertSame('UniFi WAN performance', $wanMonitor->profile->name);
        $this->assertSame(300, $wanMonitor->profile->interval_seconds);
        $this->assertSame(900, $wanMonitor->profile->stale_after_seconds);
        $this->assertStringNotContainsString('site-ext-1', $wanMonitor->target);
        $this->assertStringNotContainsString('console:one', $wanMonitor->target);

        $page = app(UnifiAdapter::class)->collectObservations($siteConfig, $connection, null, 200);
        $wan = collect($page->items)->firstWhere('metrics.scope', 'wan');

        $this->assertFalse($page->partial);
        $this->assertCount(2, $page->items);
        $this->assertNotNull($wan);
        $this->assertSame($wanMonitor->id, $wan['monitor_id']);
        $this->assertSame(MonitorState::Healthy->value, $wan['state']);
        $this->assertSame('2026-08-03T02:55:00+00:00', $wan['observed_at']);
        $this->assertSame(99.9, $wan['value']);
        $this->assertSame('percent', $wan['unit']);
        $this->assertSame(25, $wan['latency_ms']);
        $this->assertSame('wan_healthy', $wan['message']);
        $this->assertSame([
            'provider' => 'unifi',
            'scope' => 'wan',
            'interval' => '5m',
            'uptime_percent' => 99.9,
            'downtime_seconds' => 0,
            'packet_loss_percent' => 0.5,
            'average_latency_ms' => 25,
            'maximum_latency_ms' => 60,
            'download_kbps' => 750000,
            'upload_kbps' => 150000,
            'isp_asn' => '64500',
            'isp_name' => 'Example Fibre',
            'freshness_age_seconds' => 300,
        ], $wan['metrics']);
        $serialized = json_encode($page, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('gateway-sensitive-provider-id', $serialized);
        $this->assertStringNotContainsString('site-ext-1', $serialized);
        $this->assertStringNotContainsString('console:one', $serialized);

        (new PullProviderCapability(
            'unifi',
            $site->id,
            ObservationCollectionCapability::class,
        ))->handle(app(IntegrationAdapterRegistry::class), app(MonitoringOutboxPublisher::class));

        $this->assertSame(2, MonitoringOutbox::query()->count());
        $this->assertSame('unifi-health-v1:0', ProviderCapabilityCursor::query()->sole()->cursor);

        $store = new class implements TimeSeriesStore
        {
            /** @var list<TimeSeriesPoint> */
            public array $points = [];

            public function writePoints(array $points): void
            {
                array_push($this->points, ...$points);
            }

            public function range(
                string $externalKey,
                string $tier,
                CarbonImmutable $from,
                CarbonImmutable $to,
            ): array {
                return [];
            }

            public function deleteRange(
                string $externalKey,
                string $tier,
                CarbonImmutable $from,
                CarbonImmutable $to,
            ): void {}

            public function exists(
                string $externalKey,
                string $tier,
                ?CarbonImmutable $from = null,
                ?CarbonImmutable $to = null,
            ): bool {
                return false;
            }

            public function healthy(): bool
            {
                return true;
            }
        };
        config()->set('monitoring.storage.timeseries.url', 'https://timeseries.example.test');
        app()->instance(TimeSeriesStore::class, $store);

        $wanEnvelope = MonitoringOutbox::query()->get()
            ->map(fn (MonitoringOutbox $outbox) => app(RuntimeEnvelopeCodec::class)->decode($outbox->envelope_bytes))
            ->first(fn ($envelope): bool => ($envelope->payload['metrics']['scope'] ?? null) === 'wan');
        $this->assertNotNull($wanEnvelope);
        app(ObservationEnvelopeHandler::class)->handle($wanEnvelope, $site->id);

        $this->assertCount(10, $store->points);
        $this->assertSame([
            'monitor.latency' => 'milliseconds',
            'monitor.value' => 'percent',
            'observation.average_latency_ms' => 'milliseconds',
            'observation.download_kbps' => 'kilobits_per_second',
            'observation.downtime_seconds' => 'seconds',
            'observation.freshness_age_seconds' => 'seconds',
            'observation.maximum_latency_ms' => 'milliseconds',
            'observation.packet_loss_percent' => 'percent',
            'observation.upload_kbps' => 'kilobits_per_second',
            'observation.uptime_percent' => 'percent',
        ], MetricSeries::query()
            ->where('monitor_id', $wanMonitor->id)
            ->orderBy('metric')
            ->pluck('unit', 'metric')
            ->all());
        $this->assertSame(['standard'], MetricSeries::query()
            ->where('monitor_id', $wanMonitor->id)
            ->pluck('privacy_class')
            ->unique()
            ->values()
            ->all());
        $this->assertCount(1, $wanMonitor->observations()->get());
        $projected = json_encode($store->points, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('gateway-sensitive-provider-id', $projected);
        $this->assertStringNotContainsString('site-ext-1', $projected);
        $this->assertStringNotContainsString('console:one', $projected);
    }

    public function test_wan_state_distinguishes_degraded_failed_and_stale_periods(): void
    {
        $site = Site::factory()->create();
        $siteConfig = $this->siteConfig($site);
        $connection = $this->connection();
        $gateway = $this->syncGateway('gateway-state', 'ONLINE');
        $ispPayload = $this->ispMetrics([
            'avgLatency' => 20,
            'download_kbps' => 100000,
            'downtime' => 0,
            'maxLatency' => 40,
            'packetLoss' => 6,
            'upload_kbps' => 50000,
            'uptime' => 100,
        ], now()->subMinutes(5)->toIso8601String());
        $this->fakeOfficialApis(
            [$gateway],
            [$this->overview($gateway)],
            ['gateway-state' => Http::response($this->statistics(now()->toIso8601String()))],
            function () use (&$ispPayload) {
                return Http::response($ispPayload);
            },
        );
        $this->assertTrue(app(UnifiAdapter::class)->syncDevices($siteConfig, $connection)->isSuccess());

        $degraded = $this->wanItem(app(UnifiAdapter::class)->collectObservations($siteConfig, $connection, null, 25));
        $this->assertSame(MonitorState::Degraded->value, $degraded['state']);
        $this->assertSame('wan_degraded', $degraded['message']);

        $ispPayload = $this->ispMetrics([
            'avgLatency' => 20,
            'download_kbps' => 0,
            'downtime' => 300,
            'maxLatency' => 20,
            'packetLoss' => 0,
            'upload_kbps' => 0,
            'uptime' => 0,
        ], now()->subMinutes(20)->toIso8601String());
        $failed = $this->wanItem(app(UnifiAdapter::class)->collectObservations($siteConfig, $connection, null, 25));
        $this->assertSame(MonitorState::Failed->value, $failed['state']);
        $this->assertSame('wan_unavailable', $failed['message']);

        $ispPayload = $this->ispMetrics([
            'avgLatency' => 20,
            'download_kbps' => 100000,
            'downtime' => 0,
            'maxLatency' => 40,
            'packetLoss' => 0,
            'upload_kbps' => 50000,
            'uptime' => 100,
        ], now()->subMinutes(20)->toIso8601String());
        $stale = $this->wanItem(app(UnifiAdapter::class)->collectObservations($siteConfig, $connection, null, 25));
        $this->assertSame(MonitorState::Stale->value, $stale['state']);
        $this->assertSame('provider_stale', $stale['message']);
    }

    public function test_wan_rate_limit_preserves_device_observation_and_defers_without_raw_payload(): void
    {
        $site = Site::factory()->create();
        $siteConfig = $this->siteConfig($site);
        $connection = $this->connection();
        $gateway = $this->syncGateway('gateway-limited', 'ONLINE');
        $this->fakeOfficialApis(
            [$gateway],
            [$this->overview($gateway)],
            ['gateway-limited' => Http::response($this->statistics(now()->toIso8601String()))],
            Http::response(
                ['message' => 'RAW-WAN-RATE-LIMIT'],
                429,
                ['Retry-After' => '90'],
            ),
        );
        $this->assertTrue(app(UnifiAdapter::class)->syncDevices($siteConfig, $connection)->isSuccess());

        $page = app(UnifiAdapter::class)->collectObservations($siteConfig, $connection, null, 25);

        $this->assertTrue($page->partial);
        $this->assertCount(1, $page->items);
        $this->assertSame('unifi-health-v1:0', $page->nextCursor);
        $this->assertSame(90, $page->retryAfterSeconds);
        $this->assertSame('provider_rate_limited', $page->exceptions[0]['code']);
        $this->assertStringNotContainsString('RAW-', json_encode($page, JSON_THROW_ON_ERROR));
    }

    private function connection(): IntegrationProviderConnection
    {
        return IntegrationProviderConnection::query()->create([
            'provider' => 'unifi',
            'secret_encrypted' => Crypt::encryptString('official-unifi-secret'),
            'secret_last4' => 'cret',
            'status' => IntegrationProviderConnection::STATUS_CONNECTED,
        ]);
    }

    private function siteConfig(Site $site): IntegrationSiteConfig
    {
        return IntegrationSiteConfig::query()->create([
            'site_id' => $site->id,
            'provider' => 'unifi',
            'status' => IntegrationSiteConfig::STATUS_HYBRID,
            'mapped_external_site_id' => 'site-ext-1',
            'mapped_external_site_name' => 'Official Network API Site',
            'is_active' => true,
        ]);
    }

    /** @return array<string, mixed> */
    private function syncDevice(string $id, string $state): array
    {
        $suffix = substr(hash('sha256', $id), 0, 6);

        return [
            'id' => $id,
            'productLine' => 'network',
            'shortname' => 'uap',
            'model' => 'U7-Pro',
            'mac' => sprintf(
                'AA:BB:CC:%s:%s:%s',
                strtoupper(substr($suffix, 0, 2)),
                strtoupper(substr($suffix, 2, 2)),
                strtoupper(substr($suffix, 4, 2)),
            ),
            'status' => $state,
            'ip' => '10.44.0.20',
            'version' => '9.0.1',
            'name' => 'Network device',
            'lastSeen' => now()->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    private function syncGateway(string $id, string $state): array
    {
        return [
            ...$this->syncDevice($id, $state),
            'shortname' => 'udm',
            'model' => 'UDM-SE',
            'name' => 'Site gateway',
        ];
    }

    /** @param array<string, mixed> $device @return array<string, mixed> */
    private function overview(array $device): array
    {
        return [
            'id' => $device['id'],
            'name' => $device['name'],
            'model' => $device['model'],
            'macAddress' => $device['mac'],
            'ipAddress' => $device['ip'],
            'state' => strtoupper((string) $device['status']),
        ];
    }

    /** @return array<string, mixed> */
    private function statistics(
        string $heartbeat,
        int|float $cpu = 35,
        int|float $memory = 55,
    ): array {
        return [
            'uptimeSec' => 86400,
            'lastHeartbeatAt' => $heartbeat,
            'nextHeartbeatAt' => now()->addMinute()->toIso8601String(),
            'loadAverage1Min' => 0.7,
            'loadAverage5Min' => 0.5,
            'loadAverage15Min' => 0.4,
            'cpuUtilizationPct' => $cpu,
            'memoryUtilizationPct' => $memory,
            'uplink' => [
                'txRateBps' => 125000,
                'rxRateBps' => 250000,
            ],
            'interfaces' => [],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $syncDevices
     * @param  list<array<string, mixed>>  $networkDevices
     * @param  array<string, Response>  $statistics
     */
    private function fakeOfficialApis(
        array $syncDevices,
        array $networkDevices,
        array $statistics,
        mixed $ispMetrics = null,
    ): void {
        Http::fake(function (Request $request) use ($syncDevices, $networkDevices, $statistics, $ispMetrics) {
            $url = $request->url();
            $path = (string) parse_url($url, PHP_URL_PATH);
            $query = [];
            parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

            if ($url === 'https://api.ui.com/v1/sites') {
                return Http::response(['data' => [[
                    'siteId' => 'site-ext-1',
                    'hostId' => 'console:one',
                ]]]);
            }
            if ($url === 'https://api.ui.com/v1/devices') {
                return Http::response(['data' => [[
                    'hostId' => 'console:one',
                    'devices' => $syncDevices,
                ]]]);
            }
            if ($path === '/v1/isp-metrics/5m') {
                return is_callable($ispMetrics)
                    ? $ispMetrics($request)
                    : ($ispMetrics ?? Http::response(['data' => []]));
            }
            if (str_ends_with($path, '/sites/site-ext-1/devices')) {
                $offset = (int) ($query['offset'] ?? 0);
                $limit = (int) ($query['limit'] ?? 25);
                $page = array_slice($networkDevices, $offset, $limit);

                return Http::response([
                    'offset' => $offset,
                    'limit' => $limit,
                    'count' => count($page),
                    'totalCount' => count($networkDevices),
                    'data' => $page,
                ]);
            }
            if (preg_match('#/devices/([^/]+)/statistics/latest$#', $path, $matches) === 1) {
                $id = rawurldecode($matches[1]);

                return $statistics[$id] ?? Http::response(['message' => 'unexpected statistics request'], 500);
            }

            return Http::response(['message' => 'unexpected request'], 500);
        });
    }

    /** @param array<string, int|float|string> $wan @return array<string, mixed> */
    private function ispMetrics(
        array $wan,
        string $metricTime,
        string $hostId = 'console:one',
        string $siteId = 'site-ext-1',
    ): array {
        return [
            'data' => [[
                'metricType' => '5m',
                'periods' => [[
                    'data' => ['wan' => $wan],
                    'metricTime' => $metricTime,
                    'version' => '1',
                ]],
                'hostId' => $hostId,
                'siteId' => $siteId,
            ]],
            'httpStatusCode' => 200,
            'traceId' => 'provider-trace-not-persisted',
        ];
    }

    /** @return array<string, mixed> */
    private function wanItem(ProviderObservationPage $page): array
    {
        $item = collect($page->items)->firstWhere('metrics.scope', 'wan');
        $this->assertNotNull($item);

        return $item;
    }
}
