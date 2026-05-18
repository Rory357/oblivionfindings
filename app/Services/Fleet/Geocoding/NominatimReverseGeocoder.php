<?php

namespace App\Services\Fleet\Geocoding;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class NominatimReverseGeocoder implements ReverseGeocoder
{
    public function reverseGeocode(float $lat, float $lng, ?int $assetId = null): ?string
    {
        $endpoint = rtrim((string) config('fleet.maps.nominatim.endpoint'), '/');
        if ($endpoint === '') {
            Log::warning('Nominatim reverse geocode endpoint is not configured');

            return null;
        }

        $params = [
            'format' => 'jsonv2',
            'lat' => $lat,
            'lon' => $lng,
            'addressdetails' => 1,
            'zoom' => 18,
        ];

        $contactEmail = trim((string) config('fleet.maps.nominatim.contact_email', ''));
        if ($contactEmail !== '') {
            $params['email'] = $contactEmail;
        }

        $userAgent = trim((string) config('fleet.maps.nominatim.user_agent', 'OblivionFindings/1.0'));

        try {
            $response = Http::withHeaders(['User-Agent' => $userAgent])
                ->timeout((int) config('fleet.maps.reverse_geocode_timeout_seconds', 6))
                ->get($endpoint.'/reverse', $params);

            if (! $response->ok()) {
                Log::warning('Nominatim reverse geocode failed', [
                    'status' => $response->status(),
                    'endpoint' => $endpoint,
                ]);

                return null;
            }

            $address = $response->json('display_name');
            if (! is_string($address) || trim($address) === '') {
                Log::warning('Nominatim reverse geocode returned no display name', [
                    'endpoint' => $endpoint,
                ]);

                return null;
            }

            return trim($address);
        } catch (\Throwable $e) {
            Log::warning('Nominatim reverse geocode exception', [
                'error' => $e->getMessage(),
                'endpoint' => $endpoint,
            ]);

            return null;
        }
    }
}
