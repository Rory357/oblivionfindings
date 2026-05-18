<?php

namespace App\Services\Fleet;

use App\Models\FleetMapUsageLog;
use App\Services\Fleet\Geocoding\GoogleReverseGeocoder;
use App\Services\Fleet\Geocoding\NominatimReverseGeocoder;
use App\Services\Fleet\Geocoding\ReverseGeocoder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class ReverseGeocodeService
{
    public function reverseGeocode(float $lat, float $lng, ?int $assetId = null): ?string
    {
        if (! config('fleet.maps.reverse_geocode_enabled')) {
            return null;
        }

        $cacheKey = $this->cacheKey($lat, $lng);
        $cached = Cache::get($cacheKey);
        if ($cached) {
            return $cached;
        }

        if (! $this->withinRateLimit()) {
            return null;
        }

        $geocoder = $this->provider();
        if (! $geocoder) {
            return null;
        }

        $address = $geocoder->reverseGeocode($lat, $lng, $assetId);
        if (! $address) {
            return null;
        }

        $ttlDays = (int) config('fleet.maps.reverse_geocode_cache_ttl_days', 30);
        Cache::put($cacheKey, $address, now()->addDays($ttlDays));
        $this->logUsage($assetId);

        return $address;
    }

    protected function provider(): ?ReverseGeocoder
    {
        $provider = strtolower((string) config('fleet.maps.reverse_geocode_provider', 'google'));

        return match ($provider) {
            'google' => app(GoogleReverseGeocoder::class),
            'nominatim' => app(NominatimReverseGeocoder::class),
            default => $this->unknownProvider($provider),
        };
    }

    protected function unknownProvider(string $provider): ?ReverseGeocoder
    {
        Log::warning('Unknown reverse geocode provider configured', [
            'provider' => $provider,
        ]);

        return null;
    }

    protected function cacheKey(float $lat, float $lng): string
    {
        $roundedLat = round($lat, 4);
        $roundedLng = round($lng, 4);

        return 'fleet:reverse_geocode:'.$roundedLat.':'.$roundedLng;
    }

    protected function withinRateLimit(): bool
    {
        $limit = (int) config('fleet.maps.reverse_geocode_rate_limit_per_minute', 30);
        if ($limit <= 0) {
            return false;
        }

        $key = 'fleet:reverse_geocode:count:'.now()->format('YmdHi');
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
