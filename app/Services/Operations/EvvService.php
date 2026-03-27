<?php

namespace App\Services\Operations;

use App\Models\EvvRecord;
use App\Models\GeofenceZone;
use App\Models\Shift;

class EvvService
{
    public function processCheckIn(Shift $shift, int $userId, float $latitude, float $longitude, string $method = 'gps'): EvvRecord
    {
        $geofenceResult = $this->checkGeofence($shift, $latitude, $longitude);

        return EvvRecord::updateOrCreate(
            ['shift_id' => $shift->id, 'user_id' => $userId],
            [
                'organization_id' => $shift->client?->organization_id,
                'client_id' => $shift->client_id,
                'check_in_time' => now(),
                'check_in_latitude' => $latitude,
                'check_in_longitude' => $longitude,
                'check_in_method' => $method,
                'geofence_check_in' => $geofenceResult['within'],
                'distance_from_site_in' => $geofenceResult['distance'],
                'verification_status' => $geofenceResult['within'] ? 'verified' : 'flagged',
                'flagged_reason' => $geofenceResult['within'] ? null : 'Check-in outside geofence boundary',
            ]
        );
    }

    public function processCheckOut(EvvRecord $record, float $latitude, float $longitude, string $method = 'gps'): EvvRecord
    {
        $shift = $record->shift;
        $geofenceResult = $this->checkGeofence($shift, $latitude, $longitude);

        $record->update([
            'check_out_time' => now(),
            'check_out_latitude' => $latitude,
            'check_out_longitude' => $longitude,
            'check_out_method' => $method,
            'geofence_check_out' => $geofenceResult['within'],
            'distance_from_site_out' => $geofenceResult['distance'],
            'verification_status' => ($record->geofence_check_in && $geofenceResult['within']) ? 'verified' : 'flagged',
            'flagged_reason' => $geofenceResult['within'] ? $record->flagged_reason : 'Check-out outside geofence boundary',
        ]);

        return $record->fresh();
    }

    public function getFlaggedRecords(int $organizationId): \Illuminate\Database\Eloquent\Collection
    {
        return EvvRecord::where('organization_id', $organizationId)
            ->where('verification_status', 'flagged')
            ->with(['shift', 'user:id,name', 'client:id,first_name,last_name'])
            ->orderByDesc('check_in_time')
            ->get();
    }

    protected function checkGeofence(?Shift $shift, float $latitude, float $longitude): array
    {
        if (!$shift || !$shift->client_id) {
            return ['within' => false, 'distance' => null];
        }

        $zone = GeofenceZone::where('site_id', $shift->client?->site_id ?? 0)
            ->orWhere(function ($q) use ($shift) {
                $q->where('organization_id', $shift->client?->organization_id);
            })
            ->first();

        if (!$zone) {
            return ['within' => true, 'distance' => 0]; // No geofence configured, allow
        }

        $distance = $this->haversineDistance($latitude, $longitude, (float) $zone->latitude, (float) $zone->longitude);

        return [
            'within' => $distance <= (float) $zone->radius_metres,
            'distance' => round($distance, 1),
        ];
    }

    protected function haversineDistance(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $earthRadius = 6371000; // metres
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);
        $a = sin($dLat / 2) * sin($dLat / 2)
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) * sin($dLon / 2);
        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return $earthRadius * $c;
    }
}
