<?php

namespace App\Services\Tracking;

use App\Models\AssetGeofence;

class GeofenceStatusService
{
    public const STATUS_IN_ZONE = 'in_zone';

    public const STATUS_OUTSIDE_ZONE = 'outside_zone';

    public const STATUS_UNKNOWN = 'unknown';

    public function evaluate(mixed $lat, mixed $lng, ?AssetGeofence $geofence): string
    {
        if ($geofence === null || $lat === null || $lng === null) {
            return self::STATUS_UNKNOWN;
        }

        $lat = (float) $lat;
        $lng = (float) $lng;
        $shape = $geofence->shape ?? [];

        if (($geofence->type ?? null) === 'circle') {
            return $this->evaluateCircle($lat, $lng, $shape);
        }

        if (($geofence->type ?? null) === 'polygon') {
            return $this->evaluatePolygon($lat, $lng, $shape);
        }

        return self::STATUS_UNKNOWN;
    }

    private function evaluateCircle(float $lat, float $lng, array $shape): string
    {
        $center = $shape['center'] ?? $shape;
        $centerLat = $center['lat'] ?? $center['latitude'] ?? null;
        $centerLng = $center['lng'] ?? $center['lon'] ?? $center['longitude'] ?? null;
        $radiusM = $shape['radius_m'] ?? $shape['radius'] ?? null;

        if ($centerLat === null || $centerLng === null || $radiusM === null) {
            return self::STATUS_UNKNOWN;
        }

        $distance = $this->haversineDistanceMetres(
            $lat,
            $lng,
            (float) $centerLat,
            (float) $centerLng
        );

        return $distance <= (float) $radiusM
            ? self::STATUS_IN_ZONE
            : self::STATUS_OUTSIDE_ZONE;
    }

    private function evaluatePolygon(float $lat, float $lng, array $shape): string
    {
        $rawPoints = $shape['coordinates'] ?? $shape['points'] ?? $shape['vertices'] ?? [];
        $points = [];
        foreach ($rawPoints as $point) {
            $pLat = $point['lat'] ?? $point['latitude'] ?? null;
            $pLng = $point['lng'] ?? $point['lon'] ?? $point['longitude'] ?? null;
            if ($pLat === null || $pLng === null) {
                continue;
            }
            $points[] = [(float) $pLat, (float) $pLng];
        }

        if (count($points) < 3) {
            return self::STATUS_UNKNOWN;
        }

        return $this->pointInPolygon($lat, $lng, $points)
            ? self::STATUS_IN_ZONE
            : self::STATUS_OUTSIDE_ZONE;
    }

    private function haversineDistanceMetres(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $earthRadiusM = 6_371_000.0;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;
        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return $earthRadiusM * $c;
    }

    /**
     * Ray-casting algorithm. Points expected as [lat, lng] pairs.
     */
    private function pointInPolygon(float $lat, float $lng, array $points): bool
    {
        $inside = false;
        $n = count($points);

        for ($i = 0, $j = $n - 1; $i < $n; $j = $i++) {
            [$iLat, $iLng] = $points[$i];
            [$jLat, $jLng] = $points[$j];

            $intersect = (($iLng > $lng) !== ($jLng > $lng))
                && ($lat < ($jLat - $iLat) * ($lng - $iLng) / (($jLng - $iLng) ?: 1e-12) + $iLat);
            if ($intersect) {
                $inside = ! $inside;
            }
        }

        return $inside;
    }
}
