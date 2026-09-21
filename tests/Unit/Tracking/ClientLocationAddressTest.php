<?php

namespace Tests\Unit\Tracking;

use App\Services\Tracking\ClientLocationAddressService;
use App\Services\Tracking\ClientLocationHistoryService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\ClientLocationWorkspaceFixture;
use Tests\Support\CommittedDatabaseTestCase;

class ClientLocationAddressTest extends CommittedDatabaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $cache = Cache::store('database')->getStore();
        $this->assertIsolatedTestConnection($cache->getConnection());
        $this->assertIsolatedTestConnection($cache->getLockConnection());
        $this->assertSame($cache->getConnection()->getDatabaseName(), $cache->getLockConnection()->getDatabaseName());
    }

    public function test_recorded_addresses_and_disabled_or_public_providers_never_send_coordinates(): void
    {
        Http::preventStrayRequests();
        $point = ['lat' => -36.85, 'lng' => 174.76, 'timestamp' => now()->toISOString()];
        $service = app(ClientLocationAddressService::class);
        $this->assertSame($point, $service->enrich($point));
        config(['fleet.maps.client_location_address_lookup_enabled' => true, 'fleet.maps.client_location_geocoder_url' => 'https://nominatim.openstreetmap.org']);
        $this->assertSame($point, $service->enrich($point));
        $this->assertSame('recorded', $service->enrich([...$point, 'address' => 'Recorded example address'])['address_source']);
        Http::assertNothingSent();
    }

    public function test_private_lookup_is_cached_and_never_replaces_measured_coordinates_or_time(): void
    {
        Http::preventStrayRequests();
        Cache::store('database')->flush();
        config(['fleet.maps.client_location_address_lookup_enabled' => true, 'fleet.maps.client_location_geocoder_url' => 'http://127.0.0.1:8088']);
        Http::fake(['127.0.0.1:8088/*' => Http::response(['display_name' => '12 Synthetic Street, Auckland', 'lat' => '-36.9', 'lon' => '174.9'])]);
        $point = ['lat' => -36.85, 'lng' => 174.76, 'timestamp' => now()->toISOString(), 'accuracy' => 7];
        $service = app(ClientLocationAddressService::class);
        $result = $service->enrich($point);
        $this->assertSame('12 Synthetic Street, Auckland', $result['address']);
        $this->assertSame('nearest', $result['address_source']);
        foreach ($point as $key => $value) {
            $this->assertSame($value, $result[$key]);
        }
        $this->assertSame($result, $service->enrich($point));
        Http::assertSentCount(1);
        Http::assertSent(fn ($r) => array_keys($r->data()) === ['lat', 'lon', 'format', 'addressdetails', 'zoom', 'layer']);
    }

    public function test_failed_lookup_keeps_the_position_and_access_loss_discards_the_enriched_result(): void
    {
        Http::preventStrayRequests();
        Queue::fake();
        Cache::store('database')->flush();
        config(['fleet.maps.client_location_address_lookup_enabled' => true, 'fleet.maps.client_location_geocoder_url' => 'http://127.0.0.1:8088']);
        Http::fake(['127.0.0.1:8088/*' => Http::response([], 503)]);
        $point = ['lat' => -36.85, 'lng' => 174.76];
        $this->assertSame($point, app(ClientLocationAddressService::class)->enrich($point));
        Cache::store('database')->flush();
        $f = ClientLocationWorkspaceFixture::make();
        $f['device']->update(['latitude' => $point['lat'], 'longitude' => $point['lng'], 'meta' => [...$point, 'last_location_at' => now()->subMinute()->toISOString()]]);
        Http::fake(function () use ($f) {
            $f['assignment']->update(['collection_stopped_at' => now()]);

            return Http::response(['display_name' => 'Undisclosed synthetic address']);
        });
        $this->expectException(HttpException::class);
        app(ClientLocationHistoryService::class)->current($f['actor'], $f['client']);
    }
}
