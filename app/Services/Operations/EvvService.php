<?php

namespace App\Services\Operations;

use App\Models\AssetGeofence;
use App\Models\EvvRecord;
use App\Models\Shift;
use App\Models\User;
use App\Services\Fleet\FleetGeofenceService;
use App\Services\UserSiteAccessService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;

class EvvService
{
    public function __construct(
        private readonly FleetGeofenceService $geofences,
        private readonly UserSiteAccessService $siteAccess,
    ) {}

    public function processCheckIn(Shift $shift, int $userId, float $latitude, float $longitude, string $method = 'gps'): EvvRecord
    {
        $shift->loadMissing('client:id,site_id');
        if (! $shift->site_id || ! $shift->client_id || (int) $shift->client?->site_id !== (int) $shift->site_id) {
            throw ValidationException::withMessages([
                'shift_id' => 'The Shift must have matching Client and Site provenance before EVV check-in.',
            ]);
        }
        if ($shift->user_id && (int) $shift->user_id !== $userId) {
            throw ValidationException::withMessages([
                'user_id' => 'Only the assigned worker can check in to this Shift.',
            ]);
        }

        $geofenceResult = $this->checkGeofence($shift, $latitude, $longitude);

        return EvvRecord::updateOrCreate(
            ['shift_id' => $shift->id, 'user_id' => $userId],
            [
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
        $record->loadMissing('shift.client:id,site_id');
        $shift = $record->shift;
        if (! $shift
            || ! $shift->site_id
            || ! $shift->client_id
            || (int) $record->client_id !== (int) $shift->client_id
            || (int) $shift->client?->site_id !== (int) $shift->site_id
            || ($shift->user_id && (int) $record->user_id !== (int) $shift->user_id)
        ) {
            throw ValidationException::withMessages([
                'record_id' => 'The EVV record no longer has matching Shift, Client, Site and worker provenance.',
            ]);
        }
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

    public function getFlaggedRecords(User $viewer): Collection
    {
        return EvvRecord::query()
            ->whereHas('shift', fn ($shiftQuery) => $this->siteAccess->applyShiftScope(
                $shiftQuery,
                $viewer,
                ['shifts.manageAny'],
            ))
            ->where('verification_status', 'flagged')
            ->with(['shift', 'user:id,name', 'client:id,first_name,last_name'])
            ->orderByDesc('check_in_time')
            ->get();
    }

    protected function checkGeofence(?Shift $shift, float $latitude, float $longitude): array
    {
        if (! $shift || ! $shift->site_id || ! $shift->client_id) {
            return ['within' => false, 'distance' => null];
        }

        $zone = AssetGeofence::query()
            ->eligibleForClientSite((int) $shift->site_id)
            ->whereNull('asset_id')
            ->orderByDesc('scope')
            ->first();

        if (! $zone) {
            return ['within' => true, 'distance' => 0]; // No geofence configured, allow
        }

        $within = $this->geofences->isInside($zone, $latitude, $longitude);
        $shape = $zone->shape ?? [];
        $center = $shape['center'] ?? [];
        $centerLatitude = $shape['lat'] ?? $center['lat'] ?? null;
        $centerLongitude = $shape['lon'] ?? $shape['lng'] ?? $center['lon'] ?? $center['lng'] ?? null;
        $distance = $centerLatitude !== null && $centerLongitude !== null
            ? $this->haversineDistance($latitude, $longitude, (float) $centerLatitude, (float) $centerLongitude)
            : null;

        return [
            'within' => $within,
            'distance' => $distance !== null ? round($distance, 1) : null,
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
