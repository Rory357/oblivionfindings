<?php

namespace App\Services\Tracking;

final class ClientZonePosition
{
    /** A reported accuracy circle touching the boundary cannot establish either side. */
    public function classify(array $shape, float $lat, float $lng, ?int $accuracy): string
    {
        if (! is_finite($lat) || ! is_finite($lng) || abs($lat) > 90 || abs($lng) > 180 || ($accuracy !== null && $accuracy < 0)) {
            return 'unknown';
        }
        if ($shape['type'] === 'circle') {
            $center = $shape['center'];
            $a = sin(deg2rad($lat - $center['lat']) / 2) ** 2
                + cos(deg2rad($lat)) * cos(deg2rad($center['lat'])) * sin(deg2rad($lng - $center['lng']) / 2) ** 2;
            $distance = 6371000 * 2 * atan2(sqrt($a), sqrt(max(0, 1 - $a)));
            $margin = $shape['radius_m'] - $distance;

            return abs($margin) <= max(1, $accuracy ?? 0) ? 'uncertain' : ($margin > 0 ? 'inside' : 'outside');
        }
        $inside = false;
        $distance = INF;
        $points = $shape['coordinates'];
        for ($i = 0, $j = count($points) - 1; $i < count($points); $j = $i++) {
            $a = $points[$i];
            $b = $points[$j];
            if (($a['lat'] > $lat) !== ($b['lat'] > $lat)
                && $lng < ($b['lng'] - $a['lng']) * ($lat - $a['lat']) / ($b['lat'] - $a['lat']) + $a['lng']) {
                $inside = ! $inside;
            }
            // Local metre projection for distance to each edge, including vertices.
            $x1 = deg2rad($a['lng'] - $lng) * 6371000 * cos(deg2rad($lat));
            $y1 = deg2rad($a['lat'] - $lat) * 6371000;
            $x2 = deg2rad($b['lng'] - $lng) * 6371000 * cos(deg2rad($lat));
            $y2 = deg2rad($b['lat'] - $lat) * 6371000;
            $length = ($x2 - $x1) ** 2 + ($y2 - $y1) ** 2;
            $t = $length > 0 ? max(0, min(1, -($x1 * ($x2 - $x1) + $y1 * ($y2 - $y1)) / $length)) : 0;
            $distance = min($distance, hypot($x1 + $t * ($x2 - $x1), $y1 + $t * ($y2 - $y1)));
        }

        return $distance <= max(1, $accuracy ?? 0) ? 'uncertain' : ($inside ? 'inside' : 'outside');
    }
}
