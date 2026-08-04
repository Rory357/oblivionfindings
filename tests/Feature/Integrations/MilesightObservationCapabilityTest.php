<?php

namespace Tests\Feature\Integrations;

use App\Domain\Monitoring\Enums\MonitorState;
use App\Domain\Monitoring\Jobs\PullProviderCapability;
use App\Domain\Monitoring\Models\Monitor;
use App\Domain\Monitoring\Models\MonitoringOutbox;
use App\Domain\Monitoring\Models\ProviderCapabilityCursor;
use App\Domain\Monitoring\Services\MonitoringOutboxPublisher;
use App\Domain\Monitoring\Services\RuntimeEnvelopeCodec;
use App\Models\Integration\IntegrationProviderConnection;
use App\Models\Integration\IntegrationSiteConfig;
use App\Models\Site;
use App\Services\Integration\Adapters\MilesightAdapter;
use App\Services\Integration\Contracts\ObservationCollectionCapability;
use App\Services\Integration\IntegrationAdapterRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class MilesightObservationCapabilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow('2026-08-03T02:00:00Z');
        config()->set('monitoring.signing', [
            'active_key_id' => 'milesight-observation-test',
            'keys' => [
                'milesight-observation-test' => base64_encode(str_repeat("\x41", SODIUM_CRYPTO_AUTH_KEYBYTES)),
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

    public function test_sync_enrols_a_canonical_device_and_publishes_a_safe_site_scoped_observation(): void
    {
        $site = Site::factory()->create();
        $siteConfig = $this->siteConfig($site);
        $connection = $this->connection();
        $payload = $this->device(
            id: 'sensor-sensitive-provider-id',
            status: 'ONLINE',
            lastUpdate: now()->subMinute()->toIso8601String(),
            battery: 72,
        );
        $this->fakeInventory([$payload]);

        $sync = app(MilesightAdapter::class)->syncDevices($siteConfig, $connection);
        $this->assertTrue($sync->isSuccess());

        $registry = app(IntegrationAdapterRegistry::class);
        $this->assertTrue($registry->hasCapability('milesight', ObservationCollectionCapability::class));
        $this->assertContains('operational_observations', $registry->manifest('milesight')->sensitivityLabels);

        $monitor = Monitor::query()->with('device')->sole();
        $this->assertSame('milesight', $monitor->config['provider'] ?? null);
        $this->assertSame('device_status', $monitor->config['collection'] ?? null);
        $this->assertStringNotContainsString('sensor-sensitive-provider-id', $monitor->target);

        $page = app(MilesightAdapter::class)->collectObservations($siteConfig, $connection, null, 100);

        $this->assertFalse($page->partial);
        $this->assertSame('milesight-status-v1:0', $page->nextCursor);
        $this->assertCount(1, $page->items);
        $this->assertSame([
            'monitor_id' => $monitor->id,
            'device_id' => $monitor->device_id,
            'site_id' => $site->id,
            'state' => MonitorState::Healthy->value,
            'observed_at' => '2026-08-03T02:00:00+00:00',
            'value' => 1,
            'unit' => 'online',
            'latency_ms' => null,
            'message' => 'provider_online',
            'metrics' => [
                'provider' => 'milesight',
                'connectivity' => 'online',
                'battery_percent' => 72,
                'freshness_age_seconds' => 60,
            ],
        ], array_diff_key($page->items[0], ['cursor' => true, 'source_key' => true]));
        $serialized = json_encode($page, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('sensor-sensitive-provider-id', $serialized);
        $this->assertStringNotContainsString('secret-9876', $serialized);
        CarbonImmutable::setTestNow('2026-08-03T02:01:00Z');
        $retryPage = app(MilesightAdapter::class)->collectObservations($siteConfig, $connection, null, 100);
        $this->assertSame($page->items[0]['source_key'], $retryPage->items[0]['source_key']);

        (new PullProviderCapability(
            'milesight',
            $site->id,
            ObservationCollectionCapability::class,
        ))->handle(app(IntegrationAdapterRegistry::class), app(MonitoringOutboxPublisher::class));

        $cursor = ProviderCapabilityCursor::query()->sole();
        $outbox = MonitoringOutbox::query()->sole();
        $envelope = app(RuntimeEnvelopeCodec::class)->decode($outbox->envelope_bytes);
        $this->assertSame('milesight-status-v1:0', $cursor->cursor);
        $this->assertSame('monitoring-checks', $outbox->stream);
        $this->assertSame($site->id, $envelope->payload['site_id']);
        $this->assertSame($monitor->id, $envelope->payload['monitor_id']);
        $this->assertArrayNotHasKey('cursor', $envelope->payload);
    }

    public function test_status_mapping_is_paginated_and_explicit_offline_wins_over_freshness(): void
    {
        $site = Site::factory()->create();
        $siteConfig = $this->siteConfig($site);
        $connection = $this->connection();
        $this->fakeInventory([
            $this->device('offline-device', 'OFFLINE', now()->subHours(2)->toIso8601String(), 44),
            $this->device('stale-device', 'ONLINE', now()->subHours(2)->toIso8601String(), 88),
        ]);
        $this->assertTrue(app(MilesightAdapter::class)->syncDevices($siteConfig, $connection)->isSuccess());

        $first = app(MilesightAdapter::class)->collectObservations($siteConfig, $connection, null, 1);
        $second = app(MilesightAdapter::class)->collectObservations(
            $siteConfig,
            $connection,
            $first->nextCursor,
            1,
        );

        $this->assertSame(MonitorState::Failed->value, $first->items[0]['state']);
        $this->assertSame(0, $first->items[0]['value']);
        $this->assertSame('provider_offline', $first->items[0]['message']);
        $this->assertSame('milesight-status-v1:1', $first->nextCursor);
        $this->assertSame(MonitorState::Stale->value, $second->items[0]['state']);
        $this->assertNull($second->items[0]['value']);
        $this->assertSame('provider_stale', $second->items[0]['message']);
        $this->assertSame('milesight-status-v1:0', $second->nextCursor);
    }

    public function test_unresolved_inventory_returns_a_safe_partial_page(): void
    {
        $site = Site::factory()->create();
        $siteConfig = $this->siteConfig($site);
        $connection = $this->connection();
        $this->fakeInventory([$this->device('not-synchronised', 'ONLINE', now()->toIso8601String(), 10)]);

        $unresolved = app(MilesightAdapter::class)->collectObservations($siteConfig, $connection, null, 100);
        $this->assertTrue($unresolved->partial);
        $this->assertSame([], $unresolved->items);
        $this->assertSame('identity_unresolved', $unresolved->exceptions[0]['code']);
        $this->assertSame(64, strlen($unresolved->exceptions[0]['item_reference'] ?? ''));
    }

    public function test_partial_page_stops_before_an_unresolved_identity_instead_of_skipping_it(): void
    {
        $site = Site::factory()->create();
        $siteConfig = $this->siteConfig($site);
        $connection = $this->connection();
        $known = $this->device('z-known', 'ONLINE', now()->toIso8601String(), 80);
        $devices = [$known];
        $this->fakeMutableInventory($devices);
        $this->assertTrue(app(MilesightAdapter::class)->syncDevices($siteConfig, $connection)->isSuccess());

        $devices = [
            $this->device('a-unresolved', 'ONLINE', now()->toIso8601String(), 10),
            $known,
        ];
        $page = app(MilesightAdapter::class)->collectObservations($siteConfig, $connection, null, 100);

        $this->assertTrue($page->partial);
        $this->assertSame([], $page->items);
        $this->assertSame('milesight-status-v1:0', $page->nextCursor);
        $this->assertSame('identity_unresolved', $page->exceptions[0]['code']);
    }

    public function test_rate_limits_return_a_bounded_deferred_page(): void
    {
        $site = Site::factory()->create();
        $siteConfig = $this->siteConfig($site);
        $connection = $this->connection();

        Http::fake([
            'https://milesight.example.test/oauth/token' => Http::response(['data' => ['access_token' => 'access-token']]),
            'https://milesight.example.test/device/openapi/v1/devices/search' => Http::response(
                ['message' => 'RAW-PROVIDER-RATE-LIMIT'],
                429,
                ['Retry-After' => '90'],
            ),
        ]);

        $limited = app(MilesightAdapter::class)->collectObservations($siteConfig, $connection, null, 100);
        $this->assertTrue($limited->partial);
        $this->assertSame([], $limited->items);
        $this->assertSame(90, $limited->retryAfterSeconds);
        $this->assertSame('provider_rate_limited', $limited->exceptions[0]['code']);
        $this->assertStringNotContainsString('RAW-PROVIDER-RATE-LIMIT', json_encode($limited, JSON_THROW_ON_ERROR));
    }

    public function test_invalid_cursor_and_limit_fail_before_network_io(): void
    {
        Http::preventStrayRequests();
        $site = Site::factory()->create();
        $siteConfig = $this->siteConfig($site);
        $connection = $this->connection();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Milesight observation request is invalid.');
        app(MilesightAdapter::class)->collectObservations(
            $siteConfig,
            $connection,
            'provider-device-id:unsafe',
            0,
        );
    }

    private function connection(): IntegrationProviderConnection
    {
        return IntegrationProviderConnection::query()->create([
            'provider' => 'milesight',
            'secret_encrypted' => Crypt::encryptString('secret-9876'),
            'secret_last4' => '9876',
            'status' => IntegrationProviderConnection::STATUS_CONNECTED,
            'config' => [
                'client_id' => 'client-123',
                'base_url' => 'https://milesight.example.test',
            ],
        ]);
    }

    private function siteConfig(Site $site): IntegrationSiteConfig
    {
        return IntegrationSiteConfig::query()->create([
            'site_id' => $site->id,
            'provider' => 'milesight',
            'status' => IntegrationSiteConfig::STATUS_HYBRID,
            'mapped_external_site_id' => 'application-a',
            'mapped_external_site_name' => 'Care sensors',
            'is_active' => true,
        ]);
    }

    /** @return array<string, mixed> */
    private function device(
        string $id,
        string $status,
        string $lastUpdate,
        int $battery,
    ): array {
        return [
            'deviceId' => $id,
            'name' => 'Care sensor',
            'deviceType' => 'SUB_DEVICE',
            'model' => 'Temperature Sensor',
            'connectStatus' => $status,
            'lastUpdateTime' => $lastUpdate,
            'electricity' => $battery,
            'application' => [
                'applicationId' => 'application-a',
                'applicationName' => 'Care sensors',
            ],
        ];
    }

    /** @param list<array<string, mixed>> $devices */
    private function fakeInventory(array $devices): void
    {
        Http::fake(function (Request $request) use ($devices) {
            if ($request->url() === 'https://milesight.example.test/oauth/token') {
                return Http::response(['data' => ['access_token' => 'access-token']]);
            }

            return Http::response([
                'data' => [
                    'pageSize' => 100,
                    'pageNumber' => 1,
                    'total' => count($devices),
                    'content' => $devices,
                ],
            ]);
        });
    }

    /** @param list<array<string, mixed>> $devices */
    private function fakeMutableInventory(array &$devices): void
    {
        Http::fake(function (Request $request) use (&$devices) {
            if ($request->url() === 'https://milesight.example.test/oauth/token') {
                return Http::response(['data' => ['access_token' => 'access-token']]);
            }

            return Http::response([
                'data' => [
                    'pageSize' => 100,
                    'pageNumber' => 1,
                    'total' => count($devices),
                    'content' => $devices,
                ],
            ]);
        });
    }
}
