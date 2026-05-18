<?php

namespace App\Services\Fleet\Geocoding;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GoogleReverseGeocoder implements ReverseGeocoder
{
    public function reverseGeocode(float $lat, float $lng, ?int $assetId = null): ?string
    {
        $apiKey = config('fleet.maps.api_key');
        if (! $apiKey) {
            return null;
        }

        try {
            $response = Http::timeout((int) config('fleet.maps.reverse_geocode_timeout_seconds', 6))
                ->get('https://maps.googleapis.com/maps/api/geocode/json', [
                    'latlng' => $lat.','.$lng,
                    'key' => $apiKey,
                ]);

            if (! $response->ok()) {
                Log::warning('Google reverse geocode failed', [
                    'status' => $response->status(),
                ]);

                return null;
            }

            $address = $response->json('results.0.formatted_address');

            return is_string($address) && trim($address) !== '' ? trim($address) : null;
        } catch (\Throwable $e) {
            Log::warning('Google reverse geocode exception', [
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }
}
