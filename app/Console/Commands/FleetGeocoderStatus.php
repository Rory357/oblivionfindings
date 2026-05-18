<?php

namespace App\Console\Commands;

use App\Models\FleetTelemetryEvent;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class FleetGeocoderStatus extends Command
{
    protected $signature = 'fleet:geocoder:status {--fail-if-enabled : Fail when enabled provider is unhealthy}';

    protected $description = 'Report fleet reverse geocoder configuration, health, and queue backlog';

    public function handle(): int
    {
        $provider = strtolower((string) config('fleet.maps.reverse_geocode_provider', 'google'));
        $enabled = (bool) config('fleet.maps.reverse_geocode_enabled', false);
        $endpoint = $this->endpoint($provider);
        $health = $this->health($provider, $endpoint);

        $pendingRows = FleetTelemetryEvent::query()
            ->where('consent_blocked', false)
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->whereNull('address')
            ->whereNull('reverse_geocoded_at')
            ->whereNull('reverse_geocode_failed_at')
            ->count();

        $failedRows = FleetTelemetryEvent::query()
            ->where('consent_blocked', false)
            ->whereNotNull('reverse_geocode_failed_at')
            ->count();

        $latestResolvedAt = FleetTelemetryEvent::query()
            ->whereNotNull('address')
            ->whereNotNull('reverse_geocoded_at')
            ->latest('reverse_geocoded_at')
            ->value('reverse_geocoded_at');

        $this->line('Fleet geocoder status');
        $this->line('Provider: '.$provider);
        $this->line('Enabled: '.($enabled ? 'yes' : 'no'));
        $this->line('Endpoint: '.($endpoint ?: 'not configured'));
        $this->line('Health: '.$health);
        $this->line('Pending missing-address rows: '.$pendingRows);
        $this->line('Failed rows: '.$failedRows);
        $this->line('Latest resolved row timestamp: '.($latestResolvedAt ?: 'never'));

        if ($this->option('fail-if-enabled') && $enabled && $health !== 'healthy') {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function endpoint(string $provider): ?string
    {
        return match ($provider) {
            'nominatim' => rtrim((string) config('fleet.maps.nominatim.endpoint'), '/'),
            'google' => 'https://maps.googleapis.com/maps/api/geocode/json',
            default => null,
        };
    }

    private function health(string $provider, ?string $endpoint): string
    {
        if ($provider === 'google') {
            return config('fleet.maps.api_key') ? 'configured' : 'missing_api_key';
        }

        if ($provider !== 'nominatim') {
            return 'unknown_provider';
        }

        if (! $endpoint) {
            return 'unhealthy';
        }

        try {
            $response = Http::timeout((int) config('fleet.maps.reverse_geocode_timeout_seconds', 6))
                ->get($endpoint.'/status');

            return $response->ok() ? 'healthy' : 'unhealthy';
        } catch (\Throwable) {
            return 'unhealthy';
        }
    }
}
