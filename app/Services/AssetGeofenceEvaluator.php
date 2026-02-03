<?php

namespace App\Services;

use App\Models\Asset;
use App\Models\AssetGeofence;

class AssetGeofenceEvaluator
{
    public function evaluate(Asset $asset, ?float $lat, ?float $lon): array
    {
        if ($lat === null || $lon === null) {
            return [];
        }

        $breaches = [];

        $geofences = AssetGeofence::query()
            ->where('asset_id', $asset->id)
            ->where('is_active', true)
            ->get();

        foreach ($geofences as $geofence) {
            $inside = $this->isInside($geofence, $lat, $lon);
            if (!$inside) {
                $breaches[] = $geofence;
            }
        }

        return $breaches;
    }

    protected function isInside(AssetGeofence $geofence, float $lat, float $lon): bool
    {
        $shape = $geofence->shape ?? [];
        if ($geofence->type === 'polygon') {
            return $this->pointInPolygon($lat, $lon, $shape['points'] ?? []);
        }

        $centerLat = $shape['lat'] ?? null;
        $centerLon = $shape['lon'] ?? null;
        $radius = $shape['radius_m'] ?? null;

        if ($centerLat === null || $centerLon === null || $radius === null) {
            return true;
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
            return true;
        }

        for ($i = 0, $j = $count - 1; $i < $count; $j = $i++) {
            $xi = $points[$i]['lat'] ?? null;
            $yi = $points[$i]['lon'] ?? null;
            $xj = $points[$j]['lat'] ?? null;
            $yj = $points[$j]['lon'] ?? null;

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
