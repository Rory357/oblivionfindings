<?php

namespace Tests\Feature\SecurityDevices;

use App\Models\Integration\IntegrationProviderConnection;
use App\Models\Integration\IntegrationSiteConfig;
use App\Models\Site;
use App\Services\Integration\Adapters\UnifiAdapter;
use App\Services\Integration\IntegrationDiscoveryException;
use App\Support\SafeOperationalData;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

if (getenv('MONITORING_USE_PREBUILT_TEST_DATABASE') === '1') {
    $databasePath = getenv('DB_DATABASE');
    if (getenv('APP_ENV') !== 'testing'
        || getenv('DB_CONNECTION') !== 'sqlite'
        || ! is_string($databasePath)
        || $databasePath === ''
        || $databasePath === ':memory:'
        || ! is_file($databasePath)) {
        throw new \RuntimeException(
            'MONITORING_USE_PREBUILT_TEST_DATABASE requires APP_ENV=testing, DB_CONNECTION=sqlite, and an existing file-backed database.',
        );
    }

    RefreshDatabaseState::$migrated = true;
}

final class UnifiTopologyCapabilityTest extends TestCase
{
    use RefreshDatabase;

    private IntegrationSiteConfig $siteConfig;

    private IntegrationProviderConnection $connection;

    protected function setUp(): void
    {
        parent::setUp();

        $site = Site::factory()->create();
        $this->siteConfig = IntegrationSiteConfig::query()->create([
            'site_id' => $site->id,
            'provider' => 'unifi',
            'status' => IntegrationSiteConfig::STATUS_HYBRID,
            'mapped_external_site_id' => 'site-ext-1',
            'mapped_external_site_name' => 'Official Network API Site',
            'is_active' => true,
        ]);
        $this->connection = IntegrationProviderConnection::query()->create([
            'provider' => 'unifi',
            'secret_encrypted' => Crypt::encryptString('official-unifi-key'),
            'secret_last4' => '-key',
            'status' => IntegrationProviderConnection::STATUS_CONNECTED,
        ]);
        Http::preventStrayRequests();
    }

    public function test_collects_paginated_adopted_devices_and_official_uplink_relationships(): void
    {
        $this->fakeOfficialTopologyApi();
        $adapter = app(UnifiAdapter::class);

        $first = $adapter->collectTopology($this->siteConfig, $this->connection, null, 2);
        $second = $adapter->collectTopology($this->siteConfig, $this->connection, $first->nextCursor, 2);

        $this->assertSame('2', $first->nextCursor);
        $this->assertCount(2, $first->nodes);
        $this->assertCount(1, $first->edges);
        $this->assertNull($second->nextCursor);
        $this->assertCount(2, $second->nodes);
        $this->assertCount(1, $second->edges);
        $this->assertSame([
            'source' => 'provider',
            'kind' => 'uplink',
            'confidence' => 0.99,
            'evidence' => [
                'protocol' => 'unifi_network_api',
                'relationship' => 'reported_uplink',
            ],
        ], array_intersect_key($second->edges[0], array_flip([
            'source', 'kind', 'confidence', 'evidence',
        ])));
        $this->assertSame('unifi', $second->nodes[0]['identity']['provider']);
        $this->assertSame('ap-1', $second->nodes[0]['identity']['provider_id']);
        $this->assertSame(['aa:bb:cc:dd:ee:03'], $second->nodes[0]['identity']['mac_addresses']);
        $this->assertSame(['10.44.0.30'], $second->nodes[0]['identity']['addresses']);
        $this->assertStringNotContainsString(
            'official-unifi-key',
            json_encode([$first, $second], JSON_THROW_ON_ERROR),
        );

        Http::assertSent(function (Request $request): bool {
            $query = [];
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            return str_ends_with((string) parse_url($request->url(), PHP_URL_PATH), '/devices')
                && $query === ['offset' => '0', 'limit' => '2']
                && $request->hasHeader('X-API-Key', 'official-unifi-key');
        });
        Http::assertSent(function (Request $request): bool {
            $query = [];
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            return str_ends_with((string) parse_url($request->url(), PHP_URL_PATH), '/devices')
                && $query === ['offset' => '2', 'limit' => '2'];
        });
    }

    public function test_rate_limit_defers_the_snapshot_without_returning_partial_topology(): void
    {
        Http::fake(function (Request $request) {
            if ($request->url() === 'https://api.ui.com/v1/sites') {
                return Http::response(['data' => [[
                    'siteId' => 'site-ext-1',
                    'hostId' => 'console:one',
                ]]], 200);
            }

            return Http::response(['message' => 'RAW-PROVIDER-RATE-LIMIT'], 429, ['Retry-After' => '90']);
        });

        $page = app(UnifiAdapter::class)->collectTopology(
            $this->siteConfig,
            $this->connection,
            null,
            25,
        );

        $this->assertTrue($page->partial);
        $this->assertSame(90, $page->retryAfterSeconds);
        $this->assertSame([], $page->nodes);
        $this->assertSame([], $page->edges);
        $this->assertStringNotContainsString('RAW-', json_encode($page, JSON_THROW_ON_ERROR));
    }

    public function test_malformed_official_page_fails_closed(): void
    {
        Http::fake(function (Request $request) {
            if ($request->url() === 'https://api.ui.com/v1/sites') {
                return Http::response(['data' => [[
                    'siteId' => 'site-ext-1',
                    'hostId' => 'console:one',
                ]]], 200);
            }

            return Http::response([
                'offset' => 0,
                'limit' => 25,
                'count' => 2,
                'totalCount' => 1,
                'data' => [['id' => 'only-one']],
                'message' => 'RAW-MALFORMED-PROVIDER-PAYLOAD',
            ], 200);
        });

        try {
            app(UnifiAdapter::class)->collectTopology(
                $this->siteConfig,
                $this->connection,
                null,
                25,
            );
            $this->fail('Malformed UniFi topology data was accepted.');
        } catch (IntegrationDiscoveryException $exception) {
            $this->assertSame(SafeOperationalData::failureSummary(), $exception->getMessage());
            $this->assertStringNotContainsString('RAW-', $exception->getMessage());
        }
    }

    private function fakeOfficialTopologyApi(): void
    {
        Http::fake(function (Request $request) {
            $url = $request->url();
            $path = (string) parse_url($url, PHP_URL_PATH);
            $query = [];
            parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

            if ($url === 'https://api.ui.com/v1/sites') {
                return Http::response(['data' => [[
                    'siteId' => 'site-ext-1',
                    'hostId' => 'console:one',
                ]]], 200);
            }
            if (str_ends_with($path, '/devices')) {
                if (($query['offset'] ?? null) === '0') {
                    return Http::response([
                        'offset' => 0,
                        'limit' => 2,
                        'count' => 2,
                        'totalCount' => 3,
                        'data' => [
                            [
                                'id' => 'gateway-1',
                                'name' => 'Site gateway',
                                'model' => 'UDM-SE',
                                'macAddress' => 'AA:BB:CC:DD:EE:01',
                                'ipAddress' => '10.44.0.1',
                            ],
                            [
                                'id' => 'switch-1',
                                'name' => 'Core switch',
                                'model' => 'USW-Pro-24',
                                'macAddress' => 'AA:BB:CC:DD:EE:02',
                                'ipAddress' => '10.44.0.20',
                            ],
                        ],
                    ], 200);
                }

                return Http::response([
                    'offset' => 2,
                    'limit' => 2,
                    'count' => 1,
                    'totalCount' => 3,
                    'data' => [[
                        'id' => 'ap-1',
                        'name' => 'Hall access point',
                        'model' => 'U7-Pro',
                        'macAddress' => 'AA:BB:CC:DD:EE:03',
                        'ipAddress' => '10.44.0.30',
                    ]],
                ], 200);
            }
            if (str_ends_with($path, '/devices/gateway-1')) {
                return Http::response(['id' => 'gateway-1'], 200);
            }
            if (str_ends_with($path, '/devices/switch-1')) {
                return Http::response([
                    'id' => 'switch-1',
                    'uplink' => ['deviceId' => 'gateway-1'],
                ], 200);
            }
            if (str_ends_with($path, '/devices/ap-1')) {
                return Http::response([
                    'id' => 'ap-1',
                    'uplink' => ['deviceId' => 'switch-1'],
                ], 200);
            }

            return Http::response(['message' => 'unexpected request'], 500);
        });
    }
}
