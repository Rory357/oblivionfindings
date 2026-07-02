<?php

namespace App\Services\Fleet;

use App\Models\Asset;
use App\Models\AssetGeofence;
use App\Models\FleetGeofenceState;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

class FleetGeofenceService
{
    public function __construct(protected FleetSignalService $signals)
    {
    }

    public function evaluate(Asset $asset, float $lat, float $lon, CarbonInterface $occurredAt): void
    {
        $geofences = AssetGeofence::query()
            ->where('is_active', true)
            ->where(function ($query) use ($asset) {
                $query
                    ->where('asset_id', $asset->id)
                    ->orWhereHas('assignedAssets', fn ($assets) => $assets->whereKey($asset->id));
            })
            ->get();

        foreach ($geofences as $geofence) {
            DB::transaction(function () use ($asset, $geofence, $lat, $lon, $occurredAt) {
                $inside = $this->isInside($geofence, $lat, $lon);

                $state = FleetGeofenceState::query()
                    ->where('asset_id', $asset->id)
                    ->where('geofence_id', $geofence->id)
                    ->lockForUpdate()
                    ->first();

                if (!$state) {
                    $state = new FleetGeofenceState([
                        'asset_id' => $asset->id,
                        'geofence_id' => $geofence->id,
                    ]);
                }

                $previous = $state->status;
                $next = $inside ? 'inside' : 'outside';

                if ($previous !== $next) {
                    $state->status = $next;
                    $state->last_changed_at = $occurredAt;
                    if ($inside) {
                        $state->last_inside_at = $occurredAt;
                        $state->dwell_started_at = $occurredAt;
                        $this->signals->emit([
                            'asset_id' => $asset->id,
                            'geofence_id' => $geofence->id,
                            'signal_type' => 'geofence.enter',
                            'severity_hint' => 'low',
                            'occurred_at' => $occurredAt,
                            'payload' => [
                                'geofence_name' => $geofence->name,
                            ],
                        ]);
                    } else {
                        $state->last_outside_at = $occurredAt;
                        $state->dwell_started_at = null;
                        $this->signals->emit([
                            'asset_id' => $asset->id,
                            'geofence_id' => $geofence->id,
                            'signal_type' => 'geofence.breach',
                            'severity_hint' => $geofence->breach_type === 'hard' ? 'high' : 'medium',
                            'occurred_at' => $occurredAt,
                            'payload' => [
                                'geofence_name' => $geofence->name,
                            ],
                        ]);
                    }
                }

                if ($inside && $state->dwell_started_at) {
                    $dwellMinutes = $state->dwell_started_at->diffInMinutes($occurredAt);
                    $dwellThreshold = (int) config('fleet.signals.dwell_threshold_minutes', 10);
                    if ($dwellMinutes >= $dwellThreshold) {
                        $idempotency = hash('sha256', implode('|', [
                            $asset->id,
                            $geofence->id,
                            'dwell',
                            $state->dwell_started_at->toISOString(),
                        ]));
                        $this->signals->emit([
                            'asset_id' => $asset->id,
                            'geofence_id' => $geofence->id,
                            'signal_type' => 'geofence.dwell',
                            'severity_hint' => 'low',
                            'occurred_at' => $occurredAt,
                            'idempotency_key' => $idempotency,
                            'payload' => [
                                'geofence_name' => $geofence->name,
                                'dwell_minutes' => $dwellMinutes,
                            ],
                        ]);
                    }
                }

                $state->save();
            });
        }
    }

    /**
     * Canonical point-in-geofence containment check. Invalid or incomplete
     * shapes (missing circle centre/radius, polygons with fewer than 3
     * points) are treated as OUTSIDE so a misconfigured geofence never
     * silently swallows a breach.
     */
    public function isInside(AssetGeofence $geofence, float $lat, float $lon): bool
    {
        $shape = $geofence->shape ?? [];
        if ($geofence->type === 'polygon') {
            return $this->pointInPolygon($lat, $lon, $shape['points'] ?? $shape['coordinates'] ?? []);
        }

        $center = $shape['center'] ?? [];
        $centerLat = $shape['lat'] ?? $center['lat'] ?? null;
        $centerLon = $shape['lon'] ?? $shape['lng'] ?? $center['lon'] ?? $center['lng'] ?? null;
        $radius = $shape['radius_m'] ?? null;

        if ($centerLat === null || $centerLon === null || $radius === null) {
            return false;
        }

        return $this->distanceMeters($lat, $lon, $centerLat, $centerLon) <= $radius;
    }

    protected function distanceMeters(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $earthRadius = 6371000;
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;
        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
        return $earthRadius * $c;
    }

    protected function pointInPolygon(float $lat, float $lon, array $points): bool
    {
        $inside = false;
        $count = count($points);
        if ($count < 3) {
            return false;
        }

        for ($i = 0, $j = $count - 1; $i < $count; $j = $i++) {
            $xi = $points[$i]['lat'] ?? null;
            $yi = $points[$i]['lon'] ?? $points[$i]['lng'] ?? null;
            $xj = $points[$j]['lat'] ?? null;
            $yj = $points[$j]['lon'] ?? $points[$j]['lng'] ?? null;

            if ($xi === null || $yi === null || $xj === null || $yj === null) {
                continue;
            }

            $intersect = (($yi > $lon) !== ($yj > $lon))
                && ($lat < ($xj - $xi) * ($lon - $yi) / ($yj - $yi + 0.0) + $xi);

            if ($intersect) {
                $inside = !$inside;
            }
        }

        return $inside;
    }
}
