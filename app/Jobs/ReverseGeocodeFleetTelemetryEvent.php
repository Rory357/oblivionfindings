<?php

namespace App\Jobs;

use App\Models\FleetTelemetryEvent;
use App\Services\Fleet\ReverseGeocodeService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ReverseGeocodeFleetTelemetryEvent implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 30;

    public array $backoff = [30, 120];

    public function __construct(public int $eventId) {}

    public function handle(ReverseGeocodeService $geocoder): void
    {
        if (! config('fleet.maps.reverse_geocode_enabled')) {
            return;
        }

        $event = FleetTelemetryEvent::query()->find($this->eventId);
        if (! $event || $event->consent_blocked || $event->address) {
            return;
        }

        if ($event->latitude === null || $event->longitude === null) {
            return;
        }

        try {
            $address = $geocoder->reverseGeocode(
                (float) $event->latitude,
                (float) $event->longitude,
                $event->asset_id,
            );
        } catch (\Throwable $exception) {
            report($exception);
            $event->forceFill(['reverse_geocode_failed_at' => now()])->save();

            return;
        }

        if ($address) {
            $event->forceFill([
                'address' => $address,
                'reverse_geocoded_at' => now(),
                'reverse_geocode_failed_at' => null,
            ])->save();

            return;
        }

        $event->forceFill(['reverse_geocode_failed_at' => now()])->save();
    }
}
