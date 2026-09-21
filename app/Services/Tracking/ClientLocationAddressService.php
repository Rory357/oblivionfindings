<?php

namespace App\Services\Tracking;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/** Address enrichment only: never replaces the measured position or time. */
class ClientLocationAddressService
{
    public function enrich(?array $point): ?array
    {
        if (! $point) {
            return null;
        }
        if (is_string($point['address'] ?? null) && trim($point['address']) !== '') {
            return [...$point, 'address_source' => 'recorded'];
        }
        $endpoint = rtrim((string) config('fleet.maps.client_location_geocoder_url'), '/');
        // Enable only after an organisation-controlled service is verified.
        // Never use the public typed-search provider for tracker coordinates.
        $host = strtolower((string) parse_url($endpoint, PHP_URL_HOST));
        if (! config('fleet.maps.client_location_address_lookup_enabled', false)
            || ! in_array(parse_url($endpoint, PHP_URL_SCHEME), ['http', 'https'], true)
            || $host === '' || $host === 'openstreetmap.org' || str_ends_with($host, '.openstreetmap.org')) {
            return $point;
        }
        $lat = $point['lat'] ?? null;
        $lng = $point['lng'] ?? null;
        if (! is_numeric($lat) || ! is_numeric($lng) || ! is_finite((float) $lat) || ! is_finite((float) $lng)
            || abs((float) $lat) > 90 || abs((float) $lng) > 180) {
            return $point;
        }
        $key = 'client-location:address:'.hash('sha256', $endpoint.'|'.sprintf('%.6f,%.6f', $lat, $lng));
        try {
            $store = (string) config('fleet.maps.address_search_cache_store', 'database');
            if (! in_array(config('cache.stores.'.$store.'.driver'), ['database', 'redis'], true)) {
                return $point;
            }
            $cache = Cache::store($store);
            $cached = $cache->get($key);
            if (! is_array($cached)) {
                // Bound lookups across viewers. Do not bulk-enrich location history.
                if (! $cache->add('client-location:address-rate', true, now()->addSeconds(2))) {
                    return $point;
                }
                try {
                    $response = Http::withHeaders(['User-Agent' => 'OblivionFindings/1.0', 'Accept-Language' => 'en-NZ,en'])
                        ->withoutRedirecting()->connectTimeout(1)->timeout(3)->get($endpoint.'/reverse', [
                            'lat' => (float) $lat, 'lon' => (float) $lng, 'format' => 'jsonv2',
                            'addressdetails' => 0, 'zoom' => 18, 'layer' => 'address',
                        ]);
                    $value = $response->ok() ? $response->json('display_name') : null;
                    $address = is_string($value) && trim($value) !== '' && mb_strlen($value) <= 1000 ? trim($value) : null;
                    $cached = ['address' => $address];
                    $cache->put($key, $cached, $address ? now()->addDay() : now()->addMinute());
                } catch (\Throwable) {
                    // URLs and exceptions can contain personal coordinates.
                    $cache->put($key, ['address' => null], now()->addMinute());

                    return $point;
                }
            }

            return is_string($cached['address'] ?? null) && $cached['address'] !== ''
                ? [...$point, 'address' => $cached['address'], 'display_location' => $cached['address'], 'address_source' => 'nearest']
                : $point;
        } catch (\Throwable) {
            return $point;
        }
    }
}
