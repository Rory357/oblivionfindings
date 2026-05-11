<?php

namespace App\Http\Controllers\Sites;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class SiteGeocodingController extends Controller
{
    public function search(Request $request): JsonResponse
    {
        $request->validate([
            'q' => ['required', 'string', 'min:3', 'max:200'],
        ]);

        $query = trim($request->input('q'));
        $cacheKey = 'site-geocode:'.sha1(strtolower($query));

        // Cache only non-empty hits — if a query returned nothing, let the
        // user retry tomorrow without waiting 24h for the cache to expire.
        $cached = Cache::get($cacheKey);
        if (is_array($cached) && count($cached) > 0) {
            return response()->json(['results' => $cached]);
        }

        // First pass: NZ-restricted (most common case for this CRM)
        $results = $this->callNominatim($query, 'nz');

        // Fallback: if NZ returned nothing, retry globally so addresses
        // Nominatim hasn't tagged with a country still surface
        if (count($results) === 0) {
            $results = $this->callNominatim($query, null);
        }

        if (count($results) > 0) {
            Cache::put($cacheKey, $results, now()->addDay());
        }

        return response()->json(['results' => $results]);
    }

    private function callNominatim(string $query, ?string $countrycodes): array
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
            $response = Http::withHeaders([
                'User-Agent' => 'OblivionFindings-CRM/1.0 (+'.config('app.url').')',
                'Accept-Language' => 'en-NZ,en',
            ])
                ->timeout(12)
                ->get('https://nominatim.openstreetmap.org/search', $params);
        } catch (\Throwable $e) {
            \Log::warning('Nominatim request failed', ['error' => $e->getMessage()]);
            return [];
        }

        if (! $response->successful()) {
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
