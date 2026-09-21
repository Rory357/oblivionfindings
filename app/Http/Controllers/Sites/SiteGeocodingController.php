<?php

namespace App\Http\Controllers\Sites;

use App\Http\Controllers\Controller;
use Illuminate\Cache\Repository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class SiteGeocodingController extends Controller
{
    public function search(Request $request, bool $failOnError = false): JsonResponse
    {
        $request->validate([
            'q' => ['required', 'string', 'min:3', 'max:200'],
        ]);

        $query = trim($request->input('q'));
        $endpoint = rtrim((string) config('fleet.maps.address_search_endpoint', 'https://nominatim.openstreetmap.org'), '/');
        $cacheKey = 'site-geocode:'.sha1($endpoint.'|'.strtolower($query));

        // Cache only non-empty hits — if a query returned nothing, let the
        // user retry tomorrow without waiting 24h for the cache to expire.
        try {
            $cache = $this->providerCache();
            $cached = $cache->get($cacheKey);
        } catch (\Throwable $error) {
            if ($failOnError) {
                throw new \RuntimeException('Address search is temporarily unavailable.', 0, $error);
            }

            return response()->json(['results' => []]);
        }
        if (is_array($cached) && count($cached) > 0) {
            return response()->json(['results' => $cached]);
        }

        // First pass: NZ-restricted (most common case for this CRM)
        $results = $this->callNominatim($query, 'nz', $failOnError);

        // Fallback: if NZ returned nothing, retry globally so addresses
        // Nominatim hasn't tagged with a country still surface
        if (count($results) === 0) {
            $results = $this->callNominatim($query, null, $failOnError);
        }

        if (count($results) > 0) {
            $cache->put($cacheKey, $results, now()->addDay());
        }

        return response()->json(['results' => $results]);
    }

    private function providerCache(): Repository
    {
        $store = (string) config('fleet.maps.address_search_cache_store', 'database');
        if (! in_array(config('cache.stores.'.$store.'.driver'), ['database', 'redis'], true)) {
            throw new \RuntimeException('Address search requires a shared cache store.');
        }

        return Cache::store($store);
    }

    private function callNominatim(string $query, ?string $countrycodes, bool $failOnError = false): array
    {
        $params = [
            'q' => $query,
            'format' => 'jsonv2',
            'addressdetails' => 1,
            'limit' => 8,
        ];
        if ($countrycodes !== null) {
            $params['countrycodes'] = $countrycodes;
        }

        try {
            $cache = $this->providerCache();
            $endpoint = rtrim((string) config('fleet.maps.address_search_endpoint', 'https://nominatim.openstreetmap.org'), '/');
            if (! in_array(parse_url($endpoint, PHP_URL_SCHEME), ['http', 'https'], true) || ! parse_url($endpoint, PHP_URL_HOST)) {
                throw new \RuntimeException('Address search endpoint is not configured.');
            }
            // One provider-wide budget, shared by ordinary Site searches and
            // Client Location. The NZ/global fallback must use the same slot.
            $budget = 'geocoding:nominatim:'.hash('sha256', $endpoint);
            $response = $cache->lock($budget.':lock', 20)->block(2, function () use ($params, $cache, $endpoint, $budget) {
                $lastStart = (float) $cache->get($budget.':last-start', 0);
                $delay = 1.05 - (microtime(true) - $lastStart);
                if ($delay > 0) {
                    usleep((int) ceil($delay * 1000000));
                }
                $cache->put($budget.':last-start', microtime(true), now()->addMinute());

                return Http::withHeaders([
                    'User-Agent' => 'OblivionFindings-CRM/1.0 (+'.config('app.url').')',
                    'Accept-Language' => 'en-NZ,en',
                ])->withoutRedirecting()->timeout(12)
                    ->get($endpoint.'/search', $params);
            });
        } catch (\Throwable $e) {
            if ($failOnError) {
                throw new \RuntimeException('Address search is temporarily unavailable.', 0, $e);
            }
            \Log::warning('Nominatim request failed', ['error' => $e->getMessage()]);

            return [];
        }

        if (! $response->successful()) {
            if ($failOnError) {
                throw new \RuntimeException('Address search is temporarily unavailable.');
            }

            return [];
        }

        return collect($response->json())->map(function (array $hit): array {
            $a = $hit['address'] ?? [];

            return [
                'display_name' => $hit['display_name'] ?? '',
                'lat' => isset($hit['lat']) ? (float) $hit['lat'] : null,
                'lng' => isset($hit['lon']) ? (float) $hit['lon'] : null,
                'address_line_1' => trim(($a['house_number'] ?? '').' '.($a['road'] ?? '')) ?: null,
                'suburb' => $a['suburb'] ?? $a['neighbourhood'] ?? $a['village'] ?? $a['hamlet'] ?? null,
                'city' => $a['city'] ?? $a['town'] ?? $a['municipality'] ?? $a['county'] ?? null,
                'postcode' => $a['postcode'] ?? null,
                'country' => $a['country'] ?? null,
                'region' => $a['state'] ?? $a['region'] ?? null,
            ];
        })->values()->all();
    }
}
