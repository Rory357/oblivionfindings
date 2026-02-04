<?php

namespace App\Services\Fleet;

use App\Models\FleetMapUsageLog;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ReverseGeocodeService
{
    public function reverseGeocode(float $lat, float $lng, ?int $assetId = null): ?string
    {
        if (!config('fleet.maps.reverse_geocode_enabled')) {
            return null;
        }

        $apiKey = config('fleet.maps.api_key');
        if (!$apiKey) {
            return null;
        }

        $cacheKey = $this->cacheKey($lat, $lng);
        $cached = Cache::get($cacheKey);
        if ($cached) {
            return $cached;
        }

        if (!$this->withinRateLimit()) {
            return null;
        }

        try {
            $response = Http::timeout(6)->get('https://maps.googleapis.com/maps/api/geocode/json', [
                'latlng' => $lat . ',' . $lng,
                'key' => $apiKey,
            ]);

            if (!$response->ok()) {
                Log::warning('Reverse geocode failed', [
                    'status' => $response->status(),
                ]);
                return null;
            }

            $data = $response->json();
            $address = $data['results'][0]['formatted_address'] ?? null;

            if ($address) {
                $ttlDays = (int) config('fleet.maps.reverse_geocode_cache_ttl_days', 30);
                Cache::put($cacheKey, $address, now()->addDays($ttlDays));
                $this->logUsage($assetId);
            }

            return $address;
        } catch (\Throwable $e) {
            Log::warning('Reverse geocode exception', [
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    protected function cacheKey(float $lat, float $lng): string
    {
        $roundedLat = round($lat, 4);
        $roundedLng = round($lng, 4);

        return 'fleet:reverse_geocode:' . $roundedLat . ':' . $roundedLng;
    }

    protected function withinRateLimit(): bool
    {
        $limit = (int) config('fleet.maps.reverse_geocode_rate_limit_per_minute', 30);
        if ($limit <= 0) {
            return false;
        }

        $key = 'fleet:reverse_geocode:count:' . now()->format('YmdHi');
        $count = Cache::increment($key);
        if ($count === 1) {
            Cache::put($key, $count, now()->addMinutes(2));
        }

        return $count <= $limit;
    }

    protected function logUsage(?int $assetId): void
    {
        FleetMapUsageLog::create([
            'user_id' => null,
            'asset_id' => $assetId,
            'context' => 'reverse_geocode',
        ]);
    }
}
