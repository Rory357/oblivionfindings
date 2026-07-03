<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\DataAwareRule;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Validates a geofence `shape` payload against the geofence `type`.
 *
 * Accepts the same shape variants the containment engine
 * (FleetGeofenceService::isInside) understands:
 * - circle: centre as `center.lat`/`center.lng` (or flat `lat`/`lon`) plus `radius_m`
 * - polygon: `coordinates` (or `points`) with at least 3 valid lat/lng pairs
 */
class GeofenceShape implements DataAwareRule, ValidationRule
{
    protected array $data = [];

    public function setData(array $data): static
    {
        $this->data = $data;

        return $this;
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_array($value)) {
            $fail('The geofence shape must be an object.');

            return;
        }

        $type = $this->data['type'] ?? null;

        if ($type === 'circle') {
            $this->validateCircle($value, $fail);
        } elseif ($type === 'polygon') {
            $this->validatePolygon($value, $fail);
        }
        // An unknown `type` is rejected by the accompanying in:circle,polygon rule.
    }

    protected function validateCircle(array $shape, Closure $fail): void
    {
        $center = is_array($shape['center'] ?? null) ? $shape['center'] : [];
        $lat = $shape['lat'] ?? $center['lat'] ?? null;
        $lng = $shape['lon'] ?? $shape['lng'] ?? $center['lon'] ?? $center['lng'] ?? null;
        $radius = $shape['radius_m'] ?? null;

        if (! is_numeric($lat) || $lat < -90 || $lat > 90) {
            $fail('The geofence centre latitude must be a number between -90 and 90.');
        }

        if (! is_numeric($lng) || $lng < -180 || $lng > 180) {
            $fail('The geofence centre longitude must be a number between -180 and 180.');
        }

        if (! is_numeric($radius) || $radius <= 0) {
            $fail('The geofence radius must be a number greater than 0.');
        }
    }

    protected function validatePolygon(array $shape, Closure $fail): void
    {
        $points = $shape['points'] ?? $shape['coordinates'] ?? null;

        if (! is_array($points) || count($points) < 3) {
            $fail('A polygon geofence needs at least 3 points.');

            return;
        }

        $position = 0;
        foreach ($points as $point) {
            $position++;
            $lat = is_array($point) ? ($point['lat'] ?? null) : null;
            $lng = is_array($point) ? ($point['lon'] ?? $point['lng'] ?? null) : null;

            if (
                ! is_numeric($lat) || $lat < -90 || $lat > 90
                || ! is_numeric($lng) || $lng < -180 || $lng > 180
            ) {
                $fail("Polygon point {$position} must have a valid latitude and longitude.");

                return;
            }
        }
    }
}
