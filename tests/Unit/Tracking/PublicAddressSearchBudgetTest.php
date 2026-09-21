<?php

namespace Tests\Unit\Tracking;

use App\Http\Controllers\Sites\SiteGeocodingController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\CommittedDatabaseTestCase;

class PublicAddressSearchBudgetTest extends CommittedDatabaseTestCase
{
    public function test_independent_connections_share_locks_spacing_and_endpoint_scoped_results(): void
    {
        Http::preventStrayRequests();
        $this->assertSame(0, DB::transactionLevel());
        $database = DB::connection()->getDatabaseName();
        $this->assertIsolatedTestConnection(DB::connection());
        config([
            'fleet.maps.address_search_cache_store' => 'database',
            'cache.stores.database.connection' => DB::getDefaultConnection(),
            'cache.stores.database.lock_connection' => DB::getDefaultConnection(),
            'database.connections.address_budget_other' => [...DB::connection()->getConfig(), 'name' => 'address_budget_other'],
            'cache.stores.address_budget_other' => ['driver' => 'database', 'connection' => 'address_budget_other', 'lock_connection' => 'address_budget_other', 'table' => 'cache', 'lock_table' => 'cache_locks'],
        ]);
        $cache = Cache::store('database');
        $other = Cache::store('address_budget_other');
        foreach ([$cache, $other] as $store) {
            $this->assertSame($database, $store->getStore()->getConnection()->getDatabaseName());
            $this->assertSame($database, $store->getStore()->getLockConnection()->getDatabaseName());
        }
        $this->assertNotSame($cache->getStore()->getConnection()->getPdo(), $other->getStore()->getConnection()->getPdo());
        fwrite(STDERR, 'Provider cache and locks verified on isolated schema '.$database.' using two PDO connections; global cache='.config('cache.default').".\n");
        $endpoint = 'https://nominatim.openstreetmap.org';
        $budget = 'geocoding:nominatim:'.hash('sha256', $endpoint);
        $lock = $other->lock($budget.':lock', 20);
        $this->assertTrue($lock->get());
        $controller = app(SiteGeocodingController::class);
        try {
            $controller->search(Request::create('/synthetic-search', 'POST', ['q' => 'Synthetic shared budget']), true);
            $this->fail('A different connection holds the provider budget.');
        } catch (\RuntimeException $error) {
            $this->assertSame('Address search is temporarily unavailable.', $error->getMessage());
            Http::assertNothingSent();
        } finally {
            $lock->release();
        }
        $other->put($budget.':last-start', microtime(true), 60);
        Http::fake(['*' => Http::response([['display_name' => 'Synthetic shared result', 'lat' => '-36.85', 'lon' => '174.76', 'address' => []]])]);
        $started = microtime(true);
        $request = Request::create('/synthetic-search', 'POST', ['q' => 'Synthetic shared budget']);
        $controller->search($request, true);
        $this->assertGreaterThanOrEqual(1.0, microtime(true) - $started);
        $controller->search($request, true);
        Http::assertSentCount(1);
        config(['fleet.maps.address_search_endpoint' => 'https://geocoder.example.test']);
        $controller->search($request, true);
        Http::assertSentCount(2);
        Http::assertSent(fn ($r) => str_starts_with($r->url(), 'https://geocoder.example.test/search'));
        DB::purge('address_budget_other');
    }

    public function test_unshared_cache_configuration_fails_without_provider_calls(): void
    {
        Http::preventStrayRequests();
        config(['fleet.maps.address_search_cache_store' => 'array']);
        $controller = app(SiteGeocodingController::class);
        $request = Request::create('/synthetic-search', 'POST', ['q' => 'Synthetic rejected store']);
        $this->assertSame(['results' => []], $controller->search($request)->getData(true));
        try {
            $controller->search($request, true);
            $this->fail('The client adapter must get a retryable provider error.');
        } catch (\RuntimeException) {
            Http::assertNothingSent();
        }
    }
}
