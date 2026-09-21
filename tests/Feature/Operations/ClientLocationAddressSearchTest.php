<?php

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Support\ClientLocationWorkspaceFixture;

beforeEach(function () {
    Http::preventStrayRequests();
    Queue::fake();
    $this->assertStringStartsWith('oblivion_findings_pkg02a_2b9f_test_', DB::connection()->getDatabaseName());
    config(['fleet.maps.address_search_cache_store' => 'database', 'cache.stores.database.connection' => DB::getDefaultConnection(), 'cache.stores.database.lock_connection' => DB::getDefaultConnection()]);
    $store = Cache::store('database')->getStore();
    $this->assertSame(DB::connection()->getDatabaseName(), $store->getConnection()->getDatabaseName());
    $this->assertSame(DB::connection()->getDatabaseName(), $store->getLockConnection()->getDatabaseName());
});

it('does not contact the provider when address lookup is disabled', function () {
    config(['fleet.maps.client_zone_address_search_enabled' => false]);
    extract(ClientLocationWorkspaceFixture::make());
    $this->actingAs($actor)->postJson("/operations/clients/{$client->id}/location/zones/address-search", [
        'q' => 'Synthetic disabled place', 'access_fingerprint' => $payload['access_fingerprint'],
    ])->assertStatus(503)->assertHeader('Cache-Control', 'max-age=0, no-store, private');
    Http::assertNothingSent();
});

it('reuses the address provider without transmitting client or tracker context', function () {
    config(['fleet.maps.client_zone_address_search_enabled' => true]);
    extract(ClientLocationWorkspaceFixture::make());
    Http::fake(['nominatim.openstreetmap.org/*' => Http::response([
        ['display_name' => 'Synthetic QA place', 'lat' => '-36.85', 'lon' => '174.76', 'address' => []],
    ])]);
    $this->actingAs($actor)->postJson("/operations/clients/{$client->id}/location/zones/address-search", [
        'q' => 'Synthetic QA place', 'access_fingerprint' => $payload['access_fingerprint'],
    ])->assertOk()->assertJsonPath('results.0.lat', -36.85)->assertHeader('Cache-Control', 'max-age=0, no-store, private');
    Http::assertSent(fn ($request) => $request['q'] === 'Synthetic QA place'
        && array_keys($request->data()) === ['q', 'format', 'addressdetails', 'limit', 'countrycodes']);
    Queue::assertNothingPushed();
});

it('denies search before contacting a provider when management or current access is absent', function () {
    config(['fleet.maps.client_zone_address_search_enabled' => true]);
    extract(ClientLocationWorkspaceFixture::make(false));
    $this->actingAs($actor)->postJson("/operations/clients/{$client->id}/location/zones/address-search", [
        'q' => 'Synthetic denied place', 'access_fingerprint' => $payload['access_fingerprint'],
    ])->assertForbidden();
    Http::assertNothingSent();
});

it('discards results if consent ends during lookup', function () {
    config(['fleet.maps.client_zone_address_search_enabled' => true]);
    extract(ClientLocationWorkspaceFixture::make());
    Http::fake(function () use ($assignment) {
        $assignment->update(['collection_stopped_at' => now()]);

        return Http::response([['display_name' => 'Synthetic withdrawn place', 'lat' => '-36.85', 'lon' => '174.76', 'address' => []]]);
    });
    $this->actingAs($actor)->postJson("/operations/clients/{$client->id}/location/zones/address-search", [
        'q' => 'Synthetic withdrawn place', 'access_fingerprint' => $payload['access_fingerprint'],
    ])->assertForbidden()->assertDontSee('Synthetic withdrawn place');
});

it('returns a retryable search error without treating a provider failure as no matches', function () {
    config(['fleet.maps.client_zone_address_search_enabled' => true]);
    extract(ClientLocationWorkspaceFixture::make());
    Http::fake(['nominatim.openstreetmap.org/*' => Http::response([], 503)]);
    $this->actingAs($actor)->postJson("/operations/clients/{$client->id}/location/zones/address-search", [
        'q' => 'Synthetic unavailable place', 'access_fingerprint' => $payload['access_fingerprint'],
    ])->assertStatus(503)->assertJsonPath('message', 'Address search is temporarily unavailable. Try again, or move the map manually.');
});

it('shares the provider budget between NZ and global fallback requests', function () {
    Cache::flush();
    config(['fleet.maps.client_zone_address_search_enabled' => true]);
    extract(ClientLocationWorkspaceFixture::make());
    $starts = [];
    Http::fake(function ($request) use (&$starts) {
        $starts[] = microtime(true);

        return Http::response(($request['countrycodes'] ?? null) === 'nz' ? [] : [
            ['display_name' => 'Synthetic global fallback', 'lat' => '-36.85', 'lon' => '174.76', 'address' => []],
        ]);
    });
    $this->actingAs($actor)->postJson("/operations/clients/{$client->id}/location/zones/address-search", [
        'q' => 'Synthetic global fallback', 'access_fingerprint' => $payload['access_fingerprint'],
    ])->assertOk()->assertJsonCount(1, 'results');
    $this->assertCount(2, $starts);
    $this->assertGreaterThanOrEqual(1.0, $starts[1] - $starts[0]);
});

it('does not call the public provider when another application search holds the budget', function () {
    Cache::flush();
    config(['fleet.maps.client_zone_address_search_enabled' => true]);
    extract(ClientLocationWorkspaceFixture::make());
    $lock = Cache::store('database')->lock('geocoding:nominatim:'.hash('sha256', 'https://nominatim.openstreetmap.org').':lock', 20);
    $this->assertTrue($lock->get());
    try {
        $this->actingAs($actor)->postJson("/operations/clients/{$client->id}/location/zones/address-search", [
            'q' => 'Synthetic busy provider', 'access_fingerprint' => $payload['access_fingerprint'],
        ])->assertStatus(503);
        Http::assertNothingSent();
    } finally {
        $lock->release();
    }
});
