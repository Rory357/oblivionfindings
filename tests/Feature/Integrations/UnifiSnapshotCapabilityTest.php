<?php

namespace Tests\Feature\Integrations;

use App\Domain\Monitoring\Contracts\SnapshotStore;
use App\Domain\Monitoring\Exceptions\SnapshotStoreUnavailable;
use App\Domain\Monitoring\Jobs\PullProviderCapability;
use App\Domain\Monitoring\Models\ConfigurationSnapshot;
use App\Domain\Monitoring\Models\Monitor;
use App\Domain\Monitoring\Models\ProviderCapabilityCursor;
use App\Domain\Monitoring\Services\ConfigurationSnapshotService;
use App\Domain\Monitoring\Services\MonitoringOutboxPublisher;
use App\Models\Integration\IntegrationProviderConnection;
use App\Models\Integration\IntegrationSiteConfig;
use App\Models\Site;
use App\Services\Integration\Adapters\UnifiAdapter;
use App\Services\Integration\Contracts\SnapshotCollectionCapability;
use App\Services\Integration\IntegrationAdapterRegistry;
use App\Services\Integration\IntegrationDiscoveryException;
use App\Support\SafeOperationalData;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class UnifiSnapshotCapabilityTest extends TestCase
{
    use RefreshDatabase;

    private UnifiSnapshotFakeStore $store;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow('2026-08-03T04:00:00Z');
        $this->store = new UnifiSnapshotFakeStore;
        app()->instance(SnapshotStore::class, $this->store);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_gateway_configuration_is_normalised_persisted_and_deduplicated_by_content(): void
    {
        $site = Site::factory()->create();
        $siteConfig = $this->siteConfig($site);
        $connection = $this->connection();
        $gateway = $this->gateway('gateway-sensitive-provider-id');
        $wanInterfaces = [[
            'id' => 'wan-interface-sensitive-id',
            'name' => 'Primary fibre',
        ]];
        $tunnels = [[
            'id' => 'tunnel-sensitive-id',
            'name' => 'Head office mesh',
            'type' => 'wireguard',
            'metadata' => ['origin' => 'site_manager'],
        ]];
        $rateLimitedResource = null;
        $this->fakeOfficialApis([$gateway], $wanInterfaces, $tunnels, $rateLimitedResource);
        $this->assertTrue(app(UnifiAdapter::class)->syncDevices($siteConfig, $connection)->isSuccess());

        $registry = app(IntegrationAdapterRegistry::class);
        $this->assertTrue($registry->hasCapability('unifi', SnapshotCollectionCapability::class));
        $this->assertContains('configuration_snapshots', $registry->manifest('unifi')->sensitivityLabels);

        $wanMonitor = Monitor::query()->get()->first(
            fn (Monitor $monitor): bool => ($monitor->config['collection'] ?? null) === 'isp_metrics',
        );
        $this->assertNotNull($wanMonitor);

        $page = app(UnifiAdapter::class)->collectSnapshots($siteConfig, $connection, null, 200);

        $this->assertFalse($page->partial);
        $this->assertCount(1, $page->items);
        $this->assertMatchesRegularExpression('/^unifi-network-config-v1:[a-f0-9]{64}$/', $page->nextCursor);
        $this->assertSame($page->nextCursor, $page->items[0]['cursor']);
        $this->assertSame($site->id, $page->items[0]['site_id']);
        $this->assertSame($wanMonitor->device_id, $page->items[0]['device_id']);
        $this->assertSame('2026-08-03T04:00:00+00:00', $page->items[0]['captured_at']);
        $this->assertSame([
            'configuration' => [
                'schema' => 'unifi_network_site_configuration_v1',
                'site_to_site_vpn_tunnels' => [[
                    'name' => 'Head office mesh',
                    'origin' => 'site_manager',
                    'reference' => hash('sha256', 'tunnel-sensitive-id'),
                    'type' => 'wireguard',
                ]],
                'wan_interfaces' => [[
                    'name' => 'Primary fibre',
                    'reference' => hash('sha256', 'wan-interface-sensitive-id'),
                ]],
            ],
        ], $page->items[0]['payload']);
        $serialized = json_encode($page, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('gateway-sensitive-provider-id', $serialized);
        $this->assertStringNotContainsString('wan-interface-sensitive-id', $serialized);
        $this->assertStringNotContainsString('tunnel-sensitive-id', $serialized);
        $this->assertStringNotContainsString('site-ext-1', $serialized);
        $this->assertStringNotContainsString('console:one', $serialized);
        $this->assertStringNotContainsString('official-unifi-secret', $serialized);

        $this->pullSnapshot($site);

        $snapshot = ConfigurationSnapshot::query()->sole();
        $this->assertSame($site->id, $snapshot->site_id);
        $this->assertSame($wanMonitor->device_id, $snapshot->device_id);
        $this->assertSame('provider', $snapshot->source_kind);
        $this->assertSame('unifi', $snapshot->source);
        $this->assertSame($page->nextCursor, ProviderCapabilityCursor::query()->sole()->cursor);
        $this->assertArrayHasKey($snapshot->storage_path, $this->store->objects);
        $stored = $this->store->objects[$snapshot->storage_path];
        $this->assertStringContainsString('Head office mesh', $stored);
        $this->assertStringNotContainsString('tunnel-sensitive-id', $stored);
        $this->assertSame(
            $snapshot->configuration_hash,
            data_get($wanMonitor->device->fresh()->meta, 'observed.configuration_hash'),
        );

        CarbonImmutable::setTestNow('2026-08-03T04:01:01Z');
        $this->pullSnapshot($site);
        $this->assertSame(1, ConfigurationSnapshot::query()->count());

        $tunnels[0]['name'] = 'Head office mesh updated';
        CarbonImmutable::setTestNow('2026-08-03T04:02:02Z');
        $this->pullSnapshot($site);

        $latest = ConfigurationSnapshot::query()->orderByDesc('id')->firstOrFail();
        $this->assertSame(2, ConfigurationSnapshot::query()->count());
        $this->assertSame($snapshot->id, $latest->previous_snapshot_id);
        $this->assertContains(
            'configuration.site_to_site_vpn_tunnels.0.name',
            $latest->diff_summary['changed'],
        );
        $this->assertStringNotContainsString(
            'Head office mesh updated',
            json_encode($latest->diff_summary, JSON_THROW_ON_ERROR),
        );
    }

    public function test_snapshot_rate_limit_defers_without_losing_the_safe_cursor_or_exposing_payload(): void
    {
        $site = Site::factory()->create();
        $siteConfig = $this->siteConfig($site);
        $connection = $this->connection();
        $gateway = $this->gateway('gateway-rate-limited');
        $wanInterfaces = [];
        $tunnels = [];
        $rateLimitedResource = null;
        $this->fakeOfficialApis([$gateway], $wanInterfaces, $tunnels, $rateLimitedResource);
        $this->assertTrue(app(UnifiAdapter::class)->syncDevices($siteConfig, $connection)->isSuccess());

        $cursor = 'unifi-network-config-v1:'.str_repeat('a', 64);
        $rateLimitedResource = 'wans';
        $page = app(UnifiAdapter::class)->collectSnapshots($siteConfig, $connection, $cursor, 200);

        $this->assertTrue($page->partial);
        $this->assertSame([], $page->items);
        $this->assertSame($cursor, $page->nextCursor);
        $this->assertSame(90, $page->retryAfterSeconds);
        $this->assertStringNotContainsString('RAW-PROVIDER-RATE-LIMIT', json_encode($page, JSON_THROW_ON_ERROR));
    }

    public function test_snapshot_cursor_limit_and_malformed_inventory_fail_closed(): void
    {
        $site = Site::factory()->create();
        $siteConfig = $this->siteConfig($site);
        $connection = $this->connection();

        Http::preventStrayRequests();
        foreach ([
            ['provider-id:unsafe', 200],
            [null, 0],
            [null, 201],
        ] as [$cursor, $limit]) {
            try {
                app(UnifiAdapter::class)->collectSnapshots($siteConfig, $connection, $cursor, $limit);
                $this->fail('Invalid UniFi snapshot input was accepted.');
            } catch (\InvalidArgumentException $exception) {
                $this->assertSame('UniFi configuration snapshot request is invalid.', $exception->getMessage());
            }
        }
        Http::assertNothingSent();

        $gateway = $this->gateway('gateway-malformed');
        $wanInterfaces = [
            ['id' => 'duplicate-wan', 'name' => 'WAN 1'],
            ['id' => 'duplicate-wan', 'name' => 'RAW-MALFORMED-PROVIDER-PAYLOAD'],
        ];
        $tunnels = [];
        $rateLimitedResource = null;
        $this->fakeOfficialApis([$gateway], $wanInterfaces, $tunnels, $rateLimitedResource);
        $this->assertTrue(app(UnifiAdapter::class)->syncDevices($siteConfig, $connection)->isSuccess());

        try {
            app(UnifiAdapter::class)->collectSnapshots($siteConfig, $connection, null, 200);
            $this->fail('Malformed UniFi configuration inventory was accepted.');
        } catch (IntegrationDiscoveryException $exception) {
            $this->assertSame(SafeOperationalData::failureSummary(), $exception->getMessage());
            $this->assertStringNotContainsString('RAW-', $exception->getMessage());
        }
        $this->assertSame(0, ConfigurationSnapshot::query()->count());
    }

    private function pullSnapshot(Site $site): void
    {
        (new PullProviderCapability(
            'unifi',
            $site->id,
            SnapshotCollectionCapability::class,
        ))->handle(
            app(IntegrationAdapterRegistry::class),
            app(MonitoringOutboxPublisher::class),
            app(ConfigurationSnapshotService::class),
        );
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
    private function gateway(string $id): array
    {
        return [
            'id' => $id,
            'productLine' => 'network',
            'shortname' => 'udm',
            'model' => 'UDM-SE',
            'mac' => 'AA:BB:CC:DD:EE:91',
            'status' => 'ONLINE',
            'ip' => '10.44.0.1',
            'version' => '9.0.1',
            'name' => 'Site gateway',
            'lastSeen' => now()->toIso8601String(),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $syncDevices
     * @param  list<array<string, mixed>>  $wanInterfaces
     * @param  list<array<string, mixed>>  $tunnels
     */
    private function fakeOfficialApis(
        array $syncDevices,
        array &$wanInterfaces,
        array &$tunnels,
        ?string &$rateLimitedResource,
    ): void {
        Http::fake(function (Request $request) use ($syncDevices, &$wanInterfaces, &$tunnels, &$rateLimitedResource) {
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
            if (str_ends_with($path, '/wans')) {
                if ($rateLimitedResource === 'wans') {
                    return Http::response(
                        ['message' => 'RAW-PROVIDER-RATE-LIMIT'],
                        429,
                        ['Retry-After' => '90'],
                    );
                }

                return $this->page($wanInterfaces, $query);
            }
            if (str_ends_with($path, '/vpn/site-to-site-tunnels')) {
                return $this->page($tunnels, $query);
            }

            return Http::response(['message' => 'unexpected request'], 500);
        });
    }

    /** @param list<array<string, mixed>> $items @param array<string, mixed> $query */
    private function page(array $items, array $query)
    {
        $offset = (int) ($query['offset'] ?? 0);
        $limit = (int) ($query['limit'] ?? 200);
        $data = array_slice($items, $offset, $limit);

        return Http::response([
            'offset' => $offset,
            'limit' => $limit,
            'count' => count($data),
            'totalCount' => count($items),
            'data' => $data,
        ]);
    }
}

final class UnifiSnapshotFakeStore implements SnapshotStore
{
    /** @var array<string, string> */
    public array $objects = [];

    public function put(string $path, string $contents): void
    {
        $this->objects[$path] = $contents;
    }

    public function read(string $path): string
    {
        if (! isset($this->objects[$path])) {
            throw new SnapshotStoreUnavailable('Snapshot storage is unavailable.');
        }

        return $this->objects[$path];
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
