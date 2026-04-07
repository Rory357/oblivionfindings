<?php

namespace App\Jobs;

use App\Models\FleetTrip;
use App\Services\Fleet\ReverseGeocodeService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ReverseGeocodeFleetTrip implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;
    public $timeout = 30;
    public $backoff = [15, 60];

    public function __construct(public int $tripId)
    {
    }

    public function handle(ReverseGeocodeService $geocoder): void
    {
        $trip = FleetTrip::query()->find($this->tripId);
        if (!$trip || $trip->consent_blocked) {
            return;
        }

        $minDistance = (float) config('fleet.maps.reverse_geocode_min_distance_km', 0);
        if ($minDistance > 0 && (float) $trip->distance_km < $minDistance) {
            return;
        }

        $updates = [];

        if (!$trip->start_address && $trip->start_latitude !== null && $trip->start_longitude !== null) {
            $address = $geocoder->reverseGeocode((float) $trip->start_latitude, (float) $trip->start_longitude, $trip->asset_id);
            if ($address) {
                $updates['start_address'] = $address;
            }
        }

        if (!$trip->end_address && $trip->end_latitude !== null && $trip->end_longitude !== null) {
            $address = $geocoder->reverseGeocode((float) $trip->end_latitude, (float) $trip->end_longitude, $trip->asset_id);
            if ($address) {
                $updates['end_address'] = $address;
            }
        }

        if (!empty($updates)) {
            $updates['reverse_geocoded_at'] = now();
            $trip->update($updates);
        }
    }
}
